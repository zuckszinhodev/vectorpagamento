<?php

declare(strict_types=1);

namespace App\Infrastructure\Acquirers\Webhooks;

use App\Models\Charge;
use App\Services\PaymentService;
use App\Infrastructure\Acquirers\AsaasAcquirer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Asaas Webhook Handler
 *
 * Processes webhook events from Asaas API.
 *
 * Payment Events:
 *   PAYMENT_CREATED, PAYMENT_UPDATED, PAYMENT_CONFIRMED, PAYMENT_RECEIVED,
 *   PAYMENT_OVERDUE, PAYMENT_DELETED, PAYMENT_RESTORED, PAYMENT_REFUNDED,
 *   PAYMENT_PARTIALLY_REFUNDED, PAYMENT_REFUND_IN_PROGRESS, PAYMENT_REFUND_DENIED,
 *   PAYMENT_CHARGEBACK_REQUESTED, PAYMENT_CHARGEBACK_DISPUTE,
 *   PAYMENT_AWAITING_CHARGEBACK_REVERSAL, PAYMENT_DUNNING_REQUESTED,
 *   PAYMENT_DUNNING_RECEIVED, PAYMENT_ANTICIPATED,
 *   PAYMENT_AWAITING_RISK_ANALYSIS, PAYMENT_APPROVED_BY_RISK_ANALYSIS,
 *   PAYMENT_REPROVED_BY_RISK_ANALYSIS, PAYMENT_CREDIT_CARD_CAPTURE_REFUSED,
 *   PAYMENT_BANK_SLIP_VIEWED, PAYMENT_CHECKOUT_VIEWED,
 *   PAYMENT_SPLIT_CANCELLED, PAYMENT_SPLIT_DIVERGENCE_BLOCK,
 *   PAYMENT_SPLIT_DIVERGENCE_BLOCK_FINISHED
 *
 * Webhook payload format:
 * {
 *   "id": "evt_...",
 *   "event": "PAYMENT_RECEIVED",
 *   "dateCreated": "2024-06-12 16:45:03",
 *   "account": { "id": "uuid", "ownerId": null },
 *   "payment": { ... full payment object ... }
 * }
 */
