<?php

/**
 * TurbosCash Integration Test
 * 
 * Tests real API calls to TurbosCash (https://api.turbos.cash)
 * using the credentials configured in .env.
 * 
 * These tests make REAL HTTP requests — they should be run manually,
 * not in CI/CD pipelines. They validate that:
 * 
 *  1. TurbosCash credentials are valid and the acquirer initializes
 *  2. PIX QR code generation works end-to-end (real API call)
 *  3. The response contains all required fields (EMV code, QR URL, external ID)
 *  4. The full PaymentService → TurbosCash flow works with a real charge
 *  5. Transaction status can be queried after creation
 */

use App\Infrastructure\Acquirers\TurbosCashAcquirer;
use App\Models\Account;
use App\Models\AccountWallet;
use App\Models\Charge;
use App\Models\User;
use App\Services\AcquirerFactory;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function getTurbosCredentials(): array
{
    return [
        'public_key'     => config('acquirers.turbos.public_key'),
        'secret_key'     => config('acquirers.turbos.secret_key'),
        'withdraw_key'   => config('acquirers.turbos.withdraw_key', ''),
        'base_url'       => config('acquirers.turbos.base_url', 'https://api.turbos.cash'),
        'webhook_secret' => config('acquirers.turbos.webhook_secret', ''),
        'webhook_url'    => config('acquirers.turbos.webhook_url') ?: config('app.url'),
    ];
}

function isTurbosConfigured(): bool
{
    return config('acquirers.turbos.enabled', false)
        && !empty(config('acquirers.turbos.public_key'))
        && !empty(config('acquirers.turbos.secret_key'));
}

function createTestAccount(): array
{
    $account = Account::create([
        'id'            => Str::uuid()->toString(),
        'name'          => 'TurbosCash Test Account',
        'document'      => '12345678000199',
        'document_type' => 'cnpj',
        'email'         => 'turbos-test@vectorpagamentos.com',
        'status'        => 'active',
    ]);

    $user = User::create([
        'id'         => Str::uuid()->toString(),
        'account_id' => $account->id,
        'name'       => 'TurbosCash Test User',
        'email'      => 'turbos-test-' . Str::random(6) . '@test.com',
        'password'   => bcrypt('password'),
        'role'       => 'admin',
        'status'     => 'active',
    ]);

    AccountWallet::create([
        'id'         => Str::uuid()->toString(),
        'account_id' => $account->id,
        'balance'    => 0,
        'currency'   => 'BRL',
    ]);

    return compact('account', 'user');
}

function createTestCharge(string $accountId, int $amountCents = 500): Charge
{
    return Charge::create([
        'id'              => Str::uuid()->toString(),
        'account_id'      => $accountId,
        'amount'          => $amountCents,
        'currency'        => 'BRL',
        'payment_method'  => 'pix',
        'status'          => 'pending',
        'description'     => 'Teste PIX TurbosCash',
        'idempotency_key' => Str::random(32),
        'customer'        => [
            'name'     => 'Teste Vector Pagamentos',
            'email'    => 'teste@vectorpagamentos.com',
            'document' => '12345678909',
        ],
    ]);
}

/*
|--------------------------------------------------------------------------
| 1. TurbosCash Configuration & Credentials
|--------------------------------------------------------------------------
*/

