<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Coupon;
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

it('creates a percentage coupon', function () {
    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/v1/coupons', [
            'code'           => 'SAVE20',
            'type'           => 'percentage',
            'value'          => 20,
            'max_uses'       => 100,
            'expires_at'     => now()->addDays(30)->toDateTimeString(),
        ]);

    $response->assertStatus(201)
        ->assertJsonStructure(['success', 'data' => ['id', 'code', 'type', 'value']]);

    $this->assertDatabaseHas('coupons', [
        'code'       => 'SAVE20',
        'type'       => 'percentage',
        'value'      => 20,
        'account_id' => $this->account->id,
    ]);
});

it('creates a fixed value coupon', function () {
    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/v1/coupons', [
            'code'       => 'FIXED50',
            'type'       => 'fixed',
            'value'      => 5000,
            'max_uses'   => 50,
        ]);

    $response->assertStatus(201);

    $this->assertDatabaseHas('coupons', [
        'code'  => 'FIXED50',
        'type'  => 'fixed',
        'value' => 5000,
    ]);
});

it('validates unique coupon code per account', function () {
    Coupon::create([
        'id'         => Str::uuid()->toString(),
        'account_id' => $this->account->id,
        'code'       => 'DUPLICATE',
        'type'       => 'percentage',
        'value'      => 10,
        'is_active'  => true,
    ]);

    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/v1/coupons', [
            'code'  => 'DUPLICATE',
            'type'  => 'percentage',
            'value' => 15,
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['code']);
});

it('validates percentage value is between 0 and 100', function () {
    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/v1/coupons', [
            'code'  => 'INVALID',
            'type'  => 'percentage',
            'value' => -10,
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['value']);
});

it('validates fixed value is positive', function () {
    $response = $this->withHeader('Authorization', "Bearer {$this->token}")
        ->postJson('/api/v1/coupons', [
            'code'  => 'INVALID',
            'type'  => 'fixed',
            'value' => -100,
        ]);

    $response->assertStatus(422)
        ->assertJsonValidationErrors(['value']);
});

it('validates coupon code publicly', function () {
    Coupon::create([
        'id'          => Str::uuid()->toString(),
        'account_id'  => $this->account->id,
        'code'        => 'VALID20',
        'type'        => 'percentage',
        'value'       => 20,
        'is_active'   => true,
        'usage_limit' => 100,
        'usage_count' => 50,
    ]);

    $response = $this->postJson('/api/v1/coupons/validate', [
        'code'   => 'VALID20',
        'amount' => 10000,
    ]);

    $response->assertStatus(200)
        ->assertJson(['success' => true]);
});

it('rejects expired coupon', function () {
    Coupon::create([
        'id'          => Str::uuid()->toString(),
        'account_id'  => $this->account->id,
        'code'        => 'EXPIRED',
        'type'        => 'percentage',
        'value'       => 20,
        'is_active'   => true,
        'valid_until' => now()->subDays(1),
    ]);

    $response = $this->postJson('/api/v1/coupons/validate', [
        'code'   => 'EXPIRED',
        'amount' => 10000,
    ]);

    $response->assertStatus(404);
});

it('rejects coupon at max uses', function () {
    Coupon::create([
        'id'          => Str::uuid()->toString(),
        'account_id'  => $this->account->id,
        'code'        => 'MAXED',
        'type'        => 'percentage',
        'value'       => 20,
        'is_active'   => true,
        'usage_limit' => 100,
        'usage_count' => 100,
    ]);

    $response = $this->postJson('/api/v1/coupons/validate', [
        'code'   => 'MAXED',
        'amount' => 10000,
    ]);

    $response->assertStatus(404);
});
