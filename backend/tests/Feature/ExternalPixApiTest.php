<?php

declare(strict_types=1);

/**
 * Teste de Integração Real — PIX via API Externa Vector Pagamentos
 *
 * Chama a API de produção em https://api.vectorpagamentos.com/api/v1/external/charges
 * usando a API Key (sk_live_*) para gerar cobranças PIX reais.
 *
 * Para rodar:
 *   php artisan test tests/Feature/ExternalPixApiTest.php
 */

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

$baseUrl = rtrim((string) env('EXTERNAL_PIX_API_BASE_URL', 'https://api.vectorpagamentos.com/api/v1/external'), '/');
$apiKey  = (string) env('EXTERNAL_PIX_API_KEY', '');
$runExternalPixTests = filter_var(env('RUN_EXTERNAL_PIX_TESTS', false), FILTER_VALIDATE_BOOL);

beforeEach(function () use ($runExternalPixTests, $apiKey) {
    if (!$runExternalPixTests) {
        $this->markTestSkipped('External PIX tests are disabled. Set RUN_EXTERNAL_PIX_TESTS=true to enable.');
    }

    if ($apiKey === '') {
        $this->markTestSkipped('Missing EXTERNAL_PIX_API_KEY for external PIX integration tests.');
    }
});

/*
|--------------------------------------------------------------------------
| 1. Gerar PIX — POST /external/charges
|--------------------------------------------------------------------------
*/

it('gera cobrança PIX real via API externa e retorna QR code', function () use ($baseUrl, $apiKey) {
    $idempotencyKey = Str::uuid()->toString();

    $response = Http::withHeaders([
        'X-API-Key'         => $apiKey,
        'Content-Type'      => 'application/json',
        'Accept'            => 'application/json',
        'X-Idempotency-Key' => $idempotencyKey,
    ])->timeout(30)->post("{$baseUrl}/charges", [
        'amount'         => 500, // R$ 5,00 em centavos
        'payment_method' => 'pix',
        'customer'       => [
            'name'     => 'Teste Integração PIX',
            'email'    => 'teste-pix@vectorpagamentos.com',
            'document' => '52998224725',
            'phone'    => '11999998888',
        ],
    ]);

    // Resposta básica
    expect($response->successful())->toBeTrue(
        "API retornou status {$response->status()}: " . $response->body()
    );

    $json = $response->json();

    expect($json['success'])->toBeTrue('Resposta success=false: ' . ($json['error']['message'] ?? json_encode($json)));

    $data = $json['data'];

    // Campos obrigatórios
    expect($data)->toHaveKey('id');
    expect($data['payment_method'])->toBe('pix');
    expect($data['amount'])->toBe(500);
    expect(in_array($data['status'], ['pending', 'processing']))->toBeTrue(
        "Status inesperado: {$data['status']}"
    );

    // Dados PIX (pelo menos um deve existir)
    $hasPixData = !empty($data['pix_qr_code'])
        || !empty($data['pix_copy_paste'])
        || !empty($data['pix_qr_code_url']);

    expect($hasPixData)->toBeTrue(
        'Nenhum dado PIX retornado (pix_qr_code, pix_copy_paste ou pix_qr_code_url)'
    );

    // Se copy-paste veio, deve ser EMV válido
    if (!empty($data['pix_copy_paste'])) {
        expect(strlen($data['pix_copy_paste']))->toBeGreaterThan(50);
    }

    echo "\n  PIX gerado com sucesso.";
    echo "\n     Charge ID: {$data['id']}";
    echo "\n     Status: {$data['status']}";
    echo "\n     Acquirer: " . ($data['acquirer'] ?? 'N/A');
    echo "\n     PIX QR Code: " . (empty($data['pix_qr_code']) ? '❌' : '✅ (' . strlen($data['pix_qr_code']) . ' chars)');
    echo "\n     PIX Copy-Paste: " . (empty($data['pix_copy_paste']) ? '❌' : '✅ (' . strlen($data['pix_copy_paste']) . ' chars)');
    echo "\n     PIX QR URL: " . ($data['pix_qr_code_url'] ?? 'N/A');
    echo "\n";
});

/*
|--------------------------------------------------------------------------
| 2. Listar cobranças — GET /external/charges
|--------------------------------------------------------------------------
*/

it('lista cobranças via API externa', function () use ($baseUrl, $apiKey) {
    $response = Http::withHeaders([
        'X-API-Key' => $apiKey,
        'Accept'    => 'application/json',
    ])->timeout(15)->get("{$baseUrl}/charges");

    expect($response->successful())->toBeTrue(
        "GET /charges falhou com status {$response->status()}: " . $response->body()
    );

    $json = $response->json();
    expect($json['success'])->toBeTrue();
    expect($json)->toHaveKey('data');

    $total = is_array($json['data']) ? count($json['data']) : ($json['data']['total'] ?? 'N/A');
    echo "\n  Listagem OK - {$total} cobrancas retornadas\n";
});