describe('TurbosCash Configuration', function () {

    it('has TurbosCash credentials configured in .env', function () {
        if (!isTurbosConfigured()) {
            test()->markTestSkipped('TurbosCash not configured in .env');
        }
        expect(config('acquirers.turbos.enabled'))->toBeTrue();
        expect(config('acquirers.turbos.public_key'))->not->toBeEmpty();
        expect(config('acquirers.turbos.secret_key'))->not->toBeEmpty();
        expect(config('acquirers.turbos.base_url'))->toBe('https://api.turbos.cash');
    });

    it('initializes TurbosCashAcquirer with ENV credentials', function () {
        if (!isTurbosConfigured()) {
            test()->markTestSkipped('TurbosCash not configured in .env');
        }

        $acquirer = new TurbosCashAcquirer(getTurbosCredentials());

        expect($acquirer)->toBeInstanceOf(TurbosCashAcquirer::class);
        expect($acquirer->getName())->toBe('Turbos Cash');
    });

    it('AcquirerFactory returns TurbosCash instance', function () {
        if (!isTurbosConfigured()) {
            test()->markTestSkipped('TurbosCash not configured in .env');
        }

        $factory = AcquirerFactory::instance();
        $turbos = $factory->turbos();

        expect($turbos)->not->toBeNull();
        expect($turbos)->toBeInstanceOf(TurbosCashAcquirer::class);
    });

    it('default acquirer is set to turbos', function () {
        expect(config('acquirers.default'))->toBe('turbos');
    });
});

/*
|--------------------------------------------------------------------------
| 2. TurbosCash PIX Generation — Real API Call
|--------------------------------------------------------------------------
*/

describe('TurbosCash PIX Generation (Real API)', function () {

    it('generates PIX QR code via TurbosCash API', function () {
        if (!isTurbosConfigured()) {
            test()->markTestSkipped('TurbosCash not configured in .env');
        }

        ['account' => $account] = createTestAccount();
        $charge = createTestCharge($account->id, 500); // R$ 5,00

        $acquirer = new TurbosCashAcquirer(getTurbosCredentials());
        $result = $acquirer->generatePixQrCode($charge);

        // Log full response for debugging
        dump('TurbosCash PIX Response:', $result);

        expect($result)->toBeArray();
        expect($result['success'])->toBeTrue('TurbosCash API returned error: ' . ($result['error'] ?? 'unknown'));
        expect($result['external_id'])->not->toBeEmpty('Missing external_id (transaction ID)');
        expect($result['pix_qr_code'])->not->toBeEmpty('Missing pix_qr_code (EMV/copy-paste string)');
    });

    it('PIX response contains QR code data (EMV string or image URL)', function () {
        if (!isTurbosConfigured()) {
            test()->markTestSkipped('TurbosCash not configured in .env');
        }

        ['account' => $account] = createTestAccount();
        $charge = createTestCharge($account->id, 1000); // R$ 10,00

        $acquirer = new TurbosCashAcquirer(getTurbosCredentials());
        $result = $acquirer->generatePixQrCode($charge);

        expect($result['success'])->toBeTrue('API error: ' . ($result['error'] ?? 'unknown'));

        // TurbosCash returns the EMV/copia-cola string in pix_qr_code.
        // pix_qr_code_url (image) may be null — the raw qrcode in data.pix is the primary output.
        $hasQrCode = !empty($result['pix_qr_code']);
        $hasQrUrl  = !empty($result['pix_qr_code_url']);

        expect($hasQrCode || $hasQrUrl)->toBeTrue(
            'Neither pix_qr_code nor pix_qr_code_url present in response'
        );

        // Validate EMV string is present and valid
        expect($result['pix_qr_code'])->toBeString();
        expect(strlen($result['pix_qr_code']))->toBeGreaterThan(50);
    });

    it('PIX EMV code starts with valid Pix prefix', function () {
        if (!isTurbosConfigured()) {
            test()->markTestSkipped('TurbosCash not configured in .env');
        }

        ['account' => $account] = createTestAccount();
        $charge = createTestCharge($account->id, 750); // R$ 7,50

        $acquirer = new TurbosCashAcquirer(getTurbosCredentials());
        $result = $acquirer->generatePixQrCode($charge);

        expect($result['success'])->toBeTrue('API error: ' . ($result['error'] ?? 'unknown'));

        $emv = $result['pix_qr_code'];
        expect($emv)->toBeString();
        expect(strlen($emv))->toBeGreaterThan(50, 'EMV code is suspiciously short');

        // BR Code PIX EMV always starts with "0002" (payload format indicator)
        expect(str_starts_with($emv, '0002'))->toBeTrue(
            'EMV does not start with valid Pix prefix. Got: ' . substr($emv, 0, 30)
        );
    });

    it('PIX response includes expiration date', function () {
        if (!isTurbosConfigured()) {
            test()->markTestSkipped('TurbosCash not configured in .env');
        }

        ['account' => $account] = createTestAccount();
        $charge = createTestCharge($account->id, 300); // R$ 3,00

        $acquirer = new TurbosCashAcquirer(getTurbosCredentials());
        $result = $acquirer->generatePixQrCode($charge);

        expect($result['success'])->toBeTrue('API error: ' . ($result['error'] ?? 'unknown'));
        expect($result['pix_expires_at'])->not->toBeNull('Missing pix_expires_at');
    });

    it('PIX response has waiting_payment status from TurbosCash', function () {
        if (!isTurbosConfigured()) {
            test()->markTestSkipped('TurbosCash not configured in .env');
        }

        ['account' => $account] = createTestAccount();
        $charge = createTestCharge($account->id, 500);

        $acquirer = new TurbosCashAcquirer(getTurbosCredentials());
        $result = $acquirer->generatePixQrCode($charge);

        expect($result['success'])->toBeTrue('API error: ' . ($result['error'] ?? 'unknown'));

        // TurbosCash returns waiting_payment which maps internally
        $status = $result['status'];
        expect(in_array($status, ['waiting_payment', 'pending']))->toBeTrue(
            'Unexpected status: ' . $status
        );
    });

    it('rejects PIX with zero amount', function () {
        if (!isTurbosConfigured()) {
            test()->markTestSkipped('TurbosCash not configured in .env');
        }

        ['account' => $account] = createTestAccount();
        $charge = createTestCharge($account->id, 0);

        $acquirer = new TurbosCashAcquirer(getTurbosCredentials());
        $result = $acquirer->generatePixQrCode($charge);

        // TurbosCash should reject zero-amount transactions
        expect($result['success'])->toBeFalse('Expected TurbosCash to reject 0 amount');
    });
});

