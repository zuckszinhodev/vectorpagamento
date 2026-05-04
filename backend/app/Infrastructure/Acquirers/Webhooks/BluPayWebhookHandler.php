<?php

declare(strict_types=1);

namespace App\Infrastructure\Acquirers\Webhooks;

use App\Infrastructure\Acquirers\BluPayAcquirer;
use App\Models\Charge;
use App\Models\Transaction;
use App\Models\Withdrawal;
use App\Services\PaymentService;
use App\Services\WithdrawalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Webhook Handler for BluPay IP
 * 
 * Handles payment status updates from BluPay API
 * 
 * Webhook payload structures:
 * 
 * PIX IN — transaction.paid:
 * {
 *   "id": "evt_xxx",
 *   "type": "transaction",
 *   "event": "transaction.paid",
 *   "objectId": "uuid",
 *   "data": {
 *     "id": "uuid",
 *     "status": "paid",
 *     "amount": 500,
 *     "refundedAmount": 0,
 *     "externalRef": "charge-uuid",
 *     "customer": { "id": "...", "name": "...", "email": "...", "document": "..." },
 *     "pix": { "qrcode": "...", "end2EndId": "E18236120..." },
 *     "paidAt": "2025-12-02T04:55:26.942Z",
 *     "postbackUrl": "https://..."
 *   }
 * }
 * 
 * PIX IN — transaction.refunded:
 * {
 *   "id": "evt_xxx",
 *   "type": "transaction",
 *   "event": "transaction.refunded",
 *   "objectId": "uuid",
 *   "data": { "id": "uuid", "status": "refunded", "amount": 500, "refundedAmount": 500, ... }
 * }
 * 
 * PIX IN — transaction.infraction (MED/chargeback):
 * {
 *   "id": "evt_xxx",
 *   "type": "transaction",
 *   "event": "transaction.infraction",
 *   "objectId": "uuid",
 *   "data": { "id": "uuid", "status": "in_protest", "amount": 1100, ... }
 * }
 * 
 * PIX OUT — transfer.paid:
 * {
 *   "id": "evt_xxx",
 *   "type": "transfer",
 *   "event": "transfer.paid",
 *   "objectId": "uuid",
 *   "data": { "id": "uuid", "status": "paid", "amount": 10000, "paidAt": "...", "beneficiary": {...} }
 * }
 * 
 * PIX OUT — transfer.failed:
 * {
 *   "id": "evt_xxx",
 *   "type": "transfer",
 *   "event": "transfer.failed",
 *   "objectId": "uuid",
 *   "data": { "id": "uuid", "status": "failed", "failureReason": "...", "failureCode": "..." }
 * }
 * 
 * Signature: X-Webhook-Signature header with HMAC-SHA256 of body (if webhookSecret was set on transaction creation)
 */
