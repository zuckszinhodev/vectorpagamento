<?php

declare(strict_types=1);

use App\Models\Account;
use App\Models\AccountWallet;
use App\Models\Affiliate;
use App\Models\Charge;
use App\Models\Product;
use App\Models\Split;
use App\Models\SplitRule;
use App\Models\Transaction;
use App\Models\User;
use App\Http\Requests\Api\V1\CreateChargeRequest;
use App\Services\Split\RecipientValidator;
use App\Services\Split\SplitCalculator;
use App\Services\SplitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// ── Helpers ────────────────────────────────────────────

function splitTestAccount(string $label, string $status = 'active'): Account
{
    $suffix = Str::random(8);

    return Account::create([
        'id' => Str::uuid()->toString(),
        'name' => "Conta {$label}",
        'document' => (string) random_int(10000000000, 99999999999),
        'document_type' => 'cpf',
        'email' => "{$label}-{$suffix}@test.com",
        'status' => $status,
    ]);
}

function splitTestWallet(Account $account, int $available = 0): AccountWallet
{
    return AccountWallet::create([
        'id' => Str::uuid()->toString(),
        'account_id' => $account->id,
        'available_balance' => $available,
        'pending_balance' => 0,
        'blocked_balance' => 0,
        'currency' => 'BRL',
    ]);
}

/**
 * Cobrança já paga, com a carteira do produtor creditada do líquido —
 * exatamente o estado em que o PaymentService deixa as coisas antes de
 * chamar o split.
 */
function splitTestPaidCharge(Account $producer, int $amount, int $fee = 0, array $attributes = []): Charge
{
    $charge = Charge::create(array_merge([
        'id' => Str::uuid()->toString(),
        'account_id' => $producer->id,
        'amount' => $amount,
        'currency' => 'BRL',
        'payment_method' => 'pix',
        'status' => 'approved',
        'paid_at' => now(),
        'customer' => ['name' => 'Cliente Teste', 'email' => 'cliente@test.com', 'document' => '12345678900'],
        'fee_amount' => $fee,
        'net_amount' => $amount - $fee,
        'idempotency_key' => 'test_' . Str::uuid()->toString(),
    ], $attributes));

    Transaction::create([
        'id' => Str::uuid()->toString(),
        'charge_id' => $charge->id,
        'acquirer_id' => splitTestAcquirerId(),
        'type' => 'capture',
        'status' => 'approved',
        'amount' => $amount,
        'net_amount' => $amount - $fee,
    ]);

    // O produtor já recebeu o líquido inteiro na carteira.
    AccountWallet::where('account_id', $producer->id)
        ->increment('available_balance', $amount - $fee);

    return $charge->fresh();
}

function splitTestBalance(Account $account): int
{
    return (int) AccountWallet::where('account_id', $account->id)->value('available_balance');
}

