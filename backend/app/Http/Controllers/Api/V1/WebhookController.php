<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Infrastructure\Acquirers\Webhooks\BluPayWebhookHandler;
use App\Infrastructure\Acquirers\Webhooks\EnkiWebhookHandler;
use App\Infrastructure\Acquirers\Webhooks\PayFortWebhookHandler;
use App\Infrastructure\Acquirers\Webhooks\TurbosCashWebhookHandler;
use App\Infrastructure\Acquirers\Webhooks\WitetecWebhookHandler;
use App\Infrastructure\Acquirers\Webhooks\AsaasWebhookHandler;
use App\Models\AcquirerConfig;
use App\Models\Charge;
use App\Models\Transaction;
use App\Domain\Acquirer\Entities\Acquirer;
use App\Services\MemberEnrollmentService;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class WebhookController extends Controller
{
    private PaymentService $paymentService;
    private MemberEnrollmentService $memberEnrollmentService;

    public function __construct(PaymentService $paymentService, MemberEnrollmentService $memberEnrollmentService)
    {
        $this->paymentService = $paymentService;
        $this->memberEnrollmentService = $memberEnrollmentService;
    }

    // =========================================================================
    // WEBHOOK UNIFICADO - Endpoint centralizado para TODAS as adquirentes
    // POST /api/v1/webhooks
    // =========================================================================

    /**
     * Handle unified webhook for all acquirers.
     *
     * Auto-detects the acquirer from headers / payload and routes to the
     * correct internal handler.  This is the **recommended** single endpoint
     * that should be configured in every acquirer dashboard.
     *
     * Detection order:
     *  1. payfort-Signature header          → PayFort Bank
     *  2. X-Webhook-Signature + blupay body → BluPay
     *  3. X-Babylon-Signature header        → Babylon
     *  4. stripe-signature header           → Stripe
     *  5. payload.type == 'transaction'     → Stripe-like (Stripe / PagarMe)
     *  6. payload.topic                     → MercadoPago
     *  7. Fallback: generic handler
     */
    public function unified(Request $request): JsonResponse
    {
        try {
            $acquirer = $this->detectAcquirer($request);
        } catch (\Throwable $e) {
            Log::error('WebhookController::unified detectAcquirer failed', ['error' => $e->getMessage()]);
            return response()->json(['status' => 'error', 'message' => 'Failed to detect acquirer'], 500);
        }

        Log::info('Unified webhook received', [
            'detected_acquirer' => $acquirer,
            'ip'                => $request->ip(),
            'content_type'      => $request->header('Content-Type'),
            'body_size'         => strlen($request->getContent()),
        ]);

        return match ($acquirer) {
            'turbos'      => $this->turbos($request),
            'payfort'     => $this->payfort($request),
            'blupay'      => $this->blupay($request),
            'enki'        => $this->enki($request),
            'ascentpay'   => $this->ascentpay($request),
            'babylon'     => $this->babylon($request),
            'witetec'     => $this->witetec($request),
            'asaas'       => $this->asaas($request),
            'huntersub'   => $this->huntersub($request),
            'sharkhubsub' => $this->sharkhubsub($request),
            'stripe'      => $this->stripe($request),
            'pagarme'     => $this->pagarme($request),
            'mercadopago' => $this->mercadopago($request),
            default       => $this->handleGenericWebhook($request),
        };
    }

    /**
     * Detect which acquirer sent the webhook based on headers and payload.
     */
    private function detectAcquirer(Request $request): string
    {
        // Turbos Cash — postback format with type=transaction|withdraw and objectId
        $payload = $request->all();
        if (isset($payload['objectId']) && isset($payload['type']) && in_array($payload['type'], ['transaction', 'withdraw'])) {
            return 'turbos';
        }

        // Asaas – detect by asaas-access-token header or Asaas payload shape
        if ($request->hasHeader('asaas-access-token') || $request->hasHeader('Asaas-Access-Token')) {
            return 'asaas';
        }
        // Asaas payload: has 'event' starting with PAYMENT_ and 'payment.object' == 'payment'
        if (isset($payload['event']) && str_starts_with($payload['event'], 'PAYMENT_') && isset($payload['payment']['object']) && $payload['payment']['object'] === 'payment') {
            return 'asaas';
        }

        // HunterSub – detect by event type pattern (transaction.*)
        if (isset($payload['type']) && str_starts_with($payload['type'], 'transaction.') && isset($payload['data']['id'])) {
            // HunterSub events: transaction.paid, transaction.pending, transaction.failed, etc
            return 'huntersub';
        }
        if (($payload['type'] ?? null) === 'transaction' && isset($payload['data']['id'], $payload['data']['status'])) {
            return 'huntersub';
        }

        // SharkHub Subadquirente sends top-level payment/transfer payloads.
        if (isset($payload['id'], $payload['status'], $payload['method']) && strtoupper((string) $payload['method']) === 'PIX') {
            $reference = $payload['externalRef'] ?? null;
            if ($reference) {
                $charge = Charge::where('id', $reference)
                    ->orWhere('external_id', $payload['id'])
                    ->orWhere('acquirer_transaction_id', $payload['id'])
                    ->first();

                if ($charge && $charge->acquirer === 'sharkhubsub') {
                    return 'sharkhubsub';
                }
            }
        }

        // Enki – top-level event with transaction/withdrawal object
        if (isset($payload['event'])
            && is_string($payload['event'])
            && (str_starts_with($payload['event'], 'transaction.') || str_starts_with($payload['event'], 'withdrawal.'))
            && (isset($payload['transaction']) || isset($payload['withdrawal']))) {
            return 'enki';
        }

        // Witetec – unique header and event type
        if ($request->hasHeader('X-Witetec-Signature') || $request->hasHeader('x-witetec-signature')) {
            return 'witetec';
        }
        // Witetec also can be detected by eventType field
        if (isset($payload['eventType']) && (str_starts_with($payload['eventType'], 'TRANSACTION_') || str_starts_with($payload['eventType'], 'WITHDRAWAL_'))) {
            return 'witetec';
        }

        // PayFort – unique header
        if ($request->hasHeader('payfort-signature') || $request->hasHeader('payfort-Signature') || $request->hasHeader('Payfort-Signature')) {
            return 'payfort';
        }

        // BluPay – X-Webhook-Signature + specific payload keys
        if ($request->hasHeader('X-Webhook-Signature') || $request->hasHeader('x-webhook-signature')) {
            $payload = $request->all();
            // BluPay has 'transaction' or 'transfer' with nested pix data
            if (isset($payload['type']) && in_array($payload['type'], ['transaction', 'transfer'])) {
                // Distinguish from PayFort by absence of payfort-signature
                // Check if it looks like BluPay payload (has scope=postback or specific keys)
                if (isset($payload['scope']) || isset($payload['transaction']['pix']) || isset($payload['transfer']['pix'])) {
                    // Could be either PayFort or BluPay. PayFort already matched above by header.
                    return 'blupay';
                }
            }
            // Babylon also uses X-Webhook-Signature sometimes
            if ($request->hasHeader('X-Babylon-Signature') || $request->hasHeader('x-babylon-signature')) {
                return 'babylon';
            }
            return 'blupay';
        }

        // Babylon – unique header
        if ($request->hasHeader('X-Babylon-Signature') || $request->hasHeader('x-babylon-signature')) {
            return 'babylon';
        }

        // AscentPay – detect by X-AscentPay-Signature header or payload pattern
        if ($request->hasHeader('X-AscentPay-Signature') || $request->hasHeader('x-ascentpay-signature')) {
            return 'ascentpay';
        }

        // Stripe – unique header
        if ($request->hasHeader('stripe-signature') || $request->hasHeader('Stripe-Signature')) {
            return 'stripe';
        }

        // Attempt payload-based detection
        $payload = $request->all();

        // MercadoPago – has 'topic' field
        if (isset($payload['topic'])) {
            return 'mercadopago';
        }

        // Stripe / PagarMe – has 'type' with dot-separated event name
        if (isset($payload['type']) && str_contains($payload['type'], '.')) {
            $type = $payload['type'];
            if (str_starts_with($type, 'payment_intent') || str_starts_with($type, 'charge.') || str_starts_with($type, 'checkout.')) {
                return 'stripe';
            }
            if (str_starts_with($type, 'transaction_')) {
                return 'pagarme';
            }
        }

        // Babylon – various payload shapes
        if (isset($payload['data']['metadata']['charge_id']) || isset($payload['transaction']['metadata']['charge_id'])) {
            return 'babylon';
        }

        // Fallback: try to detect by looking at the charge's acquirer
        $chargeId = $payload['charge_id'] ?? $payload['reference_id'] ?? $payload['metadata']['charge_id'] ?? null;
        if ($chargeId) {
            $charge = Charge::find($chargeId);
            if ($charge && $charge->acquirer) {
                return strtolower($charge->acquirer);
            }
        }

        return 'generic';
    }

    /**
     * Handle generic/unknown acquirer webhook with best-effort processing.
     * SECURITY: Only processes webhooks where the charge can be correlated
     * with a known acquirer that has already verified the webhook signature.
     */
    private function handleGenericWebhook(Request $request): JsonResponse
    {
        Log::warning('Generic webhook handler invoked — no acquirer-specific signature verified', [
            'ip' => $request->ip(),
            'payload_keys' => array_keys($request->all()),
        ]);

        // SECURITY: Reject generic webhooks that cannot be verified.
        // All production webhooks MUST come through an acquirer-specific handler
        // with proper HMAC signature verification.
        return response()->json([
            'status' => 'rejected',
            'message' => 'Unverified webhook source. Configure the acquirer-specific endpoint.',
        ], 401);
    }

    // =========================================================================
    // HANDLERS POR ADQUIRENTE (mantidos para compatibilidade e roteamento)
    // =========================================================================

    /**
     * Handle Stripe webhooks
     * Verifies stripe-signature header via HMAC-SHA256
     */
    public function stripe(Request $request): JsonResponse
    {
        Log::info('Stripe webhook received', [
            'ip' => $request->ip(),
            'event_type' => $request->input('type'),
        ]);

        // Verify Stripe webhook signature
        $stripeSecret = config('services.stripe.webhook_secret');
        $signature = $request->header('stripe-signature') ?? $request->header('Stripe-Signature');

        if ($stripeSecret) {
            if (!$signature) {
                Log::warning('Stripe webhook missing signature', ['ip' => $request->ip()]);
                return response()->json(['status' => 'error', 'message' => 'Missing signature'], 401);
            }

            // Parse Stripe signature format: t=timestamp,v1=hash
            $parts = collect(explode(',', $signature))->mapWithKeys(function ($part) {
                [$key, $value] = explode('=', $part, 2) + [null, null];
                return [$key => $value];
            });

            $timestamp = $parts->get('t');
            $expectedHash = $parts->get('v1');
            $signedPayload = "{$timestamp}.{$request->getContent()}";
            $computedHash = hash_hmac('sha256', $signedPayload, $stripeSecret);

            if (!$expectedHash || !hash_equals($computedHash, $expectedHash)) {
                Log::warning('Stripe webhook signature mismatch', ['ip' => $request->ip()]);
                return response()->json(['status' => 'error', 'message' => 'Invalid signature'], 401);
            }

            // Reject stale webhooks (> 5 minutes)
            if ($timestamp && abs(time() - (int) $timestamp) > 300) {
                Log::warning('Stripe webhook timestamp too old', ['ip' => $request->ip()]);
                return response()->json(['status' => 'error', 'message' => 'Stale webhook'], 401);
            }
        } else {
            Log::error('Stripe webhook secret not configured — rejecting for security', ['ip' => $request->ip()]);
            return response()->json(['status' => 'error', 'message' => 'Webhook verification not configured'], 500);
        }

        try {
            $payload = $request->all();
            $eventType = $payload['type'] ?? null;

            switch ($eventType) {
                case 'payment_intent.succeeded':
                    $this->handlePaymentSuccess($payload['data']['object'] ?? []);
                    break;
                case 'payment_intent.payment_failed':
                    $this->handlePaymentFailed($payload['data']['object'] ?? []);
                    break;
                case 'charge.refunded':
                    $this->handleRefund($payload['data']['object'] ?? []);
                    break;
            }

            return response()->json(['status' => 'success']);
        } catch (\Exception $e) {
            Log::error('Stripe webhook error', ['error' => $e->getMessage()]);
            // Return 200 to prevent acquirer retries (idempotency protects us)
            return response()->json(['status' => 'error', 'message' => 'Processing failed'], 200);
        }
    }

    /**
     * Handle Pagar.me webhooks
     * Verifies X-Hub-Signature header via HMAC-SHA1
     */
    public function pagarme(Request $request): JsonResponse
    {
        Log::info('Pagar.me webhook received', [
            'ip' => $request->ip(),
            'event_type' => $request->input('type'),
        ]);

        // Verify PagarMe webhook signature
        $pagarmeSecret = config('services.pagarme.webhook_secret');
        $signature = $request->header('X-Hub-Signature') ?? $request->header('x-hub-signature');

        if ($pagarmeSecret) {
            if (!$signature) {
                Log::warning('PagarMe webhook missing signature', ['ip' => $request->ip()]);
                return response()->json(['status' => 'error', 'message' => 'Missing signature'], 401);
            }

            $expectedSignature = 'sha1=' . hash_hmac('sha1', $request->getContent(), $pagarmeSecret);
            if (!hash_equals($expectedSignature, $signature)) {
                Log::warning('PagarMe webhook signature mismatch', ['ip' => $request->ip()]);
                return response()->json(['status' => 'error', 'message' => 'Invalid signature'], 401);
            }
        } else {
            Log::error('PagarMe webhook secret not configured — rejecting for security', ['ip' => $request->ip()]);
            return response()->json(['status' => 'error', 'message' => 'Webhook verification not configured'], 500);
        }

        try {
            $payload = $request->all();
            $eventType = $payload['type'] ?? null;

            switch ($eventType) {
                case 'transaction_paid':
                    $chargeId = $payload['data']['metadata']['charge_id'] ?? null;
                    if ($chargeId) {
                        $this->updateChargeStatus($chargeId, 'paid');
                    }
                    break;
                case 'transaction_refused':
                    $chargeId = $payload['data']['metadata']['charge_id'] ?? null;
                    if ($chargeId) {
                        $this->updateChargeStatus($chargeId, 'failed');
                    }
                    break;
            }

            return response()->json(['status' => 'success']);
        } catch (\Exception $e) {
            Log::error('Pagar.me webhook error', ['error' => $e->getMessage()]);
            return response()->json(['status' => 'error', 'message' => 'Processing failed'], 200);
        }
    }

    /**
     * Handle MercadoPago webhooks
     */
    public function mercadopago(Request $request): JsonResponse
    {
        Log::info('MercadoPago webhook received', [
            'ip' => $request->ip(),
            'topic' => $request->input('topic'),
        ]);

        try {
            $topic = $request->input('topic');
            $id = $request->input('id');

            if ($topic === 'payment') {
                // In production, would fetch payment details from MercadoPago API
                Log::info('MercadoPago payment notification', ['payment_id' => $id]);
            }

            return response()->json(['status' => 'success']);
        } catch (\Exception $e) {
            Log::error('MercadoPago webhook error', ['error' => $e->getMessage()]);
            return response()->json(['status' => 'error', 'message' => 'Processing failed'], 200);
        }
    }

    /**
     * Handle Banco Babylon webhooks
     * 
     * Babylon webhook statuses:
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
    public function babylon(Request $request): JsonResponse
    {
        Log::info('Babylon webhook received', [
            'ip' => $request->ip(),
            'content_type' => $request->header('Content-Type'),
            'body_size' => strlen($request->getContent()),
        ]);

        try {
            $payload = $request->all();
            
            // Babylon may send data in different formats:
            // Format 1: { data: { id, status, metadata: { charge_id } } }
            // Format 2: { id, status, metadata: { charge_id } }
            // Format 3: { event: "transaction.paid", data: { ... } }
            // Format 4: { transaction: { id, status, ... }, metadata: { ... } }
            $transactionData = $payload['data'] ?? $payload['transaction'] ?? $payload;
            $status = $transactionData['status'] ?? $payload['status'] ?? null;
            
            // Try multiple paths to find charge_id
            $chargeId = $transactionData['metadata']['charge_id'] 
                ?? $payload['metadata']['charge_id'] 
                ?? $transactionData['charge_id']
                ?? $payload['charge_id']
                ?? $payload['reference_id']
                ?? null;

            // Extract transaction ID from Babylon
            $babylonTransactionId = $transactionData['id'] 
                ?? $transactionData['transaction_id'] 
                ?? $payload['id'] 
                ?? null;

            Log::info('Babylon webhook processing', [
                'transaction_id' => $babylonTransactionId,
                'status' => $status,
                'charge_id' => $chargeId,
                'payment_method' => $transactionData['paymentMethod'] ?? $transactionData['payment_method'] ?? null,
                'event' => $payload['event'] ?? null,
            ]);

            // Verify webhook signature (MANDATORY - reject if no secret configured)
            $signature = $request->header('X-Babylon-Signature') 
                ?? $request->header('X-Webhook-Signature')
                ?? $request->header('x-babylon-signature');
            $webhookSecret = config('acquirers.babylon.webhook_secret');
            
            if (!$webhookSecret) {
                Log::error('Babylon webhook secret not configured - rejecting webhook for security');
                return response()->json(['status' => 'error', 'message' => 'Webhook secret not configured'], 500);
            }

            if (!$signature) {
                Log::warning('Babylon webhook missing signature', ['ip' => $request->ip()]);
                return response()->json(['status' => 'error', 'message' => 'Missing signature'], 401);
            }

            $expectedSignature = hash_hmac('sha256', $request->getContent(), $webhookSecret);
            
            if (!hash_equals($expectedSignature, $signature)) {
                Log::warning('Babylon webhook signature verification failed', [
                    'expected' => substr($expectedSignature, 0, 10) . '...',
                    'received' => substr($signature, 0, 10) . '...',
                ]);
                return response()->json(['status' => 'error', 'message' => 'Invalid signature'], 401);
            }

            // If no charge_id in metadata, try to find by acquirer_transaction_id
            if (!$chargeId && $babylonTransactionId) {
                $charge = Charge::where('acquirer_transaction_id', $babylonTransactionId)
                    ->orWhere('external_id', $babylonTransactionId)
                    ->first();
                
                if ($charge) {
                    $chargeId = $charge->id;
                    Log::info('Charge found by acquirer_transaction_id', [
                        'charge_id' => $chargeId,
                        'acquirer_tid' => $babylonTransactionId,
                    ]);
                }
            }

            if (!$chargeId) {
                Log::warning('Babylon webhook: No charge_id found', [
                    'transaction_id' => $babylonTransactionId,
                    'payload_keys' => array_keys($payload),
                ]);
                // Return 200 so Babylon doesn't retry
                return response()->json(['status' => 'received', 'message' => 'No charge_id found']);
            }

            $charge = $charge ?? Charge::find($chargeId);
            if (!$charge) {
                Log::warning('Babylon webhook: Charge not found', ['charge_id' => $chargeId]);
                return response()->json(['status' => 'error', 'message' => 'Charge not found'], 404);
            }

            // Use PaymentService to handle the webhook
            $this->paymentService->handleWebhookPayment($charge, $transactionData);

            $freshCharge = $charge->fresh();

            Log::info('Babylon webhook processed successfully', [
                'charge_id' => $chargeId,
                'previous_status' => $charge->status,
                'new_status' => $freshCharge->status,
                'payment_method' => $freshCharge->payment_method,
            ]);

            return response()->json([
                'status' => 'success',
                'charge_id' => $chargeId,
                'charge_status' => $freshCharge->status,
            ]);
        } catch (\Exception $e) {
            Log::error('Babylon webhook error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            // Return 200 to acknowledge receipt — prevents retries that could double-credit
            return response()->json(['status' => 'error', 'message' => 'Processing failed'], 200);
        }
    }

    /**
     * Handle Babylon withdrawal webhooks
     * 
     * Events:
     * - withdrawal.created: Saque criado
     * - withdrawal.status_changed: Status alterado
     * - withdrawal.completed: Saque concluído
     * - withdrawal.failed: Saque falhou
     * 
     * Payload format:
     * {
     *   "event": "withdrawal.completed",
     *   "timestamp": "2025-07-10T18:15:45.456Z",
     *   "withdrawal": {
     *     "id": "756d4eec-9a22-44b0-a514-a27c366c5433",
     *     "status": "done",
     *     "requested_amount": 254,
     *     "net_amount": 254,
     *     "fee": 0,
     *     "pix": { "key_type": "CPF", "key_value": "...", "end_to_end_id": "..." }
     *   }
     * }
     */
    public function babylonWithdrawal(Request $request): JsonResponse
    {
        Log::info('Babylon withdrawal webhook received', [
            'ip' => $request->ip(),
            'content_type' => $request->header('Content-Type'),
        ]);

        try {
            $payload = $request->all();
            $event = $payload['event'] ?? null;
            $withdrawalData = $payload['withdrawal'] ?? $payload;
            
            $withdrawalId = $withdrawalData['id'] ?? null;
            $status = $withdrawalData['status'] ?? null;

            Log::info('Babylon withdrawal webhook processing', [
                'event' => $event,
                'withdrawal_id' => $withdrawalId,
                'status' => $status,
            ]);

            // Verify webhook signature
            $signature = $request->header('X-Babylon-Signature') 
                ?? $request->header('X-Webhook-Signature')
                ?? $request->header('x-babylon-signature');
            $webhookSecret = config('acquirers.babylon.webhook_secret');
            
            if (!$webhookSecret) {
                Log::error('Babylon webhook secret not configured');
                return response()->json(['status' => 'error', 'message' => 'Webhook secret not configured'], 500);
            }

            if (!$signature) {
                Log::warning('Babylon withdrawal webhook missing signature', ['ip' => $request->ip()]);
                return response()->json(['status' => 'error', 'message' => 'Missing signature'], 401);
            }

            $expectedSignature = hash_hmac('sha256', $request->getContent(), $webhookSecret);
            
            if (!hash_equals($expectedSignature, $signature)) {
                Log::warning('Babylon withdrawal webhook signature verification failed');
                return response()->json(['status' => 'error', 'message' => 'Invalid signature'], 401);
            }

            if (!$withdrawalId) {
                Log::warning('Babylon withdrawal webhook: No withdrawal ID found');
                return response()->json(['status' => 'received', 'message' => 'No withdrawal ID']);
            }

            // Map Babylon status to internal status
            $mappedStatus = match (strtolower($status)) {
                'done', 'done_manual' => 'completed',
                'approved', 'processing' => 'processing',
                'pending' => 'pending',
                'failed', 'refused' => 'failed',
                'cancelled' => 'cancelled',
                default => 'processing',
            };

            // Handle withdrawal status update
            $withdrawalService = app(\App\Services\WithdrawalService::class);
            
            if (in_array($mappedStatus, ['completed', 'failed', 'cancelled'])) {
                $withdrawalService->handleTransferCompleted(
                    $withdrawalId,
                    $mappedStatus,
                    $withdrawalData
                );
            }

            Log::info('Babylon withdrawal webhook processed', [
                'withdrawal_id' => $withdrawalId,
                'status' => $status,
                'mapped_status' => $mappedStatus,
                'event' => $event,
            ]);

            return response()->json([
                'status' => 'success',
                'withdrawal_id' => $withdrawalId,
                'event' => $event,
            ]);
        } catch (\Exception $e) {
            Log::error('Babylon withdrawal webhook error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            // Return 200 to prevent retries
            return response()->json(['status' => 'error', 'message' => 'Processing failed'], 200);
        }
    }

    /**
     * Update PIX data for a charge
     */
    private function updatePixData(string $chargeId, array $pixData): void
    {
        $charge = Charge::find($chargeId);
        if (!$charge) {
            return;
        }

        $metadata = $charge->metadata ?? [];
        $metadata['pix'] = [
            'end2end_id' => $pixData['end2EndId'] ?? null,
            'receipt_url' => $pixData['receiptUrl'] ?? null,
        ];

        $charge->update(['metadata' => $metadata]);
    }

    /**
     * Handle PayFort Bank webhooks
     * 
     * PayFort Bank webhook events:
     * Transaction (PIX IN): transaction_created, transaction_paid, transaction_refunded, transaction_infraction
     * Transfer (PIX OUT): transfer_created, transfer_updated, transfer_completed, transfer_canceled
     * 
     * Signature: HMAC-SHA256 via payfort-Signature header
     * Format: t=timestamp,v1=hmac_hex
     * Signed payload: "{timestamp}.{raw_json_body}"
     */
    public function payfort(Request $request): JsonResponse
    {
        $handler = new PayFortWebhookHandler($this->paymentService);
        return $handler->handle($request);
    }

    /**
     * Handle BluPay IP webhooks
     * 
     * Events (PIX IN):  transaction.paid, transaction.refunded, transaction.infraction
     * Events (PIX OUT): transfer.paid, transfer.failed
     * 
     * Signature: HMAC-SHA256 via X-Webhook-Signature header
     */
    public function blupay(Request $request): JsonResponse
    {
        $handler = new BluPayWebhookHandler($this->paymentService);
        return $handler->handle($request);
    }

    /**
     * Handle Turbos Cash webhooks (postback)
     *
     * Postback payload: {type: "transaction"|"withdraw", objectId, url, data: {...}}
     * Transaction statuses: waiting_payment, pending, approved, paid, refused, refunded, cancelled, chargeback
     * Transfer statuses: COMPLETED, PROCESSING, CANCELLED, REFUSED, PENDING_ANALYSIS, PENDING_QUEUE
     */
    public function turbos(Request $request): JsonResponse
    {
        $handler = new TurbosCashWebhookHandler($this->paymentService);
        return $handler->handle($request);
    }

    /**
     * Handle AscentPay webhooks (postback)
     *
     * Postback payload: {type: "transaction"|"withdrawal", objectId, data: {...}}
     * Transaction statuses: waiting_payment, pending, approved, paid, refused, refunded, cancelled, chargeback
     * Withdrawal statuses: completed, processing, cancelled, refused, pending
     */
    public function ascentpay(Request $request): JsonResponse
    {
        $handler = new \App\Infrastructure\Acquirers\Webhooks\AscentPayWebhookHandler($this->paymentService);
        return $handler->handle($request);
    }

    /**
     * Handle Enki Bank webhooks
     */
    public function enki(Request $request): JsonResponse
    {
        $handler = new EnkiWebhookHandler($this->paymentService);
        return $handler->handle($request);
    }

    /**
     * Handle Witetec webhooks
     *
     * Events: TRANSACTION_PAID, TRANSACTION_FAILED, TRANSACTION_REFUNDED, TRANSACTION_CHARGEDBACK
     *         WITHDRAWAL_PAID, WITHDRAWAL_FAILED, etc.
     * Signature: HMAC-SHA256 via X-Witetec-Signature header
     */
    public function witetec(Request $request): JsonResponse
    {
        $handler = new WitetecWebhookHandler($this->paymentService);
        return $handler->handle($request);
    }

    /**
     * Handle Asaas webhooks
     *
     * Events: PAYMENT_RECEIVED, PAYMENT_CONFIRMED, PAYMENT_REFUNDED,
     *         PAYMENT_OVERDUE, PAYMENT_DELETED, CHARGEBACK_REQUESTED, etc.
     * Auth: asaas-access-token header
     */
    public function asaas(Request $request): JsonResponse
    {
        $handler = new AsaasWebhookHandler($this->paymentService);
        return $handler->handle($request);
    }

    /**
     * Handle custom integration webhooks
     */
    public function custom(Request $request, string $integrationId): JsonResponse
    {
        Log::info('Custom webhook received', [
            'integration_id' => $integrationId,
            'ip' => $request->ip(),
        ]);

        try {
            $payload = $request->all();
            
            // Generic webhook handling
            $chargeId = $payload['charge_id'] ?? $payload['reference'] ?? null;
            $status = $payload['status'] ?? null;

            if ($chargeId && $status) {
                $mappedStatus = $this->mapStatus($status);
                $this->updateChargeStatus($chargeId, $mappedStatus);
            }

            return response()->json(['status' => 'error', 'message' => 'Erro interno ao processar webhook'], 500);
        } catch (\Exception $e) {
            Log::error('Custom webhook error', ['error' => $e->getMessage()]);
            return response()->json(['status' => 'error', 'message' => 'Erro interno ao processar webhook'], 500);
        }
    }

    /**
     * Handle successful payment
     */
    private function handlePaymentSuccess(array $data): void
    {
        $chargeId = $data['metadata']['charge_id'] ?? null;
        if ($chargeId) {
            $this->updateChargeStatus($chargeId, 'paid');
        }
    }

    /**
     * Handle failed payment
     */
    private function handlePaymentFailed(array $data): void
    {
        $chargeId = $data['metadata']['charge_id'] ?? null;
        if ($chargeId) {
            $this->updateChargeStatus($chargeId, 'failed');
        }
    }

    /**
     * Handle refund
     */
    private function handleRefund(array $data): void
    {
        $chargeId = $data['metadata']['charge_id'] ?? null;
        if ($chargeId) {
            $this->updateChargeStatus($chargeId, 'refunded');
        }
    }

    /**
     * Update charge status with idempotency protection.
     *
     * Prevents double-credit on duplicate webhooks by checking current status
     * before applying state transitions. Uses PaymentService's locked wallet update.
     */
    private function updateChargeStatus(string $chargeId, string $status): void
    {
        DB::transaction(function () use ($chargeId, $status) {
            $charge = Charge::lockForUpdate()->find($chargeId);
            if (!$charge) {
                Log::warning('Charge not found for webhook', ['charge_id' => $chargeId]);
                return;
            }

            // Idempotency: skip if charge is already in a terminal/same state
            if ($status === 'paid' || $status === 'approved') {
                if ($charge->status === 'approved') {
                    Log::info('Webhook idempotency: charge already approved', ['charge_id' => $chargeId]);
                    return;
                }
            }
            if ($status === 'refunded' && $charge->status === 'refunded') {
                Log::info('Webhook idempotency: charge already refunded', ['charge_id' => $chargeId]);
                return;
            }

            $updateData = ['status' => $status];

            if ($status === 'paid' || $status === 'approved') {
                $updateData['status'] = 'approved';
                $updateData['paid_at'] = now();

                // Wallet update uses lockForUpdate internally — safe from double-credit
                $this->paymentService->updateWalletOnPayment($charge);
                
                // Auto-enroll member if product has associated course
                $this->memberEnrollmentService->createAutomaticEnrollment($charge);
            } elseif ($status === 'refunded') {
                $updateData['refunded_at'] = now();
                $this->paymentService->updateWalletOnRefund($charge, $charge->amount);
            }

            $charge->update($updateData);

            // Create transaction record
            $txType = match(true) {
                $status === 'refunded' => 'refund',
                in_array($status, ['approved', 'paid']) => 'capture',
                default => 'sale',
            };
            $txStatus = match(true) {
                in_array($status, ['paid', 'approved']) => 'approved',
                $status === 'refunded' => 'refunded',
                in_array($status, ['failed', 'declined']) => 'failed',
                $status === 'cancelled' => 'cancelled',
                default => 'pending',
            };
            Transaction::create([
                'id' => Str::uuid()->toString(),
                'charge_id' => $chargeId,
                'acquirer_id' => $this->getDefaultAcquirerId($charge),
                'type' => $txType,
                'status' => $txStatus,
                'amount' => $charge->amount,
                'net_amount' => $charge->amount,
                'acquirer_tid' => $charge->acquirer_transaction_id ?? 'wh_' . Str::random(16),
            ]);

            Log::info('Charge status updated via webhook', [
                'charge_id' => $chargeId,
                'status' => $updateData['status'],
            ]);

            // Dispatch webhook notifications (UTMify, N8N, per-account webhooks)
            $freshCharge = $charge->fresh();
            $normalizedStatus = $updateData['status'] ?? $status;
            if (in_array($normalizedStatus, ['approved', 'paid'])) {
                $this->paymentService->dispatchApprovedNotifications($freshCharge);
            } elseif ($normalizedStatus === 'refunded') {
                $this->paymentService->dispatchRefundNotifications($freshCharge);
            } elseif (in_array($normalizedStatus, ['failed', 'cancelled'])) {
                $this->paymentService->dispatchStatusNotifications($normalizedStatus, $freshCharge);
            }
        });
    }

    /**
     * Map external status to internal status
     */
    private function mapStatus(string $externalStatus): string
    {
        return match (strtolower($externalStatus)) {
            'paid', 'approved', 'success', 'completed' => 'paid',
            'pending', 'processing', 'in_process' => 'pending',
            'failed', 'rejected', 'declined', 'refused' => 'failed',
            'cancelled', 'canceled' => 'cancelled',
            'refunded', 'reversed' => 'refunded',
            default => 'pending',
        };
    }

    /**
     * Get or create a default acquirer ID based on the charge's acquirer field.
     */
    private function getDefaultAcquirerId(?Charge $charge = null): string
    {
        $slug = $charge->acquirer ?? 'payfort';

        $acquirer = Acquirer::where('slug', $slug)->first();

        if (!$acquirer) {
            $acquirer = Acquirer::firstOrCreate(
                ['slug' => $slug],
                [
                    'id' => Str::uuid()->toString(),
                    'name' => ucfirst($slug),
                    'slug' => $slug,
                    'is_active' => true,
                    'supported_methods' => json_encode(['pix']),
                    'settings' => json_encode([]),
                ]
            );
        }

        return $acquirer->id;
    }

    /**
     * Handle webhooks from n8n (bidirectional communication)
     * 
     * This allows n8n to send commands back to the gateway
     */
    public function n8n(Request $request, string $integrationId): JsonResponse
    {
        Log::info('N8n incoming webhook received', [
            'integration_id' => $integrationId,
            'ip' => $request->ip(),
        ]);

        try {
            $integration = \App\Models\N8nIntegration::find($integrationId);

            if (!$integration) {
                Log::warning('N8n integration not found', ['integration_id' => $integrationId]);
                return response()->json(['status' => 'error', 'message' => 'Integration not found'], 404);
            }

            if (!$integration->is_active) {
                return response()->json(['status' => 'error', 'message' => 'Integration is inactive'], 400);
            }

            // Verify signature (MANDATORY when secret is configured)
            $signature = $request->header('X-N8n-Signature') ?? $request->header('X-Webhook-Signature');
            
            if ($integration->secret_key) {
                if (!$signature) {
                    Log::warning('N8n webhook missing signature', ['integration_id' => $integrationId]);
                    return response()->json(['status' => 'error', 'message' => 'Missing signature'], 401);
                }

                $n8nService = app(\App\Services\N8nWebhookService::class);
                
                if (!$n8nService->verifySignature($request->getContent(), $signature, $integration->secret_key)) {
                    Log::warning('N8n webhook signature verification failed', ['integration_id' => $integrationId]);
                    return response()->json(['status' => 'error', 'message' => 'Invalid signature'], 401);
                }
            }

            $payload = $request->all();
            $action = $payload['action'] ?? null;
            $data = $payload['data'] ?? [];

            // Process n8n action
            $result = $this->processN8nAction($action, $data, $integration);

            return response()->json([
                'status' => 'success',
                'action' => $action,
                'result' => $result,
            ]);
        } catch (\Exception $e) {
            Log::error('N8n webhook error', [
                'integration_id' => $integrationId,
                'error' => $e->getMessage(),
            ]);
            return response()->json(['status' => 'error', 'message' => 'Erro interno ao processar webhook'], 500);
        }
    }

    /**
     * Process actions from n8n
     */
    private function processN8nAction(?string $action, array $data, \App\Models\N8nIntegration $integration): array
    {
        if (!$action) {
            return ['message' => 'Webhook received', 'processed' => true];
        }

        return match ($action) {
            'ping' => ['pong' => true, 'timestamp' => now()->toIso8601String()],
            
            'get_charge' => $this->n8nGetCharge($data),
            'update_charge' => $this->n8nUpdateCharge($data, $integration),
            'cancel_charge' => $this->n8nCancelCharge($data, $integration),
            
            'create_notification' => $this->n8nCreateNotification($data, $integration),
            
            default => ['message' => "Unknown action: {$action}"],
        };
    }

    /**
     * N8n action: Get charge details
     */
    private function n8nGetCharge(array $data): array
    {
        $chargeId = $data['charge_id'] ?? null;
        
        if (!$chargeId) {
            return ['error' => 'charge_id is required'];
        }

        $charge = Charge::find($chargeId);
        
        if (!$charge) {
            return ['error' => 'Charge not found'];
        }

        return [
            'charge' => [
                'id' => $charge->id,
                'status' => $charge->status,
                'amount' => $charge->amount,
                'currency' => $charge->currency,
                'payment_method' => $charge->payment_method,
                'customer_email' => $charge->customer_email,
                'customer_name' => $charge->customer_name,
                'created_at' => $charge->created_at?->toIso8601String(),
                'paid_at' => $charge->paid_at?->toIso8601String(),
            ],
        ];
    }

    /**
     * N8n action: Update charge metadata (scoped to integration's account)
     */
    private function n8nUpdateCharge(array $data, \App\Models\N8nIntegration $integration): array
    {
        $chargeId = $data['charge_id'] ?? null;
        $metadata = $data['metadata'] ?? [];
        
        if (!$chargeId) {
            return ['error' => 'charge_id is required'];
        }

        $charge = Charge::where('id', $chargeId)
            ->where('account_id', $integration->account_id)
            ->first();
        
        if (!$charge) {
            return ['error' => 'Charge not found'];
        }

        $existingMetadata = $charge->metadata ?? [];
        $charge->update([
            'metadata' => array_merge($existingMetadata, $metadata, ['n8n_updated_at' => now()->toIso8601String()]),
        ]);

        return ['success' => true, 'message' => 'Charge metadata updated'];
    }

    /**
     * N8n action: Cancel charge (scoped to integration's account)
     */
    private function n8nCancelCharge(array $data, \App\Models\N8nIntegration $integration): array
    {
        $chargeId = $data['charge_id'] ?? null;
        $reason = $data['reason'] ?? 'Cancelled via n8n';
        
        if (!$chargeId) {
            return ['error' => 'charge_id is required'];
        }

        $charge = Charge::where('id', $chargeId)
            ->where('account_id', $integration->account_id)
            ->first();
        
        if (!$charge) {
            return ['error' => 'Charge not found'];
        }

        if (!in_array($charge->status, ['pending', 'waiting_payment'])) {
            return ['error' => 'Cannot cancel charge with status: ' . $charge->status];
        }

        $charge->update([
            'status' => 'cancelled',
            'cancelled_at' => now(),
            'cancellation_reason' => $reason,
        ]);

        return ['success' => true, 'message' => 'Charge cancelled'];
    }

    /**
     * N8n action: Create notification
     */
    private function n8nCreateNotification(array $data, \App\Models\N8nIntegration $integration): array
    {
        $title = $data['title'] ?? 'Notificação via n8n';
        $message = $data['message'] ?? '';
        $type = $data['type'] ?? 'info';
        
        if (!$integration->account_id) {
            return ['error' => 'Integration has no account associated'];
        }

        \App\Models\Notification::create([
            'id' => Str::uuid()->toString(),
            'account_id' => $integration->account_id,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'data' => [
                'source' => 'n8n',
                'integration_id' => $integration->id,
            ],
        ]);

        return ['success' => true, 'message' => 'Notification created'];
    }

    /**
     * HunterSub V2 webhook handler
     */
    public function huntersub(Request $request): JsonResponse
    {
        try {
            $rawPayload = $request->getContent();
            $payload = $request->all();
            
            Log::info('HunterSub webhook received', [
                'ip' => $request->ip(),
                'event_type' => $payload['type'] ?? null,
                'body_size' => strlen($rawPayload),
                'has_signature' => !empty(
                    $request->header('X-HunterSub-Signature')
                    ?? $request->header('x-huntersub-signature')
                    ?? $request->header('X-WISHPAG-SIGNATURE')
                    ?? $request->header('x-wishpag-signature')
                    ?? $request->header('X-Webhook-Signature')
                    ?? $request->header('x-webhook-signature')
                ),
            ]);

            $webhookSecret = $this->getHunterSubWebhookSecret();
            $signature = trim((string) (
                $request->header('X-HunterSub-Signature')
                ?? $request->header('x-huntersub-signature')
                ?? $request->header('X-WISHPAG-SIGNATURE')
                ?? $request->header('x-wishpag-signature')
                ?? $request->header('X-Webhook-Signature')
                ?? $request->header('x-webhook-signature')
                ?? ''
            ));
            $timestamp = trim((string) (
                $request->header('X-HunterSub-Timestamp')
                ?? $request->header('x-huntersub-timestamp')
                ?? $request->header('X-Webhook-Timestamp')
                ?? $request->header('x-webhook-timestamp')
                ?? ''
            ));

            if ($webhookSecret) {
                if (!$this->verifyHunterSubSignature($rawPayload, $signature, $timestamp, $webhookSecret)) {
                    Log::warning('HunterSub webhook signature verification failed', ['ip' => $request->ip()]);
                    return response()->json([
                        'status' => 'error',
                        'message' => 'Invalid signature',
                    ], 401);
                }
            } else {
                Log::info('HunterSub webhook accepted without signature verification because no webhook secret is configured');
            }

            // Validar estrutura do payload
            if (!isset($payload['type']) || !is_array($payload['data'] ?? null)) {
                Log::warning('HunterSub: Invalid webhook payload structure');
                return response()->json(['status' => 'error', 'message' => 'Invalid payload'], 400);
            }

            if (!$this->consumeHunterSubReplayToken($payload, $signature, $timestamp)) {
                Log::warning('HunterSub webhook replay detected', [
                    'ip' => $request->ip(),
                    'event_type' => $payload['type'] ?? null,
                    'transaction_id' => $payload['data']['id'] ?? null,
                ]);

                return response()->json([
                    'status' => 'ok',
                    'message' => 'Already processed',
                ]);
            }

            $eventType = (string) $payload['type'];
            $data = $payload['data'];
            $transactionId = trim((string) ($data['id'] ?? ''));

            if (!$transactionId) {
                Log::warning('HunterSub: Missing transaction ID');
                return response()->json(['status' => 'error', 'message' => 'Missing transaction ID'], 400);
            }

            // Buscar a cobrança pelo external_id/acquirer_transaction_id (ID da HunterSub)
            $charge = Charge::where('external_id', $transactionId)
                ->orWhere('acquirer_transaction_id', $transactionId)
                ->first();

            if (!$charge) {
                Log::warning('HunterSub: Charge not found', ['transaction_id' => $transactionId]);
                return response()->json(['status' => 'error', 'message' => 'Charge not found'], 404);
            }

            // Mapear evento para status interno esperado pelo PaymentService.
            // HunterSub can send either specific events (transaction.paid) or a
            // generic "transaction" event with the status inside data.status.
            $externalStatus = strtolower(trim((string) ($data['status'] ?? '')));
            $mappedStatus = match ($eventType) {
                'transaction.paid', 'transaction.delivered' => 'approved',
                'transaction.pending' => 'pending',
                'transaction.failed' => 'failed',
                'transaction.refunded' => 'refunded',
                'transaction.chargeback' => 'chargeback',
                'transaction' => match ($externalStatus) {
                    'paid', 'approved', 'success', 'completed', 'delivered' => 'approved',
                    'waiting_payment', 'pending', 'processing' => 'pending',
                    'refused', 'failed', 'declined', 'rejected' => 'failed',
                    'refunded' => 'refunded',
                    'chargeback', 'chargedback' => 'chargeback',
                    default => null,
                },
                default => null,
            };

            if (!$mappedStatus) {
                Log::info('HunterSub: Unhandled event type', ['event_type' => $eventType]);
                return response()->json(['status' => 'ok', 'message' => 'Event type not handled']);
            }

            $previousStatus = $charge->status;

            Log::info('HunterSub: Updating charge status', [
                'charge_id' => $charge->id,
                'old_status' => $previousStatus,
                'new_status' => $mappedStatus,
            ]);

            $webhookData = [
                'id' => $transactionId,
                'status' => $mappedStatus,
                'amount' => $data['amount'] ?? null,
                'refundedAmount' => $data['refundedAmount'] ?? null,
                'paymentMethod' => $data['paymentMethod'] ?? 'PIX',
                'metadata' => [
                    'charge_id' => $charge->id,
                    'event_type' => $eventType,
                    'source' => 'huntersub',
                ],
            ];

            if (isset($data['pix']) && is_array($data['pix'])) {
                $webhookData['pix'] = $data['pix'];
            }

            $this->paymentService->handleWebhookPayment($charge, $webhookData);

            $freshCharge = $charge->fresh();

            // Disparar evento de mudança de status
            event(new \App\Events\PaymentStatusChanged($freshCharge ?? $charge));

            return response()->json([
                'status' => 'success',
                'message' => 'Webhook processed successfully',
                'charge_id' => $charge->id,
                'previous_status' => $previousStatus,
                'charge_status' => $freshCharge->status ?? $previousStatus,
            ]);

        } catch (\Throwable $e) {
            Log::error('HunterSub webhook error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Internal server error',
            ], 500);
        }
    }

    public function sharkhubsub(Request $request): JsonResponse
    {
        try {
            $rawPayload = $request->getContent();
            $payload = $request->all();
            $eventId = (string) ($payload['id'] ?? '');
            $status = (string) ($payload['status'] ?? '');

            Log::info('SharkHubSub webhook received', [
                'ip' => $request->ip(),
                'id' => $eventId,
                'status' => $status,
                'method' => $payload['method'] ?? null,
                'has_signature' => !empty($request->header('X-Signature') ?? $request->header('x-signature')),
            ]);

            if ($eventId === '' || $status === '') {
                Log::warning('SharkHubSub: invalid webhook payload');
                return response()->json(['status' => 'error', 'message' => 'Invalid payload'], 400);
            }

            $isPaymentWebhook = isset($payload['payer'])
                || isset($payload['items'])
                || array_key_exists('paidAt', $payload)
                || array_key_exists('refundedAt', $payload);

            $webhookSecret = $this->getSharkHubSubWebhookSecret(!$isPaymentWebhook);
            $signature = (string) ($request->header('X-Signature') ?? $request->header('x-signature') ?? '');

            if ($webhookSecret && $signature !== '') {
                if (!\App\Infrastructure\Acquirers\SharkHubSubAcquirer::verifyWebhookSignature($rawPayload, $signature, $webhookSecret)) {
                    Log::warning('SharkHubSub webhook signature verification failed', ['ip' => $request->ip()]);
                    return response()->json(['status' => 'error', 'message' => 'Invalid signature'], 401);
                }
            } else {
                Log::info('SharkHubSub webhook accepted without signature verification', [
                    'reason' => $signature === '' ? 'no_signature_sent' : 'no_secret_configured',
                    'ip' => $request->ip(),
                ]);
            }

            if (!$isPaymentWebhook) {
                $mappedTransferStatus = \App\Infrastructure\Acquirers\SharkHubSubAcquirer::mapWebhookTransferStatus($status);
                $handled = app(\App\Services\WithdrawalService::class)
                    ->handleTransferCompleted($eventId, $mappedTransferStatus, $payload);

                return response()->json([
                    'status' => $handled ? 'success' : 'ok',
                    'message' => $handled ? 'Transfer webhook processed' : 'Transfer not found',
                    'transfer_id' => $eventId,
                ]);
            }

            $charge = null;
            $externalRef = $payload['externalRef'] ?? null;
            if ($externalRef) {
                $charge = Charge::where('id', $externalRef)->first();
            }

            if (!$charge) {
                $charge = Charge::where('external_id', $eventId)
                    ->orWhere('acquirer_transaction_id', $eventId)
                    ->first();
            }

            if (!$charge) {
                Log::warning('SharkHubSub: Charge not found', [
                    'payment_id' => $eventId,
                    'external_ref' => $externalRef,
                ]);

                return response()->json(['status' => 'error', 'message' => 'Charge not found'], 404);
            }

            $mappedStatus = \App\Infrastructure\Acquirers\SharkHubSubAcquirer::mapWebhookPaymentStatus($status);
            $webhookData = [
                'id' => $eventId,
                'status' => $mappedStatus,
                'amount' => $payload['amount'] ?? null,
                'refundedAmount' => $payload['refundedAmount'] ?? null,
                'paymentMethod' => $payload['method'] ?? 'PIX',
                'paidAt' => $payload['paidAt'] ?? null,
                'refundedAt' => $payload['refundedAt'] ?? null,
                'pix' => [
                    'endToEndId' => $payload['data']['e2e'] ?? null,
                    'copyPaste' => $payload['data']['copypaste'] ?? null,
                ],
                'metadata' => [
                    'charge_id' => $charge->id,
                    'source' => 'sharkhubsub',
                ],
            ];

            $previousStatus = $charge->status;
            $this->paymentService->handleWebhookPayment($charge, $webhookData);
            $freshCharge = $charge->fresh();

            event(new \App\Events\PaymentStatusChanged($freshCharge ?? $charge));

            return response()->json([
                'status' => 'success',
                'message' => 'Webhook processed successfully',
                'charge_id' => $charge->id,
                'previous_status' => $previousStatus,
                'charge_status' => $freshCharge->status ?? $previousStatus,
            ]);
        } catch (\Throwable $e) {
            Log::error('SharkHubSub webhook error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Internal server error',
            ], 500);
        }
    }

    private function getHunterSubWebhookSecret(): ?string
    {
        $envSecret = trim((string) config('acquirers.huntersub.webhook_secret', ''));
        if ($envSecret !== '') {
            return $envSecret;
        }

        $config = AcquirerConfig::findByKey('huntersub');
        if (!$config) {
            return null;
        }

        $credentials = $config->getDecryptedCredentials() ?? [];
        $dbSecret = trim((string) ($credentials['webhook_secret'] ?? ''));

        return $dbSecret !== '' ? $dbSecret : null;
    }

    private function getSharkHubSubWebhookSecret(bool $transfer = false): ?string
    {
        $configKey = $transfer ? 'transfer_webhook_secret' : 'webhook_secret';
        $envSecret = trim((string) config("acquirers.sharkhubsub.{$configKey}", ''));
        if ($envSecret !== '') {
            return $envSecret;
        }

        if ($transfer) {
            $fallbackEnvSecret = trim((string) config('acquirers.sharkhubsub.webhook_secret', ''));
            if ($fallbackEnvSecret !== '') {
                return $fallbackEnvSecret;
            }
        }

        $config = AcquirerConfig::findByKey('sharkhubsub');
        if (!$config) {
            return null;
        }

        $credentials = $config->getDecryptedCredentials() ?? [];
        $dbSecret = trim((string) ($credentials[$configKey] ?? ''));
        if ($transfer && $dbSecret === '') {
            $dbSecret = trim((string) ($credentials['webhook_secret'] ?? ''));
        }

        return $dbSecret !== '' ? $dbSecret : null;
    }

    private function verifyHunterSubSignature(string $rawPayload, string $signatureHeader, string $timestampHeader, string $secret): bool
    {
        if ($signatureHeader === '' || $secret === '') {
            return false;
        }

        $normalizedSignature = $signatureHeader;
        $normalizedTimestamp = $timestampHeader;

        // Supports plain signatures and "t=<timestamp>,v1=<signature>" format.
        if (str_contains($signatureHeader, '=')) {
            foreach (explode(',', $signatureHeader) as $part) {
                $pair = explode('=', trim($part), 2);
                if (count($pair) !== 2) {
                    continue;
                }

                [$key, $value] = $pair;
                $key = strtolower(trim($key));
                $value = trim($value);

                if ($key === 't' && $normalizedTimestamp === '') {
                    $normalizedTimestamp = $value;
                }

                if (in_array($key, ['v1', 'sig', 'signature'], true)) {
                    $normalizedSignature = $value;
                }
            }
        }

        if (str_starts_with(strtolower($normalizedSignature), 'sha256=')) {
            $normalizedSignature = substr($normalizedSignature, 7);
        }

        if ($normalizedTimestamp !== '') {
            if (!ctype_digit($normalizedTimestamp)) {
                return false;
            }

            if (abs(time() - (int) $normalizedTimestamp) > 300) {
                return false;
            }
        }

        $expectedRawHex = hash_hmac('sha256', $rawPayload, $secret);
        $expectedRawBase64 = base64_encode(hex2bin($expectedRawHex) ?: '');

        if (
            hash_equals(strtolower($expectedRawHex), strtolower($normalizedSignature))
            || hash_equals($expectedRawBase64, $normalizedSignature)
        ) {
            return true;
        }

        if ($normalizedTimestamp === '') {
            return false;
        }

        $signedPayload = $normalizedTimestamp . '.' . $rawPayload;
        $expectedTimestampHex = hash_hmac('sha256', $signedPayload, $secret);
        $expectedTimestampBase64 = base64_encode(hex2bin($expectedTimestampHex) ?: '');

        return hash_equals(strtolower($expectedTimestampHex), strtolower($normalizedSignature))
            || hash_equals($expectedTimestampBase64, $normalizedSignature);
    }

    private function consumeHunterSubReplayToken(array $payload, string $signature, string $timestamp): bool
    {
        $eventId = trim((string) ($payload['id'] ?? $payload['event_id'] ?? ''));
        $eventType = trim((string) ($payload['type'] ?? 'unknown'));
        $transactionId = trim((string) ($payload['data']['id'] ?? ''));

        $payloadHash = hash('sha256', json_encode($payload) ?: '');
        $source = $eventId !== ''
            ? "event:{$eventId}"
            : "{$eventType}|{$transactionId}|{$signature}|{$payloadHash}|{$timestamp}";

        $cacheKey = 'webhook_replay:huntersub:' . hash('sha256', $source);

        return Cache::add($cacheKey, now()->timestamp, now()->addMinutes(15));
    }
}

