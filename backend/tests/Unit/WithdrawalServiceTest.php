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
    config(['acquirer_routing.engine_enabled' => false, 'acquirers.sharkhubsub.enabled' => false]);
    AcquirerConfig::where('acquirer_key', 'like', 'sharkhubsub%')->update(['enabled' => false]);

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

it('splits a withdrawal across multiple nominal balances when no single acquirer can cover it', function () {
    config([
        'acquirers.enki.enabled' => true,
        'acquirers.enki.public_key' => 'pk_test_enki',
        'acquirers.enki.secret_key' => 'sk_test_enki',
        'acquirers.enki.withdrawal_key' => 'wd_test_enki',
        'acquirers.enki.base_url' => 'https://api.enki-bank.com',
        'acquirers.blupay.enabled' => true,
        'acquirers.blupay.public_key' => 'pk_test_blupay',
        'acquirers.blupay.secret_key' => 'sk_test_blupay',
        'acquirers.blupay.base_url' => 'https://api.blupayip.io',
        'acquirers.payfort.enabled' => false,
        'acquirers.babylon.enabled' => false,
        'acquirers.witetec.enabled' => false,
        'acquirers.asaas.enabled' => false,
        'acquirers.huntersub.enabled' => false,
    ]);

    AcquirerConfig::updateOrCreate(
        ['acquirer_key' => 'enki'],
        [
            'display_name' => 'Enki Bank',
            'type' => 'gateway',
            'enabled' => true,
            'supported_methods' => ['pix_out'],
            'priority' => 0,
            'settings' => [],
        ]
    );

    AcquirerConfig::updateOrCreate(
        ['acquirer_key' => 'blupay'],
        [
            'display_name' => 'BluPay',
            'type' => 'gateway',
            'enabled' => true,
            'supported_methods' => ['pix_out'],
            'priority' => 1,
            'settings' => [],
        ]
    );

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
        'https://api.enki-bank.com/v1/transfers' => Http::sequence()
            ->push(['message' => 'Saldo insuficiente na nominal'], 422)
            ->push([
                'data' => [
                    'id' => 'tr-enki-part-1',
                    'amount' => 6000,
                    'status' => 'PROCESSING',
                ],
            ], 200),
        'https://api.enki-bank.com/v1/company/balance' => Http::response([
            'data' => [
                'available' => 6000,
                'reserved' => 0,
            ],
        ], 200),
        'https://api.blupayip.io/api/v1/balance' => Http::response([
            'data' => [
                'pix' => ['balance' => '40.00'],
                'pixBlocked' => ['balance' => '0.00'],
                'reserve' => ['balance' => '0.00'],
            ],
        ], 200),
        'https://api.blupayip.io/api/v1/transactions/pix-out' => Http::response([
            'data' => [
                'id' => 'tr-blupay-part-2',
                'amount' => 4000,
                'status' => 'processing',
            ],
        ], 201),
    ]);

    AcquirerFactory::reset();
    $service = new WithdrawalService();
    $result = $service->processWithdrawal($withdrawal);

    $withdrawal->refresh();
    $parts = $withdrawal->destination['transfer_parts'] ?? [];

    expect($result['success'])->toBeTrue();
    expect($withdrawal->status)->toBe('processing');
    expect($parts)->toHaveCount(2);
    expect($parts[0]['acquirer'])->toBe('enki');
    expect($parts[0]['amount'])->toBe(6000);
    expect($parts[1]['acquirer'])->toBe('blupay');
    expect($parts[1]['amount'])->toBe(4000);
});

it('sends a prioritized AkkadPag withdrawal once with its external withdrawal key', function () {
    config([
        'acquirer_routing.engine_enabled' => true,
        'acquirers.akkadpag.enabled' => true,
        'acquirers.akkadpag.public_key' => 'pk_test_akkad',
        'acquirers.akkadpag.secret_key' => 'sk_test_akkad',
        'acquirers.akkadpag.withdrawal_key' => 'wd_test_akkad',
        'acquirers.akkadpag.base_url' => 'https://api.akkadpag.com/v1',
    ]);

    AcquirerConfig::query()->update(['enabled' => false]);
    AcquirerConfig::updateOrCreate(
        ['acquirer_key' => 'akkadpag'],
        [
            'display_name' => 'AkkadPag',
            'type' => 'gateway',
            'enabled' => true,
            'supported_methods' => ['pix_out'],
            'priority' => 0,
            'settings' => [],
        ]
    );

    Http::fake([
        'https://api.akkadpag.com/v1/company/details' => Http::response([
            'data' => ['fees' => ['withdraw_api' => ['fixed' => 25, 'percent' => 1]]],
        ]),
        'https://api.akkadpag.com/v1/transfers' => Http::response([
            'data' => ['id' => (string) Str::uuid(), 'status' => 'PROCESSING'],
        ], 201),
    ]);

    $withdrawal = Withdrawal::create([
        'id' => Str::uuid()->toString(),
        'account_id' => $this->account->id,
        'user_id' => $this->user->id,
        'amount' => 10000,
        'fee' => 500,
        'net_amount' => 9500,
        'status' => 'pending',
        'method' => 'pix',
        'destination' => [
            'type' => 'pix',
            'pix_key' => '12345678900',
            'pix_type' => 'cpf',
        ],
    ]);

    AcquirerFactory::reset();
    $service = new WithdrawalService();
    $result = $service->processWithdrawal($withdrawal);
    $withdrawal->refresh();

    expect($result['success'])->toBeTrue();
    expect($result['acquirer'])->toBe('akkadpag');
    expect($withdrawal->status)->toBe('processing');
    expect($withdrawal->acquirer)->toBe('akkadpag');
    expect($withdrawal->acquirer_transfer_id)->not->toBeEmpty();

    Http::assertSent(function ($request): bool {
        return $request->url() === 'https://api.akkadpag.com/v1/transfers'
            && $request->hasHeader('x-withdrawal-key', 'wd_test_akkad')
            && $request->data()['amount'] === 9622
            && $request->data()['pix_key'] === '12345678900'
            && $request->data()['pix_key_type'] === 'CPF';
    });
});

