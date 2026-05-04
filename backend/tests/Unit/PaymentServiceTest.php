<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\AccountAcquirer;
use App\Models\AccountWallet;
use App\Models\AcquirerConfig;
use App\Models\Charge;
use App\Models\Transaction;
use App\Services\AcquirerFactory;
use App\Services\PaymentService;
use App\Domain\Acquirer\Entities\Acquirer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    AcquirerFactory::reset();

    $this->account = Account::create([
        'id'            => Str::uuid()->toString(),
        'name'          => 'Test Account',
        'document'      => '12345678900',
        'document_type' => 'cpf',
        'email'         => 'account@test.com',
        'status'        => 'active',
    ]);

    $this->service = new PaymentService();
});

afterEach(function () {
    AcquirerFactory::reset();
});

it('checks if service is configured returns bool', function () {
    $result = $this->service->isConfigured();

    expect($result)->toBeBool();
});

it('returns active acquirer as string', function () {
    $result = $this->service->getActiveAcquirer();

    expect($result)->toBeString();
});

it('checks turbos configuration returns bool', function () {
    $result = $this->service->isTurbosConfigured();

    expect($result)->toBeBool();
});

it('checks payfort configuration returns bool', function () {
    $result = $this->service->isPayFortConfigured();

    expect($result)->toBeBool();
});

it('checks blupay configuration returns bool', function () {
    $result = $this->service->isBluPayConfigured();

    expect($result)->toBeBool();
});

it('has processPayment method', function () {
    expect(method_exists($this->service, 'processPayment'))->toBeTrue();
});

it('marks an expired pix charge as approved when a completed webhook arrives late', function () {
    AccountWallet::create([
        'id' => Str::uuid()->toString(),
        'account_id' => $this->account->id,
        'available_balance' => 0,
        'pending_balance' => 0,
        'blocked_balance' => 0,
        'currency' => 'BRL',
    ]);

    $charge = Charge::create([
        'id' => Str::uuid()->toString(),
        'account_id' => $this->account->id,
        'amount' => 10000,
        'currency' => 'BRL',
        'payment_method' => 'pix',
        'status' => 'expired',
        'acquirer' => 'babylon',
        'acquirer_transaction_id' => 'txn-old-expired',
        'idempotency_key' => Str::random(32),
        'customer' => [
            'name' => 'Cliente PIX',
            'email' => 'pix@example.com',
            'document' => '12345678900',
        ],
        'expires_at' => now()->subMinutes(10),
        'failure_reason' => 'Tempo de pagamento expirado',
    ]);

    $acquirer = Acquirer::firstOrCreate(
        ['slug' => 'babylon'],
        [
            'name' => 'Banco Babylon',
            'is_active' => true,
            'supported_methods' => ['pix'],
            'settings' => [],
        ]
    );

    Transaction::create([
        'id' => Str::uuid()->toString(),
        'charge_id' => $charge->id,
        'acquirer_id' => $acquirer->id,
        'type' => 'authorization',
        'status' => 'pending',
        'amount' => $charge->amount,
        'net_amount' => $charge->amount,
    ]);

    $this->service->handleWebhookPayment($charge, [
        'id' => 'txn-late-paid',
        'status' => 'completed',
    ]);

    $charge->refresh();
    $wallet = AccountWallet::where('account_id', $this->account->id)->first();

    expect($charge->status)->toBe('approved');
    expect($charge->paid_at)->not->toBeNull();
    expect($charge->acquirer_transaction_id)->toBe('txn-late-paid');
    expect((int) $charge->net_amount)->toBeGreaterThan(0);
    expect((int) $wallet->available_balance)->toBe((int) $charge->net_amount);

    $this->assertDatabaseHas('transactions', [
        'charge_id' => $charge->id,
        'type' => 'capture',
        'status' => 'approved',
    ]);
});

