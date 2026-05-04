<?php

declare(strict_types=1);

use App\Domain\Auth\Entities\Role;
use App\Models\Account;
use App\Models\AccountWallet;
use App\Models\AdminActionApproval;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $role = Role::create([
        'id' => Str::uuid()->toString(),
        'name' => 'Administrador',
        'slug' => 'admin',
        'permissions' => [
            'admin.withdrawals.manage',
            'admin.approvals.review',
            'admin.dashboard.read',
        ],
    ]);

    $this->account = Account::create([
        'id' => Str::uuid()->toString(),
        'name' => 'Conta Operacional',
        'document' => '12345678900',
        'document_type' => 'cpf',
        'email' => 'conta@teste.com',
        'status' => 'active',
        'kyc_status' => 'approved',
    ]);

    AccountWallet::create([
        'id' => Str::uuid()->toString(),
        'account_id' => $this->account->id,
        'available_balance' => 500000,
        'pending_balance' => 0,
        'total_balance' => 500000,
    ]);

    $this->requester = User::create([
        'id' => Str::uuid()->toString(),
        'account_id' => $this->account->id,
        'email' => 'requester@teste.com',
        'password' => bcrypt('password'),
        'name' => 'Requester Admin',
        'status' => 'active',
        'role' => 'admin',
    ]);

    $this->requester->forceFill([
        'role_id' => $role->id,
        'mfa_enabled' => true,
        'mfa_secret' => 'JBSWY3DPEHPK3PXP',
        'kyc_status' => 'approved',
    ])->save();

    $this->reviewer = User::create([
        'id' => Str::uuid()->toString(),
        'account_id' => $this->account->id,
        'email' => 'reviewer@teste.com',
        'password' => bcrypt('password'),
        'name' => 'Reviewer Admin',
        'status' => 'active',
        'role' => 'admin',
    ]);

    $this->reviewer->forceFill([
        'role_id' => $role->id,
        'mfa_enabled' => true,
        'mfa_secret' => 'JBSWY3DPEHPK3PXP',
        'kyc_status' => 'approved',
    ])->save();

    $this->requesterToken = auth()->login($this->requester);
    $this->reviewerToken = auth()->login($this->reviewer);

    $this->withdrawal = Withdrawal::create([
        'id' => Str::uuid()->toString(),
        'account_id' => $this->account->id,
        'user_id' => $this->requester->id,
        'amount' => 10000,
        'fee' => 0,
        'net_amount' => 10000,
        'method' => 'pix',
        'status' => 'pending',
        'destination' => [
            'type' => 'pix',
            'pix_key' => '12345678900',
            'pix_type' => 'cpf',
        ],
    ]);
});

it('requires dual control and creates pending approval when processing withdrawal manually', function () {
    $response = $this->withHeader('Authorization', "Bearer {$this->requesterToken}")
        ->postJson("/api/v1/admin/withdrawals/{$this->withdrawal->id}/process");

    $response->assertStatus(202)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'dual_control_required');

    $approvalId = $response->json('error.approval_id');
    expect($approvalId)->not->toBeEmpty();

    $this->assertDatabaseHas('admin_action_approvals', [
        'id' => $approvalId,
        'requester_user_id' => $this->requester->id,
        'action_key' => 'admin.withdrawal.process_single',
        'resource_type' => 'withdrawal',
        'resource_id' => $this->withdrawal->id,
        'status' => 'pending',
    ]);
});

it('prevents requester from approving own pending action', function () {
    $initial = $this->withHeader('Authorization', "Bearer {$this->requesterToken}")
        ->postJson("/api/v1/admin/withdrawals/{$this->withdrawal->id}/process");

    $approvalId = $initial->json('error.approval_id');
    expect($approvalId)->not->toBeEmpty();

    $approveAttempt = $this->withHeader('Authorization', "Bearer {$this->requesterToken}")
        ->postJson("/api/v1/admin/approvals/{$approvalId}/approve", [
            'review_reason' => 'Tentativa de autoaprovacao',
        ]);

    $approveAttempt->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'approval_not_allowed');

    $this->assertDatabaseHas('admin_action_approvals', [
        'id' => $approvalId,
        'status' => 'pending',
        'reviewer_user_id' => null,
    ]);
});

it('allows second admin to approve and requester to consume approval once', function () {
    $initial = $this->withHeader('Authorization', "Bearer {$this->requesterToken}")
        ->postJson("/api/v1/admin/withdrawals/{$this->withdrawal->id}/process");

    $approvalId = $initial->json('error.approval_id');
    expect($approvalId)->not->toBeEmpty();

    $approve = $this->withHeader('Authorization', "Bearer {$this->reviewerToken}")
        ->postJson("/api/v1/admin/approvals/{$approvalId}/approve", [
            'review_reason' => 'Conferido e aprovado',
        ]);

    $approve->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'approved');

    $retry = $this->withHeaders([
        'Authorization' => "Bearer {$this->requesterToken}",
        'X-Admin-Approval-Id' => $approvalId,
    ])->postJson("/api/v1/admin/withdrawals/{$this->withdrawal->id}/process");

    expect($retry->status())->not->toBe(202);
    expect($retry->json('error.code'))->not->toBe('dual_control_required');

    $approval = AdminActionApproval::find($approvalId);
    expect($approval)->not->toBeNull();
    expect($approval?->status)->toBe('consumed');
    expect($approval?->consumed_at)->not->toBeNull();
});
