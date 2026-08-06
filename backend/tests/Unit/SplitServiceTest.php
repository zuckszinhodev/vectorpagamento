<?php

declare(strict_types=1);

use App\Domain\Acquirer\Entities\Acquirer;
use App\Models\Account;
use App\Models\AccountWallet;
use App\Models\Charge;
use App\Models\Checkout;
use App\Models\Product;
use App\Models\Split;
use App\Models\SplitRule;
use App\Models\Transaction;
use App\Services\SplitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/* ─── helpers ─── */

function makeAccount(array $overrides = []): Account
{
    return Account::create(array_merge([
        'id'            => Str::uuid()->toString(),
        'name'          => 'Test Account',
        'document'      => '12345678900',
        'document_type' => 'cpf',
        'email'         => 'account@test.com',
        'status'        => 'active',
    ], $overrides));
}

function makeProduct(string $accountId, array $overrides = []): Product
{
    return Product::create(array_merge([
        'id'         => Str::uuid()->toString(),
        'account_id' => $accountId,
        'name'       => 'Test Product',
        'slug'       => 'test-product-' . Str::random(6),
        'price'      => 10000,
        'currency'   => 'BRL',
        'type'       => 'digital',
        'is_active'  => true,
    ], $overrides));
}

function makeCheckout(string $accountId, ?string $productId = null, array $overrides = []): Checkout
{
    return Checkout::create(array_merge([
        'id'         => Str::uuid()->toString(),
        'account_id' => $accountId,
        'product_id' => $productId,
        'name'       => 'Test Checkout',
        'slug'       => 'test-checkout-' . Str::random(6),
        'type'       => 'standard',
        'status'     => 'active',
    ], $overrides));
}

function makeAcquirer(): Acquirer
{
    return Acquirer::create([
        'name'       => 'Test Acquirer',
        'slug'       => 'test-acquirer-' . Str::random(6),
        'is_active'  => true,
    ]);
}

function makePaidCharge(string $accountId, ?string $productId = null, ?string $checkoutId = null, array $overrides = []): array
{
    $acquirer = makeAcquirer();

    $charge = Charge::create(array_merge([
        'id'              => Str::uuid()->toString(),
        'account_id'      => $accountId,
        'product_id'      => $productId,
        'checkout_id'     => $checkoutId,
        'idempotency_key' => 'idem-' . Str::random(20),
        'amount'          => 10000,
        'fee_amount'      => 90,
        'net_amount'      => 9910,
        'currency'        => 'BRL',
        'payment_method'  => 'pix',
        'status'          => 'approved',
        'customer'        => ['name' => 'Test Customer', 'email' => 'customer@test.com', 'document' => '12345678900'],
        'paid_at'         => now(),
    ], $overrides));

    $transaction = Transaction::create([
        'id'          => Str::uuid()->toString(),
        'charge_id'   => $charge->id,
        'acquirer_id' => $acquirer->id,
        'type'        => 'capture',
        'status'      => 'approved',
        'amount'      => $charge->amount,
        'net_amount'  => $charge->net_amount ?? $charge->amount,
    ]);

    // Espelha o que o PaymentService faz antes de chamar o split: o líquido
    // inteiro já está na carteira do produtor. O split redistribui esse saldo,
    // então sem esse crédito não haveria de onde tirar o valor dos recebedores.
    $wallet = AccountWallet::firstOrCreate(
        ['account_id' => $accountId],
        [
            'id'                => Str::uuid()->toString(),
            'currency'          => 'BRL',
            'available_balance' => 0,
            'pending_balance'   => 0,
            'blocked_balance'   => 0,
        ],
    );
    $wallet->increment('available_balance', (int) ($charge->net_amount ?? $charge->amount));

    return [$charge, $transaction];
}

