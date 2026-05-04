<?php

declare(strict_types=1);

namespace Infrastructure\Acquirers\Webhooks;

use Domain\Payment\Repositories\ChargeRepositoryInterface;
use Domain\Payment\Repositories\TransactionRepositoryInterface;
use Domain\Payment\ValueObjects\TransactionStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PagarmeWebhookHandler
{
    public function __construct(
        private ChargeRepositoryInterface $chargeRepository,
        private TransactionRepositoryInterface $transactionRepository
    ) {}

    public function handle(Request $request): array
    {
        $payload = $request->getContent();
        $signature = $request->header('X-Hub-Signature');

        // Verify webhook signature
        $webhookSecret = config('acquirers.pagarme.webhook_secret');

        if (empty($webhookSecret)) {
            Log::error('Pagar.me webhook rejected: webhook_secret not configured in acquirers.pagarme');
            return ['status' => 'error', 'message' => 'Webhook not configured'];
        }

        if (empty($signature)) {
            Log::warning('Pagar.me webhook rejected: missing X-Hub-Signature header');
            return ['status' => 'error', 'message' => 'Missing signature'];
        }

        $expectedSignature = 'sha256=' . hash_hmac('sha256', $payload, $webhookSecret);

        if (!hash_equals($expectedSignature, $signature)) {
            Log::warning('Pagar.me webhook signature verification failed');
            return ['status' => 'error', 'message' => 'Invalid signature'];
        }

        $data = json_decode($payload, true);
        $eventType = $data['type'] ?? 'unknown';

        Log::info('Pagar.me webhook received', [
            'type' => $eventType,
            'id' => $data['id'] ?? null,
        ]);

        return match ($eventType) {
            'order.paid' => $this->handleOrderPaid($data['data']),
            'order.payment_failed' => $this->handleOrderPaymentFailed($data['data']),
            'order.canceled' => $this->handleOrderCanceled($data['data']),
            'charge.paid' => $this->handleChargePaid($data['data']),
            'charge.payment_failed' => $this->handleChargePaymentFailed($data['data']),
            'charge.refunded' => $this->handleChargeRefunded($data['data']),
            'charge.chargedback' => $this->handleChargeChargedback($data['data']),
            default => ['status' => 'ignored', 'type' => $eventType],
        };
    }

    private function handleOrderPaid(array $data): array
    {
        $chargeId = $data['metadata']['charge_id'] ?? null;

        if (!$chargeId) {
            return ['status' => 'skipped', 'reason' => 'No charge_id in metadata'];
        }

        $charge = $this->chargeRepository->findById($chargeId);

        if (!$charge) {
            return ['status' => 'skipped', 'reason' => 'Charge not found'];
        }

        // Update transaction status
        $orderId = $data['id'];
        $transaction = $this->transactionRepository->findByAcquirerTransactionId($orderId);

        if ($transaction) {
            $transaction->updateStatus(
                TransactionStatus::APPROVED,
                ['webhook_event' => 'order.paid']
            );
            $this->transactionRepository->save($transaction);
        }

        // Mark charge as paid
        if ($charge->getStatus() !== 'paid') {
            $charge->markAsPaid(
                transactionId: $orderId,
                paidAt: now()
            );
            $this->chargeRepository->save($charge);
        }

        Log::info('Pagar.me order paid', [
            'order_id' => $orderId,
            'charge_id' => $chargeId,
        ]);

        return ['status' => 'processed', 'charge_id' => $chargeId];
    }

    private function handleOrderPaymentFailed(array $data): array
    {
        $chargeId = $data['metadata']['charge_id'] ?? null;

        if (!$chargeId) {
            return ['status' => 'skipped', 'reason' => 'No charge_id in metadata'];
        }

        $charge = $this->chargeRepository->findById($chargeId);

        if (!$charge) {
            return ['status' => 'skipped', 'reason' => 'Charge not found'];
        }

        // Update transaction status
        $orderId = $data['id'];
        $transaction = $this->transactionRepository->findByAcquirerTransactionId($orderId);

        if ($transaction) {
            $chargeData = $data['charges'][0] ?? [];
            $lastTransaction = $chargeData['last_transaction'] ?? [];
            $gatewayResponse = $lastTransaction['gateway_response'] ?? [];

            $transaction->updateStatus(
                TransactionStatus::DECLINED,
                [
                    'webhook_event' => 'order.payment_failed',
                    'error_code' => $gatewayResponse['code'] ?? 'unknown',
                    'error_message' => $gatewayResponse['message'] ?? 'Payment failed',
                ]
            );
            $this->transactionRepository->save($transaction);
        }

        Log::warning('Pagar.me order payment failed', [
            'order_id' => $orderId,
            'charge_id' => $chargeId,
        ]);

        return ['status' => 'processed', 'charge_id' => $chargeId];
    }

    private function handleOrderCanceled(array $data): array
    {
        $chargeId = $data['metadata']['charge_id'] ?? null;

        if (!$chargeId) {
            return ['status' => 'skipped', 'reason' => 'No charge_id in metadata'];
        }

        $charge = $this->chargeRepository->findById($chargeId);

        if (!$charge) {
            return ['status' => 'skipped', 'reason' => 'Charge not found'];
        }

        $charge->markAsCancelled('Cancelled via Pagar.me webhook');
        $this->chargeRepository->save($charge);

        Log::info('Pagar.me order canceled', [
            'order_id' => $data['id'],
            'charge_id' => $chargeId,
        ]);

        return ['status' => 'processed', 'charge_id' => $chargeId];
    }

    private function handleChargePaid(array $data): array
    {
        $chargeId = $data['metadata']['charge_id'] ?? null;

        if (!$chargeId) {
            return ['status' => 'skipped', 'reason' => 'No charge_id in metadata'];
        }

        Log::info('Pagar.me charge paid', [
            'pagarme_charge_id' => $data['id'],
            'charge_id' => $chargeId,
        ]);

        return ['status' => 'processed', 'charge_id' => $chargeId];
    }

    private function handleChargePaymentFailed(array $data): array
    {
        $chargeId = $data['metadata']['charge_id'] ?? null;

        if (!$chargeId) {
            return ['status' => 'skipped', 'reason' => 'No charge_id in metadata'];
        }

        Log::warning('Pagar.me charge payment failed', [
            'pagarme_charge_id' => $data['id'],
            'charge_id' => $chargeId,
        ]);

        return ['status' => 'processed', 'charge_id' => $chargeId];
    }

    private function handleChargeRefunded(array $data): array
    {
        $chargeId = $data['metadata']['charge_id'] ?? null;

        if (!$chargeId) {
            return ['status' => 'skipped', 'reason' => 'No charge_id in metadata'];
        }

        $chargeEntity = $this->chargeRepository->findById($chargeId);

        if (!$chargeEntity) {
            return ['status' => 'skipped', 'reason' => 'Charge not found'];
        }

        $refundedAmount = $data['last_transaction']['amount'] ?? $data['amount'];
        $totalAmount = $data['amount'];

        if ($refundedAmount >= $totalAmount) {
            $chargeEntity->markAsRefunded();
        } else {
            $chargeEntity->markAsPartiallyRefunded($refundedAmount);
        }

        $this->chargeRepository->save($chargeEntity);

        Log::info('Pagar.me charge refunded', [
            'pagarme_charge_id' => $data['id'],
            'charge_id' => $chargeId,
            'refunded_amount' => $refundedAmount,
        ]);

        return ['status' => 'processed', 'charge_id' => $chargeId];
    }

    private function handleChargeChargedback(array $data): array
    {
        $chargeId = $data['metadata']['charge_id'] ?? null;

        if (!$chargeId) {
            return ['status' => 'skipped', 'reason' => 'No charge_id in metadata'];
        }

        $chargeEntity = $this->chargeRepository->findById($chargeId);

        if (!$chargeEntity) {
            return ['status' => 'skipped', 'reason' => 'Charge not found'];
        }

        $chargeEntity->markAsDisputed([
            'type' => 'chargeback',
            'pagarme_charge_id' => $data['id'],
        ]);
        $this->chargeRepository->save($chargeEntity);

        Log::warning('Pagar.me charge chargedback', [
            'pagarme_charge_id' => $data['id'],
            'charge_id' => $chargeId,
        ]);

        return ['status' => 'processed', 'charge_id' => $chargeId];
    }
}
