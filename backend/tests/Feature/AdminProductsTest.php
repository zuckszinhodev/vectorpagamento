<?php

declare(strict_types=1);

use App\Domain\Auth\Entities\Role;
use App\Models\Account;
use App\Models\Charge;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function productUser(?string $roleSlug = null): array
{
    $email = 'products-' . Str::lower(Str::random(8)) . '@example.com';

    $account = Account::create([
        'id' => Str::uuid()->toString(),
        'name' => 'Conta Produtos',
        'document' => str_pad((string) random_int(1, 99999999999), 11, '0', STR_PAD_LEFT),
        'document_type' => 'cpf',
        'email' => $email,
        'status' => 'active',
    ]);

    $user = User::create([
        'id' => Str::uuid()->toString(),
        'account_id' => $account->id,
        'email' => $email,
        'password' => Hash::make('password123'),
        'name' => 'Seller Produtos',
        'status' => 'active',
    ]);

    if ($roleSlug) {
        $role = Role::where('slug', $roleSlug)->first() ?? Role::create([
            'id' => Str::uuid()->toString(),
            'name' => $roleSlug,
            'slug' => $roleSlug,
            'permissions' => ['*'],
        ]);
        $user->forceFill(['role_id' => $role->id, 'mfa_enabled' => true])->save();
    }

    return [$account, auth('api')->login($user), $user];
}

function sellerProduct(string $accountId, string $name = 'Curso Teste'): Product
{
    return Product::create([
        'id' => Str::uuid()->toString(),
        'account_id' => $accountId,
        'name' => $name,
        'slug' => Str::slug($name) . '-' . Str::lower(Str::random(6)),
        'type' => 'digital',
        'price' => 9900,
        'currency' => 'BRL',
        'is_active' => true,
    ]);
}

it('lista produtos de todos os sellers com vendas', function () {
    [, $adminToken] = productUser('owner');
    [$seller] = productUser();
    $product = sellerProduct($seller->id);

    Charge::create([
        'id' => Str::uuid()->toString(),
        'account_id' => $seller->id,
        'product_id' => $product->id,
        'amount' => 9900,
        'currency' => 'BRL',
        'payment_method' => 'pix',
        'status' => 'approved',
        'idempotency_key' => Str::random(32),
        'customer' => ['name' => 'Teste', 'email' => 'teste@example.com'],
    ]);

    test()->withHeader('Authorization', "Bearer {$adminToken}")
        ->getJson('/api/v1/admin/products/manage?search=curso')
        ->assertStatus(200)
        ->assertJsonPath('meta.total', 1)
        ->assertJsonPath('data.0.sales_count', 1)
        ->assertJsonPath('data.0.sales_amount', 9900)
        ->assertJsonPath('stats.active', 1);
});

it('filtra produtos e contadores por seller', function () {
    [, $adminToken] = productUser('owner');
    [$sellerA] = productUser();
    [$sellerB] = productUser();
    sellerProduct($sellerA->id, 'Produto A');
    sellerProduct($sellerB->id, 'Produto B1');
    sellerProduct($sellerB->id, 'Produto B2');

    test()->withHeader('Authorization', "Bearer {$adminToken}")
        ->getJson("/api/v1/admin/products/manage?account_id={$sellerB->id}")
        ->assertStatus(200)
        ->assertJsonPath('meta.total', 2)
        ->assertJsonPath('stats.total', 2)
        ->assertJsonCount(2, 'sellers');
});

it('bloqueio do admin impede o seller de reativar', function () {
    [, $adminToken] = productUser('owner');
    [$seller, , $sellerUser] = productUser();
    $product = sellerProduct($seller->id);

    test()->withHeader('Authorization', "Bearer {$adminToken}")
        ->postJson("/api/v1/admin/products/manage/{$product->id}/block", ['reason' => 'Produto proibido'])
        ->assertStatus(200);

    expect($product->refresh()->is_active)->toBeFalse();

    $product->update(['is_active' => true]);
    expect($product->refresh()->is_active)->toBeFalse();

    test()->withHeader('Authorization', "Bearer {$adminToken}")
        ->postJson("/api/v1/admin/products/manage/{$product->id}/unblock")
        ->assertStatus(200);

    expect($product->refresh()->is_active)->toBeTrue()
        ->and($product->admin_blocked_at)->toBeNull();
});

it('exige motivo para bloquear e excluir', function () {
    [, $adminToken] = productUser('owner');
    [$seller] = productUser();
    $product = sellerProduct($seller->id);
    $client = test()->withHeader('Authorization', "Bearer {$adminToken}");

    $client->postJson("/api/v1/admin/products/manage/{$product->id}/block", [])->assertStatus(422);
    $client->deleteJson("/api/v1/admin/products/manage/{$product->id}", [])->assertStatus(422);

    $client->deleteJson("/api/v1/admin/products/manage/{$product->id}", ['reason' => 'Fraude confirmada'])->assertStatus(200);

    expect(Product::find($product->id))->toBeNull()
        ->and(Product::withTrashed()->find($product->id))->not->toBeNull();
});

it('admin edita o produto sem mudar o endereço', function () {
    [, $adminToken] = productUser('owner');
    [$seller] = productUser();
    $product = sellerProduct($seller->id);
    $slug = $product->slug;

    test()->withHeader('Authorization', "Bearer {$adminToken}")
        ->putJson("/api/v1/admin/products/manage/{$product->id}", [
            'name' => 'Curso Novo',
            'description' => 'Descrição nova',
            'type' => 'service',
            'price' => 12900,
            'original_price' => 19900,
            'category' => 'Educação',
            'stock_enabled' => true,
            'stock_quantity' => 10,
            'affiliate_enabled' => true,
            'commission_percent' => 15,
        ])
        ->assertStatus(200)
        ->assertJsonPath('data.name', 'Curso Novo')
        ->assertJsonPath('data.price', 12900);

    $product->refresh();
    expect($product->slug)->toBe($slug)
        ->and($product->type)->toBe('service')
        ->and($product->stock_quantity)->toBe(10)
        ->and($product->affiliate_enabled)->toBeTrue();
});

it('não aceita preço abaixo do mínimo nem afiliado sem categoria', function () {
    [, $adminToken] = productUser('owner');
    [$seller] = productUser();
    $product = sellerProduct($seller->id);
    $payload = [
        'name' => 'Curso',
        'type' => 'digital',
        'price' => 50,
        'stock_enabled' => false,
        'affiliate_enabled' => false,
    ];
    $client = test()->withHeader('Authorization', "Bearer {$adminToken}");

    $client->putJson("/api/v1/admin/products/manage/{$product->id}", $payload)->assertStatus(422);
    $client->putJson("/api/v1/admin/products/manage/{$product->id}", array_merge($payload, ['price' => 9900, 'affiliate_enabled' => true]))
        ->assertStatus(422);
});

it('seller não acessa a gestão de produtos', function () {
    [, $sellerToken] = productUser();

    test()->withHeader('Authorization', "Bearer {$sellerToken}")
        ->getJson('/api/v1/admin/products/manage')
        ->assertStatus(403);
});

it('aprovar na vitrine grava o status', function () {
    [, $adminToken] = productUser('owner');
    [$seller] = productUser();
    $product = sellerProduct($seller->id);
    $product->forceFill(['is_showcase' => true, 'showcase_status' => 'pending'])->save();

    test()->withHeader('Authorization', "Bearer {$adminToken}")
        ->postJson("/api/v1/admin/showcase/products/{$product->id}/approve")
        ->assertStatus(200);

    expect($product->refresh()->showcase_status)->toBe('approved');
});
