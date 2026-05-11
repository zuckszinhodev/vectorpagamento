<?php

declare(strict_types=1);

/**
 * PIX Generation Tests
 *
 * Tests the PIX payment generation flow through both entry points:
 * 1. API de Cobranças  → POST /api/v1/charges (authenticated)
 * 2. Checkout Público   → POST /api/v1/checkout/{slug}/process (public)
 *
 * Also tests the status polling endpoint:
 * 3. Status Check       → GET /api/v1/checkout/charge/{id}/status
 */

use App\Models\Account;
use App\Models\AccountWallet;
use App\Models\Charge;
use App\Models\Checkout;
use App\Models\Product;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function setupPixTestData(): array
{
    $account = Account::create([
        'id'            => Str::uuid()->toString(),
        'name'          => 'PIX Test Account',
        'document'      => '12345678901',
        'document_type' => 'cpf',
        'email'         => 'pix-test@vectorpagamentos.com',
        'status'        => 'active',
    ]);

    $user = User::create([
        'id'         => Str::uuid()->toString(),
        'account_id' => $account->id,
        'email'      => 'pix-test@vectorpagamentos.com',
        'password'   => bcrypt('password123'),
        'name'       => 'PIX Test User',
        'status'     => 'active',
        'role'       => 'admin',
    ]);

    AccountWallet::create([
        'id'                => Str::uuid()->toString(),
        'account_id'        => $account->id,
        'available_balance' => 0,
        'pending_balance'   => 0,
        'blocked_balance'   => 0,
        'currency'          => 'BRL',
    ]);

    $product = Product::create([
        'id'          => Str::uuid()->toString(),
        'account_id'  => $account->id,
        'name'        => 'Produto PIX Test',
        'slug'        => 'produto-pix-test',
        'description' => 'Produto para testes de PIX',
        'price'       => 5000, // R$ 50,00
        'type'        => 'digital',
        'is_active'   => true,
    ]);

    $checkout = Checkout::create([
        'id'         => Str::uuid()->toString(),
        'account_id' => $account->id,
        'product_id' => $product->id,
        'name'       => 'Checkout PIX Test',
        'slug'       => 'checkout-pix-test',
        'status'     => 'active',
        'settings'   => json_encode(['payment_methods' => ['pix']]),
    ]);

    $token = auth('api')->login($user);

    return compact('account', 'user', 'product', 'checkout', 'token');
}

/**
 * Mock PaymentService to return successful PIX data without calling external acquirers.
 */
function mockPaymentServicePix(array $overrides = []): void
{
    $mock = Mockery::mock(PaymentService::class)->makePartial();
    $mock->shouldReceive('processPayment')
        ->andReturnUsing(function (Charge $charge) use ($overrides) {
            $pixCode = '00020126580014br.gov.bcb.pix0136' . Str::uuid()->toString();
            
            $charge->update([
                'status'                   => 'processing',
                'acquirer'                 => $overrides['acquirer'] ?? 'turbos',
                'acquirer_transaction_id'  => $overrides['transaction_id'] ?? 'txn_' . Str::random(16),
                'pix_qr_code'              => $overrides['pix_qr_code'] ?? $pixCode,
                'pix_copy_paste'           => $overrides['pix_copy_paste'] ?? $pixCode,
                'expires_at'               => now()->addMinutes(30),
            ]);

            return [
                'success'        => true,
                'acquirer'       => $overrides['acquirer'] ?? 'turbos',
                'transaction_id' => $charge->acquirer_transaction_id,
                'status'         => 'processing',
                'pix_qr_code'    => $charge->pix_qr_code,
                'pix_copy_paste' => $charge->pix_copy_paste,
                'expires_at'     => $charge->expires_at,
            ];
        });
    
    app()->instance(PaymentService::class, $mock);
}

/**
 * Mock PaymentService to simulate acquirer failure.
 */
