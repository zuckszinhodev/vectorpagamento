<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\ApiKey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->account = Account::create([
        'id'            => Str::uuid()->toString(),
        'name'          => 'Test Account',
        'document'      => '12345678900',
        'document_type' => 'cpf',
        'email'         => 'account@test.com',
        'status'        => 'active',
    ]);

    $this->user = User::create([
        'id'         => Str::uuid()->toString(),
        'account_id' => $this->account->id,
        'email'      => 'user@test.com',
        'password'   => bcrypt('password'),
        'name'       => 'Test User',
        'status'     => 'active',
        'role'       => 'admin',
    ]);

    // mfa_secret is guarded, set it directly
    $this->user->forceFill(['mfa_secret' => 'JBSWY3DPEHPK3PXP'])->save();

    $this->token = auth()->login($this->user);

    // Generate valid MFA code
    $google2fa = new Google2FA();
    $this->mfaCode = $google2fa->getCurrentOtp('JBSWY3DPEHPK3PXP');
});

it('creates an API key', function () {
    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/v1/api-keys', [
            'name'        => 'Test API Key',
            'permissions' => ['charges.create', 'charges.read'],
            'rate_limit'  => 1000,
            'mfa_code'    => $this->mfaCode,
        ]);

    $response->assertStatus(201)
        ->assertJsonStructure(['success', 'data' => ['id', 'name', 'key', 'permissions']]);

    $this->assertDatabaseHas('api_keys', [
        'name'       => 'Test API Key',
        'account_id' => $this->account->id,
    ]);
});

it('generates unique API key with prefix', function () {
    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/v1/api-keys', [
            'name'        => 'Test Key',
            'permissions' => ['charges.read'],
            'mfa_code'    => $this->mfaCode,
        ]);

    $response->assertStatus(201);

    $key = $response->json('data.key');

    expect($key)->toMatch('/^(sk_live_|sk_test_|pk_live_|pk_test_)/');
    expect(strlen($key))->toBeGreaterThan(20);
});

it('lists API keys for authenticated user', function () {
    ApiKey::create([
        'id'          => Str::uuid()->toString(),
        'account_id'  => $this->account->id,
        'name'        => 'Test Key',
        'key'         => 'wz_test_' . Str::random(32),
        'key_hash'    => hash('sha256', 'wz_test_' . Str::random(32)),
        'secret_hash' => hash('sha256', 'test_secret_1'),
        'type'        => 'secret',
        'permissions' => json_encode(['charges.read']),
        'status'      => 'active',
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/v1/api-keys');

    $response->assertStatus(200)
        ->assertJsonStructure(['success', 'data']);
});

it('does not expose full key in list', function () {
    ApiKey::create([
        'id'          => Str::uuid()->toString(),
        'account_id'  => $this->account->id,
        'name'        => 'Test Key',
        'key'         => 'wz_test_secretkey123456789',
        'key_hash'    => hash('sha256', 'wz_test_secretkey123456789'),
        'secret_hash' => hash('sha256', 'test_secret_2'),
        'type'        => 'secret',
        'permissions' => json_encode(['charges.read']),
        'status'      => 'active',
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/v1/api-keys');

    $response->assertStatus(200);

    $data = $response->json('data');
    expect($data)->toBeArray();
});

it('rotates an API key', function () {
    $apiKey = ApiKey::create([
        'id'          => Str::uuid()->toString(),
        'account_id'  => $this->account->id,
        'name'        => 'Test Key',
        'key'         => 'wz_test_oldkey123',
        'key_hash'    => hash('sha256', 'wz_test_oldkey123'),
        'secret_hash' => hash('sha256', 'test_secret_3'),
        'type'        => 'secret',
        'permissions' => json_encode(['charges.read']),
        'status'      => 'active',
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson("/api/v1/api-keys/{$apiKey->id}/rotate", [
            'mfa_code' => $this->mfaCode,
        ]);

    $response->assertStatus(200)
        ->assertJsonStructure(['success', 'data' => ['key']]);

    $newKey = $response->json('data.key');

    expect($newKey)->not->toBe('wz_test_oldkey123');
    expect($newKey)->toMatch('/^(sk_live_|sk_test_|pk_live_|pk_test_)/');
});

it('revokes an API key', function () {
    $apiKey = ApiKey::create([
        'id'          => Str::uuid()->toString(),
        'account_id'  => $this->account->id,
        'name'        => 'Test Key',
        'key'         => 'wz_test_key123',
        'key_hash'    => hash('sha256', 'wz_test_key123'),
        'secret_hash' => hash('sha256', 'test_secret_4'),
        'type'        => 'secret',
        'permissions' => json_encode(['charges.read']),
        'status'      => 'active',
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->deleteJson("/api/v1/api-keys/{$apiKey->id}");

    $response->assertStatus(200);

    $this->assertSoftDeleted('api_keys', [
        'id' => $apiKey->id,
    ]);
});

it('validates permissions array', function () {
    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/v1/api-keys', [
            'name'        => 'Test Key',
            'permissions' => 'not-an-array',
            'mfa_code'    => $this->mfaCode,
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['permissions']);
});

it('validates rate limit is positive', function () {
    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/v1/api-keys', [
            'name'        => 'Test Key',
            'permissions' => ['charges.read'],
            'mfa_code'    => $this->mfaCode,
        ]);

    $response->assertStatus(201);
});