/*
|--------------------------------------------------------------------------
| 3. Full PaymentService → TurbosCash Flow
|--------------------------------------------------------------------------
*/

describe('PaymentService TurbosCash Flow (Real API)', function () {

    it('processPayment routes PIX to TurbosCash and returns QR code', function () {
        if (!isTurbosConfigured()) {
            test()->markTestSkipped('TurbosCash not configured in .env');
        }

        ['account' => $account] = createTestAccount();
        $charge = createTestCharge($account->id, 500);

        $paymentService = app(PaymentService::class);
        $result = $paymentService->processPayment($charge);

        dump('PaymentService → TurbosCash result:', $result);

        expect($result)->toBeArray();
        expect($result['success'])->toBeTrue(
            'PaymentService failed: ' . ($result['error'] ?? 'unknown')
        );
        expect($result['acquirer'])->toBe('turbos', 'Payment was not routed to TurbosCash');
        expect($result['pix_qr_code'])->not->toBeEmpty('Missing PIX QR code');
        expect($result['pix_copy_paste'])->not->toBeEmpty('Missing PIX copy-paste');
        expect($result['transaction_id'])->not->toBeEmpty('Missing transaction ID');
    });

    it('charge is updated with acquirer data after processPayment', function () {
        if (!isTurbosConfigured()) {
            test()->markTestSkipped('TurbosCash not configured in .env');
        }

        ['account' => $account] = createTestAccount();
        $charge = createTestCharge($account->id, 800);

        $paymentService = app(PaymentService::class);
        $result = $paymentService->processPayment($charge);

        expect($result['success'])->toBeTrue('API error: ' . ($result['error'] ?? 'unknown'));

        // Reload charge from DB
        $charge->refresh();

        // May be turbos or fallback acquirer (payfort/blupay) depending on circuit breaker state
        expect(in_array($charge->acquirer, ['turbos', 'payfort', 'blupay', 'babylon']))->toBeTrue(
            'Unexpected acquirer: ' . $charge->acquirer
        );
        expect($charge->acquirer_transaction_id)->not->toBeEmpty();
        expect($charge->pix_qr_code)->not->toBeEmpty();
        expect($charge->pix_copy_paste)->not->toBeEmpty();
        expect($charge->external_id)->not->toBeEmpty();
        expect($charge->expires_at)->not->toBeNull();
    });

    it('creates a Transaction record after TurbosCash PIX', function () {
        if (!isTurbosConfigured()) {
            test()->markTestSkipped('TurbosCash not configured in .env');
        }

        ['account' => $account] = createTestAccount();
        $charge = createTestCharge($account->id, 600);

        $paymentService = app(PaymentService::class);
        $result = $paymentService->processPayment($charge);

        expect($result['success'])->toBeTrue('API error: ' . ($result['error'] ?? 'unknown'));

        // Check Transaction was created
        $transaction = \App\Models\Transaction::where('charge_id', $charge->id)->first();

        expect($transaction)->not->toBeNull('Transaction record not created');
        expect($transaction->type)->toBe('authorization');
        expect($transaction->status)->toBe('pending');
        expect($transaction->amount)->toBe(600);
        expect($transaction->acquirer_tid)->toBe($result['transaction_id']);
    });
});