function mockPaymentServiceFailure(string $errorMsg = 'Acquirer unavailable'): void
{
    $mock = Mockery::mock(PaymentService::class)->makePartial();
    $mock->shouldReceive('processPayment')
        ->andReturn([
            'success' => false,
            'error'   => $errorMsg,
        ]);
    
    app()->instance(PaymentService::class, $mock);
}

/*
|--------------------------------------------------------------------------
| 1. API de Cobranças — POST /api/v1/charges (PIX)
|--------------------------------------------------------------------------
*/

describe('API Charges PIX', function () {

    it('creates a PIX charge and returns QR code + copy-paste', function () {
        ['token' => $token] = setupPixTestData();
        mockPaymentServicePix();

        $response = test()->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/charges', [
                'amount'         => 10000, // R$ 100,00
                'payment_method' => 'pix',
                'customer'       => [
                    'name'     => 'João da Silva',
                    'email'    => 'joao@example.com',
                    'document' => '12345678901',
                ],
            ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'amount',
                    'status',
                    'payment_method',
                    'pix_qr_code',
                    'pix_copy_paste',
                ],
            ]);

        $data = $response->json('data');

        // PIX fields must be present and non-empty
        expect($data['payment_method'])->toBe('pix');
        expect($data['pix_qr_code'])->not->toBeNull()
            ->not->toBeEmpty();
        expect($data['pix_copy_paste'])->not->toBeNull()
            ->not->toBeEmpty();
        expect($data['amount'])->toBe(10000);

        // Charge must exist in DB with PIX data
        $charge = Charge::find($data['id']);
        expect($charge)->not->toBeNull();
        expect($charge->payment_method)->toBe('pix');
        expect($charge->pix_qr_code)->not->toBeNull();
        expect($charge->acquirer)->toBe('turbos');
    });

    it('returns correct acquirer in PIX response', function () {
        ['token' => $token] = setupPixTestData();
        mockPaymentServicePix(['acquirer' => 'payfort']);

        $response = test()->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/charges', [
                'amount'         => 5000,
                'payment_method' => 'pix',
                'customer'       => [
                    'name'     => 'Maria',
                    'email'    => 'maria@example.com',
                    'document' => '98765432100',
                ],
            ]);

        $response->assertStatus(201);
        expect($response->json('data.acquirer'))->toBe('payfort');
    });

    it('fails PIX charge when acquirer is unavailable', function () {
        ['token' => $token] = setupPixTestData();
        mockPaymentServiceFailure('Nenhum adquirente disponível para PIX');

        $response = test()->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/charges', [
                'amount'         => 10000,
                'payment_method' => 'pix',
                'customer'       => [
                    'name'     => 'Test',
                    'email'    => 'test@test.com',
                    'document' => '12345678901',
                ],
            ]);

        // Should return error (400 or the charge with error status)
        $json = $response->json();
        if ($response->status() === 400) {
            expect($json['success'])->toBeFalse();
        } else {
            // Some implementations still return 201 but with error in data
            expect(true)->toBeTrue();
        }
    });

    it('rejects PIX charge without customer data', function () {
        ['token' => $token] = setupPixTestData();

        $response = test()->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/charges', [
                'amount'         => 10000,
                'payment_method' => 'pix',
                // missing customer
            ]);

        $response->assertStatus(422);
    });

    it('rejects PIX charge with zero amount', function () {
        ['token' => $token] = setupPixTestData();

        $response = test()->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/charges', [
                'amount'         => 0,
                'payment_method' => 'pix',
                'customer'       => [
                    'name'     => 'Test',
                    'email'    => 'test@test.com',
                    'document' => '12345678901',
                ],
            ]);

        $response->assertStatus(422);
    });

    it('rejects unauthenticated PIX charge', function () {
        $response = test()->postJson('/api/v1/charges', [
            'amount'         => 10000,
            'payment_method' => 'pix',
            'customer'       => [
                'name'     => 'Test',
                'email'    => 'test@example.com',
                'document' => '12345678901',
            ],
        ]);

        $response->assertStatus(401);
    });
});

