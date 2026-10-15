<?php

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

beforeEach(function () {
    $account = Account::create([
        'id' => (string) Str::uuid(), 'name' => 'MFA security test',
        'document' => '12345678900', 'document_type' => 'cpf',
        'email' => 'mfa-security@example.com', 'status' => 'active',
    ]);
    $this->mfaUser = User::create([
        'id' => (string) Str::uuid(), 'account_id' => $account->id,
        'email' => 'mfa-security@example.com', 'name' => 'Test only',
        'password' => Hash::make('Local-test-only-123!'), 'status' => 'active',
    ]);
    $this->otp = new Google2FA();
    $this->mfaUser->forceFill([
        'mfa_enabled' => true, 'mfa_secret' => $this->otp->generateSecretKey(),
    ])->save();
    $this->withHeader('User-Agent', 'Local-MFA-security-test');
    $this->loginPayload = [
        'email' => $this->mfaUser->email, 'password' => 'Local-test-only-123!',
    ];
    $this->challenge = function () {
        return $this->postJson('/api/v1/auth/login', $this->loginPayload)
            ->assertOk()->assertJsonPath('data.requires_mfa', true)
            ->assertJsonMissingPath('data.token')->json('data.mfa_token');
    };
});

it('rejects fabricated MFA challenges even when remembering a device', function () {
    $this->postJson('/api/v1/auth/mfa/verify', [
        'mfa_token' => Str::random(64), 'code' => '123456', 'remember_device' => true,
    ])->assertUnauthorized()->assertJsonMissingPath('data.token');
});

it('rejects an incorrect OTP for a valid challenge', function () {
    $token = ($this->challenge)();
    $invalid = '000000';
    while ($this->otp->verifyKey($this->mfaUser->mfa_secret, $invalid, 1)) {
        $invalid = sprintf('%06d', (int) $invalid + 1);
    }
    $this->postJson('/api/v1/auth/mfa/verify', [
        'mfa_token' => $token, 'code' => $invalid, 'remember_device' => true,
    ])->assertUnauthorized()->assertJsonMissingPath('data.trusted_device_token');
});

it('rejects an expired MFA challenge', function () {
    $token = ($this->challenge)();
    $this->travel(301)->seconds();
    $this->postJson('/api/v1/auth/mfa/verify', [
        'mfa_token' => $token, 'code' => '123456',
    ])->assertUnauthorized()->assertJsonPath('error.code', 'invalid_mfa_token');
});

it('rejects a challenge after the five attempt budget is exhausted', function () {
    $token = ($this->challenge)();
    Cache::put('mfa_attempts:' . $token, 5, 300);
    $this->postJson('/api/v1/auth/mfa/verify', [
        'mfa_token' => $token, 'code' => $this->otp->getCurrentOtp($this->mfaUser->mfa_secret),
    ])->assertStatus(429)->assertJsonMissingPath('data.token');
});

it('rejects reuse of a successfully consumed challenge', function () {
    $payload = [
        'mfa_token' => ($this->challenge)(),
        'code' => $this->otp->getCurrentOtp($this->mfaUser->mfa_secret),
    ];
    $this->postJson('/api/v1/auth/mfa/verify', $payload)->assertOk();
    $this->postJson('/api/v1/auth/mfa/verify', $payload)
        ->assertUnauthorized()->assertJsonMissingPath('data.token');
});

it('rejects tampering with a trusted device token', function () {
    $trusted = $this->postJson('/api/v1/auth/mfa/verify', [
        'mfa_token' => ($this->challenge)(),
        'code' => $this->otp->getCurrentOtp($this->mfaUser->mfa_secret),
        'remember_device' => true,
    ])->assertOk()->json('data.trusted_device_token');
    expect($trusted)->toBeString();
    $this->postJson('/api/v1/auth/login', $this->loginPayload + [
        'trusted_device_token' => $trusted . 'tampered',
    ])->assertOk()->assertJsonPath('data.requires_mfa', true)->assertJsonMissingPath('data.token');
});