function makeRule(string $accountId, array $overrides = []): SplitRule
{
    $recipientId = Str::uuid()->toString();
    return SplitRule::create(array_merge([
        'id'                     => Str::uuid()->toString(),
        'account_id'             => $accountId,
        'name'                   => 'Test Rule',
        'is_default'             => false,
        'is_active'              => true,
        'priority'               => 0,
        'recipients'             => [
            [
                'recipient_id'   => $recipientId,
                'recipient_type' => 'account',
                'type'           => 'percentage',
                'value'          => 10,
                'description'    => 'Test recipient',
            ],
        ],
        'charge_fee_to_producer' => true,
        'minimum_amount'         => 0,
        'auto_process'           => false,
        'transfer_delay_hours'   => 0,
    ], $overrides));
}

/* ─── setup ─── */

beforeEach(function () {
    $this->account = makeAccount();
    $this->service = new SplitService();
});

/* ═══════════════════════════════════════════
   BASIC SERVICE METHODS
   ═══════════════════════════════════════════ */

it('returns split config for account as array', function () {
    $config = $this->service->getSplitConfig($this->account->id);
    expect($config)->toBeArray();
});

it('returns empty splits for non-existent charge', function () {
    $splits = $this->service->getSplitsForCharge(Str::uuid()->toString());
    expect($splits)->toBeArray()->toBeEmpty();
});

it('has createSplitsForCharge method', function () {
    expect(method_exists($this->service, 'createSplitsForCharge'))->toBeTrue();
});

it('has processPendingSplits method', function () {
    expect(method_exists($this->service, 'processPendingSplits'))->toBeTrue();
});

/* ═══════════════════════════════════════════
   RULE CRUD
   ═══════════════════════════════════════════ */

it('creates a split rule', function () {
    $rule = $this->service->createRule($this->account->id, [
        'name' => 'My Rule',
        'recipients' => [
            ['recipient_id' => Str::uuid()->toString(), 'recipient_type' => 'account', 'type' => 'percentage', 'value' => 15],
        ],
    ]);

    expect($rule)->toBeInstanceOf(SplitRule::class)
        ->and($rule->name)->toBe('My Rule')
        ->and($rule->account_id)->toBe($this->account->id)
        ->and($rule->is_active)->toBeTrue()
        ->and($rule->recipients)->toHaveCount(1)
        ->and($rule->recipients[0]['value'])->toBe(15);
});

it('lists rules for account', function () {
    makeRule($this->account->id, ['name' => 'Rule A']);
    makeRule($this->account->id, ['name' => 'Rule B']);
    $other = makeAccount(['email' => 'other@test.com', 'document' => '99999999999']);
    makeRule($other->id, ['name' => 'Other Rule']);

    $rules = $this->service->listRules($this->account->id);

    expect($rules)->toHaveCount(2);
});

it('gets a single rule', function () {
    $rule = makeRule($this->account->id, ['name' => 'Specific Rule']);

    $found = $this->service->getRule($this->account->id, $rule->id);

    expect($found)->not->toBeNull()
        ->and($found->id)->toBe($rule->id)
        ->and($found->name)->toBe('Specific Rule');
});

it('updates a split rule', function () {
    $rule = makeRule($this->account->id, ['name' => 'Old Name']);

    $updated = $this->service->updateRule($this->account->id, $rule->id, [
        'name'      => 'New Name',
        'is_active' => false,
    ]);

    expect($updated->name)->toBe('New Name')
        ->and($updated->is_active)->toBeFalse();
});

it('deletes a split rule', function () {
    $rule = makeRule($this->account->id);

    $result = $this->service->deleteRule($this->account->id, $rule->id);

    expect($result)->toBeTrue()
        ->and(SplitRule::find($rule->id))->toBeNull();
});

it('returns null when getting rule from another account', function () {
    $other = makeAccount(['email' => 'other2@test.com', 'document' => '88888888888']);
    $rule = makeRule($other->id);

    $found = $this->service->getRule($rule->id, $this->account->id);

    expect($found)->toBeNull();
});

/* ═══════════════════════════════════════════
   RULE RESOLUTION PRIORITY
   ═══════════════════════════════════════════ */

