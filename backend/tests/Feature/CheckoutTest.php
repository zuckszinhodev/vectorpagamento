<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Checkout;
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
        'id'          => Str::uuid()->toString(),
        'account_id'  => $this->account->id,
        'name'        => 'Test Product',
        'slug'        => 'test-product-checkout',
        'description' => 'Test Description',
        'price'       => 10000,
        'type'        => 'digital',
        'is_active'   => true,
    ]);

    $this->token = auth()->login($this->user);
});

it('creates a checkout for a product', function () {
    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/v1/checkouts', [
            'product_id' => $this->product->id,
            'name'       => 'My Checkout',
            'slug'       => 'my-checkout',
        ]);

    $response->assertStatus(201)
        ->assertJsonStructure(['success', 'data' => ['id', 'name', 'slug', 'product_id']]);

    $this->assertDatabaseHas('checkouts', [
        'product_id' => $this->product->id,
        'name'       => 'My Checkout',
        'slug'       => 'my-checkout',
    ]);
});

it('lists checkouts for authenticated user', function () {
    Checkout::create([
        'id'         => Str::uuid()->toString(),
        'account_id' => $this->account->id,
        'product_id' => $this->product->id,
        'name'       => 'Test Checkout',
        'slug'       => 'test-checkout',
        'settings'   => json_encode([]),
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/v1/checkouts');

    $response->assertStatus(200)
        ->assertJsonStructure(['success', 'data' => [['id', 'name', 'slug']]]);
});

it('auto-increments duplicate slug per account', function () {
    Checkout::create([
        'id'         => Str::uuid()->toString(),
        'account_id' => $this->account->id,
        'product_id' => $this->product->id,
        'name'       => 'First Checkout',
        'slug'       => 'duplicate-slug',
        'settings'   => json_encode([]),
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/v1/checkouts', [
            'product_id' => $this->product->id,
            'name'       => 'Second Checkout',
            'slug'       => 'duplicate-slug',
        ]);

    $response->assertStatus(201);

    $newSlug = $response->json('data.slug');
    expect($newSlug)->not->toBe('duplicate-slug');
});

it('updates checkout name', function () {
    $checkout = Checkout::create([
        'id'         => Str::uuid()->toString(),
        'account_id' => $this->account->id,
        'product_id' => $this->product->id,
        'name'       => 'Test Checkout',
        'slug'       => 'test-checkout',
        'settings'   => json_encode(['color' => '#000000']),
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson("/api/v1/checkouts/{$checkout->id}", [
            'name' => 'Updated Checkout',
        ]);

    $response->assertStatus(200);

    $checkout->refresh();
    expect($checkout->name)->toBe('Updated Checkout');
});

it('clones a checkout', function () {
    $original = Checkout::create([
        'id'         => Str::uuid()->toString(),
        'account_id' => $this->account->id,
        'product_id' => $this->product->id,
        'name'       => 'Original Checkout',
        'slug'       => 'original',
        'settings'   => json_encode(['color' => '#000000']),
        'blocks'     => json_encode([['type' => 'title', 'content' => 'Hello']]),
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson("/api/v1/checkouts/{$original->id}/clone");

    $response->assertStatus(201);

    $this->assertDatabaseHas('checkouts', [
        'name'       => 'Original Checkout (Cópia)',
        'account_id' => $this->account->id,
    ]);
});

it('deletes a checkout', function () {
    $checkout = Checkout::create([
        'id'         => Str::uuid()->toString(),
        'account_id' => $this->account->id,
        'product_id' => $this->product->id,
        'name'       => 'To Delete',
        'slug'       => 'to-delete',
        'settings'   => json_encode([]),
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->deleteJson("/api/v1/checkouts/{$checkout->id}");

    $response->assertStatus(200);

    $this->assertSoftDeleted('checkouts', ['id' => $checkout->id]);
});
