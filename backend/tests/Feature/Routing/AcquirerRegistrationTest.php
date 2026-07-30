<?php

declare(strict_types=1);

use App\Domain\Routing\Contracts\AcquirerCredentialRepositoryInterface;
use App\Domain\Routing\Contracts\CredentialVaultInterface;
use App\Models\AcquirerConfig;
use App\Models\AcquirerCredential;
use App\Models\AcquirerEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function newAcquirer(string $key = 'test_psp'): AcquirerConfig
{
    // Several acquirers are seeded by earlier migrations; start each test from
    // a clean row so credential state is entirely controlled here.
    AcquirerConfig::withTrashed()->where('acquirer_key', $key)->forceDelete();
    AcquirerCredential::where('acquirer_key', $key)->delete();

    return AcquirerConfig::create([
        'id' => (string) Str::uuid(),
        'acquirer_key' => $key,
        'display_name' => ucfirst($key),
        'type' => 'pix',
        'enabled' => false,
        'supported_methods' => ['pix'],
        'priority' => 50,
    ]);
}

beforeEach(function () {
    $this->credentials = app(AcquirerCredentialRepositoryInterface::class);
    $this->vault = app(CredentialVaultInterface::class);
});

it('stores credentials encrypted, never as plaintext', function () {
    $config = newAcquirer('witetec');

    $this->credentials->store($config, [
        'api_key' => 'live_key_super_secret_9876',
        'webhook_secret' => 'whsec_abcdef',
    ]);

    $rows = DB::table('acquirer_credentials')->where('acquirer_config_id', $config->id)->get();

    expect($rows)->toHaveCount(2);

    foreach ($rows as $row) {
        expect($row->cipher_text)->not->toContain('live_key_super_secret_9876')
            ->and($row->cipher_text)->not->toContain('whsec_abcdef')
            ->and($row->cipher_alg)->toBe('aes-256-gcm')
            ->and($row->cipher_text)->toStartWith('v');
    }
});

it('reads credentials back correctly', function () {
    $config = newAcquirer('witetec');

    $this->credentials->store($config, ['api_key' => 'live_key_1234', 'webhook_secret' => 'whsec_x']);

    expect($this->credentials->decrypted($config))
        ->toBe(['api_key' => 'live_key_1234', 'webhook_secret' => 'whsec_x']);
});

it('never exposes a full credential through the masked view', function () {
    $config = newAcquirer('witetec');
    $this->credentials->store($config, ['api_key' => 'live_key_super_secret_9876']);

    $masked = $this->credentials->masked($config);

    expect($masked['api_key']['masked'])->toBe('****9876')
        ->and(json_encode($masked))->not->toContain('live_key_super_secret_9876');
});

it('keeps credentials out of model serialisation', function () {
    $config = newAcquirer('witetec');
    $this->credentials->store($config, ['api_key' => 'live_key_super_secret_9876']);

    $row = AcquirerCredential::where('acquirer_config_id', $config->id)->first();

    // A forgotten ->toArray() in a controller must not become a leak.
    expect($row->toArray())->not->toHaveKey('cipher_text')
        ->and($row->toArray())->not->toHaveKey('fingerprint')
        ->and(json_encode($row))->not->toContain('live_key_super_secret_9876');
});

it('does not overwrite a secret when the masked placeholder is submitted', function () {
    $config = newAcquirer('witetec');
    $this->credentials->store($config, ['api_key' => 'original_secret_1234']);

    // The panel renders '****1234'; saving the form unchanged sends it back.
    $written = $this->credentials->store($config, ['api_key' => '****1234']);

    expect($written)->toBe([])
        ->and($this->credentials->decrypted($config)['api_key'])->toBe('original_secret_1234');
});

it('does not overwrite a secret when the field is submitted empty', function () {
    $config = newAcquirer('witetec');
    $this->credentials->store($config, ['api_key' => 'original_secret_1234']);

    $this->credentials->store($config, ['api_key' => '']);

    expect($this->credentials->decrypted($config)['api_key'])->toBe('original_secret_1234');
});

it('skips the write when the value is unchanged', function () {
    $config = newAcquirer('witetec');
    $this->credentials->store($config, ['api_key' => 'same_value_here']);

    $written = $this->credentials->store($config, ['api_key' => 'same_value_here']);

    // Detected via fingerprint, without decrypting the stored payload.
    expect($written)->toBe([]);
});

it('rotates a credential when a new value is submitted', function () {
    $config = newAcquirer('witetec');
    $this->credentials->store($config, ['api_key' => 'old_value_1111']);

    $written = $this->credentials->store($config, ['api_key' => 'new_value_2222']);

    expect($written)->toBe(['api_key'])
        ->and($this->credentials->decrypted($config)['api_key'])->toBe('new_value_2222')
        ->and(AcquirerCredential::where('acquirer_config_id', $config->id)->first()->rotated_at)
        ->not->toBeNull();
});

it('audits a credential rotation without recording the value', function () {
    $config = newAcquirer('witetec');
    $this->credentials->store($config, ['api_key' => 'secret_value_here']);

    $event = AcquirerEvent::where('acquirer_key', 'witetec')
        ->where('type', AcquirerEvent::TYPE_CREDENTIAL_ROTATED)
        ->first();

    expect($event)->not->toBeNull()
        ->and($event->context['fields'])->toBe(['api_key'])
        ->and(json_encode($event->context))->not->toContain('secret_value_here');
});

it('validates that all required fields are present', function () {
    $config = newAcquirer('witetec');

    expect($this->credentials->hasRequiredFields($config))->toBeFalse();

    // Witetec requires api_key.
    $this->credentials->store($config, ['api_key' => 'live_key_1234']);

    expect($this->credentials->hasRequiredFields($config))->toBeTrue();
});

it('purges every credential when an acquirer is deleted', function () {
    $config = newAcquirer('witetec');
    $this->credentials->store($config, ['api_key' => 'k1', 'webhook_secret' => 'k2']);

    $this->credentials->purge($config);

    expect(AcquirerCredential::where('acquirer_config_id', $config->id)->count())->toBe(0);
});

it('forgets a single credential field', function () {
    $config = newAcquirer('witetec');
    $this->credentials->store($config, ['api_key' => 'k1', 'webhook_secret' => 'k2']);

    $this->credentials->forget($config, 'webhook_secret');

    expect($this->credentials->decrypted($config))->toBe(['api_key' => 'k1']);
});

it('reports each declared credential field, configured or not', function () {
    $config = newAcquirer('witetec');
    $this->credentials->store($config, ['api_key' => 'only_this_one']);

    $masked = $this->credentials->masked($config);

    expect($masked['api_key']['configured'])->toBeTrue()
        ->and($masked['webhook_secret']['configured'])->toBeFalse()
        ->and($masked['webhook_secret']['masked'])->toBe('');
});

it('survives a credential that cannot be decrypted without leaking it', function () {
    $config = newAcquirer('witetec');
    $this->credentials->store($config, ['api_key' => 'good', 'webhook_secret' => 'also_good']);

    // Corrupt one row, as a bad key rotation would.
    DB::table('acquirer_credentials')
        ->where('acquirer_config_id', $config->id)
        ->where('field_key', 'webhook_secret')
        ->update(['cipher_text' => 'v1.aaaa.bbbb.cccc']);

    $decrypted = $this->credentials->decrypted($config);

    // The healthy field still works; the corrupt one is dropped, not guessed.
    expect($decrypted)->toBe(['api_key' => 'good']);
});
