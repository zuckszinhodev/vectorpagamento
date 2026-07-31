<?php

declare(strict_types=1);

/**
 * Proves the panel-only credential flow: an operator types the token in the
 * admin panel, it is encrypted into the database, and the routing engine uses
 * it to process a payment — with nothing in .env and no config entry.
 */

use App\Domain\Routing\Contracts\AcquirerCredentialRepositoryInterface;
use App\Domain\Routing\Contracts\ProviderRegistryInterface;
use App\Models\AcquirerConfig;
use App\Models\AcquirerRoutingProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function acquirerWithoutEnv(string $key, array $supportedMethods = ['pix']): AcquirerConfig
{
    AcquirerConfig::withTrashed()->where('acquirer_key', $key)->forceDelete();

    $config = AcquirerConfig::create([
        'id' => (string) Str::uuid(),
        'acquirer_key' => $key,
        'display_name' => ucfirst($key),
        'type' => 'pix',
        'enabled' => true,
        'supported_methods' => $supportedMethods,
        'priority' => 5,
    ]);

    AcquirerRoutingProfile::create([
        'id' => (string) Str::uuid(),
        'acquirer_config_id' => $config->id,
        'acquirer_key' => $key,
        'routing_enabled' => true,
        'priority' => 5,
        'weight' => 1,
    ]);

    return $config;
}

beforeEach(function () {
    Cache::flush();
    app(ProviderRegistryInterface::class)->flush();
    $this->credentials = app(AcquirerCredentialRepositoryInterface::class);
});

it('resolves an acquirer using only credentials typed in the panel', function () {
    // Nothing in .env / config for this acquirer.
    config(['acquirers.witetec' => null]);

    $config = acquirerWithoutEnv('witetec');

    // What the panel POST does.
    $this->credentials->store($config, ['api_key' => 'token_digitado_no_painel_9876']);

    $provider = app(ProviderRegistryInterface::class)->resolve('witetec');

    expect($provider)->not->toBeNull()
        ->and($provider->key())->toBe('witetec');
});

it('stores the token encrypted, never readable in the database', function () {
    $config = acquirerWithoutEnv('witetec');

    $this->credentials->store($config, ['api_key' => 'token_digitado_no_painel_9876']);

    $stored = DB::table('acquirer_credentials')
        ->where('acquirer_config_id', $config->id)
        ->where('field_key', 'api_key')
        ->first();

    expect($stored->cipher_text)->not->toContain('token_digitado_no_painel_9876')
        ->and($stored->cipher_alg)->toBe('aes-256-gcm')
        // The legacy plaintext-capable column is untouched by this flow.
        ->and(DB::table('acquirer_configs')->where('id', $config->id)->value('credentials'))->toBeNull();
});

it('refuses to resolve an acquirer whose panel credentials are incomplete', function () {
    config(['acquirers.witetec' => null]);

    $config = acquirerWithoutEnv('witetec');
    // witetec requires api_key; only the optional field was filled in.
    $this->credentials->store($config, ['webhook_secret' => 'whsec_only']);

    expect(app(ProviderRegistryInterface::class)->resolve('witetec'))->toBeNull();
});

it('does not fall back to .env when the panel has its own credentials', function () {
    config([
        'acquirers.witetec.enabled' => true,
        'acquirers.witetec.api_key' => 'CHAVE_DO_ENV_QUE_NAO_DEVE_SER_USADA',
    ]);

    $config = acquirerWithoutEnv('witetec');
    $this->credentials->store($config, ['api_key' => 'chave_do_painel_1234']);

    expect($this->credentials->decrypted($config)['api_key'])->toBe('chave_do_painel_1234');
});

it('leaves an acquirer out of routing when it has no credentials at all', function () {
    config(['acquirers.witetec' => null]);

    acquirerWithoutEnv('witetec');

    // No env, no panel entry: the acquirer must not be selectable.
    expect(app(ProviderRegistryInterface::class)->resolve('witetec'))->toBeNull();
});

/* ── Adquirente totalmente nova, cadastrada só pelo painel ──── */

it('gives a brand new acquirer credential fields to type into', function () {
    $config = acquirerWithoutEnv('psp_novo');

    $masked = $this->credentials->masked($config);

    // Previously getCredentialFields() returned [] for an unknown key, so the
    // panel rendered an empty form and the token could not be entered at all.
    expect($masked)->not->toBeEmpty()
        ->and($masked)->toHaveKey('api_key')
        ->and($masked['api_key']['required'])->toBeTrue();
});

it('lets the operator declare custom credential fields from the panel', function () {
    $config = acquirerWithoutEnv('psp_novo');

    $config->update([
        'credential_schema' => [
            ['key' => 'access_token', 'label' => 'Token de Acesso', 'type' => 'password', 'required' => true],
            ['key' => 'merchant_id', 'label' => 'Merchant ID', 'type' => 'text', 'required' => true],
            ['key' => 'base_url', 'label' => 'URL da API', 'type' => 'text', 'required' => false],
        ],
    ]);

    $masked = $this->credentials->masked($config->fresh());

    expect(array_keys($masked))->toBe(['access_token', 'merchant_id', 'base_url'])
        ->and($masked['access_token']['label'])->toBe('Token de Acesso')
        ->and($masked['base_url']['required'])->toBeFalse();
});

it('activates a brand new acquirer once its declared fields are filled', function () {
    $config = acquirerWithoutEnv('psp_novo');
    $config->update([
        'credential_schema' => [
            ['key' => 'access_token', 'label' => 'Token de Acesso', 'type' => 'password', 'required' => true],
            ['key' => 'merchant_id', 'label' => 'Merchant ID', 'type' => 'text', 'required' => true],
        ],
    ]);
    $config = $config->fresh();

    // Only one of the two required fields: not usable yet.
    $this->credentials->store($config, ['access_token' => 'tok_live_abc123']);
    expect($this->credentials->hasRequiredFields($config))->toBeFalse();

    // Both filled: the old validateCredentialsByKey() would still return false
    // here, because its match had no arm for this key.
    $this->credentials->store($config, ['merchant_id' => 'MERCH-42']);
    expect($this->credentials->hasRequiredFields($config))->toBeTrue();
});

it('encrypts a custom-schema token exactly like a built-in one', function () {
    $config = acquirerWithoutEnv('psp_novo');
    $config->update([
        'credential_schema' => [
            ['key' => 'access_token', 'label' => 'Token', 'type' => 'password', 'required' => true],
        ],
    ]);

    $this->credentials->store($config->fresh(), ['access_token' => 'tok_live_segredo_5555']);

    $stored = DB::table('acquirer_credentials')
        ->where('acquirer_config_id', $config->id)
        ->where('field_key', 'access_token')
        ->first();

    expect($stored->cipher_text)->not->toContain('tok_live_segredo_5555')
        ->and($stored->cipher_alg)->toBe('aes-256-gcm')
        ->and($stored->mask_hint)->toBe('****5555');
});

it('keeps curated validation for the known acquirers', function () {
    $config = acquirerWithoutEnv('enki');

    // Enki needs public_key AND secret_key; one alone must not be enough.
    $this->credentials->store($config, ['public_key' => 'pk_only']);
    expect($this->credentials->hasRequiredFields($config))->toBeFalse();

    $this->credentials->store($config, ['secret_key' => 'sk_too']);
    expect($this->credentials->hasRequiredFields($config))->toBeTrue();
});