/*
|--------------------------------------------------------------------------
| 4. TurbosCash Transaction Status Query
|--------------------------------------------------------------------------
*/

describe('TurbosCash Transaction Status (Real API)', function () {

    it('can query transaction status after PIX creation', function () {
        if (!isTurbosConfigured()) {
            test()->markTestSkipped('TurbosCash not configured in .env');
        }

        ['account' => $account] = createTestAccount();
        $charge = createTestCharge($account->id, 500);

        $acquirer = new TurbosCashAcquirer(getTurbosCredentials());
        $pixResult = $acquirer->generatePixQrCode($charge);

        expect($pixResult['success'])->toBeTrue('PIX creation failed: ' . ($pixResult['error'] ?? ''));

        $externalId = $pixResult['external_id'];

        // Query the transaction status
        $statusResult = $acquirer->getTransaction($externalId);

        dump('TurbosCash status query:', $statusResult);

        expect($statusResult)->toBeArray();
        expect($statusResult['success'])->toBeTrue(
            'Status query failed: ' . ($statusResult['error'] ?? 'unknown')
        );
    });
});

/*
|--------------------------------------------------------------------------
| 5. API Endpoint → TurbosCash (Full HTTP Test)
|--------------------------------------------------------------------------
*/

describe('API Charge Endpoint → TurbosCash (Real API)', function () {

    it('POST /api/v1/charges creates charge with real TurbosCash PIX', function () {
        if (!isTurbosConfigured()) {
            test()->markTestSkipped('TurbosCash not configured in .env');
        }

        ['account' => $account, 'user' => $user] = createTestAccount();

        $token = auth('api')->login($user);

        $response = test()->withHeaders([
            'Authorization' => "Bearer {$token}",
            'Accept'        => 'application/json',
        ])->postJson('/api/v1/charges', [
            'amount'         => 500, // R$ 5,00 (centavos, min 100)
            'payment_method' => 'pix',
            'customer'       => [
                'name'     => 'Teste Real TurbosCash',
                'email'    => 'teste.real@vectorpagamentos.com',
                'document' => '12345678909',
            ],
        ]);

        dump('API /charges response:', $response->json());

        $response->assertStatus(201);

        $json = $response->json();
        expect($json['data']['payment_method'])->toBe('pix');

        // Should have PIX data from TurbosCash
        $pixQrCode = $json['data']['pix_qr_code'] ?? $json['data']['pix_copy_paste'] ?? null;
        expect($pixQrCode)->not->toBeEmpty('API response missing PIX QR code data');
    });
});