/*
|--------------------------------------------------------------------------
| 3. Consultar cobrança criada — GET /external/charges/{id}
|--------------------------------------------------------------------------
*/

it('consulta detalhes de uma cobrança PIX criada', function () use ($baseUrl, $apiKey) {
    // Primeiro cria uma cobrança
    $createResponse = Http::withHeaders([
        'X-API-Key'         => $apiKey,
        'Content-Type'      => 'application/json',
        'Accept'            => 'application/json',
        'X-Idempotency-Key' => Str::uuid()->toString(),
    ])->timeout(30)->post("{$baseUrl}/charges", [
        'amount'         => 300, // R$ 3,00
        'payment_method' => 'pix',
        'customer'       => [
            'name'     => 'Consulta PIX Test',
            'email'    => 'consulta@vectorpagamentos.com',
            'document' => '52998224725',
        ],
    ]);

    expect($createResponse->successful())->toBeTrue(
        "Falha ao criar cobrança: {$createResponse->body()}"
    );

    $chargeId = $createResponse->json('data.id');
    expect($chargeId)->not->toBeNull();

    // Agora consulta
    $showResponse = Http::withHeaders([
        'X-API-Key' => $apiKey,
        'Accept'    => 'application/json',
    ])->timeout(15)->get("{$baseUrl}/charges/{$chargeId}");

    expect($showResponse->successful())->toBeTrue(
        "GET /charges/{$chargeId} falhou: {$showResponse->body()}"
    );

    $data = $showResponse->json('data');

    expect($data['id'])->toBe($chargeId);
    expect($data['payment_method'])->toBe('pix');
    expect($data['amount'])->toBe(300);

    echo "\n  Consulta OK - Charge {$chargeId}, status: {$data['status']}\n";
});

/*
|--------------------------------------------------------------------------
| 4. Validações de erro
|--------------------------------------------------------------------------
*/

it('rejeita cobrança sem amount', function () use ($baseUrl, $apiKey) {
    $response = Http::withHeaders([
        'X-API-Key'         => $apiKey,
        'Content-Type'      => 'application/json',
        'Accept'            => 'application/json',
        'X-Idempotency-Key' => Str::uuid()->toString(),
    ])->timeout(15)->post("{$baseUrl}/charges", [
        'payment_method' => 'pix',
        'customer'       => [
            'name'     => 'Sem Amount',
            'email'    => 'sem-amount@test.com',
            'document' => '52998224725',
        ],
    ]);

    expect($response->status())->toBe(422, "Esperado 422, recebeu {$response->status()}");
    echo "\n  Validacao amount: 422 retornado corretamente\n";
});

it('rejeita cobrança sem customer', function () use ($baseUrl, $apiKey) {
    $response = Http::withHeaders([
        'X-API-Key'         => $apiKey,
        'Content-Type'      => 'application/json',
        'Accept'            => 'application/json',
        'X-Idempotency-Key' => Str::uuid()->toString(),
    ])->timeout(15)->post("{$baseUrl}/charges", [
        'amount'         => 1000,
        'payment_method' => 'pix',
    ]);

    expect($response->status())->toBe(422, "Esperado 422, recebeu {$response->status()}");
    echo "\n  Validacao customer: 422 retornado corretamente\n";
});

it('rejeita requisição sem API key', function () use ($baseUrl) {
    $response = Http::withHeaders([
        'Accept' => 'application/json',
    ])->timeout(15)->post("{$baseUrl}/charges", [
        'amount'         => 500,
        'payment_method' => 'pix',
        'customer'       => [
            'name'     => 'Sem Key',
            'email'    => 'sem-key@test.com',
            'document' => '52998224725',
        ],
    ]);

    expect($response->status())->toBe(401, "Esperado 401, recebeu {$response->status()}");
    echo "\n  Auth: 401 sem API key retornado corretamente\n";
});

it('rejeita requisição com API key inválida', function () use ($baseUrl) {
    $response = Http::withHeaders([
        'X-API-Key' => 'sk_live_INVALIDA_123456789',
        'Accept'    => 'application/json',
    ])->timeout(15)->post("{$baseUrl}/charges", [
        'amount'         => 500,
        'payment_method' => 'pix',
        'customer'       => [
            'name'     => 'Key Inválida',
            'email'    => 'invalida@test.com',
            'document' => '52998224725',
        ],
    ]);

    expect(in_array($response->status(), [401, 403]))->toBeTrue(
        "Esperado 401 ou 403, recebeu {$response->status()}"
    );
    echo "\n  Auth: {$response->status()} com key invalida retornado corretamente\n";
});
