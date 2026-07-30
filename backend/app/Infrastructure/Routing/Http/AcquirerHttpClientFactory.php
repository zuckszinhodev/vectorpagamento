<?php

declare(strict_types=1);

namespace App\Infrastructure\Routing\Http;

use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Handler\CurlMultiHandler;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Shared, connection-pooling HTTP client for acquirer calls.
 *
 * Laravel's Http facade builds a fresh Guzzle client — and therefore a fresh
 * cURL handle and a fresh TLS handshake — for every request. Against acquirers
 * that is 50-150 ms of pure overhead per call, on the payment hot path.
 *
 * Here a single Guzzle client with a persistent CurlMultiHandler is reused for
 * the lifetime of the worker, so keep-alive actually keeps the TCP+TLS session
 * alive between transactions.
 */
final class AcquirerHttpClientFactory
{
    private static ?Client $shared = null;

    /**
     * Long-lived Guzzle client with a persistent handle pool.
     */
    public static function shared(): Client
    {
        if (self::$shared instanceof Client) {
            return self::$shared;
        }

        $config = (array) config('acquirer_routing.http', []);

        $handler = HandlerStack::create(new CurlMultiHandler([
            'options' => [
                CURLMOPT_MAXCONNECTS => (int) ($config['max_connections'] ?? 50),
                CURLMOPT_MAX_HOST_CONNECTIONS => (int) ($config['max_connections_per_host'] ?? 20),
            ],
        ]));

        return self::$shared = new Client([
            'handler' => $handler,
            'http_errors' => false,
            'headers' => [
                'Connection' => 'keep-alive',
                'User-Agent' => (string) ($config['user_agent'] ?? 'VectorPagamentos/2.0'),
            ],
            'curl' => [
                // Reuse the TCP/TLS session instead of renegotiating per call.
                CURLOPT_TCP_KEEPALIVE => 1,
                CURLOPT_TCP_KEEPIDLE => (int) ($config['keep_alive_seconds'] ?? 60),
                CURLOPT_TCP_KEEPINTVL => 30,
                CURLOPT_FORBID_REUSE => 0,
                CURLOPT_FRESH_CONNECT => 0,
            ],
        ]);
    }

    /**
     * A Laravel PendingRequest bound to the pooled client.
     *
     * Falls back to a plain Http request if pooling is disabled or the handler
     * cannot be built — an acquirer call must never fail because of pooling.
     */
    public static function request(): PendingRequest
    {
        if (!config('acquirer_routing.http.connection_pooling', true)) {
            return Http::acceptJson();
        }

        try {
            return Http::acceptJson()->setClient(self::shared());
        } catch (\Throwable) {
            return Http::acceptJson();
        }
    }

    /**
     * Drop the shared client — used by tests and after a config change.
     */
    public static function reset(): void
    {
        self::$shared = null;
    }
}
