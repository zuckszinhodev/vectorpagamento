<?php

declare(strict_types=1);

use App\Models\Account;
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

    $this->token = auth()->login($this->user);
});

it('lists products for authenticated user', function () {
    Product::create([
        'id'          => Str::uuid()->toString(),
        'account_id'  => $this->account->id,
        'name'        => 'Test Product',
        'slug'        => 'test-product',
        'description' => 'Test Description',
        'price'       => 10000,
        'type'        => 'digital',
        'is_active'   => true,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson('/api/v1/products');

    $response->assertStatus(200)
        ->assertJsonStructure(['success', 'data' => [['id', 'name', 'price', 'type']]]);
});

it('persists a direct discount and restores the price when removed', function () {
    $response = $this->withHeader('Authorization', "Bearer {$this->token}")->postJson('/api/v1/products', [
        'name' => 'Discounted product', 'type' => 'digital', 'price' => 8000, 'original_price' => 10000,
        'metadata' => ['direct_discount' => ['enabled' => true, 'type' => 'percentage', 'value' => 20]],
    ])->assertCreated()->assertJsonPath('data.price', 8000)->assertJsonPath('data.original_price', 10000);
    $id = $response->json('data.id');
    $this->withHeader('Authorization', "Bearer {$this->token}")->putJson("/api/v1/products/{$id}", [
        'price' => 10000, 'original_price' => null, 'metadata' => ['direct_discount' => ['enabled' => false]],
    ])->assertOk();
    $this->assertDatabaseHas('products', ['id' => $id, 'price' => 10000, 'original_price' => null]);
});

it('creates a new product', function () {
    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/v1/products', [
            'name'        => 'New Product',
            'description' => 'Product Description',
            'price'       => 5000,
            'type'        => 'digital',
            'status'      => 'active',
        ]);

    $response->assertStatus(201)
        ->assertJsonStructure(['success', 'data' => ['id', 'name', 'price']]);

    $this->assertDatabaseHas('products', [
        'name'       => 'New Product',
        'price'      => 5000,
        'account_id' => $this->account->id,
    ]);
});

it('validates required fields when creating product', function () {
    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/v1/products', []);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['name', 'price', 'type']);
});

it('validates price is positive', function () {
    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/v1/products', [
            'name'  => 'Test',
            'price' => -100,
            'type'  => 'digital',
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['price']);
});

it('updates an existing product', function () {
    $product = Product::create([
        'id'          => Str::uuid()->toString(),
        'account_id'  => $this->account->id,
        'name'        => 'Original Name',
        'slug'        => 'original-name',
        'description' => 'Original Description',
        'price'       => 10000,
        'type'        => 'digital',
        'is_active'   => true,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->putJson("/api/v1/products/{$product->id}", [
            'name'  => 'Updated Name',
            'price' => 15000,
        ]);

    $response->assertStatus(200);

    $this->assertDatabaseHas('products', [
        'id'    => $product->id,
        'name'  => 'Updated Name',
        'price' => 15000,
    ]);
});

it('deletes a product', function () {
    $product = Product::create([
        'id'          => Str::uuid()->toString(),
        'account_id'  => $this->account->id,
        'name'        => 'To Delete',
        'slug'        => 'to-delete',
        'description' => 'Will be deleted',
        'price'       => 10000,
        'type'        => 'digital',
        'is_active'   => true,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->deleteJson("/api/v1/products/{$product->id}");

    $response->assertStatus(200);

    $this->assertSoftDeleted('products', ['id' => $product->id]);
});

it('prevents accessing other account products', function () {
    $otherAccount = Account::create([
        'id'            => Str::uuid()->toString(),
        'name'          => 'Other Account',
        'document'      => '98765432100',
        'document_type' => 'cpf',
        'email'         => 'other@test.com',
        'status'        => 'active',
    ]);

    $otherProduct = Product::create([
        'id'          => Str::uuid()->toString(),
        'account_id'  => $otherAccount->id,
        'name'        => 'Other Product',
        'slug'        => 'other-product',
        'description' => 'Not accessible',
        'price'       => 10000,
        'type'        => 'digital',
        'is_active'   => true,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->getJson("/api/v1/products/{$otherProduct->id}");

    $response->assertStatus(404);
});

it('generates unique slug from product name', function () {
    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/v1/products', [
            'name'  => 'My Awesome Product',
            'price' => 5000,
            'type'  => 'digital',
        ]);

    $response->assertStatus(201);

    $product = \App\Models\Product::where('name', 'My Awesome Product')->first();
    expect($product)->not->toBeNull();
    expect(str_starts_with($product->slug, 'my-awesome-product'))->toBeTrue();
});
