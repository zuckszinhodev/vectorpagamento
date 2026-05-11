<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Charge;
use App\Models\FacebookPixelIntegration;
use App\Jobs\SendFacebookConversionEvent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Facebook Conversions API Service
 *
 * Sends server-side events to the Meta Conversions API for accurate
 * conversion tracking that bypasses browser ad-blockers.
 *
 * API Docs: https://developers.facebook.com/docs/marketing-api/conversions-api
 * Endpoint: POST https://graph.facebook.com/v21.0/{pixel_id}/events
 */
class FacebookConversionsApiService
{
    public const API_VERSION = 'v21.0';
    public const BASE_URL = 'https://graph.facebook.com';

    /**
     * Map internal charge statuses to Facebook event names.
     */
    private const EVENT_MAP = [
        'approved'           => 'Purchase',
        'pending'            => 'InitiateCheckout',
        'processing'         => 'AddPaymentInfo',
        'authorized'         => 'AddPaymentInfo',
        'declined'           => null,
        'failed'             => null,
        'cancelled'          => null,
        'expired'            => null,
        'refunded'           => null, // No standard FB event for refunds
        'partially_refunded' => null,
        'chargeback'         => null,
    ];

    // ----------------------------------------------------------------
    // Public API
    // ----------------------------------------------------------------

    /**
     * Dispatch a conversion event to Facebook CAPI asynchronously (via queue).
     */
    public function dispatch(Charge $charge, ?string $eventName = null): void
    {
        $integration = FacebookPixelIntegration::active()
            ->forAccount($charge->account_id)
            ->first();

        if (!$integration) {
            Log::debug('Facebook CAPI dispatch skipped â€“ no active integration', [
                'account_id' => $charge->account_id,
                'charge_id'  => $charge->id,
            ]);
            return;
        }

        $resolvedEvent = $eventName ?? $this->resolveEventName($charge->status);

        if (!$resolvedEvent) {
            Log::debug('Facebook CAPI dispatch skipped â€“ no event mapped for status', [
                'charge_id' => $charge->id,
                'status'    => $charge->status,
            ]);
            return;
        }

        if (!$integration->shouldTrack($resolvedEvent)) {
            Log::debug('Facebook CAPI dispatch skipped â€“ event tracking disabled', [
                'charge_id' => $charge->id,
                'event'     => $resolvedEvent,
            ]);
            return;
        }

        $payload = $this->buildEventPayload($charge, $resolvedEvent);

        SendFacebookConversionEvent::dispatch($integration->id, $payload);

        Log::info('Facebook CAPI event dispatched to queue', [
            'charge_id' => $charge->id,
            'event'     => $resolvedEvent,
        ]);
    }

    /**
     * Send conversion event synchronously (no queue).
     * Used on initial checkout creation and for direct calls.
     */
    public function dispatchSync(Charge $charge, ?string $eventName = null): ?array
    {
        $integration = FacebookPixelIntegration::active()
            ->forAccount($charge->account_id)
            ->first();

        if (!$integration) {
            Log::info('Facebook CAPI dispatchSync skipped â€“ no active integration', [
                'account_id' => $charge->account_id,
                'charge_id'  => $charge->id,
            ]);
            return null;
        }

        $resolvedEvent = $eventName ?? $this->resolveEventName($charge->status);

        if (!$resolvedEvent) {
            return null;
        }

        if (!$integration->shouldTrack($resolvedEvent)) {
            return null;
        }

        $payload = $this->buildEventPayload($charge, $resolvedEvent);

        return $this->send($integration, $payload);
    }

