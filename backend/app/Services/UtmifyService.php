<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Charge;
use App\Models\UtmifyIntegration;
use App\Jobs\SendUtmifyOrder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * UTMify Tracking Service
 *
 * Sends order events to the UTMify API for UTM tracking & attribution.
 * Endpoint: POST https://api.utmify.com.br/api-credentials/orders
 * Docs: https://utmify.com.br
 */
class UtmifyService
{
    public const ENDPOINT = 'https://api.utmify.com.br/api-credentials/orders';
    public const PLATFORM = 'Vector Pagamentos';

    /**
     * Map internal charge statuses to UTMify statuses.
     */
    private const STATUS_MAP = [
        'pending'      => 'waiting_payment',
        'processing'   => 'waiting_payment',
        'authorized'   => 'waiting_payment',
        'approved'     => 'paid',
        'declined'     => 'refused',
        'failed'       => 'refused',
        'cancelled'    => 'refused',
        'expired'      => 'refused',
        'refunded'     => 'refunded',
        'partially_refunded' => 'refunded',
        'chargeback'   => 'chargedback',
    ];

    /**
     * Map internal payment methods to UTMify payment methods.
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
     * Dispatch an order event to UTMify for every active integration of the account.
     * The actual HTTP call is done in a queued job (SendUtmifyOrder).
     */
    public function dispatch(Charge $charge): void
    {
        $integration = UtmifyIntegration::active()
            ->forAccount($charge->account_id)
            ->first();

        if (!$integration) {
            Log::info('UTMify dispatch skipped â€“ no active integration', [
                'account_id' => $charge->account_id,
                'charge_id'  => $charge->id,
            ]);
            return;
        }

        $payload = $this->buildPayload($charge);

        SendUtmifyOrder::dispatch($integration, $payload);

        Log::info('UTMify order dispatched', [
            'charge_id' => $charge->id,
            'status'    => $payload['status'],
        ]);
    }

    /**
     * Send order to UTMify synchronously (no queue).
     * Use this on charge creation to guarantee delivery even without a queue worker.
     *
     * When the charge status maps to a terminal state (paid, refunded, chargedback),
     * we ALWAYS send the initial waiting_payment event first, then the final status.
     * This ensures UTMify has the order created even if the original waiting_payment
     * notification failed (timeout, API error, etc.).
     */
    public function dispatchSync(Charge $charge): ?array
    {
        $integration = UtmifyIntegration::active()
            ->forAccount($charge->account_id)
            ->first();

        if (!$integration) {
            Log::info('UTMify dispatchSync skipped â€“ no active integration', [
                'account_id' => $charge->account_id,
                'charge_id'  => $charge->id,
            ]);
            return null;
        }

        $payload = $this->buildPayload($charge);

        // UTMify requires a two-step flow: first create the order (waiting_payment),
        // then update to the final status (paid/refunded/chargedback).
        // Always send waiting_payment first for terminal statuses to guarantee the
        // order exists in UTMify, even if the initial creation notification was lost.
        $terminalStatuses = ['paid', 'refunded', 'chargedback'];

        if (in_array($payload['status'], $terminalStatuses)) {
            $waitingPayload = $payload;
            $waitingPayload['status'] = 'waiting_payment';
            $waitingPayload['approvedDate'] = null;
            $waitingPayload['refundedAt'] = null;

            $preResult = $this->send($integration, $waitingPayload);

            Log::info('UTMify pre-step (waiting_payment) sent', [
                'charge_id' => $charge->id,
                'success'   => $preResult['success'],
                'http_code' => $preResult['status'] ?? null,
            ]);

            // Small delay to ensure UTMify processes the first event
            usleep(500_000); // 500ms
        }

        $result = $this->send($integration, $payload);

        Log::info('UTMify order sent sync', [
            'charge_id' => $charge->id,
            'status'    => $payload['status'],
            'success'   => $result['success'],
            'http_code' => $result['status'] ?? null,
        ]);

        return $result;
    }

