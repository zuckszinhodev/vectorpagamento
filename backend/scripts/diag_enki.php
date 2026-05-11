<?php

declare(strict_types=1);

use App\Models\Charge;
use App\Services\AcquirerFactory;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$mode = $argv[1] ?? 'charge';
$enki = AcquirerFactory::instance()->enki();

if (!$enki) {
    fwrite(STDERR, json_encode([
        'success' => false,
        'error' => 'Enki not configured',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(1);
}

if ($mode === 'balance') {
    echo json_encode($enki->getBalance(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(0);
}

if ($mode === 'charge') {
    $charge = new Charge();
    $charge->forceFill([
        'id' => Str::uuid()->toString(),
        'amount' => 500,
        'description' => 'Teste Enki via script',
        'customer' => [
            'name' => 'Teste Enki Cliente',
            'email' => 'teste-enki@vectorpagamentos.com',
            'document' => '52998224725',
            'phone' => '11999998888',
        ],
        'metadata' => [],
    ]);

    $result = $enki->generatePixQrCode($charge);

    echo json_encode([
        'success' => $result['success'] ?? false,
        'external_id' => $result['external_id'] ?? null,
        'status' => $result['status'] ?? null,
        'pix_copy_paste_present' => !empty($result['pix_copy_paste']),
        'pix_expires_at' => $result['pix_expires_at'] ?? null,
        'error' => $result['error'] ?? null,
        'raw_response' => $result['raw_response'] ?? null,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(($result['success'] ?? false) ? 0 : 2);
}

if ($mode === 'charge-raw') {
    $payload = [
        'amount' => 500,
        'payment_method' => 'PIX',
        'postback_url' => 'https://app.vectorpagamentos.com/api/v1/webhooks/enki',
        'items' => [[
            'title' => 'Teste Enki via script',
            'unit_price' => 500,
            'quantity' => 1,
            'tangible' => false,
            'external_ref' => 'diag-' . Str::lower(Str::random(12)),
        ]],
        'customer' => [
            'name' => 'Teste Enki Cliente',
            'email' => 'teste-enki@vectorpagamentos.com',
            'phone' => '11999998888',
            'document' => [
                'number' => '52998224725',
                'type' => 'CPF',
            ],
        ],
    ];

    $response = Http::withHeaders([
        'Authorization' => 'Basic ' . base64_encode((string) config('acquirers.enki.public_key') . ':' . (string) config('acquirers.enki.secret_key')),
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    ])->connectTimeout(10)->timeout(60)->withoutVerifying()->post(rtrim((string) config('acquirers.enki.base_url'), '/') . '/v1/transactions', $payload);

    echo json_encode([
        'http_status' => $response->status(),
        'successful' => $response->successful(),
        'body' => $response->json() ?? $response->body(),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($response->successful() ? 0 : 3);
}

if ($mode === 'charge-raw-expires-in' || $mode === 'charge-raw-expires-at') {
    $payload = [
        'amount' => 500,
        'payment_method' => 'PIX',
        'postback_url' => 'https://app.vectorpagamentos.com/api/v1/webhooks/enki',
        'items' => [[
            'title' => 'Teste Enki exp probe',
            'unit_price' => 500,
            'quantity' => 1,
            'tangible' => false,
            'external_ref' => 'diag-' . Str::lower(Str::random(12)),
        ]],
        'customer' => [
            'name' => 'Teste Enki Cliente',
            'email' => 'teste-enki@vectorpagamentos.com',
            'phone' => '11999998888',
            'document' => [
                'number' => '52998224725',
                'type' => 'CPF',
            ],
        ],
    ];

    if ($mode === 'charge-raw-expires-in') {
        $payload['expires_in'] = 3600;
    }

    if ($mode === 'charge-raw-expires-at') {
        $payload['expires_at'] = now()->addHour()->utc()->toIso8601String();
    }

    $response = Http::withHeaders([
        'Authorization' => 'Basic ' . base64_encode((string) config('acquirers.enki.public_key') . ':' . (string) config('acquirers.enki.secret_key')),
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    ])->connectTimeout(10)->timeout(60)->withoutVerifying()->post(rtrim((string) config('acquirers.enki.base_url'), '/') . '/v1/transactions', $payload);

    $body = $response->json() ?? ['raw' => $response->body()];

    echo json_encode([
        'mode' => $mode,
        'http_status' => $response->status(),
        'successful' => $response->successful(),
        'pix_expires_at' => data_get($body, 'pix.expires_at'),
        'body' => $body,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($response->successful() ? 0 : 3);
}

if ($mode === 'charge-raw-pix-expires-in' || $mode === 'charge-raw-pix-expires-at') {
    $payload = [
        'amount' => 500,
        'payment_method' => 'PIX',
        'postback_url' => 'https://app.vectorpagamentos.com/api/v1/webhooks/enki',
        'items' => [[
            'title' => 'Teste Enki pix exp probe',
            'unit_price' => 500,
            'quantity' => 1,
            'tangible' => false,
            'external_ref' => 'diag-' . Str::lower(Str::random(12)),
        ]],
        'customer' => [
            'name' => 'Teste Enki Cliente',
            'email' => 'teste-enki@vectorpagamentos.com',
            'phone' => '11999998888',
            'document' => [
                'number' => '52998224725',
                'type' => 'CPF',
            ],
        ],
        'pix' => [],
    ];

    if ($mode === 'charge-raw-pix-expires-in') {
        $payload['pix']['expires_in'] = 3600;
    }

    if ($mode === 'charge-raw-pix-expires-at') {
        $payload['pix']['expires_at'] = now()->addHour()->utc()->toIso8601String();
    }

    $response = Http::withHeaders([
        'Authorization' => 'Basic ' . base64_encode((string) config('acquirers.enki.public_key') . ':' . (string) config('acquirers.enki.secret_key')),
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    ])->connectTimeout(10)->timeout(60)->withoutVerifying()->post(rtrim((string) config('acquirers.enki.base_url'), '/') . '/v1/transactions', $payload);

    $body = $response->json() ?? ['raw' => $response->body()];

    echo json_encode([
        'mode' => $mode,
        'http_status' => $response->status(),
        'successful' => $response->successful(),
        'pix_expires_at' => data_get($body, 'pix.expires_at'),
        'body' => $body,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit($response->successful() ? 0 : 3);
}

fwrite(STDERR, "Unknown mode: {$mode}" . PHP_EOL);
exit(1);