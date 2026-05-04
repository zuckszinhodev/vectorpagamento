<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Registration
|--------------------------------------------------------------------------
*/

it('registers a new user successfully', function () {
    $response = $this->postJson('/api/v1/auth/register', [
        'name'                  => 'Test User',
        'email'                 => 'test@example.com',
        'password'              => 'Password@123',
        'password_confirmation' => 'Password@123',
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure(['success', 'data' => ['user', 'token']]);

    $this->assertDatabaseHas('users', ['email' => 'test@example.com']);
});

it('rejects registration with missing name', function () {
    $response = $this->postJson('/api/v1/auth/register', [
        'email'                 => 'test@example.com',
        'password'              => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['name']);
});

it('rejects registration with invalid email', function () {
    $response = $this->postJson('/api/v1/auth/register', [
        'name'                  => 'Test',
        'email'                 => 'not-an-email',
        'password'              => 'password123',
        'password_confirmation' => 'password123',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['email']);
});

it('rejects registration with short password', function () {
    $response = $this->postJson('/api/v1/auth/register', [
        'name'                  => 'Test',
        'email'                 => 'test@example.com',
        'password'              => 'short',
        'password_confirmation' => 'short',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['password']);
});

it('rejects registration with mismatched password confirmation', function () {
    $response = $this->postJson('/api/v1/auth/register', [
        'name'                  => 'Test',
        'email'                 => 'test@example.com',
        'password'              => 'password123',
        'password_confirmation' => 'different456',
    ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['password']);
});

/*
|--------------------------------------------------------------------------
| Login
|--------------------------------------------------------------------------
*/

it('logs in with valid credentials', function () {
    $account = Account::create([
        'id'            => Str::uuid()->toString(),
        'name'          => 'Test Account',
        'document'      => '12345678900',
        'document_type' => 'cpf',
        'email'         => 'login@example.com',
        'status'        => 'active',
    ]);

    User::create([
        'id'         => Str::uuid()->toString(),
        'account_id' => $account->id,
        'email'      => 'login@example.com',
        'password'   => Hash::make('password123'),
        'name'       => 'Login User',
        'status'     => 'active',
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email'    => 'login@example.com',
        'password' => 'password123',
    ]);

    $response->assertStatus(200)
        ->assertJsonStructure(['success', 'data' => ['user', 'token']]);
});

it('returns mfa_enabled in login response for non-MFA users', function () {
    $account = Account::create([
        'id'            => Str::uuid()->toString(),
        'name'          => 'Test Account',
        'document'      => '12345678900',
        'document_type' => 'cpf',
        'email'         => 'nomfa@example.com',
        'status'        => 'active',
    ]);

    User::create([
        'id'         => Str::uuid()->toString(),
        'account_id' => $account->id,
        'email'      => 'nomfa@example.com',
        'password'   => Hash::make('password123'),
        'name'       => 'No MFA User',
        'status'     => 'active',
        'mfa_enabled' => false,
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email'    => 'nomfa@example.com',
        'password' => 'password123',
    ]);

    $response->assertStatus(200)
        ->assertJsonPath('data.user.mfa_enabled', false);
});

it('requires mfa and returns mfa_enabled after verifyMfa for MFA users', function () {
    $account = Account::create([
        'id'            => Str::uuid()->toString(),
        'name'          => 'Test Account',
        'document'      => '12345678900',
        'document_type' => 'cpf',
        'email'         => 'mfa@example.com',
        'status'        => 'active',
    ]);

    $google2fa = new \PragmaRX\Google2FA\Google2FA();
    $secret = $google2fa->generateSecretKey();

    $user = User::create([
        'id'         => Str::uuid()->toString(),
        'account_id' => $account->id,
        'email'      => 'mfa@example.com',
        'password'   => Hash::make('password123'),
        'name'       => 'MFA User',
        'status'     => 'active',
    ]);

    $user->forceFill([
        'mfa_enabled' => true,
        'mfa_secret' => $secret,
    ])->save();

    $loginResponse = $this->postJson('/api/v1/auth/login', [
        'email'    => 'mfa@example.com',
        'password' => 'password123',
    ]);

    $loginResponse->assertStatus(200)
        ->assertJsonPath('data.requires_mfa', true);

    $mfaToken = $loginResponse->json('data.mfa_token');

    $code = $google2fa->getCurrentOtp($secret);

    $verifyResponse = $this->postJson('/api/v1/auth/mfa/verify', [
        'mfa_token' => $mfaToken,
        'code' => $code,
    ]);

    $verifyResponse->assertStatus(200)
        ->assertJsonPath('data.user.mfa_enabled', true);
});

it('rejects login with wrong password', function () {
    $account = Account::create([
        'id'            => Str::uuid()->toString(),
        'name'          => 'Test Account',
        'document'      => '12345678900',
        'document_type' => 'cpf',
        'email'         => 'wrong@example.com',
        'status'        => 'active',
    ]);

    User::create([
        'id'         => Str::uuid()->toString(),
        'account_id' => $account->id,
        'email'      => 'wrong@example.com',
        'password'   => Hash::make('password123'),
        'name'       => 'Test User',
        'status'     => 'active',
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'email'    => 'wrong@example.com',
        'password' => 'wrongpassword',
    ]);

    $response->assertStatus(401);
});

it('rejects login with nonexistent email', function () {
    $response = $this->postJson('/api/v1/auth/login', [
        'email'    => 'nobody@example.com',
        'password' => 'password123',
    ]);

    $response->assertStatus(401);
});
