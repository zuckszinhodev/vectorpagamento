<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\AccountWallet;
use App\Models\Charge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function createAuthenticatedUser(): array
{
    $email = 'charge-test-' . Str::lower(Str::random(8)) . '@example.com';
    $document = str_pad((string) random_int(1, 99999999999), 11, '0', STR_PAD_LEFT);

    $account = Account::create([
        'id'            => Str::uuid()->toString(),
        'name'          => 'Test Account',
        'document'      => $document,
        'document_type' => 'cpf',
        'email'         => $email,
        'status'        => 'active',
    ]);

    $user = User::create([
        'id'         => Str::uuid()->toString(),
        'account_id' => $account->id,
        'email'      => $email,
        'password'   => Hash::make('password123'),
        'name'       => 'Charge Test User',
        'status'     => 'active',
    ]);

    AccountWallet::create([
        'id'                => Str::uuid()->toString(),
        'account_id'        => $account->id,
        'available_balance' => 0,
        'pending_balance'   => 0,
        'blocked_balance'   => 0,
        'currency'          => 'BRL',
    ]);

    $token = auth('api')->login($user);

    return compact('account', 'user', 'token');
}

/*
|--------------------------------------------------------------------------
| Charge Listing
|--------------------------------------------------------------------------
*/

it('lists charges for authenticated user', function () {
    ['account' => $account, 'token' => $token] = createAuthenticatedUser();

    // Create some charges
    Charge::create([
        'id'              => Str::uuid()->toString(),
        'account_id'      => $account->id,
        'amount'          => 5000,
        'currency'        => 'BRL',
        'payment_method'  => 'pix',
        'status'          => 'pending',
        'idempotency_key' => Str::random(32),
        'customer'        => ['name' => 'Test', 'email' => 'test@test.com', 'document' => '12345678900'],
    ]);

    $response = test()->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/charges');

    $response->assertStatus(200)
        ->assertJsonStructure(['success', 'data']);
});

it('rejects unauthenticated charge listing', function () {
    $response = $this->getJson('/api/v1/charges');

    $response->assertStatus(401);
});

/*
|--------------------------------------------------------------------------
| Charge Creation
|--------------------------------------------------------------------------
*/

it('creates a charge with valid data', function () {
    ['account' => $account, 'token' => $token] = createAuthenticatedUser();

    $response = test()->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/charges', [
            'amount'         => 10000,
            'payment_method' => 'pix',
            'customer'       => [
                'name'  => 'João Silva',
                'email' => 'joao@example.com',
                'document' => '11144477735',
            ],
        ]);

    $response->assertStatus(201)
        ->assertJsonStructure(['success', 'data' => ['id', 'amount', 'status']]);
});

it('rejects charge creation when idempotency key is longer than 64 chars', function () {
    ['token' => $token] = createAuthenticatedUser();

    $response = test()->withHeader('Authorization', "Bearer {$token}")
        ->withHeader('X-Idempotency-Key', str_repeat('a', 65))
        ->postJson('/api/v1/charges', [
            'amount'         => 10000,
            'payment_method' => 'pix',
            'customer'       => [
                'name'  => 'João Silva',
                'email' => 'joao@example.com',
                'document' => '11144477735',
            ],
        ]);

    $response->assertStatus(400)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'invalid_idempotency_key');
});

it('allows the same idempotency key for different accounts', function () {
    ['account' => $firstAccount] = createAuthenticatedUser();
    ['account' => $secondAccount] = createAuthenticatedUser();

    $idempotencyKey = 'charge-account-scoped-idem-001';

    Charge::create([
        'id' => Str::uuid()->toString(),
        'account_id' => $firstAccount->id,
        'amount' => 10000,
        'currency' => 'BRL',
        'payment_method' => 'pix',
        'status' => 'pending',
        'idempotency_key' => $idempotencyKey,
        'customer' => [
            'name' => 'João Silva',
            'email' => 'joao+1@example.com',
            'document' => '11144477735',
        ],
    ]);

    Charge::create([
        'id' => Str::uuid()->toString(),
        'account_id' => $secondAccount->id,
        'amount' => 10000,
        'currency' => 'BRL',
        'payment_method' => 'pix',
        'status' => 'pending',
        'idempotency_key' => $idempotencyKey,
        'customer' => [
            'name' => 'Maria Souza',
            'email' => 'maria+2@example.com',
            'document' => '11144477735',
        ],
    ]);

    expect(Charge::query()->where('idempotency_key', $idempotencyKey)->count())->toBe(2);
});

it('rejects charge creation with zero amount', function () {
    ['token' => $token] = createAuthenticatedUser();

    $response = test()->withHeader('Authorization', "Bearer {$token}")
        ->postJson('/api/v1/charges', [
            'amount'         => 0,
            'payment_method' => 'pix',
            'customer'       => [
                'name'  => 'Test',
                'email' => 'test@example.com',
            ],
        ]);

    $response->assertStatus(422);
});

/*
|--------------------------------------------------------------------------
| Charge Status
|--------------------------------------------------------------------------
*/

it('shows charge details', function () {
    ['account' => $account, 'token' => $token] = createAuthenticatedUser();

    $charge = Charge::create([
        'id'              => Str::uuid()->toString(),
        'account_id'      => $account->id,
        'amount'          => 5000,
        'currency'        => 'BRL',
        'payment_method'  => 'pix',
        'status'          => 'pending',
        'idempotency_key' => Str::random(32),
        'customer'        => ['name' => 'Test', 'email' => 'test@test.com', 'document' => '12345678900'],
    ]);

    $response = test()->withHeader('Authorization', "Bearer {$token}")
        ->getJson("/api/v1/charges/{$charge->id}");

    $response->assertStatus(200)
        ->assertJson(['success' => true])
        ->assertJsonPath('data.id', $charge->id);
});
