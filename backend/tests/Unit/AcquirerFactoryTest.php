<?php

declare(strict_types=1);

use App\Domain\Acquirer\Entities\Acquirer;
use App\Models\Account;
use App\Models\AccountAcquirer;
use App\Models\AcquirerConfig;
use App\Services\AcquirerFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    AcquirerFactory::reset();
});

afterEach(function () {
    AcquirerFactory::reset();
});

it('returns singleton instance', function () {
    $a = AcquirerFactory::instance();
    $b = AcquirerFactory::instance();

    expect($a)->toBe($b);
});

it('resets singleton on reset call', function () {
    $a = AcquirerFactory::instance();
    AcquirerFactory::reset();
    $b = AcquirerFactory::instance();

    expect($a)->not->toBe($b);
});

it('checks turbos configured returns bool', function () {
    expect(AcquirerFactory::isTurbosConfigured())->toBeBool();
});

it('checks payfort configured returns bool', function () {
    expect(AcquirerFactory::isPayFortConfigured())->toBeBool();
});

it('checks blupay configured returns bool', function () {
    expect(AcquirerFactory::isBluPayConfigured())->toBeBool();
});

it('checks babylon configured returns bool', function () {
    expect(AcquirerFactory::isBabylonConfigured())->toBeBool();
});

it('returns active acquirer name as string', function () {
    $name = AcquirerFactory::instance()->getActiveAcquirerName();

    expect($name)->toBeString()->not->toBeEmpty();
});

it('returns pix acquirer order as array', function () {
    $order = AcquirerFactory::instance()->getPixAcquirerOrder('turbos');

    expect($order)->toBeArray();
});

// ── Helper to disable all acquirers in config ────────────────────────────────
function disableAllAcquirersInConfig(): void
{
    config([
        'acquirers.payfort.enabled'   => false,
        'acquirers.blupay.enabled'    => false,
        'acquirers.enki.enabled'      => false,
        'acquirers.babylon.enabled'   => false,
        'acquirers.witetec.enabled'   => false,
        'acquirers.asaas.enabled'     => false,
        'acquirers.huntersub.enabled' => false,
        'acquirers.turbos.enabled'    => false,
    ]);
}

// ── Bug 1: isXConfigured() must respect DB enabled flag ──────────────────────

it('isEnkiConfigured returns false when DB config is disabled even if env has credentials', function () {
    config([
        'acquirers.enki.enabled'    => true,
        'acquirers.enki.public_key' => 'pk_test',
        'acquirers.enki.secret_key' => 'sk_test',
    ]);

    AcquirerConfig::updateOrCreate(
        ['acquirer_key' => 'enki'],
        ['display_name' => 'Enki', 'type' => 'gateway', 'enabled' => false,
         'supported_methods' => ['pix'], 'priority' => 0, 'settings' => []]
    );

    AcquirerFactory::reset();
    expect(AcquirerFactory::isEnkiConfigured())->toBeFalse();
});

it('isBluPayConfigured returns false when DB config is disabled even if env has credentials', function () {
    config([
        'acquirers.blupay.enabled'    => true,
        'acquirers.blupay.secret_key' => 'sk_test',
        'acquirers.blupay.public_key' => 'pk_test',
    ]);

    AcquirerConfig::updateOrCreate(
        ['acquirer_key' => 'blupay'],
        ['display_name' => 'BluPay', 'type' => 'gateway', 'enabled' => false,
         'supported_methods' => ['pix'], 'priority' => 0, 'settings' => []]
    );

    AcquirerFactory::reset();
    expect(AcquirerFactory::isBluPayConfigured())->toBeFalse();
});

it('isAsaasConfigured returns false when DB config is disabled even if env has credentials', function () {
    config([
        'acquirers.asaas.enabled' => true,
        'acquirers.asaas.api_key' => 'api_test',
    ]);

    AcquirerConfig::updateOrCreate(
        ['acquirer_key' => 'asaas'],
        ['display_name' => 'Asaas', 'type' => 'gateway', 'enabled' => false,
         'supported_methods' => ['pix'], 'priority' => 0, 'settings' => []]
    );

    AcquirerFactory::reset();
    expect(AcquirerFactory::isAsaasConfigured())->toBeFalse();
});

it('isWitetecConfigured returns false when DB config is disabled even if env has credentials', function () {
    config([
        'acquirers.witetec.enabled' => true,
        'acquirers.witetec.api_key' => 'api_test',
    ]);

    AcquirerConfig::updateOrCreate(
        ['acquirer_key' => 'witetec'],
        ['display_name' => 'Witetec', 'type' => 'gateway', 'enabled' => false,
         'supported_methods' => ['pix'], 'priority' => 0, 'settings' => []]
    );

    AcquirerFactory::reset();
    expect(AcquirerFactory::isWitetecConfigured())->toBeFalse();
});

// ── Bug 2: appendConfiguredFallbacks must not add disabled acquirers ──────────

