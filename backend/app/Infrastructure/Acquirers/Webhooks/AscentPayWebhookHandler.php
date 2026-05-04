<?php

declare(strict_types=1);

namespace App\Infrastructure\Acquirers\Webhooks;

use App\Infrastructure\Acquirers\AscentPayAcquirer;
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
 * Webhook Handler for AscentPay
 * 
 * AscentPay sends postbacks (webhooks) to the configured postback_url.
 * 
 * Transaction postback:
 * {
 *   "type": "transaction",
 *   "objectId": "trx_xxx",
 *   "data": {
 *     "id": "trx_xxx",
 *     "status": "paid",
 *     "amount": 5000,
 *     "payment_method": "pix",
 *     "pix": { "qrcode": "...", "end2end_id": "E000..." },
 *     "metadata": { "charge_id": "..." },
 *     ...
 *   }
 * }
 * 
 * Withdrawal postback:
 * {
 *   "type": "withdrawal",
 *   "objectId": "wdr_xxx",
 *   "data": {
 *     "id": "wdr_xxx",
 *     "status": "completed",
 *     "amount": 10000,
 *     "pix_key": "...",
 *     ...
 *   }
 * }
 * 
 * Transaction statuses: waiting_payment, pending, approved, paid, refused, refunded, cancelled, chargeback
 * Withdrawal statuses: completed, processing, cancelled, refused, pending
 */
