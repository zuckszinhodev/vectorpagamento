<?php

declare(strict_types=1);

use App\Domain\Auth\Entities\Role;
use App\Models\Account;
use App\Models\KycSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->account = Account::create([
        'id'            => Str::uuid()->toString(),
        'name'          => 'Test Account',
        'document'      => '12345678900',
        'document_type' => 'cpf',
        'email'         => 'account@test.com',
        'status'        => 'active',
        'kyc_status'    => 'pending',
    ]);

    $this->user = User::create([
        'id'         => Str::uuid()->toString(),
        'account_id' => $this->account->id,
        'email'      => 'user@test.com',
        'password'   => bcrypt('password'),
        'name'       => 'Test User',
        'status'     => 'active',
    ]);

    $this->adminRole = Role::create([
        'id'   => Str::uuid()->toString(),
        'name' => 'Admin',
        'slug' => 'admin',
    ]);

    $this->token = auth()->login($this->user);
});

it('submits KYC information', function () {
    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/v1/kyc/submit', [
            'full_name'            => 'John Doe',
            'birth_date'           => '1990-01-15',
            'document_number'      => '12345678900',
            'phone'                => '11999999999',
            'address_street'       => 'Test Street',
            'address_number'       => '123',
            'address_neighborhood' => 'Centro',
            'address_city'         => 'São Paulo',
            'address_state'        => 'SP',
            'address_zip_code'     => '01234567',
            'account_type'         => 'individual',
        ]);

    $response->assertStatus(200)
        ->assertJsonStructure(['success', 'data' => ['submission_id']]);
});

it('validates required KYC fields', function () {
    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/v1/kyc/submit', []);

    $response->assertStatus(422)
        ->assertJsonPath('success', false)
        ->assertJsonPath('error.code', 'validation_error')
        ->assertJsonPath('error.errors.full_name.0', 'O nome completo é obrigatório')
        ->assertJsonPath('error.errors.document_number.0', 'O CPF é obrigatório')
        ->assertJsonPath('error.errors.birth_date.0', 'A data de nascimento é obrigatória');
});

it('gets KYC submission status', function () {
    KycSubmission::create([
        'id'              => Str::uuid()->toString(),
        'user_id'         => $this->user->id,
        'full_name'       => 'John Doe',
        'birth_date'      => '1990-01-15',
        'document_number' => '12345678900',
        'phone'           => '11999999999',
        'status'          => 'pending',
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/v1/kyc/status');

    $response->assertStatus(200)
        ->assertJsonStructure(['success', 'data' => ['kyc_status']]);
});

it('admin approves KYC submission', function () {
    $this->user->forceFill(['role_id' => $this->adminRole->id, 'mfa_enabled' => true])->save();
    $this->token = auth()->login($this->user);

    $submission = KycSubmission::create([
        'id'              => Str::uuid()->toString(),
        'user_id'         => $this->user->id,
        'full_name'       => 'John Doe',
        'birth_date'      => '1990-01-15',
        'document_number' => '12345678900',
        'phone'           => '11999999999',
        'status'          => 'pending',
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson("/api/v1/admin/kyc/{$submission->id}/approve");

    $response->assertStatus(200);

    $this->assertDatabaseHas('kyc_submissions', [
        'id'     => $submission->id,
        'status' => 'approved',
    ]);
});

it('admin rejects KYC submission with reason', function () {
    $this->user->forceFill(['role_id' => $this->adminRole->id, 'mfa_enabled' => true])->save();
    $this->token = auth()->login($this->user);

    $submission = KycSubmission::create([
        'id'              => Str::uuid()->toString(),
        'user_id'         => $this->user->id,
        'full_name'       => 'John Doe',
        'birth_date'      => '1990-01-15',
        'document_number' => '12345678900',
        'phone'           => '11999999999',
        'status'          => 'pending',
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson("/api/v1/admin/kyc/{$submission->id}/reject", [
            'reason' => 'Document not clear',
        ]);

    $response->assertStatus(200);

    $this->assertDatabaseHas('kyc_submissions', [
        'id'               => $submission->id,
        'status'           => 'rejected',
        'rejection_reason' => 'Document not clear',
    ]);
});

it('admin list KYC submissions includes counts and search', function () {
    $this->user->forceFill(['role_id' => $this->adminRole->id, 'mfa_enabled' => true])->save();
    $this->token = auth()->login($this->user);

    KycSubmission::create([
        'id' => Str::uuid()->toString(),
        'user_id' => $this->user->id,
        'full_name' => 'John Doe',
        'birth_date' => '1990-01-15',
        'document_number' => '11111111111',
        'status' => 'pending',
    ]);
    KycSubmission::create([
        'id' => Str::uuid()->toString(),
        'user_id' => $this->user->id,
        'full_name' => 'John Doe',
        'birth_date' => '1990-01-15',
        'document_number' => '22222222222',
        'status' => 'under_review',
    ]);
    KycSubmission::create([
        'id' => Str::uuid()->toString(),
        'user_id' => $this->user->id,
        'full_name' => 'John Doe',
        'birth_date' => '1990-01-15',
        'document_number' => '33333333333',
        'status' => 'approved',
    ]);
    KycSubmission::create([
        'id' => Str::uuid()->toString(),
        'user_id' => $this->user->id,
        'full_name' => 'John Doe',
        'birth_date' => '1990-01-15',
        'document_number' => '44444444444',
        'status' => 'rejected',
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/v1/admin/kyc?status=all');

    $response->assertStatus(200)
        ->assertJsonPath('meta.counts.pending', 1)
        ->assertJsonPath('meta.counts.under_review', 1)
        ->assertJsonPath('meta.counts.approved', 1)
        ->assertJsonPath('meta.counts.rejected', 1);

    $searchResponse = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/v1/admin/kyc?status=all&search=John');

    $searchResponse->assertStatus(200)
        ->assertJsonPath('meta.total', 4);
});