it('getActiveAcquirerName does not include disabled acquirers as fallback', function () {
    disableAllAcquirersInConfig();
    config([
        'acquirers.enki.enabled'    => true,
        'acquirers.enki.public_key' => 'pk_test',
        'acquirers.enki.secret_key' => 'sk_test',
    ]);

    // Only enki enabled in DB
    AcquirerConfig::updateOrCreate(
        ['acquirer_key' => 'enki'],
        ['display_name' => 'Enki', 'type' => 'gateway', 'enabled' => true,
         'supported_methods' => ['pix'], 'priority' => 0, 'settings' => []]
    );
    AcquirerConfig::updateOrCreate(
        ['acquirer_key' => 'payfort'],
        ['display_name' => 'PayFort', 'type' => 'gateway', 'enabled' => false,
         'supported_methods' => ['pix'], 'priority' => 1, 'settings' => []]
    );

    AcquirerFactory::reset();
    $order = AcquirerFactory::instance()->getPixAcquirerOrder('enki');

    expect($order)->not->toContain('payfort');
});

it('returns mock when all acquirers are disabled in DB', function () {
    disableAllAcquirersInConfig();

    foreach (['enki', 'payfort', 'blupay', 'asaas', 'witetec', 'huntersub', 'babylon'] as $key) {
        AcquirerConfig::updateOrCreate(
            ['acquirer_key' => $key],
            ['display_name' => $key, 'type' => 'gateway', 'enabled' => false,
             'supported_methods' => ['pix'], 'priority' => 0, 'settings' => []]
        );
    }

    AcquirerFactory::reset();
    $name = AcquirerFactory::instance()->getActiveAcquirerName('pix');

    // Should fall back to 'mock' since nothing is available
    expect($name)->toBe('mock');
});

// ── Bug 3: account-specific acquirer must be respected ───────────────────────

it('falls back to global enki when account acquirer points to a disabled payfort', function () {
    disableAllAcquirersInConfig();
    config([
        'acquirers.enki.enabled'    => true,
        'acquirers.enki.public_key' => 'pk_test_enki',
        'acquirers.enki.secret_key' => 'sk_test_enki',
    ]);

    $account = Account::create([
        'id' => Str::uuid()->toString(), 'name' => 'Seller Test',
        'document' => '12345678900', 'document_type' => 'cpf',
        'email' => 'seller@test.com', 'status' => 'active',
    ]);

    $payfort = Acquirer::firstOrCreate(
        ['slug' => 'payfort'],
        ['name' => 'PayFort Bank', 'is_active' => true, 'supported_methods' => ['pix'], 'settings' => []]
    );

    AcquirerConfig::updateOrCreate(
        ['acquirer_key' => 'payfort'],
        ['display_name' => 'PayFort', 'type' => 'gateway', 'enabled' => false,
         'supported_methods' => ['pix'], 'priority' => 1, 'settings' => []]
    );
    AcquirerConfig::updateOrCreate(
        ['acquirer_key' => 'enki'],
        ['display_name' => 'Enki', 'type' => 'gateway', 'enabled' => true,
         'supported_methods' => ['pix'], 'priority' => 0, 'settings' => []]
    );

    AccountAcquirer::create([
        'account_id' => $account->id, 'acquirer_id' => $payfort->id,
        'credentials' => encrypt(json_encode([])), 'priority' => 0,
        'is_active' => true, 'settings' => [],
    ]);

    AcquirerFactory::reset();
    $factory = AcquirerFactory::instance();

    // payfort is disabled globally → should fall back to enki
    expect($factory->getActiveAcquirerNameForAccount($account->id, 'pix'))->toBe('enki');
});

it('uses account-specific acquirer when it is enabled globally', function () {
    disableAllAcquirersInConfig();
    config([
        'acquirers.enki.enabled'          => true,
        'acquirers.enki.public_key'       => 'pk_test_enki',
        'acquirers.enki.secret_key'       => 'sk_test_enki',
        'acquirers.payfort.enabled'       => true,
        'acquirers.payfort.api_key'       => 'pk_test_payfort',
        'acquirers.payfort.api_secret'    => 'sk_test_payfort',
    ]);

    $account = Account::create([
        'id' => Str::uuid()->toString(), 'name' => 'Seller Account Priority',
        'document' => '98765432100', 'document_type' => 'cpf',
        'email' => 'seller-acct@test.com', 'status' => 'active',
    ]);

    $payfort = Acquirer::firstOrCreate(
        ['slug' => 'payfort'],
        ['name' => 'PayFort Bank', 'is_active' => true, 'supported_methods' => ['pix'], 'settings' => []]
    );

    // Global: enki has higher priority (0), payfort lower (1)
    AcquirerConfig::updateOrCreate(
        ['acquirer_key' => 'enki'],
        ['display_name' => 'Enki', 'type' => 'gateway', 'enabled' => true,
         'supported_methods' => ['pix'], 'priority' => 0, 'settings' => []]
    );
    AcquirerConfig::updateOrCreate(
        ['acquirer_key' => 'payfort'],
        ['display_name' => 'PayFort', 'type' => 'gateway', 'enabled' => true,
         'supported_methods' => ['pix'], 'priority' => 1, 'settings' => []]
    );

    // Account is specifically assigned to payfort
    AccountAcquirer::create([
        'account_id' => $account->id, 'acquirer_id' => $payfort->id,
        'credentials' => encrypt(json_encode(['api_key' => 'acct-key', 'api_secret' => 'acct-secret'])),
        'priority' => 0, 'is_active' => true, 'settings' => [],
    ]);

    AcquirerFactory::reset();
    $factory = AcquirerFactory::instance();

    // Account-specific payfort should win over global enki priority
    expect($factory->getActiveAcquirerNameForAccount($account->id, 'pix'))->toBe('payfort');
});

