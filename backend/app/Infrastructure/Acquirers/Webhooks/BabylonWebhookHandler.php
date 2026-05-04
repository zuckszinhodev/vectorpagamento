<?php

declare(strict_types=1);

namespace Infrastructure\Acquirers\Webhooks;

use Domain\Payment\Repositories\ChargeRepositoryInterface;
use Domain\Payment\Repositories\TransactionRepositoryInterface;
use Domain\Payment\ValueObjects\TransactionStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhook Handler for Banco Babylon
 * 
 * Handles payment status updates from Babylon API
 * 
 * Webhook payload structure:
 * {
 *   "id": "F92XRTVSGB2B",
 *   "type": "transaction",
 *   "objectId": "28a65292-6c74-4368-924d-f52a653706be",
 *   "data": {
 *     "id": "28a65292-6c74-4368-924d-f52a653706be",
 *     "amount": 10000,
 *     "status": "paid",
 *     ...
 *   }
 * }
 * 
 * Possible statuses:
 * - waiting_payment: Aguardando pagamento
 * - paid: Pago
 * - refused: Recusado
 * - canceled: Cancelado
 * - refunded: Estornado
 * - chargedback: Chargeback
 * - failed: Falhou
 * - expired: Expirado
 * - in_analysis: Em análise
 * - in_protest: Em protesto
 */
class BabylonWebhookHandler
{
    public function __construct(
        private ChargeRepositoryInterface $chargeRepository,
        private TransactionRepositoryInterface $transactionRepository
    ) {}

    /**
     * Handle incoming webhook request
     */
    public function handle(Request $request): array
    {
        $payload = $request->getContent();
        $signature = $request->header('X-Babylon-Signature') ?? $request->header('X-Webhook-Signature');

        // Log the incoming webhook
        Log::info('Babylon webhook received', [
            'headers' => $request->headers->all(),
            'body_preview' => substr($payload, 0, 500),
        ]);

        // Verify webhook signature — fail-secure
        $webhookSecret = config('acquirers.babylon.webhook_secret');
        if (!$webhookSecret) {
            Log::critical('Babylon webhook secret not configured — rejecting request');
            return ['status' => 'error', 'message' => 'Webhook secret not configured'];
        }

        if (!$signature) {
            Log::warning('Babylon webhook: missing signature header');
            return ['status' => 'error', 'message' => 'Missing signature'];
        }

        $expectedSignature = hash_hmac('sha256', $payload, $webhookSecret);
        if (!hash_equals($expectedSignature, $signature)) {
            Log::warning('Babylon webhook signature verification failed', [
                'expected' => $expectedSignature,
                'received' => $signature,
            ]);
            return ['status' => 'error', 'message' => 'Invalid signature'];
        }

        $data = json_decode($payload, true);

        if (!$data) {
            Log::error('Babylon webhook: Invalid JSON payload');
            return ['status' => 'error', 'message' => 'Invalid JSON payload'];
        }

        $eventType = $data['type'] ?? 'unknown';
        $eventId = $data['id'] ?? null;
        $transactionData = $data['data'] ?? $data;

        Log::info('Babylon webhook processing', [
            'event_type' => $eventType,
            'event_id' => $eventId,
            'transaction_id' => $transactionData['id'] ?? null,
            'status' => $transactionData['status'] ?? null,
        ]);

        // Handle based on transaction status
        $status = $transactionData['status'] ?? 'unknown';

        return match ($status) {
            'paid' => $this->handlePaid($transactionData),
            'refused', 'declined' => $this->handleRefused($transactionData),
            'canceled', 'cancelled' => $this->handleCanceled($transactionData),
            'refunded' => $this->handleRefunded($transactionData),
            'chargedback' => $this->handleChargedback($transactionData),
            'failed' => $this->handleFailed($transactionData),
            'expired' => $this->handleExpired($transactionData),
            'in_analysis' => $this->handleInAnalysis($transactionData),
            'waiting_payment' => $this->handleWaitingPayment($transactionData),
            default => ['status' => 'ignored', 'message' => "Unknown status: {$status}"],
        };
    }