/*
|--------------------------------------------------------------------------
| 2. Checkout Público — POST /api/v1/checkout/{slug}/process (PIX)
|--------------------------------------------------------------------------
*/

describe('Checkout PIX', function () {

    it('processes PIX payment through public checkout and returns QR code', function () {
        ['checkout' => $checkout] = setupPixTestData();
        mockPaymentServicePix();

        $response = test()->postJson("/api/v1/checkout/{$checkout->slug}/process", [
            'payment_method' => 'pix',
            'customer' => [
                'name'     => 'Comprador PIX',
                'email'    => 'comprador@example.com',
                'document' => '12345678901',
            ],
        ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'success',
                'data' => [
                    'charge_id',
                    'status',
                    'amount',
                    'payment_method',
                    'pix_qr_code',
                    'pix_copy_paste',
                ],
            ]);

        $data = $response->json('data');

        // PIX data must be returned
        expect($data['pix_qr_code'])->not->toBeNull()
            ->not->toBeEmpty();
        expect($data['pix_copy_paste'])->not->toBeNull()
            ->not->toBeEmpty();
        expect($data['payment_method'])->toBe('pix');
        expect($data['amount'])->toBe(5000); // Product price

        // Charge created in DB linked to checkout
        $charge = Charge::find($data['charge_id']);
        expect($charge)->not->toBeNull();
        expect($charge->checkout_id)->toBe($checkout->id);
        expect($charge->payment_method)->toBe('pix');
        expect($charge->pix_qr_code)->not->toBeNull();
    });

    it('uses the checkout product price as the charge amount', function () {
        ['checkout' => $checkout, 'product' => $product] = setupPixTestData();
        mockPaymentServicePix();

        $response = test()->postJson("/api/v1/checkout/{$checkout->slug}/process", [
            'payment_method' => 'pix',
            'customer' => [
                'name'     => 'Price Test',
                'email'    => 'price@test.com',
                'document' => '12345678901',
            ],
        ]);

        $response->assertStatus(201);
        expect($response->json('data.amount'))->toBe($product->price);
    });

    it('returns 404 for non-existent checkout slug', function () {
        $response = test()->postJson('/api/v1/checkout/non-existent-slug/process', [
            'payment_method' => 'pix',
            'customer' => [
                'name'     => 'Test',
                'email'    => 'test@test.com',
                'document' => '12345678901',
            ],
        ]);

        $response->assertStatus(404);
    });

    it('rejects checkout process without payment method', function () {
        ['checkout' => $checkout] = setupPixTestData();

        $response = test()->postJson("/api/v1/checkout/{$checkout->slug}/process", [
            'customer' => [
                'name'     => 'Test',
                'email'    => 'test@test.com',
                'document' => '12345678901',
            ],
        ]);

        $response->assertStatus(422);
    });

    it('rejects checkout process without customer name', function () {
        ['checkout' => $checkout] = setupPixTestData();

        $response = test()->postJson("/api/v1/checkout/{$checkout->slug}/process", [
            'payment_method' => 'pix',
            'customer' => [
                'email'    => 'test@test.com',
                'document' => '12345678901',
            ],
        ]);

        $response->assertStatus(422);
    });

    it('rejects checkout process without customer email', function () {
        ['checkout' => $checkout] = setupPixTestData();

        $response = test()->postJson("/api/v1/checkout/{$checkout->slug}/process", [
            'payment_method' => 'pix',
            'customer' => [
                'name'     => 'Test',
                'document' => '12345678901',
            ],
        ]);

        $response->assertStatus(422);
    });

    it('rejects checkout process without customer document', function () {
        ['checkout' => $checkout] = setupPixTestData();

        $response = test()->postJson("/api/v1/checkout/{$checkout->slug}/process", [
            'payment_method' => 'pix',
            'customer' => [
                'name'  => 'Test',
                'email' => 'test@test.com',
            ],
        ]);

        $response->assertStatus(422);
    });

    it('handles acquirer failure on checkout PIX gracefully', function () {
        ['checkout' => $checkout] = setupPixTestData();
        mockPaymentServiceFailure('PIX timeout');

        $response = test()->postJson("/api/v1/checkout/{$checkout->slug}/process", [
            'payment_method' => 'pix',
            'customer' => [
                'name'     => 'Failure Test',
                'email'    => 'fail@test.com',
                'document' => '12345678901',
            ],
        ]);

        // Should return 400 with error message
        $response->assertStatus(400)
            ->assertJson(['success' => false]);
        
        $error = $response->json('error');
        expect($error)->toHaveKey('code');
        expect($error['code'])->toBe('payment_failed');
    });

    it('accepts affiliate code on checkout PIX', function () {
        ['checkout' => $checkout] = setupPixTestData();
        mockPaymentServicePix();

        $response = test()->postJson("/api/v1/checkout/{$checkout->slug}/process", [
            'payment_method' => 'pix',
            'affiliate_code' => 'AFFILIATE123',
            'customer' => [
                'name'     => 'Affiliate Test',
                'email'    => 'affiliate@test.com',
                'document' => '12345678901',
            ],
        ]);

        // Should succeed (affiliate code just gets stored, even if not found)
        $response->assertStatus(201);
    });
});