it('marks an expired enki pix charge as approved when sync finds a late payment', function () {
    config([
        'acquirers.enki.enabled' => true,
        'acquirers.enki.public_key' => 'pk_test_enki',
        'acquirers.enki.secret_key' => 'sk_test_enki',
        'acquirers.enki.base_url' => 'https://api.enki-bank.com',
    ]);

    AcquirerFactory::reset();
    $this->service = new PaymentService();

    AccountWallet::create([
        'id' => Str::uuid()->toString(),
        'account_id' => $this->account->id,
        'available_balance' => 0,
        'pending_balance' => 0,
        'blocked_balance' => 0,
        'currency' => 'BRL',
    ]);

    $charge = Charge::create([
        'id' => Str::uuid()->toString(),
        'account_id' => $this->account->id,
        'amount' => 10000,
        'currency' => 'BRL',
        'payment_method' => 'pix',
        'status' => 'expired',
        'acquirer' => 'enki',
        'acquirer_transaction_id' => 'txn-enki-expired',
        'idempotency_key' => Str::random(32),
        'customer' => [
            'name' => 'Cliente PIX Enki',
            'email' => 'pix-enki@example.com',
            'document' => '12345678900',
        ],
        'expires_at' => now()->subMinutes(5),
        'failure_reason' => 'Tempo de pagamento expirado',
    ]);

    $acquirer = Acquirer::firstOrCreate(
        ['slug' => 'enki'],
        [
            'name' => 'Enki Bank',
            'is_active' => true,
            'supported_methods' => ['pix'],
            'settings' => [],
        ]
    );

    Transaction::create([
        'id' => Str::uuid()->toString(),
        'charge_id' => $charge->id,
        'acquirer_id' => $acquirer->id,
        'type' => 'authorization',
        'status' => 'pending',
        'amount' => $charge->amount,
        'net_amount' => $charge->amount,
        'acquirer_tid' => $charge->acquirer_transaction_id,
    ]);

    Http::fake([
        'https://api.enki-bank.com/v1/transactions/*' => Http::response([
            'data' => [
                'id' => $charge->acquirer_transaction_id,
                'status' => 'PAID',
                'amount' => $charge->amount,
                'fee' => 0,
                'net_amount' => $charge->amount,
                'paid_at' => now()->toIso8601String(),
                'pix' => [
                    'end_to_end' => 'E12345678901234567890123456789012',
                ],
            ],
        ], 200),
    ]);

    $result = $this->service->syncChargeStatus($charge);

    $charge->refresh();
    $wallet = AccountWallet::where('account_id', $this->account->id)->first();

    expect($result['success'])->toBeTrue();
    expect($result['status'])->toBe('approved');
    expect($charge->status)->toBe('approved');
    expect($charge->paid_at)->not->toBeNull();
    expect($charge->pix_end2end_id)->toBe('E12345678901234567890123456789012');
    expect((int) $wallet->available_balance)->toBeGreaterThan(0);

    $this->assertDatabaseHas('transactions', [
        'charge_id' => $charge->id,
        'type' => 'capture',
        'status' => 'approved',
    ]);
});

it('fills a fallback customer phone when generating enki pix', function () {
    config([
        'acquirers.enki.enabled' => true,
        'acquirers.enki.public_key' => 'pk_test_enki',
        'acquirers.enki.secret_key' => 'sk_test_enki',
        'acquirers.enki.base_url' => 'https://api.enki-bank.com',
    ]);

    AcquirerFactory::reset();
    $this->service = new PaymentService();

    $charge = Charge::create([
        'id' => Str::uuid()->toString(),
        'account_id' => $this->account->id,
        'amount' => 5000,
        'currency' => 'BRL',
        'payment_method' => 'pix',
        'status' => 'pending',
        'idempotency_key' => Str::random(32),
        'customer' => [
            'name' => 'Cliente Sem Telefone',
            'email' => 'sem-telefone@example.com',
            'document' => '12345678900',
        ],
        'description' => 'Teste Enki sem telefone',
    ]);

    $capturedPhone = null;

    Http::fake([
        'https://api.enki-bank.com/v1/transactions' => function ($request) use (&$capturedPhone) {
            $payload = $request->data();
            $capturedPhone = $payload['customer']['phone'] ?? null;

            return Http::response([
                'id' => 'txn-enki-phone-fallback',
                'status' => 'WAITING_PAYMENT',
                'pix' => [
                    'copy_paste' => '000201010212',
                    'expires_at' => now()->addMinutes(30)->toIso8601String(),
                ],
            ], 201);
        },
    ]);

    $result = $this->service->generatePixViaEnki($charge);

    expect($result['success'])->toBeTrue();
    expect($capturedPhone)->toBe('11999999999');
    expect($charge->fresh()->acquirer)->toBe('enki');
});