    /**
     * Handle paid transaction
     */
    private function handlePaid(array $data): array
    {
        $chargeId = $data['metadata']['charge_id'] ?? null;
        $transactionId = $data['id'];

        if (!$chargeId) {
            // Try to find by transaction ID
            $transaction = $this->transactionRepository->findByAcquirerTransactionId($transactionId);

            if (!$transaction) {
                Log::warning('Babylon webhook: Charge not found', [
                    'transaction_id' => $transactionId,
                ]);
                return ['status' => 'skipped', 'reason' => 'No charge_id in metadata and transaction not found'];
            }

            $chargeId = $transaction->getChargeId();
        }

        $charge = $this->chargeRepository->findById($chargeId);

        if (!$charge) {
            Log::warning('Babylon webhook: Charge not found', [
                'charge_id' => $chargeId,
            ]);
            return ['status' => 'skipped', 'reason' => 'Charge not found'];
        }

        // Update transaction status
        $transaction = $this->transactionRepository->findByAcquirerTransactionId($transactionId);

        if ($transaction) {
            $transaction->updateStatus(
                TransactionStatus::APPROVED,
                [
                    'webhook_event' => 'paid',
                    'paid_at' => $data['paidAt'] ?? now()->toIso8601String(),
                    'end2end_id' => $data['pix']['end2EndId'] ?? null,
                ]
            );
            $this->transactionRepository->save($transaction);
        }

        // Mark charge as paid
        if ($charge->getStatus() !== 'paid') {
            $charge->markAsPaid(
                transactionId: $transactionId,
                paidAt: now()
            );
            $this->chargeRepository->save($charge);
        }

        Log::info('Babylon transaction paid', [
            'transaction_id' => $transactionId,
            'charge_id' => $chargeId,
            'amount' => $data['amount'] ?? null,
            'payment_method' => $data['paymentMethod'] ?? null,
        ]);

        return [
            'status' => 'processed',
            'event' => 'paid',
            'charge_id' => $chargeId,
            'transaction_id' => $transactionId,
        ];
    }

    /**
     * Handle refused transaction
     */
    private function handleRefused(array $data): array
    {
        $chargeId = $data['metadata']['charge_id'] ?? null;
        $transactionId = $data['id'];
        $refusedReason = $data['refusedReason'] ?? 'Transaction refused';

        if (!$chargeId) {
            $transaction = $this->transactionRepository->findByAcquirerTransactionId($transactionId);
            $chargeId = $transaction?->getChargeId();
        }

        if (!$chargeId) {
            return ['status' => 'skipped', 'reason' => 'Charge not found'];
        }

        $charge = $this->chargeRepository->findById($chargeId);

        if (!$charge) {
            return ['status' => 'skipped', 'reason' => 'Charge not found'];
        }

        // Update transaction status
        $transaction = $this->transactionRepository->findByAcquirerTransactionId($transactionId);

        if ($transaction) {
            $transaction->updateStatus(
                TransactionStatus::DECLINED,
                [
                    'webhook_event' => 'refused',
                    'refused_reason' => $refusedReason,
                ]
            );
            $this->transactionRepository->save($transaction);
        }

        // Mark charge as failed
        if (!in_array($charge->getStatus(), ['failed', 'refused'])) {
            $charge->markAsFailed($refusedReason);
            $this->chargeRepository->save($charge);
        }

        Log::info('Babylon transaction refused', [
            'transaction_id' => $transactionId,
            'charge_id' => $chargeId,
            'reason' => $refusedReason,
        ]);

        return [
            'status' => 'processed',
            'event' => 'refused',
            'charge_id' => $chargeId,
            'reason' => $refusedReason,
        ];
    }