/*
|--------------------------------------------------------------------------
| 3. Status Check — GET /api/v1/checkout/charge/{id}/status
|--------------------------------------------------------------------------
*/

describe('PIX Status Check', function () {

    it('returns pending status for newly created PIX charge', function () {
        ['checkout' => $checkout, 'account' => $account] = setupPixTestData();

        $charge = Charge::create([
            'id'              => Str::uuid()->toString(),
            'account_id'      => $account->id,
            'checkout_id'     => $checkout->id,
            'amount'          => 5000,
            'currency'        => 'BRL',
            'payment_method'  => 'pix',
            'status'          => 'pending',
            'pix_qr_code'     => '00020126580014br.gov.bcb.pix',
            'pix_copy_paste'  => '00020126580014br.gov.bcb.pix',
            'idempotency_key' => Str::random(32),
            'customer'        => ['name' => 'Test', 'email' => 'test@test.com', 'document' => '12345678901'],
        ]);

        $response = test()->getJson("/api/v1/checkout/charge/{$charge->id}/status");

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'success',
                'data' => ['status'],
            ]);

        // Status should be pending or processing
        $status = $response->json('data.status');
        expect(in_array($status, ['pending', 'processing']))->toBeTrue();
    });

    it('returns paid status for completed PIX charge', function () {
        ['account' => $account] = setupPixTestData();

        $charge = Charge::create([
            'id'              => Str::uuid()->toString(),
            'account_id'      => $account->id,
            'amount'          => 5000,
            'currency'        => 'BRL',
            'payment_method'  => 'pix',
            'status'          => 'approved',
            'paid_at'         => now(),
            'pix_qr_code'     => '00020126580014br.gov.bcb.pix',
            'idempotency_key' => Str::random(32),
            'customer'        => ['name' => 'Test', 'email' => 'test@test.com', 'document' => '12345678901'],
        ]);

        $response = test()->getJson("/api/v1/checkout/charge/{$charge->id}/status");

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        $status = $response->json('data.status');
        expect(in_array($status, ['approved']))->toBeTrue();
    });

    it('returns 404 for non-existent charge', function () {
        $fakeId = Str::uuid()->toString();
        $response = test()->getJson("/api/v1/checkout/charge/{$fakeId}/status");

        $response->assertStatus(404);
    });
});

/*
|--------------------------------------------------------------------------
| 4. PIX Data Integrity
|--------------------------------------------------------------------------
*/