it('falls back to global priority when account has no acquirer assigned', function () {
    disableAllAcquirersInConfig();
    config([
        'acquirers.enki.enabled'    => true,
        'acquirers.enki.public_key' => 'pk_test_enki',
        'acquirers.enki.secret_key' => 'sk_test_enki',
    ]);

    $account = Account::create([
        'id' => Str::uuid()->toString(), 'name' => 'Seller No Acquirer',
        'document' => '11122233344', 'document_type' => 'cpf',
        'email' => 'seller-none@test.com', 'status' => 'active',
    ]);

    AcquirerConfig::updateOrCreate(
        ['acquirer_key' => 'enki'],
        ['display_name' => 'Enki', 'type' => 'gateway', 'enabled' => true,
         'supported_methods' => ['pix'], 'priority' => 0, 'settings' => []]
    );

    AcquirerFactory::reset();
    $factory = AcquirerFactory::instance();

    expect($factory->getActiveAcquirerNameForAccount($account->id, 'pix'))->toBe('enki');
});

it('resolves sharkhubsub account credentials from alias assignments', function () {
    $account = Account::create([
        'id' => Str::uuid()->toString(), 'name' => 'Seller SharkHub Alias',
        'document' => '55566677788', 'document_type' => 'cpf',
        'email' => 'seller-shark@test.com', 'status' => 'active',
    ]);

    $acquirer = Acquirer::firstOrCreate(
        ['slug' => 'sharkhubsub_vp1'],
        ['name' => 'Vectorpagamentos 1', 'is_active' => true, 'supported_methods' => ['pix'], 'settings' => []]
    );

    AcquirerConfig::updateOrCreate(
        ['acquirer_key' => 'sharkhubsub'],
        ['display_name' => 'SharkHubSub', 'type' => 'gateway', 'enabled' => true,
         'supported_methods' => ['pix'], 'priority' => 0, 'settings' => []]
    );

    AcquirerConfig::updateOrCreate(
        ['acquirer_key' => 'sharkhubsub_vp1'],
        ['display_name' => 'Vectorpagamentos 1', 'type' => 'gateway', 'enabled' => true,
         'supported_methods' => ['pix'], 'priority' => 1, 'settings' => []]
    );

    AccountAcquirer::create([
        'account_id' => $account->id,
        'acquirer_id' => $acquirer->id,
        'credentials' => encrypt(json_encode(['api_key' => 'acct-sharkhub-key'])),
        'priority' => 0,
        'is_active' => true,
        'settings' => [],
    ]);

    AcquirerFactory::reset();

    expect(AcquirerFactory::instance()->sharkhubsubForAccount($account->id))->not->toBeNull();
});

it('does not select sharkhub alias with broken credentials', function () {
    AcquirerConfig::updateOrCreate(
        ['acquirer_key' => 'sharkhubsub_vp2'],
        ['display_name' => 'Vectorpagamentos 2', 'type' => 'gateway', 'enabled' => true,
         'supported_methods' => ['pix'], 'priority' => 0, 'settings' => [], 'credentials' => 'invalid-payload']
    );

    AcquirerConfig::updateOrCreate(
        ['acquirer_key' => 'sharkhubsub_vp1'],
        ['display_name' => 'Vectorpagamentos 1', 'type' => 'gateway', 'enabled' => true,
         'supported_methods' => ['pix'], 'priority' => 1, 'settings' => [],
         'credentials' => ['api_key' => 'vp1-key', 'withdrawal_key' => 'vp1-withdrawal']]
    );

    Acquirer::firstOrCreate(
        ['slug' => 'sharkhubsub_vp1'],
        ['name' => 'Vectorpagamentos 1', 'is_active' => true, 'supported_methods' => ['pix'], 'settings' => []]
    );

    Acquirer::firstOrCreate(
        ['slug' => 'sharkhubsub_vp2'],
        ['name' => 'Vectorpagamentos 2', 'is_active' => true, 'supported_methods' => ['pix'], 'settings' => []]
    );

    AcquirerFactory::reset();

    expect(AcquirerFactory::instance()->getActiveAcquirerName('pix'))->toBe('sharkhubsub_vp1');
});