class AscentPayWebhookHandler
{
    private PaymentService $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    /**
     * Handle incoming AscentPay webhook
     */
    public function handle(Request $request): JsonResponse
    {
        Log::warning('=== ASCENTPAY WEBHOOK RECEIVED ===', [
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
                Log::warning('AscentPay webhook: Invalid JSON payload');
                return response()->json(['status' => 'error', 'message' => 'Invalid payload'], 400);
            }

            // Verify webhook signature — fail-secure
            $webhookSecret = config('acquirers.ascentpay.webhook_secret');
            $signatureHeader = $request->header('x-webhook-signature')
                ?? $request->header('X-Webhook-Signature')
                ?? $request->header('x-signature');

            if (!$webhookSecret) {
                Log::critical('AscentPay webhook secret not configured — rejecting request');
                return response()->json(['status' => 'error', 'message' => 'Webhook secret not configured'], 401);
            }

            if (!$signatureHeader || !AscentPayAcquirer::verifyWebhookSignature($rawPayload, $signatureHeader, $webhookSecret)) {
                Log::warning('AscentPay webhook signature verification failed', [
                    'header' => substr($signatureHeader ?? '', 0, 20) . '...',
                ]);
                return response()->json(['status' => 'error', 'message' => 'Invalid signature'], 401);
            }
            Log::info('AscentPay webhook signature verified');

            // Route by type
            $type = $payload['type'] ?? null;
            $objectId = $payload['objectId'] ?? $payload['object_id'] ?? null;
            $data = $payload['data'] ?? $payload;

            Log::info('AscentPay webhook parsed', [
                'type' => $type,
                'objectId' => $objectId,
                'status' => $data['status'] ?? 'unknown',
            ]);

            return match ($type) {
                'transaction' => $this->handleTransactionEvent($data, $objectId),
                'withdrawal', 'withdraw' => $this->handleWithdrawEvent($data, $objectId),
                default => $this->handleGenericEvent($payload, $objectId),
            };

        } catch (\Exception $e) {
            Log::error('AscentPay webhook exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Handle transaction event (PIX IN, credit card, boleto)
     */
    private function handleTransactionEvent(array $data, ?string $objectId): JsonResponse
    {
        $transactionId = $objectId ?? $data['id'] ?? null;
        $status = $data['status'] ?? 'unknown';

        Log::info('AscentPay transaction event', [
            'transactionId' => $transactionId,
            'status' => $status,
        ]);

        // Find the charge
        $charge = $this->findCharge($data, $transactionId);

        if (!$charge) {
            Log::warning('AscentPay webhook: Charge not found', [
                'transactionId' => $transactionId,
                'metadata' => $data['metadata'] ?? null,
            ]);
            return response()->json(['status' => 'ok', 'message' => 'Charge not found - ignored']);
        }

        // Map status
        $mappedStatus = $this->mapTransactionStatus($status);

        // Extract PIX payer info if available
        $pixData = $data['pix'] ?? [];
        $end2endId = $pixData['end2end_id'] ?? $pixData['end2EndId'] ?? $pixData['endToEndId'] ?? null;

        if ($end2endId) {
            try {
                $charge->update(['pix_end2end_id' => $end2endId]);
            } catch (\Exception $e) {
                Log::debug('Could not update end2end_id', ['error' => $e->getMessage()]);
            }
        }

        // Build transaction data for PaymentService
        $transactionData = [
            'id' => $transactionId,
            'status' => $mappedStatus,
            'amount' => $data['amount'] ?? null,
            'payment_method' => $data['payment_method'] ?? $data['paymentMethod'] ?? 'pix',
            'pix' => $pixData,
            'metadata' => $data['metadata'] ?? [],
        ];

        // Delegate to PaymentService
        $this->paymentService->handleWebhookPayment($charge, $transactionData);

        return response()->json([
            'status' => 'ok',
            'message' => 'Transaction webhook processed',
            'charge_id' => $charge->id,
        ]);
    }

    /**
     * Handle withdrawal event (PIX OUT)
     */
    private function handleWithdrawEvent(array $data, ?string $objectId): JsonResponse
    {
        $transferId = $objectId ?? $data['id'] ?? null;
        $status = $data['status'] ?? 'unknown';

        Log::info('AscentPay withdrawal event', [
            'transferId' => $transferId,
            'status' => $status,
        ]);

        // Try to find matching Withdrawal record (PIX OUT)
        $withdrawal = null;
        if ($transferId) {
            $withdrawal = Withdrawal::where('acquirer_transfer_id', $transferId)
                ->whereIn('status', ['processing', 'pending'])
                ->first();
        }

        if ($withdrawal) {
            try {
                $withdrawalService = app(WithdrawalService::class);
                $handled = $withdrawalService->handleTransferCompleted(
                    $transferId,
                    $status,
                    $data
                );

                Log::info('AscentPay withdraw webhook processed for withdrawal', [
                    'withdrawal_id' => $withdrawal->id,
                    'transfer_id' => $transferId,
                    'status' => $status,
                    'handled' => $handled,
                ]);

                return response()->json([
                    'status' => 'ok',
                    'message' => 'Withdrawal webhook processed',
                    'withdrawal_id' => $withdrawal->id,
                ]);
            } catch (\Exception $e) {
                Log::error('AscentPay withdrawal processing error', [
                    'error' => $e->getMessage(),
                    'withdrawal_id' => $withdrawal->id ?? null,
                ]);
            }
        }

        // Fallback: try to find charge by transaction ID
        if ($transferId) {
            $charge = Charge::where('acquirer_transaction_id', $transferId)
                ->orWhere('external_id', $transferId)
                ->first();

            if ($charge) {
                $mappedStatus = $this->mapTransactionStatus($status);
                $this->paymentService->handleWebhookPayment($charge, [
                    'id' => $transferId,
                    'status' => $mappedStatus,
                ]);

                return response()->json([
                    'status' => 'ok',
                    'message' => 'Withdrawal matched to charge',
                    'charge_id' => $charge->id,
                ]);
            }
        }

        Log::warning('AscentPay withdrawal webhook: no matching record found', [
            'transferId' => $transferId,
        ]);

        return response()->json(['status' => 'ok', 'message' => 'No matching record found - ignored']);
    }

    /**
     * Handle generic/unknown event type
     */
    private function handleGenericEvent(array $payload, ?string $objectId): JsonResponse
    {
        Log::info('AscentPay generic event', [
            'objectId' => $objectId,
            'keys' => array_keys($payload),
        ]);

        // Try to process as transaction
        $data = $payload['data'] ?? $payload;
        $transactionId = $objectId ?? $data['id'] ?? null;

        if ($transactionId) {
            $charge = $this->findCharge($data, $transactionId);
            if ($charge) {
                $status = $this->mapTransactionStatus($data['status'] ?? 'unknown');
                $this->paymentService->handleWebhookPayment($charge, [
                    'id' => $transactionId,
                    'status' => $status,
                ]);

                return response()->json([
                    'status' => 'ok',
                    'message' => 'Generic event processed as transaction',
                    'charge_id' => $charge->id,
                ]);
            }
        }

        return response()->json(['status' => 'ok', 'message' => 'Event received']);
    }

    /**
     * Find charge by various identifiers
     */
    private function findCharge(array $data, ?string $transactionId): ?Charge
    {
        // Try by acquirer_transaction_id / external_id
        if ($transactionId) {
            $charge = Charge::where('acquirer_transaction_id', $transactionId)
                ->orWhere('external_id', $transactionId)
                ->first();
            if ($charge) return $charge;
        }

        // Try by metadata.charge_id
        $chargeId = $data['metadata']['charge_id'] ?? null;
        if ($chargeId) {
            $charge = Charge::find($chargeId);
            if ($charge) return $charge;
        }

        // Try by externalRef in items
        $externalRef = $data['items'][0]['external_ref'] ?? $data['items'][0]['externalRef'] ?? null;
        if ($externalRef) {
            $charge = Charge::find($externalRef);
            if ($charge) return $charge;
        }

        return null;
    }

    /**
     * Map AscentPay transaction status to internal status
     */
    private function mapTransactionStatus(string $status): string
    {
        return match (strtolower($status)) {
            'paid', 'approved', 'authorized' => 'paid',
            'waiting_payment', 'pending' => 'waiting_payment',
            'processing', 'in_analysis' => 'pending',
            'refused', 'declined' => 'refused',
            'canceled', 'cancelled' => 'cancelled',
            'refunded' => 'refunded',
            'chargedback', 'chargeback' => 'chargedback',
            'failed', 'error' => 'failed',
            'expired' => 'expired',
            default => $status,
        };
    }

    /**
     * Map AscentPay withdrawal status to internal status
     */
    private function mapWithdrawStatus(string $status): string
    {
        return match (strtolower($status)) {
            'completed', 'paid', 'approved' => 'completed',
            'processing', 'pending', 'pending_analysis', 'pending_queue' => 'processing',
            'cancelled', 'canceled' => 'cancelled',
            'refused', 'declined' => 'refused',
            'failed', 'error' => 'failed',
            default => $status,
        };
    }
}