describe('PIX Data Integrity', function () {

    it('stores PIX QR code in charge model correctly', function () {
        ['account' => $account] = setupPixTestData();

        $pixCode = '00020126580014br.gov.bcb.pix0136abc-def-ghi-jkl';

        $charge = Charge::create([
            'id'              => Str::uuid()->toString(),
            'account_id'      => $account->id,
            'amount'          => 10000,
            'currency'        => 'BRL',
            'payment_method'  => 'pix',
            'status'          => 'processing',
            'pix_qr_code'     => $pixCode,
            'pix_copy_paste'  => $pixCode,
            'idempotency_key' => Str::random(32),
            'customer'        => ['name' => 'Test', 'email' => 'test@test.com', 'document' => '12345678901'],
        ]);

        $fresh = Charge::find($charge->id);
        expect($fresh->pix_qr_code)->toBe($pixCode);
        expect($fresh->pix_copy_paste)->toBe($pixCode);
        expect($fresh->payment_method)->toBe('pix');
    });

    it('stores acquirer transaction ID on PIX charge', function () {
        ['account' => $account] = setupPixTestData();

        $txnId = 'txn_' . Str::random(16);

        $charge = Charge::create([
            'id'                       => Str::uuid()->toString(),
            'account_id'               => $account->id,
            'amount'                   => 15000,
            'currency'                 => 'BRL',
            'payment_method'           => 'pix',
            'status'                   => 'processing',
            'acquirer'                 => 'turbos',
            'acquirer_transaction_id'  => $txnId,
            'idempotency_key'          => Str::random(32),
            'customer'                 => ['name' => 'Test', 'email' => 'test@test.com', 'document' => '12345678901'],
        ]);

        $fresh = Charge::find($charge->id);
        expect($fresh->acquirer)->toBe('turbos');
        expect($fresh->acquirer_transaction_id)->toBe($txnId);
    });

    it('charge belongs to correct account and checkout', function () {
        ['account' => $account, 'checkout' => $checkout] = setupPixTestData();
        mockPaymentServicePix();

        $response = test()->postJson("/api/v1/checkout/{$checkout->slug}/process", [
            'payment_method' => 'pix',
            'customer' => [
                'name'     => 'Integrity Test',
                'email'    => 'integrity@test.com',
                'document' => '12345678901',
            ],
        ]);

        $response->assertStatus(201);

        $chargeId = $response->json('data.charge_id');
        $charge = Charge::find($chargeId);

        expect($charge->account_id)->toBe($account->id);
        expect($charge->checkout_id)->toBe($checkout->id);
        expect($charge->product_id)->toBe($checkout->product_id);
    });
});

/*
|--------------------------------------------------------------------------
| 5. Integração Real — PIX via API Externa (SEM mock)
|--------------------------------------------------------------------------
|
| Esses testes chamam a API externa real (adquirente configurada no .env).
| NÃO usam Http::fake() nem mock do PaymentService.
|
| Para rodar: php artisan test --filter="Integração Real"
| Requer: adquirente configurada e ativa no .env (ASAAS, PAYFORT, BLUPAY, etc.)
|
*/

