<?php

declare(strict_types=1);

namespace App\Infrastructure\Acquirers\Webhooks;

use App\Models\Charge;
use App\Models\Withdrawal;
use App\Services\PaymentService;
use App\Services\WithdrawalService;
use App\Infrastructure\Acquirers\WitetecAcquirer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Witetec Webhook Handler
 * 
 * Processes webhook events from Witetec API
 * 
 * Event Types:
 * - TRANSACTION_PENDING, TRANSACTION_PAID, TRANSACTION_FAILED
 * - TRANSACTION_REFUNDED, TRANSACTION_CHARGEDBACK, TRANSACTION_DISPUTE
 * - TRANSACTION_PROCESSING, TRANSACTION_AUTHORIZED, TRANSACTION_REFUSED, TRANSACTION_BLOCKED
 * - WITHDRAWAL_PENFUNDED: Transaction refunded (MED)
 * - TRANSACTION_CHARGEDBACK: Transaction chargedback (dispute)
 * - TRANSACTION_DISPUTE: Transaction in dispute
 * - TRANSACTION_WAITING_PAYMENT: Awaiting payment confirmation
 * - TRANSACTION_PROCESSING: Transaction being processed
 * - TRANSACTION_AUTHORIZED: Payment authorized (card)
 * - TRANSACTION_REFUSED: Payment refused
 * - TRANSACTION_BLOCKED: Transaction blocked
 * 
 * Withdrawal Events:
 * - WITHDRAWAL_PENDING: Withdrawal created, awaiting approval
 * - WITHDRAWAL_APPROVED: Withdrawal approved (manual)
 * - WITHDRAWAL_REJECTED: Withdrawal rejected
 * - WITHDRAWAL_PROCESSING: Withdrawal being processed
 * - WITHDRAWAL_PAID: Withdrawal completed successfully
 * - WITHDRAWAL_FAILED: Withdrawal failed
 * - WITHDRAWAL_REFUNDED: Withdrawal refunded
 * - WITHDRAWAL_CANCELED: Withdrawal cancelled
 * - WITHDRAWAL_BLOCKED: Withdrawal blocked
 */
class WitetecWebhookHandler
{
    private PaymentService $paymentService;
    private ?WithdrawalService $withdrawalService = null;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
        
        try {
            $this->withdrawalService = app(WithdrawalService::class);
        } catch (\Exception $e) {
            Log::warning('WithdrawalService not available in WitetecWebhookHandler', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Handle Witetec webhook
     */
    public function handle(Request $request): JsonResponse
    {
        Log::info('Witetec webhook received', [
            'ip' => $request->ip(),
            'content_type' => $request->header('Content-Type'),
            'body_size' => strlen($request->getContent()),
        ]);

        try {
            // Verify webhook signature if secret is configured
            $webhookSecret = config('acquirers.witetec.webhook_secret');
            
            if ($webhookSecret) {
                $signature = $request->header('X-Witetec-Signature') 
                    ?? $request->header('x-witetec-signature')
                    ?? $request->header('X-Webhook-Signature')
                    ?? $request->header('x-webhook-signature');

                if (!$signature) {
                    Log::warning('Witetec webhook missing signature', ['ip' => $request->ip()]);
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Missing signature'
                    ], 401);
                }

                if (!WitetecAcquirer::verifyWebhookSignature($request->getContent(), $signature, $webhookSecret)) {
                    Log::warning('Witetec webhook signature verification failed', [
                        'ip' => $request->ip(),
                    ]);
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Invalid signature'
                    ], 401);
                }
            }

            $payload = $request->all();
            $eventType = $payload['eventType'] ?? null;

            if (!$eventType) {
                Log::warning('Witetec webhook missing eventType', [
                    'payload_keys' => array_keys($payload),
                ]);
                return response()->json([
                    'status' => 'error',
                    'message' => 'Missing eventType'
                ], 400);
            }

            Log::info('Witetec webhook event', [
                'event_type' => $eventType,
                'transaction_id' => $payload['id'] ?? null,
            ]);

            // Route to appropriate handler based on event type
            if (str_starts_with($eventType, 'TRANSACTION_')) {
                return $this->handleTransactionEvent($payload);
            }

            if (str_starts_with($eventType, 'WITHDRAWAL_')) {
                return $this->handleWithdrawalEvent($payload);
            }

            Log::warning('Witetec webhook unknown event type', [
                'event_type' => $eventType,
            ]);

