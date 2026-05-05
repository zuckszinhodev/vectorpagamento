<?php

declare(strict_types=1);

namespace App\Infrastructure\Acquirers\Webhooks;

use App\Domain\Acquirer\Entities\Acquirer;
use App\Infrastructure\Acquirers\PayFortAcquirer;
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
 * Webhook Handler for PayFort Bank
 * 
 * Handles payment status updates from PayFort Bank API
 * 
 * Webhook payload structures:
 * 
 * PIX IN (Transaction):
 * {
 *   "id": "trx_xxx",
 *   "type": "transaction",
 *   "event": "transaction_paid",
 *   "scope": "postback",
 *   "transaction": {
 *     "id": "trx_xxx",
 *     "amount": 5000,
 *     "status": "paid",
 *     "pix": { "endToEndId": "E000..." }
 *   }
 * }
 * 
 * PIX OUT (Transfer):
 * {
 *   "id": "transfer_xxx",
 *   "type": "transfer",
 *   "event": "transfer_completed",
 *   "scope": "postback",
 *   "transfer": {
 *     "id": "transfer_xxx",
 *     "amount": 10000,
 *     "status": "completed",
 *     "pix": { "endToEndId": "E000..." }
 *   }
 * }
 * 
 * Signature header: payfort-Signature: t=timestamp,v1=hmac_hex
 * Signature = HMAC-SHA256(timestamp + "." + raw_body, webhook_secret)
 * 
 * Transaction events: transaction_created, transaction_paid, transaction_refunded, transaction_infraction
 * Transfer events: transfer_created, transfer_updated, transfer_completed, transfer_canceled
 */