    /**
     * Send event data to Meta Conversions API.
     */
    public function send(FacebookPixelIntegration $integration, array $eventPayload): array
    {
        $url = sprintf(
            '%s/%s/%s/events',
            self::BASE_URL,
            self::API_VERSION,
            $integration->pixel_id
        );

        $body = [
            'data' => [$eventPayload],
        ];

        // Add test event code if configured (for Events Manager debugging)
        if ($integration->test_event_code) {
            $body['test_event_code'] = $integration->test_event_code;
        }

        try {
            $response = Http::timeout(15)
                ->withToken($integration->access_token)
                ->post($url, $body);

            $responseBody = $response->body();

            if ($response->successful()) {
                $integration->markSuccess((string) $response->status(), $responseBody);

                return [
                    'success' => true,
                    'status'  => $response->status(),
                    'body'    => $response->json(),
                ];
            }

            $integration->markFailure((string) $response->status(), $responseBody);

            Log::warning('Facebook CAPI error', [
                'status'   => $response->status(),
                'body'     => $responseBody,
                'pixel_id' => $integration->pixel_id,
            ]);

            return [
                'success' => false,
                'status'  => $response->status(),
                'error'   => $responseBody,
            ];
        } catch (\Exception $e) {
            $integration->markFailure('exception', $e->getMessage());

            Log::error('Facebook CAPI send exception', [
                'pixel_id' => $integration->pixel_id,
                'error'    => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'status'  => 0,
                'error'   => $e->getMessage(),
            ];
        }
    }

    /**
     * Send a test event to validate the pixel + access token configuration.
     */
    public function testConnection(string $pixelId, string $accessToken, ?string $testEventCode = null): array
    {
        $url = sprintf('%s/%s/%s/events', self::BASE_URL, self::API_VERSION, $pixelId);

        $eventPayload = [
            'event_name'  => 'PageView',
            'event_time'  => time(),
            'event_id'    => 'test_' . Str::uuid()->toString(),
            'action_source' => 'website',
            'user_data' => [
                'client_ip_address' => '127.0.0.1',
                'client_user_agent' => 'Vector Pagamentos/TestConnection',
            ],
        ];

        $body = [
            'data' => [$eventPayload],
        ];

        if ($testEventCode) {
            $body['test_event_code'] = $testEventCode;
        }

        try {
            $response = Http::timeout(15)
                ->withToken($accessToken)
                ->post($url, $body);

            $responseBody = $response->body();
            $json = $response->json();

            if ($response->successful()) {
                return [
                    'valid'   => true,
                    'status'  => $response->status(),
                    'body'    => $json,
                    'message' => 'ConexÃ£o com Facebook Conversions API validada com sucesso!',
                ];
            }

            // Parse Meta error
            $errorMessage = $json['error']['message'] ?? $responseBody;

            return [
                'valid'   => false,
                'status'  => $response->status(),
                'error'   => $errorMessage,
                'body'    => $json,
            ];
        } catch (\Exception $e) {
            return [
                'valid'   => false,
                'status'  => 0,
                'error'   => $e->getMessage(),
            ];
        }
    }

    /**
     * Get the public pixel data for a given account (used in public checkout responses).
     * Returns only non-sensitive data: pixel_id and tracking preferences.
     */
    public function getPixelDataForAccount(string $accountId): ?array
    {
        $integration = FacebookPixelIntegration::active()
            ->forAccount($accountId)
            ->first();

        if (!$integration) {
            return null;
        }

        return [
            'pixel_id'                => $integration->pixel_id,
            'track_page_view'         => $integration->track_page_view,
            'track_initiate_checkout' => $integration->track_initiate_checkout,
            'track_add_payment_info'  => $integration->track_add_payment_info,
            'track_purchase'          => $integration->track_purchase,
        ];
    }

    // ----------------------------------------------------------------
    // Private helpers
    // ----------------------------------------------------------------

    /**
     * Resolve internal charge status to a Facebook standard event name.
     */
    private function resolveEventName(string $status): ?string
    {
        return self::EVENT_MAP[$status] ?? null;
    }

    /**
     * Build the event payload conforming to Meta Conversions API spec.
     *
     * @see https://developers.facebook.com/docs/marketing-api/conversions-api/parameters/server-event
     */
    private function buildEventPayload(Charge $charge, string $eventName): array
    {
        $customer = $charge->customer ?? [];
        $metadata = $charge->metadata ?? [];

        $payload = [
            'event_name'    => $eventName,
            'event_time'    => $charge->paid_at?->timestamp ?? $charge->created_at->timestamp ?? time(),
            'event_id'      => $charge->id, // Use charge UUID for deduplication
            'action_source' => 'website',
            'event_source_url' => $metadata['checkout_url'] ?? $metadata['source_url'] ?? null,
            'user_data'     => $this->buildUserData($customer, $metadata),
            'custom_data'   => $this->buildCustomData($charge, $eventName),
        ];

        // Remove null values
        return array_filter($payload, fn ($v) => $v !== null);
    }

    /**
     * Build user_data for customer matching.
     * Meta requires hashed PII for advanced matching.
     *
     * @see https://developers.facebook.com/docs/marketing-api/conversions-api/parameters/customer-information-parameters
     */
    private function buildUserData(array $customer, array $metadata): array
    {
        $userData = [];

        // Email (hashed)
        $email = $customer['email'] ?? null;
        if ($email) {
            $userData['em'] = [hash('sha256', strtolower(trim($email)))];
        }

        // Phone (hashed) â€“ normalize to E.164 without + prefix
        $phone = $customer['phone'] ?? null;
        if ($phone) {
            $normalizedPhone = preg_replace('/[^0-9]/', '', $phone);
            $userData['ph'] = [hash('sha256', $normalizedPhone)];
        }

        // Name (hashed)
        $name = $customer['name'] ?? null;
        if ($name) {
            $parts = explode(' ', trim($name));
            $firstName = strtolower(trim($parts[0]));
            $lastName = count($parts) > 1 ? strtolower(trim(end($parts))) : null;

            $userData['fn'] = [hash('sha256', $firstName)];
            if ($lastName) {
                $userData['ln'] = [hash('sha256', $lastName)];
            }
        }

        // CPF/CNPJ as external_id (hashed)
        $document = $customer['document'] ?? null;
        if ($document) {
            $cleanDoc = preg_replace('/[^0-9]/', '', $document);
            $userData['external_id'] = [hash('sha256', $cleanDoc)];
        }

        // IP + User Agent from metadata (captured at checkout time)
        if (!empty($metadata['customer_ip'])) {
            $userData['client_ip_address'] = $metadata['customer_ip'];
        }

        if (!empty($metadata['user_agent'])) {
            $userData['client_user_agent'] = $metadata['user_agent'];
        }

        // Facebook click ID (fbclid) and browser ID (fbp/fbc) from tracking params
        $tracking = $metadata['tracking'] ?? [];

        if (!empty($tracking['fbclid'])) {
            $userData['fbc'] = 'fb.1.' . (time() * 1000) . '.' . $tracking['fbclid'];
        }
        if (!empty($tracking['fbp'])) {
            $userData['fbp'] = $tracking['fbp'];
        }
        if (!empty($tracking['fbc'])) {
            $userData['fbc'] = $tracking['fbc'];
        }

        // Country â€“ default to BR for Brazilian gateway
        $userData['country'] = [hash('sha256', 'br')];

        return $userData;
    }

    /**
     * Build custom_data with transaction details.
     *
     * @see https://developers.facebook.com/docs/marketing-api/conversions-api/parameters/custom-data
     */
    private function buildCustomData(Charge $charge, string $eventName): array
    {
        $customData = [
            'currency' => $charge->currency ?? 'BRL',
            'value'    => round($charge->amount / 100, 2), // Convert cents to currency units
        ];

        // Product info
        if ($charge->product) {
            $customData['content_name'] = $charge->product->name;
            $customData['content_ids']  = [$charge->product_id];
            $customData['content_type'] = 'product';
            $customData['contents']     = [
                [
                    'id'       => $charge->product_id,
                    'quantity' => 1,
                    'item_price' => round($charge->amount / 100, 2),
                ],
            ];
        }

        // Payment method info for AddPaymentInfo event
        if ($eventName === 'AddPaymentInfo') {
            $customData['content_category'] = $charge->payment_method;
        }

        // Order reference
        $customData['order_id'] = $charge->id;

        return $customData;
    }
}
