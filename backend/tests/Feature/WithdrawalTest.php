<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\AccountWallet;
use App\Domain\Auth\Entities\Role;
use App\Models\User;
use App\Models\Withdrawal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

beforeEach(function () {
    $adminRole = Role::create([
        'id' => Str::uuid()->toString(),
        'name' => 'Administrador',
        'slug' => 'admin',
        'permissions' => ['admin.withdrawals.manage'],
    ]);

    $this->account = Account::create([
        'id'            => Str::uuid()->toString(),
        'name'          => 'Test Account',
        'document'      => '12345678900',
        'document_type' => 'cpf',
        'email'         => 'account@test.com',
        'status'        => 'active',
        'kyc_status'    => 'approved',
    ]);

    $this->wallet = AccountWallet::create([
        'id'                => Str::uuid()->toString(),
        'account_id'        => $this->account->id,
        'available_balance' => 50000,
        'pending_balance'   => 10000,
        'total_balance'     => 60000,
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

    // These fields are guarded, set them directly
    $this->user->forceFill([
        'role_id' => $adminRole->id,
        'mfa_enabled' => true,
        'mfa_secret' => 'JBSWY3DPEHPK3PXP',
        'kyc_status' => 'approved',
    ])->save();

    $this->token = auth()->login($this->user);

    // Generate a valid MFA code for tests
    $google2fa = new Google2FA();
    $this->mfaCode = $google2fa->getCurrentOtp('JBSWY3DPEHPK3PXP');
});

it('creates a withdrawal request', function () {
    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/v1/withdrawals', [
            'amount'      => 10000,
            'method'      => 'pix',
            'mfa_code'    => $this->mfaCode,
            'destination'  => [
                'type'    => 'pix',
                'pix_key' => '12345678900',
                'pix_type' => 'cpf',
            ],
        ]);

    $response->assertStatus(201)
        ->assertJsonStructure(['success', 'data' => ['id', 'amount', 'status']]);

    $this->assertDatabaseHas('withdrawals', [
        'account_id' => $this->account->id,
        'amount'     => 10000,
        'method'     => 'pix',
    ]);
});

it('replays a withdrawal when reusing the same idempotency key', function () {
    $idempotencyKey = 'wd-idem-replay-001';

    $payload = [
        'amount'      => 10000,
        'method'      => 'pix',
        'mfa_code'    => $this->mfaCode,
        'destination' => [
            'type' => 'pix',
            'pix_key' => '12345678900',
            'pix_type' => 'cpf',
        ],
    ];

    $first = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->withHeader('X-Idempotency-Key', $idempotencyKey)
        ->postJson('/api/v1/withdrawals', $payload);

    $first->assertStatus(201)
        ->assertHeader('X-Idempotency-Key', $idempotencyKey)
        ->assertJsonPath('success', true);

    $withdrawalId = $first->json('data.id');

    Cache::forget('idempotency:' . hash('sha256', "{$this->account->id}:{$idempotencyKey}"));

    $second = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->withHeader('X-Idempotency-Key', $idempotencyKey)
        ->postJson('/api/v1/withdrawals', $payload);

    $second->assertStatus(200)
        ->assertHeader('X-Idempotency-Key', $idempotencyKey)
        ->assertHeader('X-Idempotency-Replayed', 'true')
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.id', $withdrawalId);

    $this->assertDatabaseCount('withdrawals', 1);
});

it('returns conflict when idempotency key is reused with different withdrawal payload', function () {
    $idempotencyKey = 'wd-idem-conflict-001';

    $basePayload = [
        'amount'      => 10000,
        'method'      => 'pix',
        'mfa_code'    => $this->mfaCode,
        'destination' => [
            'type' => 'pix',
            'pix_key' => '12345678900',
            'pix_type' => 'cpf',
        ],
    ];

    $this->withHeader('Authorization', "Bearer {$this->token}")
        ->withHeader('X-Idempotency-Key', $idempotencyKey)
        ->postJson('/api/v1/withdrawals', $basePayload)
        ->assertStatus(201);

    Cache::forget('idempotency:' . hash('sha256', "{$this->account->id}:{$idempotencyKey}"));

    $conflictingPayload = $basePayload;
    $conflictingPayload['amount'] = 12000;

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->withHeader('X-Idempotency-Key', $idempotencyKey)
        ->postJson('/api/v1/withdrawals', $conflictingPayload);

    $response->assertStatus(409)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'idempotency_key_reused_with_different_payload');

    $this->assertDatabaseCount('withdrawals', 1);
});

