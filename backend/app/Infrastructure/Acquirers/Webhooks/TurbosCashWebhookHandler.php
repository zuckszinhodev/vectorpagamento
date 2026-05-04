<?php

declare(strict_types=1);

namespace App\Infrastructure\Acquirers\Webhooks;

use App\Infrastructure\Acquirers\TurbosCashAcquirer;
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
 * Webhook Handler for Turbos Cash
 * 
 * Turbos Cash sends postbacks (webhooks) with the following structure:
 * 
 * Transaction postback:
 * {
 *   "type": "transaction",
 *   "objectId": "trx_xxx",
 *   "url": "https://...",
 *   "data": {
 *     "id": "trx_xxx",
 *     "status": "paid",
 *     "amount": 5000,
 *     "paymentMethod": "pix",
 *     "pix": { "qrcode": "...", "endToEndId": "E000..." },
 *     "metadata": { "charge_id": "..." },
 *     ...
 *   }
 * }
 * 
 * Withdraw postback:
 * {
 *   "type": "withdraw",
 *   "objectId": "transfer_xxx",
 *   "url": "https://...",
 *   "data": {
 *     "id": "transfer_xxx",
 *     "status": "COMPLETED",
 *     "amount": 10000,
 *     "pixKey": "...",
 *     ...
 *   }
 * }
 * 
 * Transaction statuses: waiting_payment, pending, approved, refused, in_protest, refunded, paid, cancelled, chargeback
 * Transfer statuses: COMPLETED, PROCESSING, CANCELLED, REFUSED, PENDING_ANALYSIS, PENDING_QUEUE
 */