class PayFortWebhookHandler
{
    private PaymentService $paymentService;
    private ?PayFortAcquirer $payfortAcquirer;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
        // Resolve acquirer lazily; may be null in test/dev if not configured
        try {
            $this->payfortAcquirer = \App\Services\AcquirerFactory::instance()->payfort();
        } catch (\Throwable $e) {
            Log::warning('PayFort webhook: failed to initialize acquirer', ['error' => $e->getMessage()]);
            $this->payfortAcquirer = null;
        }
    }

    /**
     * Handle incoming PayFort Bank webhook
     */
    public function handle(Request $request): JsonResponse
    {
        // CRITICAL: Log immediately on entry to detect if request reaches here at all
        Log::warning('=== PAYFORT WEBHOOK RECEIVED ===', [
            'ip' => $request->ip(),
            'method' => $request->method(),
            'url' => $request->fullUrl(),
            'content_type' => $request->header('Content-Type'),
            'body_size' => strlen($request->getContent()),
            'has_signature' => !empty(
                $request->header('payfort-signature')
                ?? $request->header('payfort-Signature')
                ?? $request->header('Payfort-Signature')
                ?? $request->header('cotapay-signature')
                ?? $request->header('CotaPay-Signature')
            ),
            'all_headers' => collect($request->headers->all())->map(fn($v) => $v[0] ?? $v)->toArray(),
            'body_preview' => substr($request->getContent(), 0, 300),
        ]);

        try {
            $rawPayload = $request->getContent();
            $payload = json_decode($rawPayload, true);

            if (!$payload) {
                Log::warning('PayFort webhook: Invalid JSON payload');
                return response()->json(['status' => 'error', 'message' => 'Invalid payload'], 400);
            }

            // Verify webhook signature
            $signatureHeader = $request->header('payfort-signature') 
                ?? $request->header('payfort-Signature')
                ?? $request->header('Payfort-Signature');

            if (empty($signatureHeader)) {
                // Some PayFort tenants still send alias header name.
                $signatureHeader = $request->header('cotapay-signature')
                    ?? $request->header('CotaPay-Signature');
            }

            $webhookSecret = config('acquirers.payfort.webhook_secret');

            // Verify signature only when the secret is configured.
            // When no secret is available, we fall back to "verify-on-receive":
            // the API is queried directly before any payment is processed (see handleTransactionEvent).
            if (!empty($webhookSecret)) {
                if (empty($signatureHeader)) {
                    Log::warning('PayFort webhook rejected: missing signature header', ['ip' => $request->ip()]);
                    return response()->json(['status' => 'error', 'message' => 'Missing signature'], 401);
                }

                if (!PayFortAcquirer::verifyWebhookSignature($rawPayload, $signatureHeader, $webhookSecret)) {
                    Log::warning('PayFort webhook signature verification failed', [
                        'header' => substr($signatureHeader, 0, 20) . '...',
                    ]);
                    return response()->json(['status' => 'error', 'message' => 'Invalid signature'], 401);
                }
                Log::info('PayFort webhook signature verified');
            } else {
                Log::info('PayFort webhook: no secret configured — will verify each transaction via API before processing');
            }

            $eventType = $payload['event'] ?? null;
            $type = $payload['type'] ?? null;

            Log::info('PayFort webhook processing', [
                'event' => $eventType,
                'type' => $type,
                'id' => $payload['id'] ?? null,
            ]);

            // Route to appropriate handler based on type
            if ($type === 'transaction') {
                return $this->handleTransactionEvent($payload);
            } elseif ($type === 'transfer') {
                return $this->handleTransferEvent($payload);
            }

            Log::info('PayFort webhook: Unhandled type', ['type' => $type]);
            return response()->json(['status' => 'received', 'message' => 'Event type not handled']);

        } catch (\Exception $e) {
            Log::error('PayFort webhook error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['status' => 'error', 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * Handle transaction events (PIX IN)
     */
    private function handleTransactionEvent(array $payload): JsonResponse
    {
        $event = strtolower((string) ($payload['event'] ?? ''));
        $transaction = $payload['transaction'] ?? [];
        $transactionId = $transaction['id'] ?? $payload['id'] ?? null;
        $status = strtolower((string) ($transaction['status'] ?? $this->mapTransactionEventToStatus($event)));
        $amount = $transaction['amount'] ?? null;

        Log::info('PayFort transaction event', [
            'event' => $event,
            'transaction_id' => $transactionId,
            'status' => $status,
            'amount' => $amount,
        ]);

        // ── Verify-on-receive when no webhook secret is configured ─────────────
        // The webhook payload is untrusted without a signature. Query the PayFort
        // API directly to get the authoritative status before processing any payment.
        $webhookSecret = config('acquirers.payfort.webhook_secret');

        if (empty($webhookSecret) && $this->mapTransactionStatus($status) === 'paid') {
            if (!$transactionId) {
                Log::warning('PayFort verify-on-receive skipped: no transaction_id in payload', ['payload' => $payload]);
                return response()->json(['status' => 'error', 'message' => 'Missing transaction id'], 400);
            }

            if (!$this->payfortAcquirer) {
                Log::error('PayFort verify-on-receive failed: acquirer not initialized — rejecting webhook to prevent false payment');
                return response()->json(['status' => 'error', 'message' => 'Acquirer not configured'], 500);
            }

            $apiResult = $this->payfortAcquirer->getTransaction($transactionId);

            if (!($apiResult['success'] ?? false)) {
                Log::error('PayFort verify-on-receive: API query failed — rejecting webhook', [
                    'transaction_id' => $transactionId,
                    'error' => $apiResult['error'] ?? 'unknown',
                ]);
                return response()->json(['status' => 'error', 'message' => 'Could not verify transaction'], 502);
            }

            $verifiedStatus = strtolower((string) ($apiResult['status'] ?? 'unknown'));
            $verifiedAmount = $apiResult['amount'] ?? null;

            Log::info('PayFort verify-on-receive result', [
                'transaction_id'  => $transactionId,
                'webhook_status'  => $status,
                'verified_status' => $verifiedStatus,
                'webhook_amount'  => $amount,
                'verified_amount' => $verifiedAmount,
            ]);

            // Reject if the API says the transaction is NOT paid
            if ($this->mapTransactionStatus($verifiedStatus) !== 'paid') {
                Log::warning('PayFort verify-on-receive: webhook claimed paid but API returned different status — ignoring', [
                    'transaction_id'  => $transactionId,
                    'webhook_status'  => $status,
                    'verified_status' => $verifiedStatus,
                ]);
                return response()->json(['status' => 'ignored', 'message' => 'Transaction not confirmed by API']);
            }

            // Use the API-verified amount (not the webhook payload amount) to prevent tampering
            $amount = $verifiedAmount ?? $amount;

            // Overwrite transaction data with API-verified values
            $transaction['status'] = $verifiedStatus;
            $status = $verifiedStatus;
            $transaction['amount'] = $amount;
            if (isset($apiResult['data']['pix'])) {
                $transaction['pix'] = $apiResult['data']['pix'];
            }
        }
        // ─────────────────────────────────────────────────────────────────────

        // Find charge by external/acquirer transaction ID
        $charge = $this->findChargeByExternalId($transactionId);

        if (!$charge) {
            Log::warning('PayFort webhook: Charge not found for transaction', [
                'transaction_id' => $transactionId,
            ]);
            // Return 200 so PayFort doesn't retry
            return response()->json(['status' => 'received', 'message' => 'Charge not found for transaction']);
        }

        // Build transaction data in format expected by PaymentService
        $transactionData = [
            'id' => $transactionId,
            'status' => $this->mapTransactionStatus($status),
            'paymentMethod' => 'PIX',
            'amount' => $amount,
            'metadata' => [
                'charge_id' => $charge->id,
            ],
        ];

        // Add PIX data if available
        if (isset($transaction['pix'])) {
            $transactionData['pix'] = $transaction['pix'];

            // Update end2end ID on the charge
            if (isset($transaction['pix']['endToEndId'])) {
                $charge->update([
                    'pix_end2end_id' => $transaction['pix']['endToEndId'],
                ]);
            }

            // Update payer info if available
            if (isset($transaction['pix']['payerInfo'])) {
                $metadata = $charge->metadata ?? [];
                $metadata['pix_payer'] = $transaction['pix']['payerInfo'];
                $charge->update(['metadata' => $metadata]);
            }
        }

        // Refresh charge from DB to avoid stale data (e.g. duplicate webhooks)
        $charge->refresh();

        // Use PaymentService to handle the webhook
        $this->paymentService->handleWebhookPayment($charge, $transactionData);

        $freshCharge = $charge->fresh();

        Log::info('PayFort transaction webhook processed', [
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
     */
    private function handleTransferEvent(array $payload): JsonResponse
    {
        $event = strtolower((string) ($payload['event'] ?? ''));
        $transfer = $payload['transfer'] ?? [];
        $transferId = $transfer['id'] ?? $payload['id'] ?? null;
        $status = strtolower((string) ($transfer['status'] ?? $this->mapTransferEventToStatus($event)));

        Log::info('PayFort transfer event', [
            'event' => $event,
            'transfer_id' => $transferId,
            'status' => $status,
            'amount' => $transfer['amount'] ?? null,
        ]);

        // ── Try to resolve as a Withdrawal (PIX OUT / saque) ──────────
        $withdrawal = Withdrawal::where('acquirer_transfer_id', $transferId)
            ->whereIn('status', ['processing', 'pending'])
            ->first();

        if ($withdrawal) {
            try {
                $withdrawalService = app(WithdrawalService::class);
                $handled = $withdrawalService->handleTransferCompleted(
                    $transferId,
                    $status,
                    $transfer
                );

                Log::info('PayFort transfer webhook processed for withdrawal', [
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
                Log::error('PayFort transfer webhook withdrawal handling error', [
                    'withdrawal_id' => $withdrawal->id,
                    'error'         => $e->getMessage(),
                ]);
            }
        }

        // ── Fallback: try to find a charge linked to this transfer ────
        $charge = $this->findChargeByExternalId($transferId);

        if ($charge) {
            $mappedStatus = $this->mapTransferStatus($status);
            
            $updateData = ['status' => $mappedStatus];
            
            if ($mappedStatus === 'approved' || $mappedStatus === 'paid') {
                $updateData['paid_at'] = now();
            }

            $charge->update($updateData);

            // Create transaction record
            Transaction::create([
                'id' => Str::uuid()->toString(),
                'charge_id' => $charge->id,
                'acquirer_id' => $this->getPayFortAcquirerId(),
                'type' => 'sale',
                'status' => $mappedStatus === 'paid' ? 'approved' : 'pending',
                'amount' => ($transfer['amount'] ?? 0) / 100,
                'net_amount' => ($transfer['amount'] ?? 0) / 100,
                'acquirer_tid' => $transferId,
            ]);

            Log::info('PayFort transfer webhook processed for charge', [
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
     * Find charge by PayFort external transaction ID
     */
    private function findChargeByExternalId(?string $externalId): ?Charge
    {
        if (!$externalId) {
            return null;
        }

        return Charge::where('acquirer_transaction_id', $externalId)
            ->orWhere('external_id', $externalId)
            ->first();
    }

    /**
     * Map PayFort transaction status to internal status
     */
    private function mapTransactionStatus(string $status): string
    {
        $mapped = match (strtolower($status)) {
            'paid', 'approved', 'completed', 'confirmed', 'settled' => 'paid',
            'pending', 'created', 'processing', 'waiting', 'waiting_payment' => 'waiting_payment',
            'canceled', 'cancelled' => 'canceled',
            'expired' => 'expired',
            'refunded', 'partially_refunded' => 'refunded',
            'infraction', 'chargedback', 'chargeback' => 'chargedback',
            'refused', 'declined', 'failed', 'error' => 'failed',
            default => 'waiting_payment',
        };

        Log::info('PayFort mapTransactionStatus', [
            'original' => $status,
            'mapped' => $mapped,
        ]);

        return $mapped;
    }

    /**
     * Map PayFort event name to fallback transaction status.
     */
    private function mapTransactionEventToStatus(string $event): string
    {
        return match (strtolower($event)) {
            'transaction_paid', 'transaction_approved' => 'paid',
            'transaction_refunded' => 'refunded',
            'transaction_refused', 'transaction_declined', 'transaction_failed' => 'failed',
            'transaction_canceled', 'transaction_cancelled' => 'canceled',
            'transaction_infraction', 'infraction', 'transaction_chargeback', 'transaction_chargedback' => 'chargedback',
            'transaction_expired' => 'expired',
            default => 'waiting_payment',
        };
    }

    /**
     * Get or create the PayFort acquirer ID for transaction records.
     */
    private function getPayFortAcquirerId(): string
    {
        static $id = null;
        if ($id) return $id;

        $acquirer = Acquirer::firstOrCreate(
            ['slug' => 'payfort'],
            [
                'id' => Str::uuid()->toString(),
                'name' => 'PayFort Bank',
                'slug' => 'payfort',
                'is_active' => true,
                'supported_methods' => json_encode(['pix']),
                'settings' => json_encode([]),
            ]
        );

        $id = $acquirer->id;
        return $id;
    }

    /**
     * Map PayFort transfer status to internal status
     */
    private function mapTransferStatus(string $status): string
    {
        return match (strtolower($status)) {
            'completed', 'paid', 'approved', 'settled' => 'paid',
            'queued', 'processing', 'pending', 'created', 'updated' => 'pending',
            'failed', 'refused', 'error' => 'failed',
            'canceled', 'cancelled' => 'canceled',
            default => 'pending',
        };
    }

    /**
     * Map PayFort event name to fallback transfer status.
     */
    private function mapTransferEventToStatus(string $event): string
    {
        return match (strtolower($event)) {
            'transfer_completed', 'transfer_paid', 'transfer_approved' => 'completed',
            'transfer_canceled', 'transfer_cancelled' => 'canceled',
            'transfer_failed', 'transfer_refused' => 'failed',
            default => 'processing',
        };
    }
}