it('validates sufficient balance', function () {
    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/v1/withdrawals', [
            'amount'      => 100000,
            'method'      => 'pix',
            'mfa_code'    => $this->mfaCode,
            'destination'  => [
                'type'    => 'pix',
                'pix_key' => '12345678900',
                'pix_type' => 'cpf',
            ],
        ]);

    $response->assertStatus(422);
});

it('validates minimum withdrawal amount', function () {
    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/v1/withdrawals', [
            'amount'      => 50,
            'method'      => 'pix',
            'mfa_code'    => $this->mfaCode,
            'destination'  => [
                'type'    => 'pix',
                'pix_key' => '12345678900',
                'pix_type' => 'cpf',
            ],
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['amount']);
});

it('requires KYC approval for withdrawals', function () {
    $this->user->forceFill(['kyc_status' => 'pending'])->save();

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/v1/withdrawals', [
            'amount'      => 10000,
            'method'      => 'pix',
            'mfa_code'    => $this->mfaCode,
            'destination'  => [
                'type'    => 'pix',
                'pix_key' => '12345678900',
                'pix_type' => 'cpf',
            ],
        ]);

    $response->assertStatus(403);
});

it('lists withdrawals for authenticated user', function () {
    Withdrawal::create([
        'id'          => Str::uuid()->toString(),
        'account_id'  => $this->account->id,
        'user_id'     => $this->user->id,
        'amount'      => 10000,
        'fee'         => 0,
        'net_amount'  => 10000,
        'method'      => 'pix',
        'status'      => 'pending',
        'destination' => ['type' => 'pix', 'pix_key' => '12345678900', 'pix_type' => 'cpf'],
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/v1/withdrawals');

    $response->assertStatus(200)
        ->assertJsonStructure(['success', 'data' => [['id', 'amount', 'status']]]);
});

it('cancels a pending withdrawal', function () {
    $withdrawal = Withdrawal::create([
        'id'          => Str::uuid()->toString(),
        'account_id'  => $this->account->id,
        'user_id'     => $this->user->id,
        'amount'      => 10000,
        'fee'         => 0,
        'net_amount'  => 10000,
        'method'      => 'pix',
        'status'      => 'pending',
        'destination' => ['type' => 'pix', 'pix_key' => '12345678900', 'pix_type' => 'cpf'],
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson("/api/v1/withdrawals/{$withdrawal->id}/cancel");

    $response->assertStatus(200);

    $this->assertDatabaseHas('withdrawals', [
        'id'     => $withdrawal->id,
        'status' => 'cancelled',
    ]);
});

it('cannot cancel completed withdrawal', function () {
    $withdrawal = Withdrawal::create([
        'id'          => Str::uuid()->toString(),
        'account_id'  => $this->account->id,
        'user_id'     => $this->user->id,
        'amount'      => 10000,
        'fee'         => 0,
        'net_amount'  => 10000,
        'method'      => 'pix',
        'status'      => 'completed',
        'destination' => ['type' => 'pix', 'pix_key' => '12345678900', 'pix_type' => 'cpf'],
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson("/api/v1/withdrawals/{$withdrawal->id}/cancel");

    $response->assertStatus(422);
});

it('allows admin to cancel a pending withdrawal with mandatory reason', function () {
    $withdrawal = Withdrawal::create([
        'id'          => Str::uuid()->toString(),
        'account_id'  => $this->account->id,
        'user_id'     => $this->user->id,
        'amount'      => 10000,
        'fee'         => 0,
        'net_amount'  => 10000,
        'method'      => 'pix',
        'status'      => 'pending',
        'destination' => ['type' => 'pix', 'pix_key' => '12345678900', 'pix_type' => 'cpf'],
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson("/api/v1/admin/withdrawals/{$withdrawal->id}/cancel", [
            'reason' => 'Saque bloqueado por análise antifraude.',
        ]);

    $response->assertStatus(200)
        ->assertJsonPath('success', true);

    $this->assertDatabaseHas('withdrawals', [
        'id' => $withdrawal->id,
        'status' => 'cancelled',
        'failure_reason' => 'Saque bloqueado por análise antifraude.',
    ]);

    $this->wallet->refresh();
    expect((int) $this->wallet->available_balance)->toBe(60000);
    expect((int) $this->wallet->pending_balance)->toBe(0);

    $this->assertDatabaseHas('audit_logs', [
        'entity_type' => 'withdrawal',
        'entity_id' => $withdrawal->id,
        'action' => 'admin_cancelled',
    ]);
});

it('blocks admin cancellation for a processing withdrawal already sent to the gateway', function () {
    $withdrawal = Withdrawal::create([
        'id' => Str::uuid()->toString(),
        'account_id' => $this->account->id,
        'user_id' => $this->user->id,
        'amount' => 10000,
        'fee' => 0,
        'net_amount' => 10000,
        'method' => 'pix',
        'status' => 'processing',
        'acquirer' => 'payfort',
        'acquirer_transfer_id' => 'transfer-123',
        'destination' => ['type' => 'pix', 'pix_key' => '12345678900', 'pix_type' => 'cpf'],
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson("/api/v1/admin/withdrawals/{$withdrawal->id}/cancel", [
            'reason' => 'Cancelar por solicitação do suporte.',
        ]);

    $response->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'acquirer_api_cancellation_not_supported')
        ->assertJsonPath('data.acquirer', 'payfort')
        ->assertJsonPath('data.acquirer_transfer_id', 'transfer-123')
        ->assertJsonPath('message', 'O saque já foi enviado ao PayFort (transferência transfer-123). A API pública do adquirente não oferece cancelamento de PIX OUT. Cancele no painel do PayFort e aguarde o webhook de cancelamento para o estorno automático.');

    $withdrawal->refresh();
    expect($withdrawal->status)->toBe('processing');
});

it('returns detailed timeline logs for an admin withdrawal investigation', function () {
    $withdrawal = Withdrawal::create([
        'id'          => Str::uuid()->toString(),
        'account_id'  => $this->account->id,
        'user_id'     => $this->user->id,
        'amount'      => 10000,
        'fee'         => 0,
        'net_amount'  => 10000,
        'method'      => 'pix',
        'status'      => 'pending',
        'destination' => ['type' => 'pix', 'pix_key' => '12345678900', 'pix_type' => 'cpf'],
    ]);

    AuditLog::create([
        'id' => Str::uuid()->toString(),
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'action' => 'admin_manual_review',
        'entity_type' => 'withdrawal',
        'entity_id' => $withdrawal->id,
        'metadata' => ['reviewer' => 'qa'],
        'created_at' => now(),
    ]);

    DB::table('admin_access_logs')->insert([
        'id' => Str::uuid()->toString(),
        'user_id' => $this->user->id,
        'account_id' => $this->account->id,
        'endpoint' => "/api/v1/admin/withdrawals/{$withdrawal->id}/process",
        'method' => 'POST',
        'ip_address' => '127.0.0.1',
        'response_status' => 200,
        'response_time_ms' => 11.25,
        'accessed_at' => now(),
        'created_at' => now(),
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson("/api/v1/admin/withdrawals/{$withdrawal->id}/logs");

    $response->assertStatus(200)
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.withdrawal_id', $withdrawal->id);

    $events = $response->json('data.events');
    expect($events)->not->toBeEmpty();
    expect(collect($events)->pluck('source')->contains('audit_log'))->toBeTrue();
});
