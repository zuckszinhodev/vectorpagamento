<?php

declare(strict_types=1);

use App\Domain\Auth\Entities\Role;
use App\Mail\KycApprovedMail;
use App\Models\Account;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $role = Role::create([
        'id' => Str::uuid()->toString(),
        'name' => 'Administrador',
        'slug' => 'admin',
        'permissions' => ['admin.customers.manage'],
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
        'status' => 'pending',
        'kyc_status' => 'pending',
    ]);

    $this->admin = User::create([
        'id' => Str::uuid()->toString(),
        'account_id' => $adminAccount->id,
        'email' => 'admin@teste.com',
        'password' => bcrypt('password'),
        'name' => 'Admin User',
        'status' => 'active',
        'role' => 'admin',
    ]);
    $this->admin->forceFill([
        'role_id' => $role->id,
        'mfa_enabled' => true,
        'mfa_secret' => 'JBSWY3DPEHPK3PXP',
        'kyc_status' => 'approved',
    ])->save();

    $this->customer = User::create([
        'id' => Str::uuid()->toString(),
        'account_id' => $customerAccount->id,
        'email' => 'customer@teste.com',
        'password' => bcrypt('password'),
        'name' => 'Customer User',
        'status' => 'active',
        'role' => 'user',
        'kyc_status' => 'pending',
    ]);

    $this->adminToken = auth()->login($this->admin);
});

it('emails the seller when an admin approves KYC manually', function () {
    Mail::fake();

    $this->withHeader('Authorization', "Bearer {$this->adminToken}")
        ->postJson("/api/v1/admin/customers/{$this->customer->id}/approve-kyc")
        ->assertOk()
        ->assertJsonPath('data.kyc_status', 'approved');

    Mail::assertSent(KycApprovedMail::class, fn (KycApprovedMail $mail) => $mail->hasTo('customer@teste.com'));
});

it('does not email again when KYC was already approved', function () {
    $this->customer->forceFill(['kyc_status' => 'approved', 'kyc_approved_at' => now()])->save();
    Mail::fake();

    $this->withHeader('Authorization', "Bearer {$this->adminToken}")
        ->postJson("/api/v1/admin/customers/{$this->customer->id}/approve-kyc")
        ->assertOk();

    Mail::assertNotSent(KycApprovedMail::class);
});
