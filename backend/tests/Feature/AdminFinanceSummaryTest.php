<?php

declare(strict_types=1);

use App\Domain\Auth\Entities\Role;
use App\Models\Account;
use App\Models\Charge;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function financeAdmin(): array
{
    $email = 'finance-' . Str::lower(Str::random(8)) . '@example.com';

    $account = Account::create([
        'id' => Str::uuid()->toString(),
        'name' => 'Finance Account',
        'document' => str_pad((string) random_int(1, 99999999999), 11, '0', STR_PAD_LEFT),
        'document_type' => 'cpf',
        'email' => $email,
        'status' => 'active',
    ]);

    $role = Role::where('slug', 'owner')->first() ?? Role::create([
        'id' => Str::uuid()->toString(),
        'name' => 'Owner',
        'slug' => 'owner',
        'permissions' => ['*'],
    ]);

    $user = User::create([
        'id' => Str::uuid()->toString(),
        'account_id' => $account->id,
        'email' => $email,
        'password' => Hash::make('password123'),
        'name' => 'Finance Admin',
        'status' => 'active',
    ]);
    $user->forceFill(['role_id' => $role->id, 'mfa_enabled' => true])->save();

    return [$account, auth('api')->login($user), $user];
}

function financeCharge(string $accountId, string $status, int $amount, string $email = 'teste@example.com'): void
{
    Charge::create([
        'id' => Str::uuid()->toString(),
        'account_id' => $accountId,
        'amount' => $amount,
        'currency' => 'BRL',
        'payment_method' => 'pix',
        'status' => $status,
        'idempotency_key' => Str::random(32),
        'customer' => ['name' => 'Teste', 'email' => $email],
    ]);
}

it('soma as cobranças de todas as páginas por status', function () {
    [$account, $token] = financeAdmin();

    financeCharge($account->id, 'approved', 5000);
    financeCharge($account->id, 'approved', 3000);
    financeCharge($account->id, 'pending', 1000);

    test()->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/admin/charges/summary')
        ->assertStatus(200)
        ->assertJsonPath('data.total', 3)
        ->assertJsonPath('data.by_status.approved.count', 2)
        ->assertJsonPath('data.by_status.approved.amount', 8000)
        ->assertJsonPath('data.by_status.pending.count', 1);
});

it('aplica a busca no resumo e na lista de cobranças', function () {
    [$account, $token] = financeAdmin();

    financeCharge($account->id, 'approved', 5000, 'achar@example.com');
    financeCharge($account->id, 'approved', 3000, 'outro@example.com');

    test()->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/admin/charges/summary?search=achar')
        ->assertJsonPath('data.total', 1);

    test()->withHeader('Authorization', "Bearer {$token}")
        ->getJson('/api/v1/admin/charges?search=achar')
        ->assertJsonPath('meta.total', 1);
});

it('resume e busca saques por status', function () {
    [$account, $token, $user] = financeAdmin();

    foreach ([['pending', 2000], ['pending', 1000], ['completed', 4000]] as [$status, $amount]) {
        Withdrawal::create([
            'id' => Str::uuid()->toString(),
            'account_id' => $account->id,
            'user_id' => $user->id,
            'amount' => $amount,
            'fee' => 0,
            'net_amount' => $amount,
            'method' => 'pix',
            'status' => $status,
            'destination' => ['pix_key' => 'chave@example.com'],
        ]);
    }

    $client = test()->withHeader('Authorization', "Bearer {$token}");

    $client->getJson('/api/v1/admin/withdrawals/summary')
        ->assertStatus(200)
        ->assertJsonPath('data.total', 3)
        ->assertJsonPath('data.by_status.pending.count', 2)
        ->assertJsonPath('data.by_status.pending.amount', 3000);

    $client->getJson('/api/v1/admin/withdrawals?search=' . urlencode('Finance Admin'))
        ->assertJsonPath('meta.total', 3);
});
