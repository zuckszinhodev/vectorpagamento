<?php

declare(strict_types=1);

use App\Domain\Auth\Entities\Role;
use App\Models\Account;
use App\Models\AdminActionApproval;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('returns an impersonation token immediately without dual control approval', function () {
    $role = Role::create([
        'id' => Str::uuid()->toString(),
        'name' => 'Administrador',
        'slug' => 'admin',
        'permissions' => [
            'admin.customers.manage',
        ],
    ]);

    $adminAccount = Account::create([
        'id' => Str::uuid()->toString(),
        'name' => 'Conta Admin',
        'document' => '12345678900',
        'document_type' => 'cpf',
        'email' => 'admin@teste.com',
        'status' => 'active',
        'kyc_status' => 'approved',
    ]);

    $customerAccount = Account::create([
        'id' => Str::uuid()->toString(),
        'name' => 'Conta Cliente',
        'document' => '98765432100',
        'document_type' => 'cpf',
        'email' => 'cliente@teste.com',
        'status' => 'active',
        'kyc_status' => 'pending',
    ]);

    $admin = User::create([
        'id' => Str::uuid()->toString(),
        'account_id' => $adminAccount->id,
        'email' => 'admin@teste.com',
        'password' => bcrypt('password'),
        'name' => 'Admin User',
        'status' => 'active',
        'role' => 'admin',
    ]);

    $admin->forceFill([
        'role_id' => $role->id,
        'mfa_enabled' => true,
        'mfa_secret' => 'JBSWY3DPEHPK3PXP',
        'kyc_status' => 'approved',
    ])->save();

    $customer = User::create([
        'id' => Str::uuid()->toString(),
        'account_id' => $customerAccount->id,
        'email' => 'customer@teste.com',
        'password' => bcrypt('password'),
        'name' => 'Customer User',
        'status' => 'active',
        'role' => 'user',
        'kyc_status' => 'pending',
    ]);

    $adminToken = auth()->login($admin);

    $response = $this->withHeader('Authorization', "Bearer {$adminToken}")
        ->postJson("/api/v1/admin/customers/{$customer->id}/impersonate");

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.user.id', $customer->id)
        ->assertJsonPath('data.user.email', $customer->email);

    expect($response->json('data.token'))->not->toBeEmpty();

    $this->assertDatabaseMissing('admin_action_approvals', [
        'requester_user_id' => $admin->id,
        'action_key' => 'admin.customer.impersonate',
        'resource_type' => 'user',
        'resource_id' => $customer->id,
    ]);

    expect(AdminActionApproval::query()->count())->toBe(0);
});
