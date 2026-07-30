<?php

declare(strict_types=1);

use App\Domain\Routing\Exceptions\CredentialDecryptionException;
use App\Infrastructure\Routing\Security\AesGcmCredentialVault;

function vaultWithKeys(array $versions = [1]): AesGcmCredentialVault
{
    $keys = [];

    foreach ($versions as $version) {
        // Deterministic per-version key so tests are reproducible.
        $keys[$version] = hash('sha256', "test-key-v{$version}", true);
    }

    return new AesGcmCredentialVault($keys, max($versions));
}

it('encrypts and decrypts a credential', function () {
    $vault = vaultWithKeys();
    $secret = $vault->encrypt('sk_live_super_secret_value');

    expect($vault->decrypt($secret->cipherText, $secret->keyVersion))
        ->toBe('sk_live_super_secret_value');
});

it('never stores the plaintext inside the ciphertext', function () {
    $vault = vaultWithKeys();
    $secret = $vault->encrypt('sk_live_super_secret_value');

    expect($secret->cipherText)->not->toContain('sk_live_super_secret_value')
        ->and($secret->cipherText)->toStartWith('v1.');
});

it('produces a different ciphertext each time for the same plaintext', function () {
    $vault = vaultWithKeys();

    // A fresh IV per encryption: identical secrets must not be linkable.
    expect($vault->encrypt('same-value')->cipherText)
        ->not->toBe($vault->encrypt('same-value')->cipherText);
});

it('masks a secret showing only the last four characters', function () {
    $vault = vaultWithKeys();

    expect($vault->mask('sk_live_abcd1234'))->toBe('****1234');
});

it('fully masks short secrets', function () {
    $vault = vaultWithKeys();

    expect($vault->mask('abc123'))->toBe('******')
        ->and($vault->mask('abc123'))->not->toContain('abc');
});

it('rejects a tampered ciphertext instead of returning garbage', function () {
    $vault = vaultWithKeys();
    $secret = $vault->encrypt('sk_live_value');

    // Flip one bit in the raw ciphertext bytes. Operating on the decoded
    // bytes guarantees an actual change, unlike substituting characters that
    // may not appear in a given base64 string.
    $parts = explode('.', $secret->cipherText);
    $raw = base64_decode($parts[3], true);
    $raw[0] = chr(ord($raw[0]) ^ 0x01);
    $parts[3] = base64_encode($raw);
    $tampered = implode('.', $parts);

    expect(fn () => $vault->decrypt($tampered, 1))
        ->toThrow(CredentialDecryptionException::class);
});

it('refuses to treat an unencrypted value as plaintext', function () {
    $vault = vaultWithKeys();

    // The old implementation fell back to json_decode here, silently keeping
    // plaintext credentials working. That fallback is gone on purpose.
    expect(fn () => $vault->decrypt('{"api_key":"plain"}', 1))
        ->toThrow(CredentialDecryptionException::class);
});

it('decrypts payloads written under a previous key version', function () {
    $oldVault = vaultWithKeys([1]);
    $secret = $oldVault->encrypt('rotated-secret');

    // New key is current, old key kept for reading.
    $rotatedVault = vaultWithKeys([1, 2]);

    expect($rotatedVault->decrypt($secret->cipherText, 1))->toBe('rotated-secret')
        ->and($rotatedVault->currentKeyVersion())->toBe(2);
});

it('writes new secrets under the current key version after rotation', function () {
    $vault = vaultWithKeys([1, 2]);

    expect($vault->encrypt('new-secret')->keyVersion)->toBe(2);
});

it('fails when a key version is not available on this instance', function () {
    $secret = vaultWithKeys([1, 2])->encrypt('secret');

    expect(fn () => vaultWithKeys([1])->decrypt($secret->cipherText, 2))
        ->toThrow(CredentialDecryptionException::class);
});

it('rejects keys that are not 32 bytes', function () {
    expect(fn () => new AesGcmCredentialVault([1 => 'too-short'], 1))
        ->toThrow(InvalidArgumentException::class);
});

it('produces a stable fingerprint for change detection', function () {
    $vault = vaultWithKeys();

    expect($vault->fingerprint('value'))->toBe($vault->fingerprint('value'))
        ->and($vault->fingerprint('value'))->not->toBe($vault->fingerprint('other'));
});

it('keeps the secret out of string and debug representations', function () {
    $secret = vaultWithKeys()->encrypt('sk_live_leak_me');

    expect((string) $secret)->toBe('[encrypted]')
        ->and(json_encode($secret))->not->toContain('sk_live_leak_me')
        ->and(print_r($secret->__debugInfo(), true))->not->toContain('sk_live_leak_me');
});