class TurbosCashWebhookHandler
{
    private PaymentService $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    /**
     * Handle incoming Turbos Cash webhook
     */
    public function handle(Request $request): JsonResponse
    {
        Log::warning('=== TURBOS CASH WEBHOOK RECEIVED ===', [
            'ip' => $request->ip(),
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'content_type' => $request->header('Content-Type'),
            'body_size' => strlen($request->getContent()),
            'body_preview' => substr($request->getContent(), 0, 500),
        ]);

        try {
            $rawPayload = $request->getContent();
            $payload = json_decode($rawPayload, true);

            if (!$payload) {
                Log::warning('TurbosCash webhook: Invalid JSON payload');
                return response()->json(['status' => 'error', 'message' => 'Invalid payload'], 400);
            }

            // Verify webhook signature — fail-secure
            $webhookSecret = config('acquirers.turbos.webhook_secret');
            $signatureHeader = $request->header('x-webhook-signature')
                ?? $request->header('X-Webhook-Signature')
                ?? $request->header('x-signature');

            if (!$webhookSecret) {
                Log::critical('TurbosCash webhook secret not configured — rejecting request');
                return response()->json(['status' => 'error', 'message' => 'Webhook secret not configured'], 401);
            }

            if (!$signatureHeader || !TurbosCashAcquirer::verifyWebhookSignature($rawPayload, $signatureHeader, $webhookSecret)) {
                Log::warning('TurbosCash webhook signature verification failed', [
                    'header' => substr($signatureHeader ?? '', 0, 20) . '...',
                ]);
                return response()->json(['status' => 'error', 'message' => 'Invalid signature'], 401);
            }
            Log::info('TurbosCash webhook signature verified');

            $type = $payload['type'] ?? null;
            $objectId = $payload['objectId'] ?? null;
            $data = $payload['data'] ?? $payload;

            Log::info('TurbosCash webhook processing', [
                'type' => $type,
                'object_id' => $objectId,
                'status' => $data['status'] ?? null,
            ]);

            // Route based on type
            if ($type === 'transaction') {
                return $this->handleTransactionEvent($data, $objectId);
            } elseif ($type === 'withdraw') {
                return $this->handleWithdrawEvent($data, $objectId);
            }

            // Unknown type — try to handle generically
            Log::info('TurbosCash webhook: unknown type, attempting generic handling', ['type' => $type]);

            // If it has transaction-like fields, treat as transaction
            if (isset($data['paymentMethod']) || isset($data['pix']) || isset($data['boleto'])) {
                return $this->handleTransactionEvent($data, $objectId ?? $data['id'] ?? null);
            }

            // If it has transfer-like fields, treat as withdraw
            if (isset($data['pixKey']) || isset($data['method'])) {
                return $this->handleWithdrawEvent($data, $objectId ?? $data['id'] ?? null);
            }

            return response()->json(['status' => 'received', 'message' => 'Event type not handled']);

        } catch (\Exception $e) {
            Log::error('TurbosCash webhook error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Handle transaction events (PIX IN / Card / Boleto)
     */
    private function handleTransactionEvent(array $data, ?string $objectId): JsonResponse
    {
        $transactionId = (string) ($data['id'] ?? $objectId);
        $status = $data['status'] ?? null;
        $amount = $data['amount'] ?? null;
        $paymentMethod = $data['paymentMethod'] ?? 'pix';

        Log::info('TurbosCash transaction event', [
            'transaction_id' => $transactionId,
            'status' => $status,
            'amount' => $amount,
            'payment_method' => $paymentMethod,
        ]);

        // Find charge by external ID or metadata
        $charge = $this->findCharge($transactionId, $data);

        if (!$charge) {
            Log::warning('TurbosCash webhook: Charge not found', [
                'transaction_id' => $transactionId,
                'metadata' => $data['metadata'] ?? null,
            ]);
            // Return 200 so Turbos doesn't retry indefinitely
            return response()->json(['status' => 'received', 'message' => 'Charge not found']);
        }

        // Map Turbos Cash status to internal status
        $mappedStatus = $this->mapTransactionStatus($status);

        // Build transaction data for PaymentService
        $transactionData = [
            'id' => $transactionId,
            'status' => $mappedStatus,
            'paymentMethod' => strtoupper($paymentMethod),
            'amount' => $amount,
            'metadata' => [
                'charge_id' => $charge->id,
            ],
        ];

        // Update PIX-specific data
        if (isset($data['pix'])) {
            $transactionData['pix'] = $data['pix'];

            if (isset($data['pix']['endToEndId'])) {
                $charge->update([
                    'pix_end2end_id' => $data['pix']['endToEndId'],
                ]);
            }

            if (isset($data['pix']['payerInfo'])) {
                $metadata = $charge->metadata ?? [];
                $metadata['pix_payer'] = $data['pix']['payerInfo'];
                $charge->update(['metadata' => $metadata]);
            }
        }

        // Refresh to avoid stale data
        $charge->refresh();

        // Avoid re-processing already completed charges
        if (in_array($charge->status, ['paid', 'refunded', 'chargedback']) && $mappedStatus === $charge->status) {
            Log::info('TurbosCash webhook: charge already in final status', [
                'charge_id' => $charge->id,
                'status' => $charge->status,
            ]);
            return response()->json([
                'status' => 'success',
                'message' => 'Already processed',
                'charge_id' => $charge->id,
            ]);
        }

        // Use PaymentService to handle the webhook
        $this->paymentService->handleWebhookPayment($charge, $transactionData);

        $freshCharge = $charge->fresh();

        Log::info('TurbosCash transaction webhook processed', [
            'charge_id' => $charge->id,
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
     * Handle withdraw events (PIX OUT)
     */
    private function handleWithdrawEvent(array $data, ?string $objectId): JsonResponse
    {
        $transferId = $data['id'] ?? $objectId;
        $status = $data['status'] ?? null;

        Log::info('TurbosCash withdraw event', [
            'transfer_id' => $transferId,
            'status' => $status,
            'amount' => $data['amount'] ?? null,
        ]);

        // Try to resolve as a Withdrawal (PIX OUT / saque)
        $withdrawal = Withdrawal::where('acquirer_transfer_id', $transferId)
            ->whereIn('status', ['processing', 'pending'])
            ->first();

        if ($withdrawal) {
            try {
                $withdrawalService = app(WithdrawalService::class);
                $handled = $withdrawalService->handleTransferCompleted(
                    $transferId,
                    $status,
                    $data
                );

                Log::info('TurbosCash withdraw webhook processed for withdrawal', [
                    'withdrawal_id' => $withdrawal->id,
                    'transfer_id' => $transferId,
                    'status' => $status,
                    'handled' => $handled,
                ]);

                return response()->json([
                    'status' => 'success',
                    'type' => 'withdrawal',
                    'withdrawal_id' => $withdrawal->id,
                    'transfer_id' => $transferId,
                    'transfer_status' => $status,
                ]);
            } catch (\Exception $e) {
                Log::error('TurbosCash withdraw webhook error', [
                    'withdrawal_id' => $withdrawal->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Fallback: try to find a charge linked to this transfer
        $charge = Charge::where('acquirer_transaction_id', $transferId)
            ->orWhere('external_id', $transferId)
            ->first();

        if ($charge) {
            $mappedStatus = $this->mapTransferStatus($status);
            $updateData = ['status' => $mappedStatus];

            if (in_array($mappedStatus, ['approved', 'paid'])) {
                $updateData['paid_at'] = now();
            }

            $charge->update($updateData);

            Transaction::create([
                'id' => Str::uuid()->toString(),
                'charge_id' => $charge->id,
                'type' => 'sale', // PIX OUT withdraw
                'status' => $mappedStatus === 'paid' ? 'approved' : $mappedStatus,
                'amount' => ($data['amount'] ?? 0) / 100,
                'provider' => 'turbos',
                'provider_transaction_id' => $transferId,
            ]);

            Log::info('TurbosCash withdraw webhook processed for charge', [
                'charge_id' => $charge->id,
                'transfer_id' => $transferId,
                'status' => $mappedStatus,
            ]);
        } else {
            Log::warning('TurbosCash webhook: no withdrawal or charge found for transfer', [
                'transfer_id' => $transferId,
            ]);
        }

        return response()->json([
            'status' => 'success',
            'transfer_id' => $transferId,
            'transfer_status' => $status,
        ]);
    }

    /**
     * Find charge by external transaction ID or metadata
     */
    private function findCharge(string|int|null $externalId, array $data): ?Charge
    {
        if (!$externalId && empty($data)) {
            return null;
        }

        $externalId = $externalId !== null ? (string) $externalId : null;

        // Try by acquirer_transaction_id or external_id
        if ($externalId) {
            $charge = Charge::where('acquirer_transaction_id', $externalId)
                ->orWhere('external_id', $externalId)
                ->first();

            if ($charge) {
                return $charge;
            }
        }

        // Try by metadata.charge_id
        $metadata = $data['metadata'] ?? null;
        if (is_string($metadata)) {
            $metadata = json_decode($metadata, true);
        }
        $chargeId = $metadata['charge_id'] ?? null;
        if ($chargeId) {
            $charge = Charge::find($chargeId);
            if ($charge) {
                return $charge;
            }
        }

        // Try by externalRef
        $externalRef = $data['externalRef'] ?? null;
        if ($externalRef) {
            return Charge::where('external_id', $externalRef)->first();
        }

        return null;
    }

    /**
     * Map Turbos Cash transaction status to internal status
     */
    private function mapTransactionStatus(string $status): string
    {
        return match (strtolower($status)) {
            'paid', 'approved' => 'paid',
            'waiting_payment', 'pending' => 'waiting_payment',
            'cancelled', 'canceled' => 'canceled',
            'refunded' => 'refunded',
            'refused' => 'failed',
            'in_protest', 'chargeback' => 'chargedback',
            default => 'waiting_payment',
        };
    }

    /**
     * Map Turbos Cash transfer status to internal status
     */
    private function mapTransferStatus(string $status): string
    {
        return match (strtoupper($status)) {
            'COMPLETED' => 'paid',
            'PROCESSING', 'PENDING_ANALYSIS', 'PENDING_QUEUE' => 'pending',
            'CANCELLED' => 'canceled',
            'REFUSED' => 'failed',
            default => 'pending',
        };
    }
}