    /**
     * Send order synchronously (for testing or immediate feedback).
     */
    public function send(UtmifyIntegration $integration, array $payload): array
    {
        try {
            $response = Http::timeout(15)
                ->withHeaders([
                    'x-api-token' => $integration->api_token,
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ])
                ->post(self::ENDPOINT, $payload);

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

            Log::warning('UTMify API error', [
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

            Log::error('UTMify send exception', [
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
     * Validate a token by sending a real order (isTest=false) to UTMify.
     * 
     * UTMify's isTest=true bypasses credential validation and always returns 200,
     * so we must test with isTest=false to verify the token is actually valid.
     * We immediately follow up with a 'refunded' status to cancel the test order
     * so it doesn't pollute the user's UTMify dashboard.
     */
    public function testToken(string $apiToken): array
    {
        try {
            $orderId = 'vectorpagamentos-test-' . now()->timestamp;
            $payload = [
                'orderId'       => $orderId,
                'platform'      => self::PLATFORM,
                'paymentMethod' => 'pix',
                'status'        => 'paid',
                'createdAt'     => now()->utc()->format('Y-m-d H:i:s'),
                'approvedDate'  => now()->utc()->format('Y-m-d H:i:s'),
                'refundedAt'    => null,
                'customer' => [
                    'name'     => 'Teste Vector Pagamentos',
                    'email'    => 'teste@vectorpagamentos.com',
                    'phone'    => null,
                    'document' => null,
                    'country'  => 'BR',
                    'ip'       => '127.0.0.1',
                ],
                'products' => [
                    [
                        'id'           => 'test-product',
                        'name'         => 'Produto Teste',
                        'planId'       => null,
                        'planName'     => null,
                        'quantity'     => 1,
                        'priceInCents' => 100,
                    ],
                ],
                'trackingParameters' => [
                    'src'          => null,
                    'sck'          => null,
                    'utm_source'   => null,
                    'utm_campaign' => null,
                    'utm_medium'   => null,
                    'utm_content'  => null,
                    'utm_term'     => null,
                ],
                'commission' => [
                    'totalPriceInCents'      => 100,
                    'gatewayFeeInCents'      => 0,
                    'userCommissionInCents'  => 100,
                ],
                'isTest'  => false,
                'isTeste' => false,
            ];

            $response = Http::timeout(15)
                ->withHeaders([
                    'x-api-token' => $apiToken,
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ])
                ->post(self::ENDPOINT, $payload);

            $isValid = $response->successful();

            // Clean up: send a refund for the test order so it won't appear in reports
            if ($isValid) {
                try {
                    $payload['status'] = 'refunded';
                    $payload['refundedAt'] = now()->utc()->format('Y-m-d H:i:s');
                    Http::timeout(10)
                        ->withHeaders([
                            'x-api-token' => $apiToken,
                            'Content-Type' => 'application/json',
                        ])
                        ->post(self::ENDPOINT, $payload);
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
     * Build the full UTMify payload from a Charge model.
     */
    public function buildPayload(Charge $charge): array
    {
        $customer = $charge->customer ?? [];
        $metadata = $charge->metadata ?? [];
        $product  = $charge->product;

        $utmifyStatus = self::STATUS_MAP[$charge->status] ?? 'waiting_payment';
        $paymentMethod = self::PAYMENT_METHOD_MAP[$charge->payment_method] ?? 'pix';

        Log::debug('UTMify buildPayload', [
            'charge_id'      => $charge->id,
            'charge_status'  => $charge->status,
            'utmify_status'  => $utmifyStatus,
            'payment_method' => $paymentMethod,
        ]);

        // Commission values
        $totalPriceInCents      = (int) $charge->amount;
        $gatewayFeeInCents      = (int) ($charge->fee_amount ?? 0);
        $userCommissionInCents  = $totalPriceInCents - $gatewayFeeInCents;

        // Tracking parameters extracted from checkout URL / metadata
        $tracking = $metadata['tracking'] ?? $metadata['trackingParameters'] ?? [];

        return [
            'orderId'       => $charge->id,
            'platform'      => self::PLATFORM,
            'paymentMethod' => $paymentMethod,
            'status'        => $utmifyStatus,
            'createdAt'     => $charge->created_at?->utc()->format('Y-m-d H:i:s'),
            'approvedDate'  => $charge->paid_at?->utc()->format('Y-m-d H:i:s'),
            'refundedAt'    => $charge->refunded_at?->utc()->format('Y-m-d H:i:s'),
            'customer' => [
                'name'     => $customer['name'] ?? null,
                'email'    => $customer['email'] ?? null,
                'phone'    => $customer['phone'] ?? null,
                'document' => $customer['document'] ?? null,
                'country'  => $customer['country'] ?? 'BR',
                'ip'       => !empty($customer['ip']) ? $customer['ip']
                            : (!empty($metadata['ip']) ? $metadata['ip']
                            : (!empty($charge->client_ip) ? $charge->client_ip
                            : (request()?->ip() ?? '0.0.0.0'))),
            ],
            'products' => [
                [
                    'id'           => $product?->id ?? $charge->product_id ?? $charge->id,
                    'name'         => $product?->name ?? $charge->description ?? 'Produto',
                    'planId'       => null,
                    'planName'     => null,
                    'quantity'     => 1,
                    'priceInCents' => (int) ($product?->price ?? $charge->amount),
                ],
            ],
            'trackingParameters' => [
                'src'          => $tracking['src'] ?? null,
                'sck'          => $tracking['sck'] ?? null,
                'utm_source'   => $tracking['utm_source'] ?? $metadata['utm_source'] ?? null,
                'utm_campaign' => $tracking['utm_campaign'] ?? $metadata['utm_campaign'] ?? null,
                'utm_medium'   => $tracking['utm_medium'] ?? $metadata['utm_medium'] ?? null,
                'utm_content'  => $tracking['utm_content'] ?? $metadata['utm_content'] ?? null,
                'utm_term'     => $tracking['utm_term'] ?? $metadata['utm_term'] ?? null,
            ],
            'commission' => [
                'totalPriceInCents'      => $totalPriceInCents,
                'gatewayFeeInCents'      => $gatewayFeeInCents,
                'userCommissionInCents'  => max($userCommissionInCents, 0),
                'currency'               => $charge->currency ?? 'BRL',
            ],
            'isTeste' => false,
            'isTest'  => false,
        ];
    }
}