            return response()->json([
                'status' => 'received',
                'message' => 'Unknown event type'
            ]);

        } catch (\Exception $e) {
            Log::error('Witetec webhook error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Return 500 so Witetec retries the webhook
            return response()->json([
                'status' => 'error',
                'message' => 'Processing failed'
            ], 500);
        }
    }

    /**
     * Handle transaction events (TRANSACTION_*)
     */
    private function handleTransactionEvent(array $payload): JsonResponse
    {
        $eventType = $payload['eventType'];
        $transactionId = $payload['id'] ?? null;
        $status = $payload['status'] ?? null;
        $chargeId = $payload['metadata']['charge_id'] ?? null;

        Log::info('Witetec transaction event', [
            'event_type' => $eventType,
            'transaction_id' => $transactionId,
            'status' => $status,
            'charge_id' => $chargeId,
        ]);

        // Try to find charge by metadata first
        if (!$chargeId && $transactionId) {
            $charge = Charge::where('external_id', $transactionId)
                ->orWhere('acquirer_transaction_id', $transactionId)
                ->first();

            if ($charge) {
                $chargeId = $charge->id;
                Log::info('Charge found by transaction ID', [
                    'charge_id' => $chargeId,
                    'transaction_id' => $transactionId,
                ]);
            }
        }

        if (!$chargeId) {
            Log::warning('Witetec webhook: charge_id not found', [
                'event_type' => $eventType,
                'transaction_id' => $transactionId,
            ]);
            return response()->json([
                'status' => 'received',
                'message' => 'Charge ID not found'
            ]);
        }

        $charge = Charge::find($chargeId);
        if (!$charge) {
            Log::warning('Witetec webhook: charge not found in database', [
                'charge_id' => $chargeId,
            ]);
            return response()->json([
                'status' => 'error',
                'message' => 'Charge not found'
            ], 404);
        }

        // Idempotency check - skip if already in terminal state
        if ($this->isTerminalState($charge->status, $eventType)) {
            Log::info('Witetec webhook idempotency: charge already in terminal state', [
                'charge_id' => $chargeId,
                'current_status' => $charge->status,
                'event_type' => $eventType,
            ]);
            return response()->json([
                'status' => 'success',
                'message' => 'Already processed (idempotent)'
            ]);
        }

        // Map event type to internal status
        $mappedStatus = $this->mapEventTypeToStatus($eventType);

        // Update charge with additional data from webhook
        $this->updateChargeFromWebhook($charge, $payload);

        // Use PaymentService to handle the status update
        $webhookData = array_merge($payload, ['status' => $mappedStatus]);
        $this->paymentService->handleWebhookPayment($charge, $webhookData);

        $freshCharge = $charge->fresh();

        Log::info('Witetec transaction webhook processed', [
            'charge_id' => $chargeId,
            'event_type' => $eventType,
            'previous_status' => $charge->status,
            'new_status' => $freshCharge->status,
        ]);

        return response()->json([
            'status' => 'success',
            'charge_id' => $chargeId,
            'charge_status' => $freshCharge->status,
        ]);
    }

    /**
     * Handle withdrawal events (WITHDRAWAL_*)
     */
    private function handleWithdrawalEvent(array $payload): JsonResponse
    {
        if (!$this->withdrawalService) {
            Log::error('WithdrawalService not available');
            return response()->json([
                'status' => 'error',
                'message' => 'Withdrawal service not available'
            ], 500);
        }

        $eventType = $payload['eventType'];
        $withdrawalId = $payload['id'] ?? null;
        $status = $payload['status'] ?? null;
        $internalWithdrawalId = $payload['metadata']['withdrawal_id'] ?? null;

        Log::info('Witetec withdrawal event', [
            'event_type' => $eventType,
            'witetec_withdrawal_id' => $withdrawalId,
            'status' => $status,
            'internal_withdrawal_id' => $internalWithdrawalId,
        ]);

        // Find withdrawal by internal ID or acquirer transfer ID
        $withdrawal = null;
        if ($internalWithdrawalId) {
            $withdrawal = Withdrawal::find($internalWithdrawalId);
        }

        if (!$withdrawal && $withdrawalId) {
            $withdrawal = Withdrawal::where('acquirer_transfer_id', $withdrawalId)->first();
        }

        if (!$withdrawal) {
            Log::warning('Witetec webhook: withdrawal not found', [
                'event_type' => $eventType,
                'witetec_id' => $withdrawalId,
                'internal_id' => $internalWithdrawalId,
            ]);
            return response()->json([
                'status' => 'received',
                'message' => 'Withdrawal not found'
            ]);
        }

        // Map event type to acquirer status for WithdrawalService
        $acquirerStatus = $this->mapWithdrawalEventToAcquirerStatus($eventType);

        // Handle via WithdrawalService
        $this->withdrawalService->handleTransferCompleted(
            $withdrawal->acquirer_transfer_id ?? $withdrawalId,
            $acquirerStatus,
            $payload
        );

        $freshWithdrawal = $withdrawal->fresh();

        Log::info('Witetec withdrawal webhook processed', [
            'withdrawal_id' => $withdrawal->id,
            'event_type' => $eventType,
            'previous_status' => $withdrawal->status,
            'new_status' => $freshWithdrawal->status,
        ]);

        return response()->json([
            'status' => 'success',
            'withdrawal_id' => $withdrawal->id,
            'withdrawal_status' => $freshWithdrawal->status,
        ]);
    }

    /**
     * Check if charge is already in a terminal state for this event
     */
    private function isTerminalState(string $currentStatus, string $eventType): bool
    {
        // Prevent double-credit on duplicate PAID webhooks
        if ($eventType === 'TRANSACTION_PAID' && $currentStatus === 'approved') {
            return true;
        }

        // Prevent re-processing refunded charges
        if ($eventType === 'TRANSACTION_REFUNDED' && $currentStatus === 'refunded') {
            return true;
        }

        // Prevent re-processing chargebacks
        if (in_array($eventType, ['TRANSACTION_CHARGEDBACK', 'TRANSACTION_DISPUTE']) 
            && $currentStatus === 'chargeback') {
            return true;
        }

        return false;
    }

    /**
     * Map Witetec event type to internal status
     */
    private function mapEventTypeToStatus(string $eventType): string
    {
        return match ($eventType) {
            'TRANSACTION_PENDING' => 'pending',
            'TRANSACTION_PAID' => 'approved',
            'TRANSACTION_FAILED' => 'failed',
            'TRANSACTION_REFUNDED' => 'refunded',
            'TRANSACTION_WAITING_PAYMENT' => 'pending',
            'TRANSACTION_PROCESSING' => 'processing',
            'TRANSACTION_AUTHORIZED' => 'authorized',
            'TRANSACTION_REFUSED' => 'declined',
            'TRANSACTION_CHARGEDBACK', 'TRANSACTION_DISPUTE' => 'chargeback',
            'TRANSACTION_BLOCKED' => 'failed',
            default => 'pending',
        };
    }

    /**
     * Map Witetec withdrawal event to acquirer status for WithdrawalService
     */
    private function mapWithdrawalEventToAcquirerStatus(string $eventType): string
    {
        return match ($eventType) {
            'WITHDRAWAL_PENDING' => 'pending',
            'WITHDRAWAL_APPROVED' => 'processing',
            'WITHDRAWAL_REJECTED' => 'failed',
            'WITHDRAWAL_PROCESSING' => 'processing',
            'WITHDRAWAL_PAID' => 'completed',
            'WITHDRAWAL_FAILED' => 'failed',
            'WITHDRAWAL_REFUNDED' => 'cancelled',
            'WITHDRAWAL_CANCELED' => 'cancelled',
            'WITHDRAWAL_BLOCKED' => 'failed',
            default => 'processing',
        };
    }

    /**
     * Update charge with additional data from webhook
     */
    private function updateChargeFromWebhook(Charge $charge, array $payload): void
    {
        $updateData = [];

        // Update PIX end2end ID if available
        if (isset($payload['end2endId'])) {
            $updateData['pix_end2end_id'] = $payload['end2endId'];
        }

        // Update bank information if available (for PIX payments)
        if (isset($payload['toBank']) || isset($payload['toCNPJ'])) {
            $metadata = $charge->metadata ?? [];
            $metadata['bank_info'] = [
                'bank' => $payload['toBank'] ?? null,
                'branch' => $payload['toBankBranch'] ?? null,
                'account' => $payload['toBankAccount'] ?? null,
                'ispb' => $payload['ispb'] ?? null,
                'name' => $payload['toName'] ?? null,
                'cnpj' => $payload['toCNPJ'] ?? null,
            ];
            $updateData['metadata'] = $metadata;
        }

        // Update paid_at timestamp
        if (isset($payload['paidAt']) && !$charge->paid_at) {
            $updateData['paid_at'] = \Carbon\Carbon::parse($payload['paidAt']);
        }

        if (!empty($updateData)) {
            $charge->update($updateData);
        }
    }
}