/** Adquirente fictício — `transactions.acquirer_id` é obrigatório no schema. */
function splitTestAcquirerId(): string
{
    $existing = DB::table('acquirers')->where('slug', 'test-acquirer')->value('id');
    if ($existing) {
        return (string) $existing;
    }

    $id = Str::uuid()->toString();
    DB::table('acquirers')->insert([
        'id' => $id,
        'name' => 'Test Acquirer',
        'slug' => 'test-acquirer',
        'is_active' => true,
        'supported_methods' => json_encode(['pix', 'credit_card']),
        'settings' => json_encode([]),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

beforeEach(function () {
    $this->producer = splitTestAccount('produtor');
    splitTestWallet($this->producer);

    $this->partner = splitTestAccount('parceiro');
    splitTestWallet($this->partner);

    $this->user = User::create([
        'id' => Str::uuid()->toString(),
        'account_id' => $this->producer->id,
        'email' => 'produtor-' . Str::random(6) . '@test.com',
        'password' => bcrypt('password'),
        'name' => 'Dono',
        'status' => 'active',
        'role' => 'admin',
    ]);

    $this->service = app(SplitService::class);
});

// ══════════════════════════════════════════════════════
//  CALCULADOR — cálculo puro
// ══════════════════════════════════════════════════════

describe('SplitCalculator', function () {
    it('divide percentual e devolve o resto ao produtor', function () {
        $result = (new SplitCalculator())->calculate(
            recipients: [['recipient_id' => 'parceiro', 'type' => 'percentage', 'value' => 30]],
            grossAmount: 10000,
            feeAmount: 0,
            producerAccountId: 'produtor',
        );

        expect($result->distributedToRecipients())->toBe(3000)
            ->and($result->producerAmount())->toBe(7000)
            ->and($result->isBalanced())->toBeTrue();
    });

    it('nunca distribui mais que o disponível quando os percentuais passam de 100', function () {
        $result = (new SplitCalculator())->calculate(
            recipients: [
                ['recipient_id' => 'a', 'type' => 'percentage', 'value' => 50],
                ['recipient_id' => 'b', 'type' => 'percentage', 'value' => 50],
                ['recipient_id' => 'c', 'type' => 'percentage', 'value' => 50],
            ],
            grossAmount: 10000,
            feeAmount: 0,
            producerAccountId: 'produtor',
        );

        // 150% pedidos, mas só existem 10000 para dividir.
        expect($result->distributedToRecipients())->toBe(10000)
            ->and($result->producerAmount())->toBe(0)
            ->and($result->isBalanced())->toBeTrue();
    });

    it('nunca distribui mais que o disponível com valores fixos altos', function () {
        $result = (new SplitCalculator())->calculate(
            recipients: [
                ['recipient_id' => 'a', 'type' => 'fixed', 'value' => 8000],
                ['recipient_id' => 'b', 'type' => 'fixed', 'value' => 8000],
            ],
            grossAmount: 10000,
            feeAmount: 0,
            producerAccountId: 'produtor',
        );

        expect($result->distributedToRecipients())->toBe(10000)
            ->and($result->allocations[0]->gross)->toBe(8000)
            ->and($result->allocations[1]->gross)->toBe(2000)
            ->and($result->isBalanced())->toBeTrue();
    });

    it('desconta a taxa do produtor quando o modo é FEE_PRODUCER', function () {
        $result = (new SplitCalculator())->calculate(
            recipients: [['recipient_id' => 'parceiro', 'type' => 'percentage', 'value' => 30]],
            grossAmount: 10000,
            feeAmount: 500,
            producerAccountId: 'produtor',
            feeMode: SplitCalculator::FEE_PRODUCER,
        );

        // Parceiro leva 30% do BRUTO; o produtor absorve a taxa inteira.
        expect($result->distributedToRecipients())->toBe(3000)
            ->and($result->producerAmount())->toBe(6500)
            ->and($result->distributable)->toBe(9500)
            ->and($result->isBalanced())->toBeTrue();
    });

    it('rateia a taxa entre todos quando o modo é FEE_PROPORTIONAL', function () {
        $result = (new SplitCalculator())->calculate(
            recipients: [['recipient_id' => 'parceiro', 'type' => 'percentage', 'value' => 30]],
            grossAmount: 10000,
            feeAmount: 500,
            producerAccountId: 'produtor',
            feeMode: SplitCalculator::FEE_PROPORTIONAL,
        );

        // Parceiro leva 30% do LÍQUIDO — divide a taxa proporcionalmente.
        expect($result->distributedToRecipients())->toBe(2850)
            ->and($result->producerAmount())->toBe(6650)
            ->and($result->isBalanced())->toBeTrue();
    });

    it('mantém o rateio fechado mesmo com arredondamento quebrado', function () {
        $result = (new SplitCalculator())->calculate(
            recipients: [
                ['recipient_id' => 'a', 'type' => 'percentage', 'value' => 33.333],
                ['recipient_id' => 'b', 'type' => 'percentage', 'value' => 33.333],
                ['recipient_id' => 'c', 'type' => 'percentage', 'value' => 33.333],
            ],
            grossAmount: 10000,
            feeAmount: 0,
            producerAccountId: 'produtor',
        );

        expect($result->isBalanced())->toBeTrue()
            ->and($result->distributedToRecipients() + $result->producerAmount())->toBe(10000);
    });

    it('devolve tudo ao produtor quando não há recebedores', function () {
        $result = (new SplitCalculator())->calculate(
            recipients: [],
            grossAmount: 10000,
            feeAmount: 500,
            producerAccountId: 'produtor',
        );

        expect($result->producerAmount())->toBe(9500)
            ->and($result->isBalanced())->toBeTrue();
    });

    it('não cria valor quando a taxa é maior que a cobrança', function () {
        $result = (new SplitCalculator())->calculate(
            recipients: [['recipient_id' => 'a', 'type' => 'percentage', 'value' => 50]],
            grossAmount: 1000,
            feeAmount: 5000,
            producerAccountId: 'produtor',
        );

        expect($result->distributable)->toBe(0)
            ->and($result->distributedToRecipients())->toBe(0)
            ->and($result->isBalanced())->toBeTrue();
    });
});

// ══════════════════════════════════════════════════════
//  VALIDAÇÃO DE DESTINATÁRIOS
// ══════════════════════════════════════════════════════

describe('RecipientValidator', function () {
    it('aceita conta ativa do sistema', function () {
        $errors = (new RecipientValidator())->validate([
            ['recipient_id' => $this->partner->id, 'recipient_type' => 'account'],
        ]);

        expect($errors)->toBeEmpty();
    });

    it('rejeita conta que não existe', function () {
        $errors = (new RecipientValidator())->validate([
            ['recipient_id' => Str::uuid()->toString(), 'recipient_type' => 'account'],
        ]);

        expect($errors)->toHaveKey('recipients.0.recipient_id')
            ->and($errors['recipients.0.recipient_id'])->toContain('não encontrada');
    });

    it('rejeita conta bloqueada', function () {
        $blocked = splitTestAccount('bloqueada', 'blocked');

        $errors = (new RecipientValidator())->validate([
            ['recipient_id' => $blocked->id, 'recipient_type' => 'account'],
        ]);

        expect($errors)->toHaveKey('recipients.0.recipient_id');
    });

    it('rejeita o mesmo destinatário duas vezes na mesma regra', function () {
        $errors = (new RecipientValidator())->validate([
            ['recipient_id' => $this->partner->id, 'recipient_type' => 'account'],
            ['recipient_id' => $this->partner->id, 'recipient_type' => 'account'],
        ]);

        expect($errors)->toHaveKey('recipients.1.recipient_id')
            ->and($errors['recipients.1.recipient_id'])->toContain('já foi adicionado');
    });

    it('resolve a conta de pagamento de um afiliado pelo metadata', function () {
        $affiliateAccount = splitTestAccount('afiliado');
        $affiliateUser = User::create([
            'id' => Str::uuid()->toString(),
            'account_id' => $affiliateAccount->id,
            'email' => 'afiliado-' . Str::random(6) . '@test.com',
            'password' => bcrypt('password'),
            'name' => 'Afiliado',
            'status' => 'active',
            'role' => 'user',
        ]);
        $product = Product::create([
            'id' => Str::uuid()->toString(),
            'account_id' => $this->producer->id,
            'name' => 'Produto',
            'slug' => 'produto-' . Str::random(6),
            'price' => 10000,
            'type' => 'digital',
            'status' => 'active',
            'is_active' => true,
        ]);
        $affiliate = Affiliate::create([
            'id' => Str::uuid()->toString(),
            'account_id' => $this->producer->id,
            'user_id' => $affiliateUser->id,
            'product_id' => $product->id,
            'code' => 'AFF' . Str::random(6),
            'commission_type' => 'percentage',
            'commission_value' => 20,
            'status' => 'active',
            'metadata' => ['affiliate_account_id' => $affiliateAccount->id],
        ]);

        $resolved = (new RecipientValidator())->resolvePayoutAccountId($affiliate->id, 'affiliate');

        // Precisa cair na conta DO AFILIADO, não na do produtor dono do programa.
        expect($resolved)->toBe($affiliateAccount->id)
            ->and($resolved)->not->toBe($this->producer->id);
    });
});

// ══════════════════════════════════════════════════════
//  CRIAÇÃO E PROCESSAMENTO — dinheiro de verdade
// ══════════════════════════════════════════════════════

describe('createSplitsForCharge', function () {
    it('move o valor do produtor para o parceiro sem criar dinheiro', function () {
        $charge = splitTestPaidCharge($this->producer, 10000, 0);

        SplitRule::create([
            'id' => Str::uuid()->toString(),
            'account_id' => $this->producer->id,
            'name' => 'Parceiro 30%',
            'is_default' => true,
            'is_active' => true,
            'recipients' => [[
                'recipient_id' => $this->partner->id,
                'recipient_type' => 'account',
                'type' => 'percentage',
                'value' => 30,
            ]],
            'auto_process' => true,
            'transfer_delay_hours' => 0,
        ]);

        $this->service->createSplitsForCharge($charge);

        expect(splitTestBalance($this->partner))->toBe(3000)
            ->and(splitTestBalance($this->producer))->toBe(7000)
            // O total em carteira nunca pode passar do que a cobrança arrecadou.
            ->and(splitTestBalance($this->partner) + splitTestBalance($this->producer))->toBe(10000);
    });

    it('respeita a taxa: produtor absorve, parceiro recebe sobre o bruto', function () {
        $charge = splitTestPaidCharge($this->producer, 10000, 500);

        SplitRule::create([
            'id' => Str::uuid()->toString(),
            'account_id' => $this->producer->id,
            'name' => 'Parceiro 30%',
            'is_default' => true,
            'is_active' => true,
            'recipients' => [[
                'recipient_id' => $this->partner->id,
                'recipient_type' => 'account',
                'type' => 'percentage',
                'value' => 30,
            ]],
            'charge_fee_to_producer' => true,
            'auto_process' => true,
            'transfer_delay_hours' => 0,
        ]);

        $this->service->createSplitsForCharge($charge);

        expect(splitTestBalance($this->partner))->toBe(3000)
            ->and(splitTestBalance($this->producer))->toBe(6500)
            // 9500 é o líquido; nada além disso pode ser distribuído.
            ->and(splitTestBalance($this->partner) + splitTestBalance($this->producer))->toBe(9500);
    });

    it('divide entre três contas do sistema sem sobra nem estouro', function () {
        $b = splitTestAccount('b');
        splitTestWallet($b);
        $c = splitTestAccount('c');
        splitTestWallet($c);

        $charge = splitTestPaidCharge($this->producer, 30000, 0);

        SplitRule::create([
            'id' => Str::uuid()->toString(),
            'account_id' => $this->producer->id,
            'name' => 'Três vias',
            'is_default' => true,
            'is_active' => true,
            'recipients' => [
                ['recipient_id' => $this->partner->id, 'recipient_type' => 'account', 'type' => 'percentage', 'value' => 25],
                ['recipient_id' => $b->id, 'recipient_type' => 'account', 'type' => 'percentage', 'value' => 25],
                ['recipient_id' => $c->id, 'recipient_type' => 'account', 'type' => 'fixed', 'value' => 5000],
            ],
            'auto_process' => true,
            'transfer_delay_hours' => 0,
        ]);

        $this->service->createSplitsForCharge($charge);

        expect(splitTestBalance($this->partner))->toBe(7500)
            ->and(splitTestBalance($b))->toBe(7500)
            ->and(splitTestBalance($c))->toBe(5000)
            ->and(splitTestBalance($this->producer))->toBe(10000);

        $total = splitTestBalance($this->partner) + splitTestBalance($b)
            + splitTestBalance($c) + splitTestBalance($this->producer);
        expect($total)->toBe(30000);
    });

    it('é idempotente: chamar duas vezes não credita duas vezes', function () {
        $charge = splitTestPaidCharge($this->producer, 10000, 0);

        SplitRule::create([
            'id' => Str::uuid()->toString(),
            'account_id' => $this->producer->id,
            'name' => 'Parceiro 40%',
            'is_default' => true,
            'is_active' => true,
            'recipients' => [[
                'recipient_id' => $this->partner->id,
                'recipient_type' => 'account',
                'type' => 'percentage',
                'value' => 40,
            ]],
            'auto_process' => true,
            'transfer_delay_hours' => 0,
        ]);

        $this->service->createSplitsForCharge($charge);
        $this->service->createSplitsForCharge($charge);

        expect(splitTestBalance($this->partner))->toBe(4000)
            ->and(splitTestBalance($this->producer))->toBe(6000);
    });

    it('processar o mesmo split de novo não credita em dobro', function () {
        $charge = splitTestPaidCharge($this->producer, 10000, 0);

        SplitRule::create([
            'id' => Str::uuid()->toString(),
            'account_id' => $this->producer->id,
            'name' => 'Parceiro 40%',
            'is_default' => true,
            'is_active' => true,
            'recipients' => [[
                'recipient_id' => $this->partner->id,
                'recipient_type' => 'account',
                'type' => 'percentage',
                'value' => 40,
            ]],
            'auto_process' => true,
            'transfer_delay_hours' => 0,
        ]);

        $this->service->createSplitsForCharge($charge);

        $split = Split::where('recipient_id', $this->partner->id)->firstOrFail();
        $this->service->processSplit($split);
        $this->service->processSplit($split);

        expect(splitTestBalance($this->partner))->toBe(4000);
    });

    it('marca o split como falho quando a conta destino não existe', function () {
        $charge = splitTestPaidCharge($this->producer, 10000, 0);
        $ghostId = Str::uuid()->toString();

        SplitRule::create([
            'id' => Str::uuid()->toString(),
            'account_id' => $this->producer->id,
            'name' => 'Conta fantasma',
            'is_default' => true,
            'is_active' => true,
            'recipients' => [[
                'recipient_id' => $ghostId,
                'recipient_type' => 'account',
                'type' => 'percentage',
                'value' => 30,
            ]],
            'auto_process' => true,
            'transfer_delay_hours' => 0,
        ]);

        $this->service->createSplitsForCharge($charge);

        $ghostSplit = Split::where('recipient_id', $ghostId)->firstOrFail();

        expect($ghostSplit->fresh()->status)->toBe('failed')
            // Nenhuma carteira fantasma pode ter sido criada para o UUID inválido.
            ->and(AccountWallet::where('account_id', $ghostId)->exists())->toBeFalse()
            // O produtor continua com o valor: nada saiu da conta dele.
            ->and(splitTestBalance($this->producer))->toBe(10000);
    });

    it('não cria splits quando a cobrança não está paga', function () {
        $charge = Charge::create([
            'id' => Str::uuid()->toString(),
            'account_id' => $this->producer->id,
            'amount' => 10000,
            'currency' => 'BRL',
            'payment_method' => 'pix',
            'status' => 'pending',
            'customer' => ['name' => 'X', 'email' => 'x@test.com', 'document' => '1'],
            'idempotency_key' => 'test_' . Str::uuid()->toString(),
        ]);

        expect($this->service->createSplitsForCharge($charge))->toBe([]);
    });

    it('respeita o valor mínimo da regra', function () {
        $charge = splitTestPaidCharge($this->producer, 5000, 0);

        SplitRule::create([
            'id' => Str::uuid()->toString(),
            'account_id' => $this->producer->id,
            'name' => 'Só acima de R$100',
            'is_default' => true,
            'is_active' => true,
            'minimum_amount' => 10000,
            'recipients' => [[
                'recipient_id' => $this->partner->id,
                'recipient_type' => 'account',
                'type' => 'percentage',
                'value' => 30,
            ]],
            'auto_process' => true,
            'transfer_delay_hours' => 0,
        ]);

        $this->service->createSplitsForCharge($charge);

        // Regra não se aplica: parceiro não recebe nada.
        expect(splitTestBalance($this->partner))->toBe(0)
            ->and(splitTestBalance($this->producer))->toBe(5000);
    });

    it('não processa na hora quando auto_process está desligado', function () {
        $charge = splitTestPaidCharge($this->producer, 10000, 0);

        SplitRule::create([
            'id' => Str::uuid()->toString(),
            'account_id' => $this->producer->id,
            'name' => 'Manual',
            'is_default' => true,
            'is_active' => true,
            'recipients' => [[
                'recipient_id' => $this->partner->id,
                'recipient_type' => 'account',
                'type' => 'percentage',
                'value' => 30,
            ]],
            'auto_process' => false,
            'transfer_delay_hours' => 0,
        ]);

        $this->service->createSplitsForCharge($charge);

        expect(splitTestBalance($this->partner))->toBe(0)
            ->and(Split::where('recipient_id', $this->partner->id)->first()->status)->toBe('pending');

        // Processamento em lote entrega o valor depois.
        $this->service->processPendingSplits();

        expect(splitTestBalance($this->partner))->toBe(3000);
    });
});

// ══════════════════════════════════════════════════════
//  SPLIT INLINE VIA API
// ══════════════════════════════════════════════════════

describe('split inline da API', function () {
    it('divide conforme split_rules do metadata da cobrança', function () {
        $charge = splitTestPaidCharge($this->producer, 10000, 0, [
            'metadata' => [
                'split_rules' => [[
                    'recipient_id' => $this->partner->id,
                    'recipient_type' => 'account',
                    'type' => 'percentage',
                    'percentage' => 25,
                ]],
            ],
        ]);

        $this->service->createSplitsForCharge($charge);

        expect(splitTestBalance($this->partner))->toBe(2500)
            ->and(splitTestBalance($this->producer))->toBe(7500);
    });

    it('aceita valor fixo em centavos', function () {
        $charge = splitTestPaidCharge($this->producer, 10000, 0, [
            'metadata' => [
                'split_rules' => [[
                    'recipient_id' => $this->partner->id,
                    'recipient_type' => 'account',
                    'type' => 'fixed',
                    'amount' => 1500,
                ]],
            ],
        ]);

        $this->service->createSplitsForCharge($charge);

        expect(splitTestBalance($this->partner))->toBe(1500)
            ->and(splitTestBalance($this->producer))->toBe(8500);
    });

    it('não deixa percentuais somando mais de 100 estourarem o valor', function () {
        $b = splitTestAccount('b');
        splitTestWallet($b);

        $charge = splitTestPaidCharge($this->producer, 10000, 0, [
            'metadata' => [
                'split_rules' => [
                    ['recipient_id' => $this->partner->id, 'recipient_type' => 'account', 'type' => 'percentage', 'percentage' => 70],
                    ['recipient_id' => $b->id, 'recipient_type' => 'account', 'type' => 'percentage', 'percentage' => 70],
                ],
            ],
        ]);

        $this->service->createSplitsForCharge($charge);

        $total = splitTestBalance($this->partner) + splitTestBalance($b) + splitTestBalance($this->producer);

        expect($total)->toBe(10000)
            ->and(splitTestBalance($this->partner))->toBe(7000)
            ->and(splitTestBalance($b))->toBe(3000)
            ->and(splitTestBalance($this->producer))->toBe(0);
    });
});

// ══════════════════════════════════════════════════════
//  VALIDAÇÃO DO SPLIT INLINE (CreateChargeRequest)
// ══════════════════════════════════════════════════════

describe('validação do split_rules na criação de cobrança', function () {
    /** Roda as regras do FormRequest sobre um payload, sem passar por HTTP. */
    $validate = function (array $payload): array {
        $request = CreateChargeRequest::create('/api/v1/charges', 'POST', $payload);
        $request->setContainer(app());

        $validator = validator($payload, $request->rules());
        $request->withValidator($validator);

        return $validator->errors()->toArray();
    };

    it('rejeita percentuais somando mais de 100%', function () use ($validate) {
        $b = splitTestAccount('b');

        $errors = $validate([
            'amount' => 10000,
            'payment_method' => 'pix',
            'customer' => ['name' => 'C', 'email' => 'c@test.com', 'document' => '12345678900'],
            'split_rules' => [
                ['recipient_id' => $this->partner->id, 'recipient_type' => 'account', 'type' => 'percentage', 'percentage' => 60],
                ['recipient_id' => $b->id, 'recipient_type' => 'account', 'type' => 'percentage', 'percentage' => 60],
            ],
        ]);

        expect($errors)->toHaveKey('split_rules')
            ->and($errors['split_rules'][0])->toContain('120');
    });

    it('rejeita valores fixos que excedem a cobrança', function () use ($validate) {
        $errors = $validate([
            'amount' => 10000,
            'payment_method' => 'pix',
            'customer' => ['name' => 'C', 'email' => 'c@test.com', 'document' => '12345678900'],
            'split_rules' => [
                ['recipient_id' => $this->partner->id, 'recipient_type' => 'account', 'type' => 'fixed', 'amount' => 15000],
            ],
        ]);

        expect($errors)->toHaveKey('split_rules')
            ->and($errors['split_rules'][0])->toContain('excede');
    });

    it('rejeita destinatário que não existe no sistema', function () use ($validate) {
        $errors = $validate([
            'amount' => 10000,
            'payment_method' => 'pix',
            'customer' => ['name' => 'C', 'email' => 'c@test.com', 'document' => '12345678900'],
            'split_rules' => [
                ['recipient_id' => Str::uuid()->toString(), 'recipient_type' => 'account', 'type' => 'percentage', 'percentage' => 30],
            ],
        ]);

        expect($errors)->toHaveKey('split_rules.0.recipient_id');
    });

    it('aceita split válido para conta do sistema', function () use ($validate) {
        $errors = $validate([
            'amount' => 10000,
            'payment_method' => 'pix',
            'customer' => ['name' => 'C', 'email' => 'c@test.com', 'document' => '12345678900'],
            'split_rules' => [
                ['recipient_id' => $this->partner->id, 'recipient_type' => 'account', 'type' => 'percentage', 'percentage' => 30],
            ],
        ]);

        expect($errors)->toBeEmpty();
    });
});

// ══════════════════════════════════════════════════════
//  API HTTP — regras de split
// ══════════════════════════════════════════════════════

describe('API de regras de split', function () {
    beforeEach(function () {
        $this->token = auth()->login($this->user);
    });

    it('cria regra com conta válida do sistema', function () {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/v1/splits/rules', [
                'name' => 'Parceria',
                'recipients' => [[
                    'recipient_id' => $this->partner->id,
                    'recipient_type' => 'account',
                    'type' => 'percentage',
                    'value' => 30,
                ]],
            ]);

        $response->assertStatus(201);
        expect(SplitRule::where('account_id', $this->producer->id)->count())->toBe(1);
    });

    it('recusa regra apontando para conta inexistente', function () {
        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/v1/splits/rules', [
                'name' => 'Fantasma',
                'recipients' => [[
                    'recipient_id' => Str::uuid()->toString(),
                    'recipient_type' => 'account',
                    'type' => 'percentage',
                    'value' => 30,
                ]],
            ]);

        $response->assertStatus(422);
        expect(SplitRule::where('account_id', $this->producer->id)->count())->toBe(0);
    });

    it('cria regras separadas para produtos diferentes com os mesmos destinatários', function () {
        $productA = Product::create([
            'id' => Str::uuid()->toString(),
            'account_id' => $this->producer->id,
            'name' => 'Produto A',
            'slug' => 'produto-a-' . Str::random(6),
            'price' => 10000,
            'type' => 'digital',
            'status' => 'active',
            'is_active' => true,
        ]);
        $productB = Product::create([
            'id' => Str::uuid()->toString(),
            'account_id' => $this->producer->id,
            'name' => 'Produto B',
            'slug' => 'produto-b-' . Str::random(6),
            'price' => 20000,
            'type' => 'digital',
            'status' => 'active',
            'is_active' => true,
        ]);

        $payload = fn (string $productId) => [
            'name' => "Regra {$productId}",
            'product_id' => $productId,
            'recipients' => [[
                'recipient_id' => $this->partner->id,
                'recipient_type' => 'account',
                'type' => 'percentage',
                'value' => 20,
            ]],
        ];

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/v1/splits/rules', $payload($productA->id))
            ->assertStatus(201);

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/v1/splits/rules', $payload($productB->id))
            ->assertStatus(201);

        // Mesmos destinatários, produtos diferentes: precisam ser duas regras.
        expect(SplitRule::where('account_id', $this->producer->id)->count())->toBe(2)
            ->and(SplitRule::where('product_id', $productB->id)->exists())->toBeTrue();
    });

    it('recusa regra com percentuais acima de 100', function () {
        $b = splitTestAccount('b');

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/v1/splits/rules', [
                'name' => 'Estouro',
                'recipients' => [
                    ['recipient_id' => $this->partner->id, 'recipient_type' => 'account', 'type' => 'percentage', 'value' => 60],
                    ['recipient_id' => $b->id, 'recipient_type' => 'account', 'type' => 'percentage', 'value' => 60],
                ],
            ]);

        $response->assertStatus(422);
    });

    it('simula o rateio com os mesmos números da execução real', function () {
        $rule = SplitRule::create([
            'id' => Str::uuid()->toString(),
            'account_id' => $this->producer->id,
            'name' => 'Parceiro 30%',
            'is_active' => true,
            'recipients' => [[
                'recipient_id' => $this->partner->id,
                'recipient_type' => 'account',
                'type' => 'percentage',
                'value' => 30,
            ]],
            'charge_fee_to_producer' => true,
        ]);

        $response = $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/splits/rules/{$rule->id}/simulate", [
                'amount' => 10000,
                'fee_amount' => 500,
            ]);

        $response->assertStatus(200);
        $data = $response->json('data');

        expect($data['recipients'][0]['net_amount'])->toBe(3000)
            ->and($data['producer']['net_amount'])->toBe(6500)
            ->and($data['balanced'])->toBeTrue();

        // A simulação tem que bater com o que o split real faz.
        $charge = splitTestPaidCharge($this->producer, 10000, 500);
        $rule->update(['is_default' => true, 'auto_process' => true, 'transfer_delay_hours' => 0]);
        $this->service->createSplitsForCharge($charge);

        expect(splitTestBalance($this->partner))->toBe($data['recipients'][0]['net_amount'])
            ->and(splitTestBalance($this->producer))->toBe($data['producer']['net_amount']);
    });
});