class BluPayWebhookHandler
{
    private PaymentService $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    /**
     * Handle incoming BluPay webhook
     */
    public function handle(Request $request): JsonResponse
    {
        Log::info('BluPay webhook received', [
            'ip' => $request->ip(),
            'content_type' => $request->header('Content-Type'),
            'body_size' => strlen($request->getContent()),
        ]);

        try {
            $rawPayload = $request->getContent();
            $payload = json_decode($rawPayload, true);

            if (!$payload) {
                Log::warning('BluPay webhook: Invalid JSON payload');
                return response()->json(['status' => 'error', 'message' => 'Invalid payload'], 400);
            }

            // Verify webhook signature — fail-secure
            $signatureHeader = $request->header('X-Webhook-Signature')
                ?? $request->header('x-webhook-signature');

            $webhookSecret = config('acquirers.blupay.webhook_secret');

            if (!$webhookSecret) {
                Log::critical('BluPay webhook secret not configured — rejecting request');
                return response()->json(['status' => 'error', 'message' => 'Webhook secret not configured'], 401);
            }

            if (!$signatureHeader || !BluPayAcquirer::verifyWebhookSignature($rawPayload, $signatureHeader, $webhookSecret)) {
                Log::warning('BluPay webhook signature verification failed', [
                    'header' => substr($signatureHeader ?? '', 0, 20) . '...',
                ]);
                return response()->json(['status' => 'error', 'message' => 'Invalid signature'], 401);
            }
            Log::info('BluPay webhook signature verified');

            $eventType = $payload['event'] ?? null;
            $type = $payload['type'] ?? null;

            Log::info('BluPay webhook processing', [
                'event' => $eventType,
                'type' => $type,
                'id' => $payload['id'] ?? null,
                'objectId' => $payload['objectId'] ?? null,
            ]);

            // Route to appropriate handler based on type
            if ($type === 'transaction') {
                return $this->handleTransactionEvent($payload);
            } elseif ($type === 'transfer') {
                return $this->handleTransferEvent($payload);
            }

            Log::info('BluPay webhook: Unhandled type', ['type' => $type]);
            return response()->json(['status' => 'received', 'message' => 'Event type not handled']);

        } catch (\Exception $e) {
            Log::error('BluPay webhook error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Handle transaction events (PIX IN)
     * 
     * Events: transaction.paid, transaction.refunded, transaction.infraction
     */
    private function handleTransactionEvent(array $payload): JsonResponse
    {
        $event = $payload['event'] ?? null;
        $data = $payload['data'] ?? [];
        $transactionId = $data['id'] ?? $payload['objectId'] ?? null;
        $status = $data['status'] ?? null;
        $amount = $data['amount'] ?? null;
        $externalRef = $data['externalRef'] ?? null;

        Log::info('BluPay transaction event', [
            'event' => $event,
            'transaction_id' => $transactionId,
            'external_ref' => $externalRef,
            'status' => $status,
            'amount' => $amount,
        ]);

        // Find charge by externalRef (which is our charge ID) or by acquirer_transaction_id
        $charge = $this->findCharge($transactionId, $externalRef);

        if (!$charge) {
            Log::warning('BluPay webhook: Charge not found for transaction', [
                'transaction_id' => $transactionId,
                'external_ref' => $externalRef,
            ]);
            // Return 200 so BluPay doesn't retry
            return response()->json(['status' => 'received', 'message' => 'Charge not found for transaction']);
        }

        // Map BluPay status to internal webhook status
        $mappedStatus = $this->mapTransactionStatus($status, $event);

        // Build transaction data in format expected by PaymentService::handleWebhookPayment
        $transactionData = [
            'id' => $transactionId,
            'status' => $mappedStatus,
            'paymentMethod' => 'PIX',
            'amount' => $amount,
            'refundedAmount' => $data['refundedAmount'] ?? null,
            'metadata' => [
                'charge_id' => $charge->id,
            ],
        ];

        // Add PIX data if available
        if (isset($data['pix'])) {
            $transactionData['pix'] = $data['pix'];

            // Update end2end ID on the charge
            $end2EndId = $data['pix']['end2EndId'] ?? $data['pix']['endToEndId'] ?? null;
            if ($end2EndId) {
                $charge->update([
                    'pix_end2end_id' => $end2EndId,
                ]);
            }

            // Store payer info in metadata if available
            if (isset($data['pix']['payer'])) {
                $metadata = $charge->metadata ?? [];
                $metadata['pix_payer'] = $data['pix']['payer'];
                $charge->update(['metadata' => $metadata]);
            }
        }

        // Refresh charge from DB to avoid stale data
        $charge->refresh();

        // Use PaymentService to handle the webhook (this triggers UTMify, n8n, emails, etc.)
        $this->paymentService->handleWebhookPayment($charge, $transactionData);

        $freshCharge = $charge->fresh();

        Log::info('BluPay transaction webhook processed', [
            'charge_id' => $charge->id,
            'event' => $event,
            'previous_status' => $charge->status,
            'new_status' => $freshCharge->status,
        ]);

        return response()->json([
            'status' => 'success',
            'charge_id' => $charge->id,
            'charge_status' => $freshCharge->status,
        ]);
    }

    /**
     * Handle transfer events (PIX OUT)
     * 
     * Events: transfer.paid, transfer.failed
     */
    private function handleTransferEvent(array $payload): JsonResponse
    {
        $event = $payload['event'] ?? null;
        $data = $payload['data'] ?? [];
        $transferId = $data['id'] ?? $payload['objectId'] ?? null;
        $status = $data['status'] ?? null;
        $externalRef = $data['externalRef'] ?? null;

        Log::info('BluPay transfer event', [
            'event' => $event,
            'transfer_id' => $transferId,
            'external_ref' => $externalRef,
            'status' => $status,
            'amount' => $data['amount'] ?? null,
        ]);

        // ── Try to resolve as a Withdrawal (PIX OUT / saque) ────────────
        $withdrawal = Withdrawal::where('acquirer_transfer_id', $transferId)
            ->whereIn('status', ['processing', 'pending'])
            ->first();

        if (!$withdrawal && $externalRef) {
            // externalRef might be the withdrawal UUID
            $withdrawal = Withdrawal::where('id', $externalRef)
                ->whereIn('status', ['processing', 'pending'])
                ->first();
        }

        if ($withdrawal) {
            try {
                $withdrawalService = app(WithdrawalService::class);
                $handled = $withdrawalService->handleTransferCompleted(
                    $transferId,
                    $status ?? 'unknown',
                    $data
                );

                Log::info('BluPay transfer webhook processed for withdrawal', [
                    'withdrawal_id' => $withdrawal->id,
                    'transfer_id'   => $transferId,
                    'status'        => $status,
                    'handled'       => $handled,
                ]);

                return response()->json([
                    'status'          => 'success',
                    'type'            => 'withdrawal',
                    'withdrawal_id'   => $withdrawal->id,
                    'transfer_id'     => $transferId,
                    'transfer_status' => $status,
                ]);
            } catch (\Exception $e) {
                Log::error('BluPay transfer webhook withdrawal handling error', [
                    'withdrawal_id' => $withdrawal->id,
                    'error'         => $e->getMessage(),
                ]);
            }
        }

        // ── Fallback: try to find a charge linked to this transfer ────
        // Try to find a charge/withdrawal linked to this transfer
        $charge = $this->findCharge($transferId, $externalRef);

        if ($charge) {
            $mappedStatus = $this->mapTransferStatus($status);

            $updateData = ['status' => $mappedStatus];

            if ($mappedStatus === 'approved' || $mappedStatus === 'paid') {
                $updateData['paid_at'] = now();
            }

            if ($status === 'failed') {
                $updateData['failure_reason'] = $data['failureReason'] ?? 'Transfer failed';
            }

            $charge->update($updateData);

            // Create transaction record
            Transaction::create([
                'id' => Str::uuid()->toString(),
                'charge_id' => $charge->id,
                'type' => 'sale', // PIX OUT transfer
                'status' => $mappedStatus === 'paid' ? 'approved' : $mappedStatus,
                'amount' => $data['amount'] ?? 0,
                'provider' => 'blupay',
                'provider_transaction_id' => $transferId,
            ]);

            Log::info('BluPay transfer webhook processed for charge', [
                'charge_id' => $charge->id,
                'transfer_id' => $transferId,
                'status' => $mappedStatus,
            ]);
        }

        return response()->json([
            'status' => 'success',
            'transfer_id' => $transferId,
            'transfer_status' => $status,
        ]);
    }

    /**
     * Find charge by BluPay transaction ID or externalRef
     * 
     * BluPay sends our charge ID as externalRef (set during PIX creation).
     * Also checks acquirer_transaction_id and external_id fields.
     */
    private function findCharge(?string $transactionId, ?string $externalRef): ?Charge
    {
        // First try by externalRef (which is our charge UUID)
        if ($externalRef) {
            $charge = Charge::find($externalRef);
            if ($charge) {
                return $charge;
            }
        }

        // Then try by acquirer_transaction_id or external_id
        if ($transactionId) {
            $charge = Charge::where('acquirer_transaction_id', $transactionId)
                ->orWhere('external_id', $transactionId)
                ->first();
            if ($charge) {
                return $charge;
            }
        }

        return null;
    }

    /**
     * Map BluPay transaction status to internal webhook status
     * 
     * Maps to the status values expected by PaymentService::handleWebhookPayment():
     *   paid, refused/failed/declined, expired, canceled/cancelled, refunded, chargedback, waiting_payment
     */
    private function mapTransactionStatus(?string $status, ?string $event = null): string
    {
        // Use event type for more precise mapping
        if ($event === 'transaction.paid') {
            return 'paid';
        }
        if ($event === 'transaction.refunded') {
            return 'refunded';
        }
        if ($event === 'transaction.infraction') {
            return 'chargedback';
        }

        // Fallback to status-based mapping
        $mapped = match (strtolower($status ?? '')) {
            'paid', 'approved', 'completed', 'confirmed', 'settled' => 'paid',
            'pending', 'created', 'processing', 'waiting', 'waiting_payment' => 'waiting_payment',
            'canceled', 'cancelled' => 'canceled',
            'expired' => 'expired',
            'refunded', 'partially_refunded' => 'refunded',
            'in_protest', 'infraction', 'chargedback', 'chargeback' => 'chargedback',
            'refused', 'declined', 'failed', 'error' => 'failed',
            default => 'waiting_payment',
        };

        Log::info('BluPay mapTransactionStatus', [
            'original' => $status,
            'event' => $event,
            'mapped' => $mapped,
        ]);

        return $mapped;
    }

    /**
     * Map BluPay transfer status to internal status
     */
    private function mapTransferStatus(?string $status): string
    {
        return match (strtolower($status ?? '')) {
            'paid', 'completed' => 'paid',
            'processing', 'pending' => 'pending',
            'failed' => 'failed',
            'canceled', 'cancelled' => 'canceled',
            default => 'pending',
        };
    }
}