it('does not retry an ambiguous AkkadPag withdrawal or release its reserved funds', function () {
    config([
        'acquirer_routing.engine_enabled' => true,
        'acquirers.akkadpag.enabled' => true,
        'acquirers.akkadpag.public_key' => 'pk_test_akkad',
        'acquirers.akkadpag.secret_key' => 'sk_test_akkad',
        'acquirers.akkadpag.withdrawal_key' => 'wd_test_akkad',
        'acquirers.akkadpag.base_url' => 'https://api.akkadpag.com/v1',
    ]);

    AcquirerConfig::query()->update(['enabled' => false]);
    AcquirerConfig::updateOrCreate(
        ['acquirer_key' => 'akkadpag'],
        [
            'display_name' => 'AkkadPag',
            'type' => 'gateway',
            'enabled' => true,
            'supported_methods' => ['pix_out'],
            'priority' => 0,
            'settings' => [],
        ]
    );

    Http::fake([
        'https://api.akkadpag.com/v1/company/details' => Http::response([
            'data' => ['fees' => ['withdraw_api' => ['fixed' => 25, 'percent' => 1]]],
        ]),
        'https://api.akkadpag.com/v1/transfers' => Http::response(['message' => 'Internal server error'], 503),
    ]);

    $withdrawal = Withdrawal::create([
        'id' => Str::uuid()->toString(),
        'account_id' => $this->account->id,
        'user_id' => $this->user->id,
        'amount' => 10000,
        'fee' => 500,
        'net_amount' => 9500,
        'status' => 'pending',
        'method' => 'pix',
        'destination' => [
            'type' => 'pix',
            'pix_key' => '12345678900',
            'pix_type' => 'cpf',
        ],
    ]);

    AcquirerFactory::reset();
    $service = new WithdrawalService();
    $firstResult = $service->processWithdrawal($withdrawal);
    $withdrawal->refresh();
    $secondResult = $service->processWithdrawal($withdrawal);
    $withdrawal->refresh();

    expect($firstResult['ambiguous'])->toBeTrue();
    expect($secondResult['ambiguous'])->toBeTrue();
    expect($withdrawal->status)->toBe('processing');
    expect($withdrawal->acquirer_transfer_id)->toBeNull();
    expect($withdrawal->destination['_akkadpag_dispatch_started_at'])->not->toBeEmpty();
    expect(Http::recorded())->toHaveCount(2);
});

it('reconciles an AkkadPag transfer using the status lookup endpoint', function () {
    config([
        'acquirer_routing.engine_enabled' => true,
        'acquirers.akkadpag.enabled' => true,
        'acquirers.akkadpag.public_key' => 'pk_test_akkad',
        'acquirers.akkadpag.secret_key' => 'sk_test_akkad',
        'acquirers.akkadpag.withdrawal_key' => 'wd_test_akkad',
        'acquirers.akkadpag.base_url' => 'https://api.akkadpag.com/v1',
    ]);

    AcquirerConfig::query()->update(['enabled' => false]);
    AcquirerConfig::updateOrCreate(
        ['acquirer_key' => 'akkadpag'],
        [
            'display_name' => 'AkkadPag',
            'type' => 'gateway',
            'enabled' => true,
            'supported_methods' => ['pix_out'],
            'priority' => 0,
            'settings' => [],
        ]
    );

    $transferId = Str::uuid()->toString();
    Http::fake([
        "https://api.akkadpag.com/v1/transfers/{$transferId}" => Http::response([
            'data' => ['id' => $transferId, 'status' => 'COMPLETED'],
        ]),
    ]);

    $withdrawal = Withdrawal::create([
        'id' => Str::uuid()->toString(),
        'account_id' => $this->account->id,
        'user_id' => $this->user->id,
        'amount' => 10000,
        'fee' => 500,
        'net_amount' => 9500,
        'status' => 'processing',
        'method' => 'pix',
        'acquirer' => 'akkadpag',
        'acquirer_transfer_id' => $transferId,
        'destination' => ['type' => 'pix', 'pix_key' => '12345678900', 'pix_type' => 'cpf'],
    ]);

    AcquirerFactory::reset();
    $results = (new WithdrawalService())->syncProcessingWithdrawals();

    expect($results['checked'])->toBe(1);
    expect($results['completed'])->toBe(1);
    expect($withdrawal->fresh()->status)->toBe('completed');
    Http::assertSent(fn ($request) => $request->url() === "https://api.akkadpag.com/v1/transfers/{$transferId}");
});