it('resolves product-specific rule over default', function () {
    $product = makeProduct($this->account->id);

    $defaultRule = makeRule($this->account->id, [
        'name'       => 'Default',
        'is_default' => true,
    ]);

    $productRule = makeRule($this->account->id, [
        'name'       => 'Product Specific',
        'product_id' => $product->id,
    ]);

    [$charge] = makePaidCharge($this->account->id, $product->id);

    $resolved = $this->service->resolveRule($charge);

    expect($resolved)->not->toBeNull()
        ->and($resolved->id)->toBe($productRule->id);
});

it('resolves default rule when no product-specific rule exists', function () {
    $defaultRule = makeRule($this->account->id, [
        'name'       => 'Default',
        'is_default' => true,
    ]);

    [$charge] = makePaidCharge($this->account->id);

    $resolved = $this->service->resolveRule($charge);

    expect($resolved)->not->toBeNull()
        ->and($resolved->id)->toBe($defaultRule->id);
});

it('returns null when no rule matches', function () {
    [$charge] = makePaidCharge($this->account->id);

    $resolved = $this->service->resolveRule($charge);

    expect($resolved)->toBeNull();
});

it('skips inactive rules', function () {
    makeRule($this->account->id, [
        'name'       => 'Inactive Default',
        'is_default' => true,
        'is_active'  => false,
    ]);

    [$charge] = makePaidCharge($this->account->id);
    $resolved = $this->service->resolveRule($charge);

    expect($resolved)->toBeNull();
});

it('skips rules where minimum_amount is not met', function () {
    makeRule($this->account->id, [
        'name'           => 'High Minimum',
        'is_default'     => true,
        'minimum_amount' => 99999,
    ]);

    [$charge] = makePaidCharge($this->account->id); // amount = 10000

    $resolved = $this->service->resolveRule($charge);

    expect($resolved)->toBeNull();
});

/* ═══════════════════════════════════════════
   SPLIT CREATION
   ═══════════════════════════════════════════ */

it('creates splits from a percentage rule', function () {
    $recipientId = Str::uuid()->toString();
    $rule = makeRule($this->account->id, [
        'name'       => 'Default 10%',
        'is_default' => true,
        'recipients' => [
            [
                'recipient_id'   => $recipientId,
                'recipient_type' => 'account',
                'type'           => 'percentage',
                'value'          => 10,
                'description'    => 'Platform fee',
            ],
        ],
    ]);

    [$charge, $transaction] = makePaidCharge($this->account->id);

    // Verify rule resolves correctly
    $resolved = $this->service->resolveRule($charge);
    expect($resolved)->not->toBeNull()
        ->and($resolved->id)->toBe($rule->id);

    $splits = $this->service->createSplitsForCharge($charge);

    // Duas parcelas: a do recebedor e a do produtor com o restante.
    expect($splits)->toHaveCount(2);

    $split = is_array($splits[0]) ? $splits[0] : $splits[0]->toArray();
    expect($split['recipient_id'])->toBe($recipientId)
        ->and($split['type'])->toBe('percentage');

    // charge_fee_to_producer = true: o recebedor leva 10% do BRUTO (10000) e o
    // produtor absorve a taxa sozinho. Antes, a taxa era descontada duas vezes
    // (uma no net_amount, outra por recebedor).
    expect($split['amount'])->toBe(1000);

    // O rateio fecha exatamente com o líquido: 1000 + 8910 = 9910.
    $producerSplit = $splits[1];
    expect($producerSplit['recipient_id'])->toBe($this->account->id)
        ->and($producerSplit['amount'])->toBe(8910)
        ->and($split['amount'] + $producerSplit['amount'])->toBe(9910);
});