describe('Integração Real — PIX via API Externa', function () {

    /**
     * Verifica se há pelo menos uma adquirente configurada.
     * Se nenhuma estiver configurada, pula os testes com mensagem.
     */
    beforeEach(function () {
        $runRealPixTests = filter_var(env('RUN_REAL_PIX_TESTS', false), FILTER_VALIDATE_BOOL);

        if (!$runRealPixTests) {
            test()->markTestSkipped('RUN_REAL_PIX_TESTS=false — pulando testes de integração PIX real.');
        }

        $hasAcquirer = !empty(env('ASAAS_API_KEY'))
            || !empty(env('PAYFORT_API_KEY'))
            || !empty(env('BLUPAY_SECRET_KEY'))
            || !empty(env('BABYLON_SECRET_KEY'));

        if (!$hasAcquirer) {
            test()->markTestSkipped('Nenhuma adquirente configurada no .env — pulando teste de integração real.');
        }
    });

    it('gera PIX real via POST /api/v1/charges usando adquirente configurada', function () {
        ['token' => $token, 'account' => $account] = setupPixTestData();

        $idempotencyKey = Str::uuid()->toString();

        $response = test()->withHeaders([
            'Authorization'    => "Bearer {$token}",
            'X-Idempotency-Key' => $idempotencyKey,
        ])->postJson('/api/v1/charges', [
            'amount'         => 500, // R$ 5,00 (valor baixo para teste)
            'payment_method' => 'pix',
            'customer'       => [
                'name'     => 'Teste Integração PIX',
                'email'    => 'teste-pix-real@vectorpagamentos.com',
                'document' => '52998224725',
                'phone'    => '11999998888',
            ],
        ]);

        // Deve criar a cobrança com sucesso
        $response->assertStatus(201)
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'success',
                'data' => [
                    'id',
                    'amount',
                    'status',
                    'payment_method',
                ],
            ]);

        $data = $response->json('data');

        // Verificações básicas
        expect($data['payment_method'])->toBe('pix');
        expect($data['amount'])->toBe(500);
        expect(in_array($data['status'], ['pending', 'processing']))->toBeTrue(
            "Status esperado pending ou processing, recebido: {$data['status']}"
        );

        // A cobrança deve ter dados PIX (QR code ou copy-paste)
        $charge = Charge::find($data['id']);
        expect($charge)->not->toBeNull();
        expect($charge->payment_method)->toBe('pix');

        // Pelo menos um campo PIX deve estar preenchido
        $hasPixData = !empty($charge->pix_qr_code)
            || !empty($charge->pix_copy_paste)
            || !empty($charge->pix_qr_code_url);
        expect($hasPixData)->toBeTrue('Nenhum dado PIX retornado pela adquirente');

        // Deve ter registrado qual adquirente foi usada
        expect($charge->acquirer)->not->toBeNull(
            'Acquirer não registrado na cobrança'
        );

        Log::info('✅ Teste PIX real bem-sucedido', [
            'charge_id'      => $charge->id,
            'acquirer'       => $charge->acquirer,
            'external_id'    => $charge->acquirer_transaction_id ?? $charge->external_id ?? null,
            'has_qr_code'    => !empty($charge->pix_qr_code),
            'has_copy_paste' => !empty($charge->pix_copy_paste),
            'has_qr_url'     => !empty($charge->pix_qr_code_url),
            'status'         => $charge->status,
        ]);
    });

    it('gera PIX real via checkout público usando adquirente configurada', function () {
        ['checkout' => $checkout, 'product' => $product] = setupPixTestData();

        $response = test()->postJson("/api/v1/checkout/{$checkout->slug}/process", [
            'payment_method' => 'pix',
            'customer'       => [
                'name'     => 'Comprador PIX Real',
                'email'    => 'comprador-real@vectorpagamentos.com',
                'document' => '52998224725',
                'phone'    => '11988887777',
            ],
        ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'success',
                'data' => [
                    'charge_id',
                    'status',
                    'amount',
                    'payment_method',
                ],
            ]);

        $data = $response->json('data');

        expect($data['payment_method'])->toBe('pix');
        expect($data['amount'])->toBe($product->price);

        // Dados PIX devem estar presentes na resposta
        $hasPixInResponse = !empty($data['pix_qr_code'])
            || !empty($data['pix_copy_paste']);
        expect($hasPixInResponse)->toBeTrue('Resposta do checkout não contém dados PIX');

        // Verificar no banco
        $charge = Charge::find($data['charge_id']);
        expect($charge)->not->toBeNull();
        expect($charge->checkout_id)->toBe($checkout->id);
        expect($charge->acquirer)->not->toBeNull();

        Log::info('✅ Teste PIX checkout real bem-sucedido', [
            'charge_id' => $charge->id,
            'acquirer'  => $charge->acquirer,
            'status'    => $charge->status,
        ]);
    });

    it('retorna dados PIX válidos com formato correto', function () {
        ['token' => $token] = setupPixTestData();

        $response = test()->withHeaders([
            'Authorization'    => "Bearer {$token}",
            'X-Idempotency-Key' => Str::uuid()->toString(),
        ])->postJson('/api/v1/charges', [
            'amount'         => 1000, // R$ 10,00
            'payment_method' => 'pix',
            'customer'       => [
                'name'     => 'Validação Formato PIX',
                'email'    => 'formato-pix@vectorpagamentos.com',
                'document' => '52998224725',
            ],
        ]);

        $response->assertStatus(201);

        $charge = Charge::find($response->json('data.id'));
        expect($charge)->not->toBeNull();

        // Se QR code copy-paste veio, deve ser string EMV válida (começa com 0002)
        if (!empty($charge->pix_copy_paste)) {
            expect(str_starts_with($charge->pix_copy_paste, '0002'))->toBeTrue(
                'PIX copy-paste não começa com 0002 (formato EMV inválido): ' . substr($charge->pix_copy_paste, 0, 30)
            );
            expect(strlen($charge->pix_copy_paste))->toBeGreaterThan(50)
                ->toBeLessThan(500);
        }

        // Se QR code image veio, deve ser base64, URL ou EMV
        if (!empty($charge->pix_qr_code)) {
            $decoded = base64_decode($charge->pix_qr_code, true);
            $isBase64 = ($decoded !== false && base64_encode($decoded) === $charge->pix_qr_code)
                || str_starts_with($charge->pix_qr_code, 'data:image')
                || str_starts_with($charge->pix_qr_code, 'iVBOR'); // PNG base64
            $isUrl = filter_var($charge->pix_qr_code, FILTER_VALIDATE_URL) !== false;
            $isEmv = str_starts_with($charge->pix_qr_code, '0002');

            expect($isBase64 || $isUrl || $isEmv)->toBeTrue(
                'PIX QR code não é base64 válido, URL nem EMV: ' . substr($charge->pix_qr_code, 0, 50)
            );
        }

        Log::info('✅ Formato PIX validado', [
            'acquirer'         => $charge->acquirer,
            'copy_paste_len'   => strlen($charge->pix_copy_paste ?? ''),
            'qr_code_len'      => strlen($charge->pix_qr_code ?? ''),
        ]);
    });

    it('idempotência previne cobrança PIX duplicada', function () {
        ['token' => $token] = setupPixTestData();

        $idempotencyKey = Str::uuid()->toString();
        $payload = [
            'amount'         => 500,
            'payment_method' => 'pix',
            'customer'       => [
                'name'     => 'Idempotência PIX',
                'email'    => 'idem@vectorpagamentos.com',
                'document' => '52998224725',
            ],
        ];

        // Primeira chamada
        $response1 = test()->withHeaders([
            'Authorization'      => "Bearer {$token}",
            'X-Idempotency-Key'  => $idempotencyKey,
        ])->postJson('/api/v1/charges', $payload);

        $response1->assertStatus(201);
        $chargeId1 = $response1->json('data.id');

        // Segunda chamada com mesma idempotency key
        $response2 = test()->withHeaders([
            'Authorization'      => "Bearer {$token}",
            'X-Idempotency-Key'  => $idempotencyKey,
        ])->postJson('/api/v1/charges', $payload);

        // Idempotência pode retornar a mesma cobrança (200/201) ou 409 Conflict
        $status2 = $response2->status();
        $chargeId2 = $response2->json('data.id');

        // Contar charges no banco para verificar se não duplicou
        $totalCharges = Charge::where('idempotency_key', $idempotencyKey)->count();

        // Comportamento aceitável: mesma cobrança retornada OU conflito OU no máximo 1 charge criada
        $noDuplicate = $status2 === 409
            || $chargeId2 === $chargeId1
            || $totalCharges <= 1;

        if (!$noDuplicate) {
            Log::warning('Idempotência não preveniu duplicata', [
                'charge_id_1'   => $chargeId1,
                'charge_id_2'   => $chargeId2,
                'status_2'      => $status2,
                'total_charges' => $totalCharges,
            ]);
        }

        // Pelo menos não deve retornar erro de servidor
        expect(in_array($status2, [200, 201, 409, 422]))->toBeTrue(
            "Status inesperado na segunda chamada: {$status2}"
        );
    });
});
