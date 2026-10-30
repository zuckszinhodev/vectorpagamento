<?php

declare(strict_types=1);

use App\Domain\Auth\Entities\Role;
use App\Models\Account;
use App\Models\AccountWallet;
use App\Models\AcquirerConfig;
use App\Models\Charge;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\Withdrawal;
use App\Services\PaymentService;
use App\Services\SystemFeeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function feeAccount(?string $roleSlug = null): array
{
    $email = 'fees-' . Str::lower(Str::random(8)) . '@example.com';

    $account = Account::create([
        'id' => Str::uuid()->toString(),
        'name' => 'Fee Account',
        'document' => str_pad((string) random_int(1, 99999999999), 11, '0', STR_PAD_LEFT),
        'document_type' => 'cpf',
        'email' => $email,
        'status' => 'active',
    ]);

    AccountWallet::create([
        'id' => Str::uuid()->toString(),
        'account_id' => $account->id,
        'available_balance' => 0,
        'pending_balance' => 0,
        'blocked_balance' => 0,
        'currency' => 'BRL',
    ]);

    $user = User::create([
        'id' => Str::uuid()->toString(),
        'account_id' => $account->id,
        'email' => $email,
        'password' => Hash::make('password123'),
        'name' => 'Fee User',
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

function feeAcquirer(): AcquirerConfig
{
    DB::table('acquirer_configs')->where('acquirer_key', 'kokupay')->delete();

    return AcquirerConfig::create([
        'acquirer_key' => 'kokupay',
        'display_name' => 'Koku Pay',
        'type' => 'pix',
        'enabled' => true,
        'supported_methods' => ['pix'],
        'acquirer_fee_percentage' => 2.00,
        'acquirer_fee_fixed' => 1.00,
        'acquirer_fee_pix_out_percentage' => 0,
        'acquirer_fee_pix_out_fixed' => 5.00,
        'gateway_fee_percentage' => 7.00,
        'gateway_fee_fixed' => 3.00,
        'priority' => 1,
    ]);
}

function setPixFee(float $percentage, float $fixed): void
{
    foreach (['percentage' => $percentage, 'fixed' => $fixed] as $part => $value) {
        $key = "system_fee_pix_{$part}";
        SystemSetting::updateOrCreate(['key' => $key], ['value' => (string) $value, 'type' => 'string', 'group' => 'fees', 'description' => $key]);
        Cache::forget("system_setting_{$key}");
    }
}

function feeCharge(string $accountId, int $amount, array $overrides = []): Charge
{
    return Charge::create(array_merge([
        'id' => Str::uuid()->toString(),
        'account_id' => $accountId,
        'amount' => $amount,
        'currency' => 'BRL',
        'payment_method' => 'pix',
        'status' => 'approved',
        'acquirer' => 'kokupay',
        'idempotency_key' => Str::random(32),
        'customer' => ['name' => 'Teste', 'email' => 'teste@example.com'],
        'paid_at' => now(),
    ], $overrides));
}

it('cobra do seller só a taxa do sistema e grava a da adquirente como custo', function () {
    feeAcquirer();
    setPixFee(7, 3);
    [$account] = feeAccount();
    $charge = feeCharge($account->id, 10000);

    app(PaymentService::class)->updateWalletOnPayment($charge);

    $charge->refresh();
    expect($charge->fee_amount)->toBe(1000)
        ->and($charge->net_amount)->toBe(9000)
        ->and($charge->acquirer_fee_amount)->toBe(300);
    expect((int) AccountWallet::where('account_id', $account->id)->value('available_balance'))->toBe(9000);
});

it('mudar a taxa não reescreve cobranças já creditadas', function () {
    feeAcquirer();
    setPixFee(7, 3);
    [$account] = feeAccount();
    $charge = feeCharge($account->id, 10000);
    app(PaymentService::class)->updateWalletOnPayment($charge);

    setPixFee(20, 0);

    expect(app(SystemFeeService::class)->forCharge($charge->refresh()))->toBe(1000);
});

it('a taxa nunca passa do valor da venda', function () {
    setPixFee(7, 3);

    expect(app(SystemFeeService::class)->calculate(100, 'pix'))->toBe(100);
});

it('sem taxa do sistema mantém a taxa de gateway da adquirente', function () {
    $acquirer = feeAcquirer();
    SystemSetting::where('key', 'like', 'system_fee_%')->delete();
    Cache::flush();

    expect(app(SystemFeeService::class)->calculate(10000, 'pix', $acquirer))->toBe(1000);
});

it('calcula o lucro com custo gravado, custo estimado e saques', function () {
    feeAcquirer();
    [$account, , $user] = feeAccount();

    feeCharge($account->id, 10000, ['fee_amount' => 1000, 'net_amount' => 9000, 'acquirer_fee_amount' => 250]);
    feeCharge($account->id, 10000, ['fee_amount' => 1000, 'net_amount' => 9000]);

    Withdrawal::create([
        'id' => Str::uuid()->toString(),
        'account_id' => $account->id,
        'user_id' => $user->id,
        'amount' => 5000,
        'fee' => 1000,
        'net_amount' => 4000,
        'method' => 'pix',
        'status' => 'completed',
        'acquirer' => 'kokupay',
        'processed_at' => now(),
        'destination' => ['pix_key' => 'chave@example.com'],
    ]);

    $totals = app(SystemFeeService::class)->profitReport()['totals'];

    expect($totals['volume'])->toBe(20000)
        ->and($totals['fee_revenue'])->toBe(2000)
        ->and($totals['acquirer_cost'])->toBe(550)
        ->and($totals['withdrawal_fee_revenue'])->toBe(1000)
        ->and($totals['withdrawal_cost'])->toBe(500)
        ->and($totals['profit'])->toBe(1950);
});

it('salva as taxas pela aba do admin e bloqueia quem não é admin', function () {
    [, $adminToken] = feeAccount('owner');
    [, $sellerToken] = feeAccount();

    $payload = [
        'rates' => [
            'pix' => ['percentage' => 6.5, 'fixed' => 2],
            'credit_card' => ['percentage' => 8, 'fixed' => 1],
            'boleto' => ['percentage' => 4, 'fixed' => 3],
        ],
        'withdrawal' => ['pix_fee' => 500, 'bank_fee' => 700, 'extra_enabled' => false, 'extra_type' => 'fixed', 'extra_value' => 0],
    ];

    test()->withHeader('Authorization', "Bearer {$sellerToken}")
        ->putJson('/api/v1/admin/fees', $payload)
        ->assertStatus(403);

    auth('api')->forgetUser();

    test()->withHeader('Authorization', "Bearer {$adminToken}")
        ->putJson('/api/v1/admin/fees', $payload)
        ->assertStatus(200)
        ->assertJsonPath('data.rates.pix.percentage', 6.5)
        ->assertJsonPath('data.withdrawal.pix_fee', 500);

    expect(app(SystemFeeService::class)->calculate(10000, 'pix'))->toBe(850);
});

it('não expõe mais taxa por seller', function () {
    [, $adminToken, $user] = feeAccount('owner');

    test()->withHeader('Authorization', "Bearer {$adminToken}")
        ->putJson("/api/v1/admin/customers/{$user->id}/fees", ['pix_fee_percent' => 1])
        ->assertStatus(404);
});