it('creates multi-recipient splits', function () {
    $recipient1 = Str::uuid()->toString();
    $recipient2 = Str::uuid()->toString();

    makeRule($this->account->id, [
        'name'       => 'Multi',
        'is_default' => true,
        'recipients' => [
            ['recipient_id' => $recipient1, 'recipient_type' => 'account', 'type' => 'percentage', 'value' => 10],
            ['recipient_id' => $recipient2, 'recipient_type' => 'affiliate', 'type' => 'percentage', 'value' => 5],
        ],
    ]);

    [$charge] = makePaidCharge($this->account->id);

    $splits = $this->service->createSplitsForCharge($charge);

    // Dois recebedores + a parcela do produtor.
    expect($splits)->toHaveCount(3)
        ->and($splits[0]['recipient_id'])->toBe($recipient1)
        ->and($splits[1]['recipient_id'])->toBe($recipient2)
        ->and($splits[2]['recipient_id'])->toBe($this->account->id);

    // 10% + 5% do bruto, produtor com o resto — soma igual ao líquido.
    expect($splits[0]['amount'])->toBe(1000)
        ->and($splits[1]['amount'])->toBe(500)
        ->and($splits[0]['amount'] + $splits[1]['amount'] + $splits[2]['amount'])->toBe(9910);
});

it('creates fixed amount splits', function () {
    $recipientId = Str::uuid()->toString();

    $rule = makeRule($this->account->id, [
        'name'       => 'Fixed 500',
        'is_default' => true,
        'recipients' => [
            ['recipient_id' => $recipientId, 'recipient_type' => 'account', 'type' => 'fixed', 'value' => 500],
        ],
    ]);

    [$charge] = makePaidCharge($this->account->id);

    // Verify rule resolves
    $resolved = $this->service->resolveRule($charge);
    expect($resolved)->not->toBeNull()
        ->and($resolved->id)->toBe($rule->id);

    $splits = $this->service->createSplitsForCharge($charge);

    // Valor fixo do recebedor + restante do produtor.
    expect($splits)->toHaveCount(2);
    $split = is_array($splits[0]) ? $splits[0] : $splits[0]->toArray();
    expect($split['recipient_id'])->toBe($recipientId)
        ->and($split['amount'])->toBe(500)
        ->and($split['amount'] + $splits[1]['amount'])->toBe(9910);
});

it('does not create splits for non-paid charge', function () {
    makeRule($this->account->id, [
        'name'       => 'Default',
        'is_default' => true,
    ]);

    $acquirer = makeAcquirer();
    $charge = Charge::create([
        'id'              => Str::uuid()->toString(),
        'account_id'      => $this->account->id,
        'idempotency_key' => 'idem-pending-' . Str::random(10),
        'amount'          => 10000,
        'currency'        => 'BRL',
        'payment_method'  => 'pix',
        'status'          => 'pending', // NOT paid
        'customer'        => ['name' => 'Test', 'email' => 'test@test.com', 'document' => '12345678900'],
    ]);

    $splits = $this->service->createSplitsForCharge($charge);

    expect($splits)->toBeEmpty();
});

it('is idempotent — does not duplicate splits', function () {
    $recipientId = Str::uuid()->toString();
    makeRule($this->account->id, [
        'name'       => 'Default',
        'is_default' => true,
        'recipients' => [
            ['recipient_id' => $recipientId, 'recipient_type' => 'account', 'type' => 'percentage', 'value' => 10],
        ],
    ]);

    [$charge] = makePaidCharge($this->account->id);

    $first  = $this->service->createSplitsForCharge($charge);
    $second = $this->service->createSplitsForCharge($charge);

    // Recebedor + produtor, e a segunda chamada devolve os mesmos registros.
    expect($first)->toHaveCount(2);
    expect($second)->toHaveCount(2);

    // Nada foi duplicado no banco.
    expect(Split::count())->toBe(2);
});

/* ═══════════════════════════════════════════
   SIMULATION
   ═══════════════════════════════════════════ */

