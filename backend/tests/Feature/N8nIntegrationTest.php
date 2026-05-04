<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\N8nIntegration;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

it('creates N8N integration', function () {
    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/v1/n8n', [
            'name'       => 'My N8N Workflow',
            'webhook_url' => 'https://n8n.example.com/webhook/test',
            'events'     => ['charge.paid', 'charge.cancelled'],
        ]);

    $response->assertStatus(201)
        ->assertJsonStructure(['success', 'data' => ['id', 'name', 'webhook_url']]);

    $this->assertDatabaseHas('n8n_integrations', [
        'name'       => 'My N8N Workflow',
        'account_id' => $this->account->id,
    ]);
});

it('validates webhook URL format', function () {
    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/v1/n8n', [
            'name'        => 'Test',
            'webhook_url' => 'not-a-url',
            'events'      => ['charge.paid'],
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['webhook_url']);
});

it('tests N8N integration connection', function () {
    Http::fake(['https://n8n.example.com/webhook/test' => Http::response(['success' => true], 200)]);

    $integration = N8nIntegration::create([
        'id'          => Str::uuid()->toString(),
        'account_id'  => $this->account->id,
        'name'        => 'Test Integration',
        'webhook_url' => 'https://n8n.example.com/webhook/test',
        'events'      => ['charge.paid'],
        'secret_key'  => Str::random(64),
        'is_active'   => true,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson("/api/v1/n8n/{$integration->id}/test");

    $response->assertStatus(200);

    Http::assertSent(function ($request) {
        return $request->url() === 'https://n8n.example.com/webhook/test';
    });
});

it('lists N8N integrations', function () {
    N8nIntegration::create([
        'id'          => Str::uuid()->toString(),
        'account_id'  => $this->account->id,
        'name'        => 'Test Integration',
        'webhook_url' => 'https://n8n.example.com/webhook/test',
        'events'      => ['charge.paid'],
        'secret_key'  => Str::random(64),
        'is_active'   => true,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/v1/n8n');

    $response->assertStatus(200)
        ->assertJsonStructure(['success', 'data']);
});

it('updates N8N integration', function () {
    $integration = N8nIntegration::create([
        'id'          => Str::uuid()->toString(),
        'account_id'  => $this->account->id,
        'name'        => 'Original Name',
        'webhook_url' => 'https://n8n.example.com/webhook/test',
        'events'      => ['charge.paid'],
        'secret_key'  => Str::random(64),
        'is_active'   => true,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson("/api/v1/n8n/{$integration->id}", [
            'name'   => 'Updated Name',
            'events' => ['charge.paid', 'charge.cancelled'],
        ]);

    $response->assertStatus(200);

    $this->assertDatabaseHas('n8n_integrations', [
        'id'   => $integration->id,
        'name' => 'Updated Name',
    ]);
});

it('deletes N8N integration', function () {
    $integration = N8nIntegration::create([
        'id'          => Str::uuid()->toString(),
        'account_id'  => $this->account->id,
        'name'        => 'To Delete',
        'webhook_url' => 'https://n8n.example.com/webhook/test',
        'events'      => ['charge.paid'],
        'secret_key'  => Str::random(64),
        'is_active'   => true,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->deleteJson("/api/v1/n8n/{$integration->id}");

    $response->assertStatus(200);

    $this->assertDatabaseMissing('n8n_integrations', ['id' => $integration->id]);
});
