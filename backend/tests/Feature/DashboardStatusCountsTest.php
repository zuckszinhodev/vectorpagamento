<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Charge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function dashboardStatusUser(): array
{
    $email = 'dashboard-' . Str::lower(Str::random(8)) . '@example.com';

    $account = Account::create([
        'id' => Str::uuid()->toString(),
        'name' => 'Dashboard Account',
        'document' => str_pad((string) random_int(1, 99999999999), 11, '0', STR_PAD_LEFT),
        'document_type' => 'cpf',
        'email' => $email,
        'status' => 'active',
    ]);

    $user = User::create([
        'id' => Str::uuid()->toString(),
        'account_id' => $account->id,
        'email' => $email,
        'password' => Hash::make('password123'),
        'name' => 'Dashboard User',
        'status' => 'active',
    ]);

    return [$account, auth('api')->login($user)];
}

it('conta as cobranças do período por status real', function () {
    [$account, $token] = dashboardStatusUser();

    foreach (['approved', 'pending', 'processing', 'failed', 'declined', 'expired'] as $status) {
        Charge::create([
            'id' => Str::uuid()->toString(),
            'account_id' => $account->id,
            'amount' => 5000,
            'currency' => 'BRL',
            'payment_method' => 'pix',
            'status' => $status,
            'idempotency_key' => Str::random(32),
            'customer' => ['name' => 'Teste', 'email' => 'teste@example.com'],
            'paid_at' => $status === 'approved' ? now() : null,
        ]);
    }

    $response = test()->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/reports/dashboard');

    $response->assertStatus(200)
        ->assertJsonPath('data.summary.total_charges', 6)
        ->assertJsonPath('data.summary.approved_charges', 1)
        ->assertJsonPath('data.summary.pending_charges', 2)
        ->assertJsonPath('data.summary.rejected_charges', 2);
});

it('não inventa percentuais quando não há cobranças', function () {
    [, $token] = dashboardStatusUser();

    $response = test()->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/reports/dashboard');

    $response->assertStatus(200)
        ->assertJsonPath('data.summary.total_charges', 0)
        ->assertJsonPath('data.summary.pending_charges', 0)
        ->assertJsonPath('data.summary.rejected_charges', 0);
});
