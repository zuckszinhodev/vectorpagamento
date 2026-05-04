<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\Checkout;
use App\Models\Product;
use App\Services\CheckoutRendererService;
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
        'id'         => Str::uuid()->toString(),
        'account_id' => $this->account->id,
        'name'       => 'Checkout Test Product',
        'slug'       => 'checkout-test-product',
        'price'      => 15000,
        'type'       => 'digital',
        'status'     => 'active',
        'is_active'  => true,
    ]);

    $this->checkout = Checkout::create([
        'id'         => Str::uuid()->toString(),
        'product_id' => $this->product->id,
        'account_id' => $this->account->id,
        'name'       => 'Test Checkout',
        'slug'       => 'test-checkout-renderer',
        'is_active'  => true,
    ]);

    $this->service = app(CheckoutRendererService::class);
});

it('renders checkout to HTML string', function () {
    $html = $this->service->renderToHtml($this->checkout);

    expect($html)->toBeString();
    expect($html)->toContain('<html');
});

it('includes product name in rendered HTML', function () {
    $html = $this->service->renderToHtml($this->checkout);

    expect($html)->toContain('Checkout Test Product');
});

it('produces valid HTML with closing tags', function () {
    $html = $this->service->renderToHtml($this->checkout);

    expect($html)->toContain('</html>');
    expect($html)->toContain('</head>');
    expect($html)->toContain('</body>');
});

it('includes price in rendered HTML', function () {
    $html = $this->service->renderToHtml($this->checkout);

    expect($html)->toContain('150');
});
