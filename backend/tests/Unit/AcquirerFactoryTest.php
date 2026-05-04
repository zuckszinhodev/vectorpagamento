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

it('falls back to global enki when account acquirer points to a disabled payfort', function () {
    config([
        'acquirers.default' => 'enki',
        'acquirers.enki.enabled' => true,
        'acquirers.enki.public_key' => 'pk_test_enki',
        'acquirers.enki.secret_key' => 'sk_test_enki',
        'acquirers.payfort.enabled' => false,
        'acquirers.blupay.enabled' => false,
        'acquirers.babylon.enabled' => false,
        'acquirers.witetec.enabled' => false,
        'acquirers.asaas.enabled' => false,
        'acquirers.huntersub.enabled' => false,
    ]);

    $account = Account::create([
        'id' => Str::uuid()->toString(),
        'name' => 'Seller Test',
        'document' => '12345678900',
        'document_type' => 'cpf',
        'email' => 'seller@test.com',
        'status' => 'active',
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
            'enabled' => false,
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
        'account_id' => $account->id,
        'acquirer_id' => $payfort->id,
        'credentials' => encrypt(json_encode([])),
        'priority' => 0,
        'is_active' => true,
        'settings' => [],
    ]);

    AcquirerFactory::reset();
    $factory = AcquirerFactory::instance();

    expect($factory->getActiveAcquirerNameForAccount($account->id, 'pix'))->toBe('enki');
    expect($factory->getPixAcquirerOrderForAccount($account->id, 'enki'))->toBe(['enki']);
});

it('uses the global admin priority even when the account links another active pix acquirer', function () {
    config([
        'acquirers.enki.enabled' => true,
        'acquirers.enki.public_key' => 'pk_test_enki',
        'acquirers.enki.secret_key' => 'sk_test_enki',
        'acquirers.payfort.enabled' => true,
        'acquirers.payfort.api_key' => 'pk_test_payfort',
        'acquirers.payfort.api_secret' => 'sk_test_payfort',
        'acquirers.blupay.enabled' => false,
        'acquirers.babylon.enabled' => false,
        'acquirers.witetec.enabled' => false,
        'acquirers.asaas.enabled' => false,
        'acquirers.huntersub.enabled' => false,
    ]);

    $account = Account::create([
        'id' => Str::uuid()->toString(),
        'name' => 'Seller Global Priority',
        'document' => '12345678900',
        'document_type' => 'cpf',
        'email' => 'seller-global@test.com',
        'status' => 'active',
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
        'account_id' => $account->id,
        'acquirer_id' => $payfort->id,
        'credentials' => encrypt(json_encode(['api_key' => 'account-payfort', 'api_secret' => 'account-secret'])),
        'priority' => 0,
        'is_active' => true,
        'settings' => [],
    ]);

    AcquirerFactory::reset();
    $factory = AcquirerFactory::instance();

    expect($factory->getActiveAcquirerNameForAccount($account->id, 'pix'))->toBe('enki');
    expect(array_slice($factory->getPixAcquirerOrderForAccount($account->id, 'enki'), 0, 2))->toBe(['enki', 'payfort']);
});
