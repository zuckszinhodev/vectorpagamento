<?php

declare(strict_types=1);

namespace Infrastructure\Acquirers\Webhooks;

use Domain\Payment\Repositories\ChargeRepositoryInterface;
use Domain\Payment\Repositories\TransactionRepositoryInterface;
use Domain\Payment\ValueObjects\TransactionStatus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\MercadoPagoConfig;

class MercadoPagoWebhookHandler
{
    private PaymentClient $paymentClient;

    public function __construct(
        private ChargeRepositoryInterface $chargeRepository,
        private TransactionRepositoryInterface $transactionRepository
    ) {
        MercadoPagoConfig::setAccessToken(config('acquirers.mercadopago.access_token'));
        $this->paymentClient = new PaymentClient();
    }

    public function handle(Request $request): array
    {
        $signature = $request->header('x-signature');
        $requestId = $request->header('x-request-id');
        $dataId = $request->input('data.id');

        // Verify webhook signature
        if (!$this->verifySignature($request, $signature)) {
            Log::warning('MercadoPago webhook signature verification failed');
            return ['status' => 'error', 'message' => 'Invalid signature'];
        }

        $data = $request->all();
        $eventType = $data['type'] ?? 'unknown';
        $action = $data['action'] ?? null;

        Log::info('MercadoPago webhook received', [
            'type' => $eventType,
            'action' => $action,
            'id' => $dataId,
        ]);

        // MercadoPago sends minimal data, need to fetch full payment details
        if ($eventType === 'payment' && $dataId) {
            return $this->handlePaymentEvent($dataId, $action);
        }

        return ['status' => 'ignored', 'type' => $eventType];
    }