class AsaasWebhookHandler
{
    private PaymentService $paymentService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;
    }

    /**
     * Handle Asaas webhook request.
     */
    public function handle(Request $request): JsonResponse
    {
        Log::info('Asaas webhook received', [
            'ip' => $request->ip(),
            'content_type' => $request->header('Content-Type'),
            'body_size' => strlen($request->getContent()),
        ]);

        try {
            // Verify webhook token — reject if token is not configured (prevents unauthenticated payloads)
            $webhookToken = config('acquirers.asaas.webhook_token');

            if (empty($webhookToken)) {
                Log::error('Asaas webhook rejected: webhook_token not configured in acquirers.asaas', [
                    'ip' => $request->ip(),
                ]);
                return response()->json(['status' => 'error', 'message' => 'Webhook not configured'], 500);
            }

            $receivedToken = $request->header('asaas-access-token')
                ?? $request->header('Asaas-Access-Token');

            if (empty($receivedToken)) {
                Log::warning('Asaas webhook missing access token', ['ip' => $request->ip()]);
                return response()->json([
                    'status' => 'error',
                    'message' => 'Missing access token',
                ], 401);
            }

            if (!AsaasAcquirer::verifyWebhookToken($receivedToken, $webhookToken)) {
                Log::warning('Asaas webhook token verification failed', ['ip' => $request->ip()]);
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid access token',
                ], 401);
            }

            $payload = $request->all();
            $event = $payload['event'] ?? null;

            if (!$event) {
                Log::warning('Asaas webhook missing event field', [
                    'payload_keys' => array_keys($payload),
                ]);
                return response()->json([
                    'status' => 'error',
                    'message' => 'Missing event field',
                ], 400);
            }

            $payment = $payload['payment'] ?? null;

            if (!$payment) {
                Log::warning('Asaas webhook missing payment object', [
                    'event' => $event,
                ]);
                return response()->json([
                    'status' => 'received',
                    'message' => 'No payment data',
                ]);
            }

            Log::info('Asaas webhook event', [
                'event' => $event,
                'payment_id' => $payment['id'] ?? null,
                'external_reference' => $payment['externalReference'] ?? null,
                'status' => $payment['status'] ?? null,
                'billing_type' => $payment['billingType'] ?? null,
            ]);

            return $this->handlePaymentEvent($event, $payment, $payload);
        } catch (\Exception $e) {
            Log::error('Asaas webhook error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            // Return 500 so Asaas retries the webhook
            return response()->json([
                'status' => 'error',
                'message' => 'Processing failed',
            ], 500);
        }
    }

    /**
     * Handle payment-related webhook event.
     */
    private function handlePaymentEvent(string $event, array $payment, array $fullPayload): JsonResponse
    {
        $asaasPaymentId = $payment['id'] ?? null;
        $externalReference = $payment['externalReference'] ?? null;

        // Find charge by externalReference (our charge ID) or by external_id (Asaas payment ID)
        $charge = null;

        if ($externalReference) {
            $charge = Charge::find($externalReference);
        }

        if (!$charge && $asaasPaymentId) {
            $charge = Charge::where('external_id', $asaasPaymentId)
                ->orWhere('acquirer_transaction_id', $asaasPaymentId)
                ->first();
        }

        if (!$charge) {
            Log::warning('Asaas webhook: charge not found', [
                'event' => $event,
                'asaas_payment_id' => $asaasPaymentId,
                'external_reference' => $externalReference,
            ]);
            return response()->json([
                'status' => 'received',
                'message' => 'Charge not found',
            ]);
        }

        // Informational events — just log
        if (in_array($event, [
            'PAYMENT_CREATED',
            'PAYMENT_UPDATED',
            'PAYMENT_BANK_SLIP_VIEWED',
            'PAYMENT_CHECKOUT_VIEWED',
            'PAYMENT_RESTORED',
            'PAYMENT_SPLIT_DIVERGENCE_BLOCK',
            'PAYMENT_SPLIT_DIVERGENCE_BLOCK_FINISHED',
        ])) {
            Log::info("Asaas informational event: {$event}", [
                'charge_id' => $charge->id,
            ]);
            return response()->json(['status' => 'received']);
        }

        // Idempotency check
        if ($this->isTerminalState($charge->status, $event)) {
            Log::info('Asaas webhook idempotency: charge already in terminal state', [
                'charge_id' => $charge->id,
                'current_status' => $charge->status,
                'event' => $event,
            ]);
            return response()->json([
                'status' => 'success',
                'message' => 'Already processed (idempotent)',
            ]);
        }

        // Update charge fields from webhook data
        $this->updateChargeFromWebhook($charge, $payment);

        // Map event to a webhook payload that PaymentService understands
        $webhookPayload = [
            'event' => $event,
            'payment' => $payment,
            'status' => $this->mapEventToInternalStatus($event),
            'acquirer' => 'asaas',
            'acquirer_transaction_id' => $asaasPaymentId,
        ];

        // Process based on event type
        switch ($event) {
            case 'PAYMENT_CONFIRMED':
            case 'PAYMENT_RECEIVED':
            case 'PAYMENT_APPROVED_BY_RISK_ANALYSIS':
            case 'PAYMENT_ANTICIPATED':
            case 'PAYMENT_DUNNING_RECEIVED':
                $this->paymentService->handleWebhookPayment($charge, $webhookPayload);
                break;

            case 'PAYMENT_OVERDUE':
                $charge->status = Charge::STATUS_EXPIRED;
                $charge->save();
                break;

            case 'PAYMENT_REFUNDED':
            case 'PAYMENT_PARTIALLY_REFUNDED':
                $charge->status = $event === 'PAYMENT_PARTIALLY_REFUNDED'
                    ? Charge::STATUS_PARTIALLY_REFUNDED
                    : Charge::STATUS_REFUNDED;
                $charge->refunded_at = now();
                $charge->save();
                break;

            case 'PAYMENT_REFUND_IN_PROGRESS':
                // Don't change status yet, refund is pending
                Log::info('Asaas refund in progress', ['charge_id' => $charge->id]);
                break;

            case 'PAYMENT_REFUND_DENIED':
                Log::warning('Asaas refund denied', [
                    'charge_id' => $charge->id,
                    'payment_id' => $asaasPaymentId,
                ]);
                break;

            case 'PAYMENT_CHARGEBACK_REQUESTED':
            case 'PAYMENT_CHARGEBACK_DISPUTE':
            case 'PAYMENT_AWAITING_CHARGEBACK_REVERSAL':
                $charge->status = Charge::STATUS_CHARGEBACK;
                $charge->save();
                break;

            case 'PAYMENT_REPROVED_BY_RISK_ANALYSIS':
            case 'PAYMENT_CREDIT_CARD_CAPTURE_REFUSED':
                $charge->status = Charge::STATUS_DECLINED;
                $charge->save();
                break;

            case 'PAYMENT_DELETED':
                $charge->status = Charge::STATUS_CANCELLED;
                $charge->save();
                break;

            case 'PAYMENT_AWAITING_RISK_ANALYSIS':
                $charge->status = Charge::STATUS_PROCESSING;
                $charge->save();
                break;

            case 'PAYMENT_SPLIT_CANCELLED':
                Log::warning('Asaas split cancelled', [
                    'charge_id' => $charge->id,
                    'split_data' => $payment['split'] ?? null,
                ]);
                break;

            default:
                Log::info("Asaas unhandled event: {$event}", ['charge_id' => $charge->id]);
                break;
        }

        $freshCharge = $charge->fresh();

        Log::info('Asaas webhook processed', [
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
     * Update charge fields from Asaas webhook payment data.
     */
    private function updateChargeFromWebhook(Charge $charge, array $payment): void
    {
        $updates = [];

        // Set external ID if not already set
        if (empty($charge->external_id) && !empty($payment['id'])) {
            $updates['external_id'] = $payment['id'];
        }

        // Set acquirer transaction ID
        if (!empty($payment['id'])) {
            $updates['acquirer_transaction_id'] = $payment['id'];
        }

        // Set acquirer
        if (empty($charge->acquirer)) {
            $updates['acquirer'] = 'asaas';
        }

        // PIX data
        if (($payment['billingType'] ?? '') === 'PIX') {
            if (!empty($payment['pixTransaction'])) {
                $updates['pix_end2end_id'] = $payment['pixTransaction'];
            }
        }

        if (!empty($updates)) {
            $charge->update($updates);
        }
    }

    /**
     * Map Asaas event to internal status.
     */
    private function mapEventToInternalStatus(string $event): string
    {
        return match ($event) {
            'PAYMENT_CONFIRMED' => 'authorized',
            'PAYMENT_RECEIVED', 'PAYMENT_ANTICIPATED',
            'PAYMENT_APPROVED_BY_RISK_ANALYSIS',
            'PAYMENT_DUNNING_RECEIVED' => 'approved',
            'PAYMENT_OVERDUE' => 'expired',
            'PAYMENT_REFUNDED' => 'refunded',
            'PAYMENT_PARTIALLY_REFUNDED' => 'partially_refunded',
            'PAYMENT_CHARGEBACK_REQUESTED',
            'PAYMENT_CHARGEBACK_DISPUTE',
            'PAYMENT_AWAITING_CHARGEBACK_REVERSAL' => 'chargeback',
            'PAYMENT_REPROVED_BY_RISK_ANALYSIS',
            'PAYMENT_CREDIT_CARD_CAPTURE_REFUSED' => 'declined',
            'PAYMENT_DELETED' => 'cancelled',
            'PAYMENT_AWAITING_RISK_ANALYSIS' => 'processing',
            default => 'pending',
        };
    }

    /**
     * Check if charge is already in a terminal state for this event.
     */
    private function isTerminalState(string $currentStatus, string $event): bool
    {
        // Prevent double-credit on duplicate RECEIVED/CONFIRMED webhooks
        if (in_array($event, ['PAYMENT_RECEIVED', 'PAYMENT_CONFIRMED', 'PAYMENT_ANTICIPATED'])
            && $currentStatus === Charge::STATUS_APPROVED) {
            return true;
        }

        // Prevent re-processing refunded charges (but allow partial refund on approved)
        if ($event === 'PAYMENT_REFUNDED' && $currentStatus === Charge::STATUS_REFUNDED) {
            return true;
        }

        // Prevent re-processing chargebacks
        if (in_array($event, ['PAYMENT_CHARGEBACK_REQUESTED', 'PAYMENT_CHARGEBACK_DISPUTE'])
            && $currentStatus === Charge::STATUS_CHARGEBACK) {
            return true;
        }

        return false;
    }
}