it('does not issue access when the account was blocked after starting MFA', function () {
    $token = ($this->challenge)();
    $this->mfaUser->forceFill(['status' => 'blocked'])->save();
    $this->postJson('/api/v1/auth/mfa/verify', [
        'mfa_token' => $token, 'code' => $this->otp->getCurrentOtp($this->mfaUser->mfa_secret),
    ])->assertForbidden()->assertJsonMissingPath('data.token');
});

it('does not accept the same OTP again for a different login challenge', function () {
    $code = $this->otp->getCurrentOtp($this->mfaUser->mfa_secret);
    $this->postJson('/api/v1/auth/mfa/verify', [
        'mfa_token' => ($this->challenge)(), 'code' => $code,
    ])->assertOk();
    $this->postJson('/api/v1/auth/mfa/verify', [
        'mfa_token' => ($this->challenge)(), 'code' => $code,
    ])->assertUnauthorized()->assertJsonMissingPath('data.token');
});

it('requires local MFA after successful Google authentication', function () {
    config(['services.google.client_id' => 'local-test-client']);
    Http::fake(['oauth2.googleapis.com/*' => Http::response([
        'sub' => 'test-google-subject', 'aud' => 'local-test-client',
        'email' => $this->mfaUser->email, 'email_verified' => 'true',
    ])]);
    $token = $this->postJson('/api/v1/auth/google', ['credential' => 'test-only'])
        ->assertOk()->assertJsonPath('data.requires_mfa', true)
        ->assertJsonMissingPath('data.token')->json('data.mfa_token');
    $this->postJson('/api/v1/auth/mfa/verify', [
        'mfa_token' => $token, 'code' => $this->otp->getCurrentOtp($this->mfaUser->mfa_secret),
    ])->assertOk()->assertJsonStructure(['data' => ['token' => ['access_token']]]);
});

it('rejects Google identities without verified email or matching audience', function ($audience, $verified) {
    config(['services.google.client_id' => 'local-test-client']);
    Http::fake(['oauth2.googleapis.com/*' => Http::response([
        'sub' => 'test-google-subject', 'aud' => $audience,
        'email' => $this->mfaUser->email, 'email_verified' => $verified,
    ])]);
    $this->postJson('/api/v1/auth/google', ['credential' => 'test-only'])
        ->assertUnauthorized()->assertJsonMissingPath('data.token');
})->with([
    'wrong audience' => ['another-client', 'true'],
    'unverified email' => ['local-test-client', 'false'],
]);

it('still allows Google login without local MFA when MFA is disabled', function () {
    $this->mfaUser->forceFill(['mfa_enabled' => false])->save();
    config(['services.google.client_id' => 'local-test-client']);
    Http::fake(['oauth2.googleapis.com/*' => Http::response([
        'sub' => 'test-google-subject', 'aud' => 'local-test-client',
        'email' => $this->mfaUser->email, 'email_verified' => true,
    ])]);
    $this->postJson('/api/v1/auth/google', ['credential' => 'test-only'])
        ->assertOk()->assertJsonStructure(['data' => ['token' => ['access_token']]]);
});

it('invalidates pending challenges after credentials or MFA change', function ($change) {
    $token = ($this->challenge)();
    $code = $this->otp->getCurrentOtp($this->mfaUser->mfa_secret);
    $changes = match ($change) {
        'password' => ['password' => Hash::make('Replacement-test-123!')],
        'disabled' => ['mfa_enabled' => false],
        'secret' => ['mfa_secret' => $this->otp->generateSecretKey()],
    };
    $this->mfaUser->forceFill($changes)->save();
    $this->postJson('/api/v1/auth/mfa/verify', ['mfa_token' => $token, 'code' => $code])
        ->assertUnauthorized()->assertJsonMissingPath('data.token');
})->with(['password', 'disabled', 'secret']);