it('simulates a split rule without creating records', function () {
    $recipientId = Str::uuid()->toString();
    $rule = makeRule($this->account->id, [
        'name'       => 'Sim Rule',
        'recipients' => [
            ['recipient_id' => $recipientId, 'recipient_type' => 'account', 'type' => 'percentage', 'value' => 20],
        ],
    ]);

    $result = $this->service->simulateSplit($rule->id, $this->account->id, 10000, 90);

    expect($result)->toBeArray()
        ->and($result)->toHaveKey('recipients')
        ->and($result)->toHaveKey('rule_id')
        ->and($result['recipients'])->toHaveCount(1);

    // No records in DB
    expect(Split::count())->toBe(0);
});

/* ═══════════════════════════════════════════
   SPLIT PROCESSING
   ═══════════════════════════════════════════ */

it('processes pending splits and credits wallet', function () {
    $recipient = makeAccount(['email' => 'recipient@test.com', 'document' => '11111111111']);

    // Create wallet for recipient
    AccountWallet::create([
        'id'                => Str::uuid()->toString(),
        'account_id'        => $recipient->id,
        'currency'          => 'BRL',
        'available_balance' => 0,
        'pending_balance'   => 0,
        'blocked_balance'   => 0,
    ]);

    makeRule($this->account->id, [
        'name'         => 'Auto Process',
        'is_default'   => true,
        'auto_process' => false,
        'recipients'   => [
            ['recipient_id' => $recipient->id, 'recipient_type' => 'account', 'type' => 'percentage', 'value' => 10],
        ],
    ]);

    [$charge] = makePaidCharge($this->account->id);
    $this->service->createSplitsForCharge($charge);

    // Recebedor + produtor, ambos aguardando processamento.
    expect(Split::where('status', 'pending')->count())->toBe(2);

    $result = $this->service->processPendingSplits();

    expect($result['processed'])->toBe(2)
        ->and($result['failed'])->toBe(0);

    expect(Split::where('status', 'transferred')->count())->toBe(2);

    // O recebedor ficou com 10% do bruto...
    $recipientWallet = AccountWallet::where('account_id', $recipient->id)->first();
    expect((int) $recipientWallet->available_balance)->toBe(1000);

    // ...e saiu exatamente esse valor da carteira do produtor. O total em
    // circulação continua sendo o líquido da cobrança.
    $producerWallet = AccountWallet::where('account_id', $this->account->id)->first();
    expect((int) $producerWallet->available_balance)->toBe(8910)
        ->and((int) $producerWallet->available_balance + (int) $recipientWallet->available_balance)
        ->toBe(9910);
});

/* ═══════════════════════════════════════════
   SPLITRULE MODEL VALIDATION
   ═══════════════════════════════════════════ */

it('validates that percentage recipients sum must not exceed 100', function () {
    $rule = makeRule($this->account->id, [
        'recipients' => [
            ['recipient_id' => Str::uuid()->toString(), 'recipient_type' => 'account', 'type' => 'percentage', 'value' => 60],
            ['recipient_id' => Str::uuid()->toString(), 'recipient_type' => 'account', 'type' => 'percentage', 'value' => 50],
        ],
    ]);

    expect($rule->isValid())->toBeFalse()
        ->and($rule->getTotalPercentage())->toBe(110.0);
});

it('validates rule with sum exactly 100 is valid', function () {
    $rule = makeRule($this->account->id, [
        'recipients' => [
            ['recipient_id' => Str::uuid()->toString(), 'recipient_type' => 'account', 'type' => 'percentage', 'value' => 70],
            ['recipient_id' => Str::uuid()->toString(), 'recipient_type' => 'account', 'type' => 'percentage', 'value' => 30],
        ],
    ]);

    expect($rule->isValid())->toBeTrue()
        ->and($rule->getTotalPercentage())->toBe(100.0);
});

it('validates minimum amount filter', function () {
    $rule = makeRule($this->account->id, ['minimum_amount' => 5000]);

    expect($rule->appliesToAmount(5000))->toBeTrue()
        ->and($rule->appliesToAmount(5001))->toBeTrue()
        ->and($rule->appliesToAmount(4999))->toBeFalse();
});

