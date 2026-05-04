<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Checkout;
use App\Models\Coupon;
use App\Models\Product;
use App\Services\PaymentService;
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

    $this->product = Product::create([
        'id'          => Str::uuid()->toString(),
        'account_id'  => $this->account->id,
        'name'        => 'Test Product',
        'slug'        => 'test-product',
        'description' => 'Test Description',
        'price'       => 10000,
        'type'        => 'digital',
        'is_active'   => true,
    ]);

    $this->checkout = Checkout::create([
        'id'         => Str::uuid()->toString(),
        'account_id' => $this->account->id,
        'product_id' => $this->product->id,
        'name'       => 'Test Checkout',
        'slug'       => 'test-checkout',
        'status'     => 'active',
        'settings'   => json_encode(['color' => '#000000']),
        'blocks'     => json_encode([
            ['type' => 'title', 'content' => 'Buy Now'],
            ['type' => 'price', 'price' => 10000],
        ]),
    ]);
});

it('loads public checkout page', function () {
    $response = $this->getJson("/api/v1/checkout/{$this->checkout->slug}");

    $response->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data' => ['id', 'name', 'slug', 'product'],
        ]);
});

it('applies valid coupon to checkout', function () {
    $coupon = Coupon::create([
        'id'          => Str::uuid()->toString(),
        'account_id'  => $this->account->id,
        'code'        => 'SAVE20',
        'type'        => 'percentage',
        'value'       => 20,
        'is_active'   => true,
        'usage_limit' => 100,
        'usage_count' => 0,
    ]);

    $response = $this->postJson('/api/v1/coupons/validate', [
        'code'   => 'SAVE20',
        'amount' => 10000,
    ]);

    $response->assertStatus(200)
        ->assertJson([
            'success' => true,
        ]);
});

it('calculates installments correctly', function () {
    $response = $this->getJson("/api/v1/checkout/{$this->checkout->slug}");

    $response->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data',
        ]);
});

it('processes PIX payment', function () {
    $paymentServiceMock = \Mockery::mock(PaymentService::class);
    $paymentServiceMock->shouldReceive('processPayment')
        ->once()
        ->andReturn([
            'success' => true,
            'acquirer' => 'mock',
            'transaction_id' => 'txn_test_pix_123',
            'status' => 'pending',
            'pix_qr_code' => '00020101021226FAKEPIXQRCODE5802BR5909TESTSTORE6009SAOPAULO62070503***6304ABCD',
            'pix_copy_paste' => '00020101021226FAKEPIXCOPYPASTE5802BR5909TESTSTORE6009SAOPAULO62070503***6304ABCD',
        ]);
    $this->app->instance(PaymentService::class, $paymentServiceMock);

    $response = $this->postJson("/api/v1/checkout/{$this->checkout->slug}/process", [
        'payment_method' => 'pix',
        'customer'       => [
            'name'     => 'John Doe',
            'email'    => 'john@test.com',
            'document' => '12345678900',
        ],
    ]);

    $response->assertStatus(201)
        ->assertJsonStructure([
            'success',
            'data' => ['charge_id', 'status', 'amount', 'payment_method'],
        ]);
});

it('validates customer data on checkout', function () {
    $response = $this->postJson("/api/v1/checkout/{$this->checkout->slug}/process", [
        'payment_method' => 'pix',
        'customer'       => [],
    ]);

    $response->assertStatus(422)
        ->assertJson([
            'success' => false,
            'error' => ['code' => 'validation_error'],
        ]);
});

it('returns 404 for non-existent checkout', function () {
    $response = $this->getJson('/api/v1/checkout/non-existent-slug');

    $response->assertStatus(404);
});