it('serializes concurrent verification of the same challenge', function () {
    $token = ($this->challenge)();
    $lock = Cache::lock('mfa_verify:' . hash('sha256', $token), 30);
    expect($lock->get())->toBeTrue();
    try {
        $this->postJson('/api/v1/auth/mfa/verify', [
            'mfa_token' => $token, 'code' => $this->otp->getCurrentOtp($this->mfaUser->mfa_secret),
        ])->assertStatus(429)->assertJsonPath('error.code', 'mfa_verification_busy');
    } finally {
        $lock->release();
    }
    $this->postJson('/api/v1/auth/mfa/verify', [
        'mfa_token' => $token, 'code' => $this->otp->getCurrentOtp($this->mfaUser->mfa_secret),
    ])->assertOk();
});

it('revokes trusted devices after a password change through the API', function () {
    $verified = $this->postJson('/api/v1/auth/mfa/verify', [
        'mfa_token' => ($this->challenge)(),
        'code' => $this->otp->getCurrentOtp($this->mfaUser->mfa_secret), 'remember_device' => true,
    ])->assertOk();
    $trusted = $verified->json('data.trusted_device_token');
    $this->postJson('/api/v1/auth/login', $this->loginPayload + ['trusted_device_token' => $trusted])
        ->assertOk()->assertJsonStructure(['data' => ['token' => ['access_token']]]);
    $this->withToken($verified->json('data.token.access_token'))
        ->postJson('/api/v1/auth/change-password', [
            'current_password' => $this->loginPayload['password'],
            'password' => 'Replacement-test-123!', 'password_confirmation' => 'Replacement-test-123!',
        ])->assertOk();
    $this->postJson('/api/v1/auth/login', [
        'email' => $this->mfaUser->email, 'password' => 'Replacement-test-123!', 'trusted_device_token' => $trusted,
    ])->assertOk()->assertJsonPath('data.requires_mfa', true)->assertJsonMissingPath('data.token');
});

it('rejects malformed MFA input without an internal server error', function () {
    $this->postJson('/api/v1/auth/mfa/verify', [
        'mfa_token' => ['unexpected'], 'code' => ['123456'],
    ])->assertUnprocessable()->assertJsonValidationErrors(['mfa_token', 'code']);
});

it('rejects reuse of the login OTP for MFA removal', function () {
    $code = $this->otp->getCurrentOtp($this->mfaUser->mfa_secret);
    $access = $this->postJson('/api/v1/auth/mfa/verify', [
        'mfa_token' => ($this->challenge)(), 'code' => $code,
    ])->assertOk()->json('data.token.access_token');
    $this->withToken($access)->postJson('/api/v1/auth/mfa/disable', ['code' => $code])
        ->assertUnprocessable();
    expect($this->mfaUser->fresh()->mfa_enabled)->toBeTrue();
});

it('rejects reuse of the login OTP for another MFA check', function () {
    $code = $this->otp->getCurrentOtp($this->mfaUser->mfa_secret);
    $access = $this->postJson('/api/v1/auth/mfa/verify', [
        'mfa_token' => ($this->challenge)(), 'code' => $code,
    ])->assertOk()->json('data.token.access_token');
    $this->withToken($access)->postJson('/api/v1/auth/mfa/check', ['code' => $code])
        ->assertUnauthorized();
});

it('revokes trusted devices after password reset through the API', function () {
    $trusted = $this->postJson('/api/v1/auth/mfa/verify', [
        'mfa_token' => ($this->challenge)(),
        'code' => $this->otp->getCurrentOtp($this->mfaUser->mfa_secret), 'remember_device' => true,
    ])->assertOk()->json('data.trusted_device_token');
    $resetToken = Str::random(64);
    Cache::put('password_reset:' . $resetToken, $this->mfaUser->id, 3600);
    $this->postJson('/api/v1/auth/reset-password', [
        'token' => $resetToken, 'password' => 'Replacement-test-123!',
        'password_confirmation' => 'Replacement-test-123!',
    ])->assertOk();
    $this->postJson('/api/v1/auth/login', [
        'email' => $this->mfaUser->email, 'password' => 'Replacement-test-123!', 'trusted_device_token' => $trusted,
    ])->assertOk()->assertJsonPath('data.requires_mfa', true)->assertJsonMissingPath('data.token');
});
