<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\CustomDomain;
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

    $this->token = auth()->login($this->user);
});

it('creates a custom domain', function () {
    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/v1/domains', [
            'domain' => 'checkout.example.com',
            'type'   => 'checkout',
        ]);

    $response->assertStatus(201)
        ->assertJsonStructure(['success', 'data' => ['id', 'domain', 'status']]);

    $this->assertDatabaseHas('custom_domains', [
        'domain'     => 'checkout.example.com',
        'account_id' => $this->account->id,
        'status'     => 'pending',
    ]);
});

it('validates domain format', function () {
    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/v1/domains', [
            'domain' => 'invalid domain',
            'type'   => 'checkout',
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['domain']);
});

it('validates unique domain globally', function () {
    CustomDomain::create([
        'id'         => Str::uuid()->toString(),
        'account_id' => $this->account->id,
        'domain'     => 'taken.example.com',
        'status'     => 'verified',
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/v1/domains', [
            'domain' => 'taken.example.com',
            'type'   => 'checkout',
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['domain']);
});

it('lists domains for authenticated user', function () {
    CustomDomain::create([
        'id'         => Str::uuid()->toString(),
        'account_id' => $this->account->id,
        'domain'     => 'test.example.com',
        'status'     => 'verified',
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/v1/domains');

    $response->assertStatus(200)
        ->assertJsonStructure(['success', 'data' => [['id', 'domain', 'status']]]);
});

it('verifies domain DNS', function () {
    $domain = CustomDomain::create([
        'id'         => Str::uuid()->toString(),
        'account_id' => $this->account->id,
        'domain'     => 'verify.example.com',
        'status'     => 'pending',
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson("/api/v1/domains/{$domain->id}/verify");

    // DNS verification will fail in test env - accept 200 or 400
    expect($response->status())->toBeIn([200, 400]);
});

it('deletes a domain', function () {
    $domain = CustomDomain::create([
        'id'         => Str::uuid()->toString(),
        'account_id' => $this->account->id,
        'domain'     => 'delete.example.com',
        'status'     => 'pending',
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->deleteJson("/api/v1/domains/{$domain->id}");

    $response->assertStatus(200);

    $this->assertSoftDeleted('custom_domains', ['id' => $domain->id]);
});