    /**
     * Handle canceled transaction
     */
    private function handleCanceled(array $data): array
    {
        $chargeId = $data['metadata']['charge_id'] ?? null;
        $transactionId = $data['id'];

        if (!$chargeId) {
            $transaction = $this->transactionRepository->findByAcquirerTransactionId($transactionId);
            $chargeId = $transaction?->getChargeId();
        }

        if (!$chargeId) {
            return ['status' => 'skipped', 'reason' => 'Charge not found'];
        }

        $charge = $this->chargeRepository->findById($chargeId);

        if (!$charge) {
            return ['status' => 'skipped', 'reason' => 'Charge not found'];
        }

        $transaction = $this->transactionRepository->findByAcquirerTransactionId($transactionId);

        if ($transaction) {
            $transaction->updateStatus(
                TransactionStatus::CANCELLED,
                ['webhook_event' => 'canceled']
            );
            $this->transactionRepository->save($transaction);
        }

        if ($charge->getStatus() !== 'canceled') {
            $charge->markAsCanceled();
            $this->chargeRepository->save($charge);
        }

        Log::info('Babylon transaction canceled', [
            'transaction_id' => $transactionId,
            'charge_id' => $chargeId,
        ]);

        return [
            'status' => 'processed',
            'event' => 'canceled',
            'charge_id' => $chargeId,
        ];
    }

    /**
     * Handle refunded transaction
     */
    private function handleRefunded(array $data): array
    {
        $chargeId = $data['metadata']['charge_id'] ?? null;
        $transactionId = $data['id'];
        $refundedAmount = $data['refundedAmount'] ?? $data['amount'];

        if (!$chargeId) {
            $transaction = $this->transactionRepository->findByAcquirerTransactionId($transactionId);
            $chargeId = $transaction?->getChargeId();
        }

        if (!$chargeId) {
            return ['status' => 'skipped', 'reason' => 'Charge not found'];
        }

        $charge = $this->chargeRepository->findById($chargeId);

        if (!$charge) {
            return ['status' => 'skipped', 'reason' => 'Charge not found'];
        }

        $transaction = $this->transactionRepository->findByAcquirerTransactionId($transactionId);

        if ($transaction) {
            $transaction->updateStatus(
                TransactionStatus::REFUNDED,
                [
                    'webhook_event' => 'refunded',
                    'refunded_amount' => $refundedAmount,
                ]
            );
            $this->transactionRepository->save($transaction);
        }

        if ($charge->getStatus() !== 'refunded') {
            $charge->markAsRefunded($refundedAmount / 100); // Convert from cents
            $this->chargeRepository->save($charge);
        }

        Log::info('Babylon transaction refunded', [
            'transaction_id' => $transactionId,
            'charge_id' => $chargeId,
            'refunded_amount' => $refundedAmount,
        ]);

        return [
            'status' => 'processed',
            'event' => 'refunded',
            'charge_id' => $chargeId,
            'refunded_amount' => $refundedAmount,
        ];
    }

    /**
     * Handle chargedback transaction
     */
    private function handleChargedback(array $data): array
    {
        $chargeId = $data['metadata']['charge_id'] ?? null;
        $transactionId = $data['id'];

        if (!$chargeId) {
            $transaction = $this->transactionRepository->findByAcquirerTransactionId($transactionId);
            $chargeId = $transaction?->getChargeId();
        }

        if (!$chargeId) {
            return ['status' => 'skipped', 'reason' => 'Charge not found'];
        }

        $charge = $this->chargeRepository->findById($chargeId);

        if (!$charge) {
            return ['status' => 'skipped', 'reason' => 'Charge not found'];
        }

        $transaction = $this->transactionRepository->findByAcquirerTransactionId($transactionId);

        if ($transaction) {
            $transaction->updateStatus(
                TransactionStatus::CHARGEDBACK,
                ['webhook_event' => 'chargedback']
            );
            $this->transactionRepository->save($transaction);
        }

        if ($charge->getStatus() !== 'chargedback') {
            $charge->markAsChargedback();
            $this->chargeRepository->save($charge);
        }

        Log::warning('Babylon transaction chargedback', [
            'transaction_id' => $transactionId,
            'charge_id' => $chargeId,
        ]);

        return [
            'status' => 'processed',
            'event' => 'chargedback',
            'charge_id' => $chargeId,
        ];
    }

