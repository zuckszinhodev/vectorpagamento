<?php

declare(strict_types=1);

use App\Domain\Acquirer\Entities\Acquirer;
use App\Models\Account;
use App\Models\AccountAcquirer;
use App\Models\AcquirerConfig;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\AcquirerFactory;
use App\Services\WithdrawalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    AcquirerFactory::reset();

    $this->account = Account::create([
        'id' => Str::uuid()->toString(),
        'name' => 'Seller Withdrawal',
        'document' => '12345678900',
        'document_type' => 'cpf',
        'email' => 'withdrawal-account@test.com',
        'status' => 'active',
    ]);

    $this->user = User::create([
        'id' => Str::uuid()->toString(),
        'account_id' => $this->account->id,
        'email' => 'withdrawal-user@test.com',
        'password' => bcrypt('password'),
        'name' => 'Withdrawal User',
    ]);
});

afterEach(function () {
    foreach (['payfort', 'blupay', 'enki', 'babylon', 'huntersub'] as $name) {
        Cache::forget("circuit_breaker:acquirer:{$name}:state");
        Cache::forget("circuit_breaker:acquirer:{$name}:failures");
        Cache::forget("circuit_breaker:acquirer:{$name}:opened_at");
    }

    AcquirerFactory::reset();
});

it('uses the global admin priority for pix out even when the account links payfort', function () {
    config([
        'acquirers.enki.enabled' => true,
        'acquirers.enki.public_key' => 'pk_test_enki',
        'acquirers.enki.secret_key' => 'sk_test_enki',
        'acquirers.enki.withdrawal_key' => 'wd_test_enki',
        'acquirers.enki.base_url' => 'https://api.enki-bank.com',
        'acquirers.payfort.enabled' => true,
        'acquirers.payfort.api_key' => 'pk_test_payfort',
        'acquirers.payfort.api_secret' => 'sk_test_payfort',
        'acquirers.payfort.base_url' => 'https://api.payfortbank.com',
        'acquirers.blupay.enabled' => false,
        'acquirers.babylon.enabled' => false,
        'acquirers.witetec.enabled' => false,
        'acquirers.asaas.enabled' => false,
        'acquirers.huntersub.enabled' => false,
    ]);

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

    AccountAcquirer::create([
        'account_id' => $this->account->id,
        'acquirer_id' => $payfort->id,
        'credentials' => encrypt(json_encode([
            'api_key' => 'account-payfort',
            'api_secret' => 'account-secret',
        ])),
        'priority' => 0,
        'is_active' => true,
        'settings' => [],
    ]);

    AcquirerFactory::reset();
    $service = new WithdrawalService();

    $withdrawal = Withdrawal::create([
        'id' => Str::uuid()->toString(),
        'account_id' => $this->account->id,
        'user_id' => $this->user->id,
        'amount' => 10000,
        'fee' => 0,
        'net_amount' => 10000,
        'status' => 'pending',
        'method' => 'pix',
        'destination' => [
            'type' => 'pix',
            'pix_key' => '12345678900',
            'pix_type' => 'cpf',
        ],
    ]);

    Http::fake([
        'https://api.enki-bank.com/v1/transfers' => Http::response([
            'data' => [
                'id' => 'tr-enki-1',
                'amount' => 10000,
                'net_amount' => 9900,
                'fee' => 100,
                'status' => 'PROCESSING',
            ],
        ], 200),
        'https://api.payfortbank.com/api/v1/pix/out/pixkey' => Http::response([
            'data' => [
                'id' => 'tr-payfort-1',
                'amount' => 10000,
                'netAmount' => 9900,
                'status' => 'queued',
            ],
        ], 200),
    ]);

    expect(AcquirerFactory::instance()->getPixOutAcquirerOrder())->toBe(['enki', 'payfort']);

    $result = $service->processWithdrawal($withdrawal);

    $withdrawal->refresh();

    expect($result['success'])->toBeTrue();
    expect($result['acquirer'])->toBe('enki');
    expect($result['transfer_id'])->toBe('tr-enki-1');
    expect($withdrawal->status)->toBe('processing');
    expect($withdrawal->acquirer)->toBe('enki');
    expect($withdrawal->acquirer_transfer_id)->toBe('tr-enki-1');

    Http::assertSent(fn ($request) => $request->url() === 'https://api.enki-bank.com/v1/transfers');
    Http::assertNotSent(fn ($request) => $request->url() === 'https://api.payfortbank.com/api/v1/pix/out/pixkey');
});

it('returns a detailed error when pix out acquirers are skipped', function () {
    config([
        'acquirers.enki.enabled' => true,
        'acquirers.enki.public_key' => 'pk_test_enki',
        'acquirers.enki.secret_key' => 'sk_test_enki',
        'acquirers.enki.withdrawal_key' => 'wd_test_enki',
        'acquirers.enki.base_url' => 'https://api.enki-bank.com',
        'acquirers.payfort.enabled' => false,
        'acquirers.blupay.enabled' => false,
        'acquirers.babylon.enabled' => false,
        'acquirers.witetec.enabled' => false,
        'acquirers.asaas.enabled' => false,
        'acquirers.huntersub.enabled' => false,
    ]);

    Cache::put('circuit_breaker:acquirer:enki:state', 'open', now()->addMinutes(5));
    Cache::put('circuit_breaker:acquirer:enki:opened_at', time(), now()->addMinutes(5));

    $withdrawal = Withdrawal::create([
        'id' => Str::uuid()->toString(),
        'account_id' => $this->account->id,
        'user_id' => $this->user->id,
        'amount' => 10000,
        'fee' => 0,
        'net_amount' => 10000,
        'status' => 'pending',
        'method' => 'pix',
        'destination' => [
            'type' => 'pix',
            'pix_key' => '12345678900',
            'pix_type' => 'cpf',
        ],
    ]);

    Http::fake();

    $service = new WithdrawalService();
    $result = $service->processWithdrawal($withdrawal);

    $withdrawal->refresh();

    expect($result['success'])->toBeFalse();
    expect($result['error'])->toContain('Nenhuma tentativa de PIX OUT executada');
    expect($result['error'])->toContain('Circuit breaker aberto para enki');
    expect($withdrawal->status)->toBe('failed');
    expect((string) $withdrawal->failure_reason)->toContain('Circuit breaker aberto para enki');

    Http::assertNothingSent();
});