    private function handlePaymentEvent(string $paymentId, ?string $action): array
    {
        try {
            // Fetch full payment data from MercadoPago
            $payment = $this->paymentClient->get((int) $paymentId);

            if (!$payment) {
                return ['status' => 'skipped', 'reason' => 'Payment not found on MercadoPago'];
            }

            $chargeId = $payment->metadata?->charge_id ?? null;

            if (!$chargeId) {
                return ['status' => 'skipped', 'reason' => 'No charge_id in metadata'];
            }

            $charge = $this->chargeRepository->findById($chargeId);

            if (!$charge) {
                return ['status' => 'skipped', 'reason' => 'Charge not found'];
            }

            return match ($payment->status) {
                'approved' => $this->handlePaymentApproved($payment, $charge, $chargeId),
                'rejected' => $this->handlePaymentRejected($payment, $charge, $chargeId),
                'cancelled' => $this->handlePaymentCancelled($payment, $charge, $chargeId),
                'refunded' => $this->handlePaymentRefunded($payment, $charge, $chargeId),
                'charged_back' => $this->handlePaymentChargedBack($payment, $charge, $chargeId),
                'pending', 'in_process', 'authorized' => $this->handlePaymentPending($payment, $chargeId),
                default => ['status' => 'ignored', 'payment_status' => $payment->status],
            };

        } catch (\Exception $e) {
            Log::error('MercadoPago webhook processing error', [
                'payment_id' => $paymentId,
                'error' => $e->getMessage(),
            ]);

            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    private function handlePaymentApproved(object $payment, $charge, string $chargeId): array
    {
        // Update transaction status
        $transaction = $this->transactionRepository->findByAcquirerTransactionId((string) $payment->id);

        if ($transaction) {
            $transaction->updateStatus(
                TransactionStatus::APPROVED,
                [
                    'webhook_event' => 'payment.approved',
                    'authorization_code' => $payment->authorization_code ?? null,
                ]
            );
            $this->transactionRepository->save($transaction);
        }

        // Mark charge as paid
        if ($charge->getStatus() !== 'paid') {
            $charge->markAsPaid(
                transactionId: (string) $payment->id,
                paidAt: now()
            );
            $this->chargeRepository->save($charge);
        }

        Log::info('MercadoPago payment approved', [
            'payment_id' => $payment->id,
            'charge_id' => $chargeId,
        ]);

        return ['status' => 'processed', 'charge_id' => $chargeId];
    }

    private function handlePaymentRejected(object $payment, $charge, string $chargeId): array
    {
        // Update transaction status
        $transaction = $this->transactionRepository->findByAcquirerTransactionId((string) $payment->id);

        if ($transaction) {
            $transaction->updateStatus(
                TransactionStatus::DECLINED,
                [
                    'webhook_event' => 'payment.rejected',
                    'status_detail' => $payment->status_detail ?? 'rejected',
                    'error_message' => $this->getStatusDetailMessage($payment->status_detail ?? ''),
                ]
            );
            $this->transactionRepository->save($transaction);
        }

        Log::warning('MercadoPago payment rejected', [
            'payment_id' => $payment->id,
            'charge_id' => $chargeId,
            'status_detail' => $payment->status_detail,
        ]);

        return ['status' => 'processed', 'charge_id' => $chargeId];
    }

    private function handlePaymentCancelled(object $payment, $charge, string $chargeId): array
    {
        $charge->markAsCancelled('Cancelled via MercadoPago');
        $this->chargeRepository->save($charge);

        Log::info('MercadoPago payment cancelled', [
            'payment_id' => $payment->id,
            'charge_id' => $chargeId,
        ]);

        return ['status' => 'processed', 'charge_id' => $chargeId];
    }

    private function handlePaymentRefunded(object $payment, $charge, string $chargeId): array
    {
        $refundedAmount = (int) ($payment->transaction_amount_refunded * 100);
        $totalAmount = (int) ($payment->transaction_amount * 100);

        if ($refundedAmount >= $totalAmount) {
            $charge->markAsRefunded();
        } else {
            $charge->markAsPartiallyRefunded($refundedAmount);
        }

        $this->chargeRepository->save($charge);

        Log::info('MercadoPago payment refunded', [
            'payment_id' => $payment->id,
            'charge_id' => $chargeId,
            'refunded_amount' => $refundedAmount,
        ]);

        return ['status' => 'processed', 'charge_id' => $chargeId];
    }

    private function handlePaymentChargedBack(object $payment, $charge, string $chargeId): array
    {
        $charge->markAsDisputed([
            'type' => 'chargeback',
            'mercadopago_payment_id' => $payment->id,
            'reason' => $payment->status_detail ?? 'chargeback',
        ]);
        $this->chargeRepository->save($charge);

        Log::warning('MercadoPago payment charged back', [
            'payment_id' => $payment->id,
            'charge_id' => $chargeId,
        ]);

        return ['status' => 'processed', 'charge_id' => $chargeId];
    }

    private function handlePaymentPending(object $payment, string $chargeId): array
    {
        Log::info('MercadoPago payment pending', [
            'payment_id' => $payment->id,
            'charge_id' => $chargeId,
            'status' => $payment->status,
        ]);

        return ['status' => 'processed', 'charge_id' => $chargeId, 'payment_status' => 'pending'];
    }

    private function verifySignature(Request $request, ?string $signature): bool
    {
        if (!$signature) {
            return false;
        }

        $webhookSecret = config('acquirers.mercadopago.webhook_secret');

        if (!$webhookSecret) {
            Log::critical('MercadoPago webhook_secret not configured — rejecting all webhooks');
            return false;
        }

        // Parse signature parts
        $signatureParts = [];
        foreach (explode(',', $signature) as $part) {
            $parts = explode('=', $part, 2);
            if (count($parts) === 2) {
                $signatureParts[$parts[0]] = $parts[1];
            }
        }

        $timestamp = $signatureParts['ts'] ?? '';
        $receivedSignature = $signatureParts['v1'] ?? '';

        // Build manifest for signature verification
        $dataId = $request->input('data.id', '');
        $requestId = $request->header('x-request-id', '');

        $manifest = "id:{$dataId};request-id:{$requestId};ts:{$timestamp};";

        $expectedSignature = hash_hmac('sha256', $manifest, $webhookSecret);

        return hash_equals($expectedSignature, $receivedSignature);
    }

    private function getStatusDetailMessage(string $statusDetail): string
    {
        return match ($statusDetail) {
            'cc_rejected_bad_filled_card_number' => 'Número do cartão inválido',
            'cc_rejected_bad_filled_date' => 'Data de validade inválida',
            'cc_rejected_bad_filled_other' => 'Dados do cartão inválidos',
            'cc_rejected_bad_filled_security_code' => 'CVV inválido',
            'cc_rejected_blacklist' => 'Cartão não aceito',
            'cc_rejected_call_for_authorize' => 'Ligue para autorização',
            'cc_rejected_card_disabled' => 'Cartão desabilitado',
            'cc_rejected_duplicated_payment' => 'Pagamento duplicado',
            'cc_rejected_high_risk' => 'Transação rejeitada por segurança',
            'cc_rejected_insufficient_amount' => 'Saldo insuficiente',
            'cc_rejected_invalid_installments' => 'Parcelas inválidas',
            'cc_rejected_max_attempts' => 'Limite de tentativas excedido',
            default => 'Transação não autorizada',
        };
    }
}