    /**
     * Handle failed transaction
     */
    private function handleFailed(array $data): array
    {
        $chargeId = $data['metadata']['charge_id'] ?? null;
        $transactionId = $data['id'];
        $reason = $data['refusedReason'] ?? 'Transaction failed';

        if (!$chargeId) {
            $transaction = $this->transactionRepository->findByAcquirerTransactionId($transactionId);
            $chargeId = $transaction?->getChargeId();
        }

        if (!$chargeId) {
            return ['status' => 'skipped', 'reason' => 'Charge not found'];
        }

        $charge = $this->chargeRepository->findById($chargeId);

        if (!$charge) {
            return ['status' => 'skipped', 'reason' => 'Charge not found'];
        }

        $transaction = $this->transactionRepository->findByAcquirerTransactionId($transactionId);

        if ($transaction) {
            $transaction->updateStatus(
                TransactionStatus::FAILED,
                [
                    'webhook_event' => 'failed',
                    'reason' => $reason,
                ]
            );
            $this->transactionRepository->save($transaction);
        }

        if ($charge->getStatus() !== 'failed') {
            $charge->markAsFailed($reason);
            $this->chargeRepository->save($charge);
        }

        Log::error('Babylon transaction failed', [
            'transaction_id' => $transactionId,
            'charge_id' => $chargeId,
            'reason' => $reason,
        ]);

        return [
            'status' => 'processed',
            'event' => 'failed',
            'charge_id' => $chargeId,
            'reason' => $reason,
        ];
    }

    /**
     * Handle expired transaction
     */
    private function handleExpired(array $data): array
    {
        $chargeId = $data['metadata']['charge_id'] ?? null;
        $transactionId = $data['id'];

        if (!$chargeId) {
            $transaction = $this->transactionRepository->findByAcquirerTransactionId($transactionId);
            $chargeId = $transaction?->getChargeId();
        }

        if (!$chargeId) {
            return ['status' => 'skipped', 'reason' => 'Charge not found'];
        }

        $charge = $this->chargeRepository->findById($chargeId);

        if (!$charge) {
            return ['status' => 'skipped', 'reason' => 'Charge not found'];
        }

        $transaction = $this->transactionRepository->findByAcquirerTransactionId($transactionId);

        if ($transaction) {
            $transaction->updateStatus(
                TransactionStatus::EXPIRED,
                ['webhook_event' => 'expired']
            );
            $this->transactionRepository->save($transaction);
        }

        if ($charge->getStatus() !== 'expired') {
            $charge->markAsExpired();
            $this->chargeRepository->save($charge);
        }

        Log::info('Babylon transaction expired', [
            'transaction_id' => $transactionId,
            'charge_id' => $chargeId,
        ]);

        return [
            'status' => 'processed',
            'event' => 'expired',
            'charge_id' => $chargeId,
        ];
    }

    /**
     * Handle transaction in analysis
     */
    private function handleInAnalysis(array $data): array
    {
        $chargeId = $data['metadata']['charge_id'] ?? null;
        $transactionId = $data['id'];

        Log::info('Babylon transaction in analysis', [
            'transaction_id' => $transactionId,
            'charge_id' => $chargeId,
        ]);

        if (!$chargeId) {
            $transaction = $this->transactionRepository->findByAcquirerTransactionId($transactionId);
            $chargeId = $transaction?->getChargeId();
        }

        if ($chargeId) {
            $transaction = $this->transactionRepository->findByAcquirerTransactionId($transactionId);

            if ($transaction) {
                $transaction->updateStatus(
                    TransactionStatus::PENDING,
                    ['webhook_event' => 'in_analysis']
                );
                $this->transactionRepository->save($transaction);
            }
        }

        return [
            'status' => 'processed',
            'event' => 'in_analysis',
            'charge_id' => $chargeId,
        ];
    }

    /**
     * Handle waiting payment status (usually initial PIX/Boleto status)
     */
    private function handleWaitingPayment(array $data): array
    {
        $chargeId = $data['metadata']['charge_id'] ?? null;
        $transactionId = $data['id'];

        Log::info('Babylon transaction waiting payment', [
            'transaction_id' => $transactionId,
            'charge_id' => $chargeId,
            'payment_method' => $data['paymentMethod'] ?? null,
        ]);

        // This is usually the initial status, no action needed
        return [
            'status' => 'acknowledged',
            'event' => 'waiting_payment',
            'charge_id' => $chargeId,
        ];
    }
}
