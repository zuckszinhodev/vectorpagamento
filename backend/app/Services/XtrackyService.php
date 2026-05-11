<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Charge;
use App\Models\XtrackyIntegration;
use App\Jobs\SendXtrackyEvent;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * XTracky Tracking Service
 *
 * Sends order events to the XTracky API for UTM tracking & attribution.
 * Endpoint: POST https://xapi.xtracky.com/api/integrations/pre/{integration_id}
 * Docs: https://docs.xtracky.com/
 */
class XtrackyService
{
    public const BASE_URL = 'https://xapi.xtracky.com/api/integrations/pre';
    public const PLATFORM = 'CUSTOM';

    /**
     * Map internal charge statuses to XTracky statuses.
     */
    private const STATUS_MAP = [
        'pending'              => 'waiting_payment',
        'processing'           => 'waiting_payment',
        'authorized'           => 'waiting_payment',
        'approved'             => 'paid',
        'declined'             => 'refused',
        'failed'               => 'refused',
        'cancelled'            => 'refused',
        'expired'              => 'refused',
        'refunded'             => 'refunded',
        'partially_refunded'   => 'refunded',
        'chargeback'           => 'chargedback',
    ];

    /**
     * Map internal payment methods to XTracky payment methods.
     */
    private const PAYMENT_METHOD_MAP = [
        'pix'         => 'pix',
        'credit_card' => 'credit_card',
        'boleto'      => 'boleto',
        'paypal'      => 'paypal',
    ];

    // ----------------------------------------------------------------
    // Public API
    // ----------------------------------------------------------------

    /**
     * Dispatch an order event to XTracky (via queue).
     */
    public function dispatch(Charge $charge): void
    {
        $integration = XtrackyIntegration::active()
            ->forAccount($charge->account_id)
            ->first();

        if (!$integration) {
            Log::info('XTracky dispatch skipped – no active integration', [
                'account_id' => $charge->account_id,
                'charge_id'  => $charge->id,
            ]);
            return;
        }

        $payload = $this->buildPayload($charge);

        SendXtrackyEvent::dispatch($integration, $payload);

        Log::info('XTracky event dispatched', [
            'charge_id' => $charge->id,
            'status'    => $payload['status'],
        ]);
    }

    /**
     * Send order to XTracky synchronously.
     *
     * For terminal states (paid, refunded, chargedback) we always send
     * waiting_payment first (two-step flow), then the terminal status.
     */
    public function dispatchSync(Charge $charge): ?array
    {
        $integration = XtrackyIntegration::active()
            ->forAccount($charge->account_id)
            ->first();

        if (!$integration) {
            Log::info('XTracky dispatchSync skipped – no active integration', [
                'account_id' => $charge->account_id,
                'charge_id'  => $charge->id,
            ]);
            return null;
        }

        $payload = $this->buildPayload($charge);

        // Two-step flow: send waiting_payment first for terminal statuses.
        $terminalStatuses = ['paid', 'refunded', 'chargedback'];

        if (in_array($payload['status'], $terminalStatuses)) {
            $waitingPayload = $payload;
            $waitingPayload['status'] = 'waiting_payment';

            $preResult = $this->send($integration, $waitingPayload);

            Log::info('XTracky pre-step (waiting_payment) sent', [
                'charge_id' => $charge->id,
                'success'   => $preResult['success'],
                'http_code' => $preResult['status'] ?? null,
            ]);

            usleep(500_000); // 500ms delay
        }

        $result = $this->send($integration, $payload);

        Log::info('XTracky event sent sync', [
            'charge_id' => $charge->id,
            'status'    => $payload['status'],
            'success'   => $result['success'],
            'http_code' => $result['status'] ?? null,
        ]);

        return $result;
    }

    /**
     * Send payload to XTracky API.
     */
    public function send(XtrackyIntegration $integration, array $payload): array
    {
        $url = self::BASE_URL . '/' . $integration->api_token;

        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ])
                ->post($url, $payload);

            $body = $response->body();

            if ($response->successful()) {
                $integration->markSuccess((string) $response->status(), $body);

                return [
                    'success' => true,
                    'status'  => $response->status(),
                    'body'    => $response->json(),
                ];
            }

            $integration->markFailure((string) $response->status(), $body);

            Log::warning('XTracky API error', [
                'status'  => $response->status(),
                'body'    => $body,
                'payload' => $payload,
            ]);

            return [
                'success' => false,
                'status'  => $response->status(),
                'error'   => $body,
            ];
        } catch (\Exception $e) {
            $integration->markFailure('exception', $e->getMessage());

            Log::error('XTracky send exception', [
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'status'  => 0,
                'error'   => $e->getMessage(),
            ];
        }
    }

    /**
     * Test the integration token by sending a test payload to XTracky.
     */
    public function testToken(string $apiToken): array
    {
        $url = self::BASE_URL . '/' . $apiToken;

        try {
            $orderId = 'vectorpagamentos-test-' . now()->timestamp;
            $payload = [
                'orderId'    => $orderId,
                'amount'     => 100,
                'status'     => 'waiting_payment',
                'platform'   => self::PLATFORM,
                'utm_source' => null,
            ];

            $response = Http::timeout(15)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ])
                ->post($url, $payload);

            $isValid = $response->successful();

            // Clean up: send refunded to remove the test order
            if ($isValid) {
                try {
                    $payload['status'] = 'refunded';
                    Http::timeout(10)
                        ->withHeaders(['Content-Type' => 'application/json'])
                        ->post($url, $payload);
                } catch (\Exception $e) {
                    // Ignore cleanup errors
                }
            }

            return [
                'valid'  => $isValid,
                'status' => $response->status(),
                'body'   => $response->json() ?? $response->body(),
            ];
        } catch (\Exception $e) {
            return [
                'valid'  => false,
                'status' => 0,
                'error'  => $e->getMessage(),
            ];
        }
    }

    // ----------------------------------------------------------------
    // Private helpers
    // ----------------------------------------------------------------

    /**
     * Build the XTracky payload from a Charge model.
     */
    public function buildPayload(Charge $charge): array
    {
        $metadata = $charge->metadata ?? [];
        $tracking = $metadata['tracking'] ?? $metadata['trackingParameters'] ?? [];

        $xtrackyStatus = self::STATUS_MAP[$charge->status] ?? 'waiting_payment';

        return [
            'orderId'    => $charge->id,
            'amount'     => (int) $charge->amount,
            'status'     => $xtrackyStatus,
            'platform'   => self::PLATFORM,
            'utm_source' => $tracking['utm_source'] ?? $metadata['utm_source'] ?? null,
        ];
    }
}