it('uses the global admin priority for pix even when the account links payfort', function () {
    config([
        'acquirers.enki.enabled' => true,
        'acquirers.enki.public_key' => 'pk_test_enki',
        'acquirers.enki.secret_key' => 'sk_test_enki',
        'acquirers.enki.base_url' => 'https://api.enki-bank.com',
        'acquirers.payfort.enabled' => true,
        'acquirers.payfort.api_key' => 'pk_test_payfort',
        'acquirers.payfort.api_secret' => 'sk_test_payfort',
        'acquirers.blupay.enabled' => false,
        'acquirers.babylon.enabled' => false,
        'acquirers.witetec.enabled' => false,
        'acquirers.asaas.enabled' => false,
        'acquirers.huntersub.enabled' => false,
    ]);

    AcquirerFactory::reset();
    $this->service = new PaymentService();

    $payfort = Acquirer::firstOrCreate(
        ['slug' => 'payfort'],
        [
            'name' => 'PayFort Bank',
            'is_active' => true,
            'supported_methods' => ['pix'],
            'settings' => [],
        ]
    );

    AcquirerConfig::updateOrCreate(
        ['acquirer_key' => 'enki'],
        [
            'display_name' => 'Enki Bank',
            'type' => 'gateway',
            'enabled' => true,
            'supported_methods' => ['pix'],
            'priority' => 0,
            'settings' => [],
        ]
    );

    AcquirerConfig::updateOrCreate(
        ['acquirer_key' => 'payfort'],
        [
            'display_name' => 'PayFort Bank',
            'type' => 'gateway',
            'enabled' => true,
            'supported_methods' => ['pix'],
            'priority' => 1,
            'settings' => [],
        ]
    );

    AccountAcquirer::create([
        'account_id' => $this->account->id,
        'acquirer_id' => $payfort->id,
        'credentials' => encrypt(json_encode(['api_key' => 'account-payfort', 'api_secret' => 'account-secret'])),
        'priority' => 0,
        'is_active' => true,
        'settings' => [],
    ]);

    $charge = Charge::create([
        'id' => Str::uuid()->toString(),
        'account_id' => $this->account->id,
        'amount' => 5000,
        'currency' => 'BRL',
        'payment_method' => 'pix',
        'status' => 'pending',
        'idempotency_key' => Str::random(32),
        'customer' => [
            'name' => 'Cliente Prioridade Global',
            'email' => 'global-priority@example.com',
            'document' => '12345678900',
            'phone' => '11999999999',
        ],
        'description' => 'Teste prioridade global',
    ]);

    Http::fake([
        'https://api.enki-bank.com/v1/transactions' => Http::response([
            'id' => 'txn-global-priority-enki',
            'status' => 'WAITING_PAYMENT',
            'pix' => [
                'copy_paste' => '000201010212',
                'expires_at' => now()->addMinutes(30)->toIso8601String(),
            ],
        ], 201),
    ]);

    $result = $this->service->processPayment($charge);

    expect($result['success'])->toBeTrue();
    expect($result['acquirer'])->toBe('enki');
    expect($charge->fresh()->acquirer)->toBe('enki');
});

it('uses a 24 hour expiration window for fallback pix qr codes', function () {
    $charge = Charge::create([
        'id' => Str::uuid()->toString(),
        'account_id' => $this->account->id,
        'amount' => 5000,
        'currency' => 'BRL',
        'payment_method' => 'pix',
        'status' => 'pending',
        'idempotency_key' => Str::random(32),
        'customer' => [
            'name' => 'Cliente Mock',
            'email' => 'mock@example.com',
            'document' => '12345678900',
        ],
    ]);

    $reflection = new ReflectionMethod(PaymentService::class, 'generateMockPaymentData');
    $reflection->setAccessible(true);

    $result = $reflection->invoke($this->service, $charge);

    $charge->refresh();
    $expiresInHours = now()->diffInHours($charge->expires_at);

    expect($result['success'])->toBeTrue();
    expect($result['mock'])->toBeTrue();
    expect($expiresInHours)->toBeGreaterThanOrEqual(23);
});
