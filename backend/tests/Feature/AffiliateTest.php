<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Affiliate;
use App\Models\Product;
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

    $this->product = Product::create([
        'id'         => Str::uuid()->toString(),
        'account_id' => $this->account->id,
        'name'       => 'Test Product',
        'slug'       => 'test-product-affiliate',
        'price'      => 10000,
        'type'       => 'digital',
        'status'     => 'active',
        'is_active'  => true,
    ]);

    $this->token = auth()->login($this->user);
});

it('creates an affiliate', function () {
    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/v1/affiliates', [
            'name'             => 'John Affiliate',
            'email'            => 'affiliate@test.com',
            'commission_type'  => 'percentage',
            'commission_value' => 10.5,
            'product_id'       => $this->product->id,
        ]);

    $response->assertStatus(201)
        ->assertJsonStructure(['success', 'data' => ['id', 'name', 'code', 'commission_type', 'commission_value']]);

    $this->assertDatabaseHas('affiliates', [
        'name'             => 'John Affiliate',
        'commission_type'  => 'percentage',
        'account_id'       => $this->account->id,
    ]);
});

it('requires commission_type and commission_value', function () {
    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/v1/affiliates', [
            'name'  => 'Test',
            'email' => 'test@test.com',
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['commission_type', 'commission_value']);
});

it('validates commission_value is not negative', function () {
    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/v1/affiliates', [
            'name'             => 'Test',
            'email'            => 'test@test.com',
            'commission_type'  => 'percentage',
            'commission_value' => -5,
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['commission_value']);
});

it('lists affiliates for authenticated user', function () {
    Affiliate::create([
        'id'               => Str::uuid()->toString(),
        'account_id'       => $this->account->id,
        'user_id'          => $this->user->id,
        'product_id'       => $this->product->id,
        'token'            => Str::random(64),
        'name'             => 'Test Affiliate',
        'email'            => 'affiliate@test.com',
        'code'             => 'TEST123',
        'commission_type'  => 'percentage',
        'commission_value' => 10,
        'status'           => 'pending',
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/v1/affiliates');

    $response->assertStatus(200)
        ->assertJsonStructure(['success', 'data']);
});

it('updates affiliate information', function () {
    $affiliate = Affiliate::create([
        'id'               => Str::uuid()->toString(),
        'account_id'       => $this->account->id,
        'user_id'          => $this->user->id,
        'product_id'       => $this->product->id,
        'token'            => Str::random(64),
        'name'             => 'Original Name',
        'email'            => 'original@test.com',
        'code'             => 'ORIGINAL',
        'commission_type'  => 'percentage',
        'commission_value' => 10,
        'status'           => 'pending',
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson("/api/v1/affiliates/{$affiliate->id}", [
            'name'             => 'Updated Name',
            'commission_value' => 15,
        ]);

    $response->assertStatus(200);

    $this->assertDatabaseHas('affiliates', [
        'id'               => $affiliate->id,
        'name'             => 'Updated Name',
    ]);
});

it('deactivates an affiliate', function () {
    $affiliate = Affiliate::create([
        'id'               => Str::uuid()->toString(),
        'account_id'       => $this->account->id,
        'user_id'          => $this->user->id,
        'product_id'       => $this->product->id,
        'token'            => Str::random(64),
        'name'             => 'Active Affiliate',
        'email'            => 'active@test.com',
        'code'             => 'ACTIVE',
        'commission_type'  => 'percentage',
        'commission_value' => 10,
        'status'           => 'pending',
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson("/api/v1/affiliates/{$affiliate->id}", [
            'status' => 'inactive',
        ]);

    $response->assertStatus(200);

    $this->assertDatabaseHas('affiliates', [
        'id'     => $affiliate->id,
        'status' => 'inactive',
    ]);
});

it('deletes an affiliate', function () {
    $affiliate = Affiliate::create([
        'id'               => Str::uuid()->toString(),
        'account_id'       => $this->account->id,
        'user_id'          => $this->user->id,
        'product_id'       => $this->product->id,
        'token'            => Str::random(64),
        'name'             => 'To Delete',
        'email'            => 'delete@test.com',
        'code'             => 'DELETE',
        'commission_type'  => 'percentage',
        'commission_value' => 10,
        'status'           => 'pending',
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->deleteJson("/api/v1/affiliates/{$affiliate->id}");

    $response->assertStatus(200);

    $this->assertSoftDeleted('affiliates', ['id' => $affiliate->id]);
});
