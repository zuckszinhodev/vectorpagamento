<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Affiliate;
use App\Models\AccountWallet;
use App\Models\Charge;
use App\Models\Split;
use App\Models\SplitRule;
use App\Models\Transaction;
use App\Services\Split\InsufficientProducerBalanceException;
use App\Services\Split\RecipientValidator;
use App\Services\Split\SplitAllocation;
use App\Services\Split\SplitCalculator;
use App\Services\Split\SplitResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SplitService
{
    public function __construct(
        private readonly SplitCalculator $calculator = new SplitCalculator(),
        private readonly RecipientValidator $recipientValidator = new RecipientValidator(),
    ) {}

    /**
     * Valida os destinatários de uma regra antes de gravar.
     *
     * @return array<string, string>  Erros por campo; vazio significa válido.
     */
    public function validateRecipients(array $recipients): array
    {
        return $this->recipientValidator->validate($recipients);
    }

    // ══════════════════════════════════════════════════════
    //  SPLIT RULE MANAGEMENT (CRUD)
    // ══════════════════════════════════════════════════════

    /**
     * List all split rules for an account.
     */
    public function listRules(string $accountId): Collection
    {
        return SplitRule::forAccount($accountId)
            ->orderByDesc('priority')
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Get a single split rule.
     */
    public function getRule(string $accountId, string $ruleId): ?SplitRule
    {
        return SplitRule::forAccount($accountId)->find($ruleId);
    }

    /**
     * Create a new split rule.
     */
    public function createRule(string $accountId, array $data): SplitRule
    {
        return DB::transaction(function () use ($accountId, $data) {
            // Deduplica só dentro do MESMO escopo (produto + checkout). Comparar
            // apenas os destinatários fazia uma regra nova de outro produto
            // devolver a regra existente, e a segunda nunca era criada.
            $recipientIds = collect($data['recipients'] ?? [])->pluck('recipient_id')->sort()->values()->toArray();
            if (!empty($recipientIds)) {
                $existing = SplitRule::forAccount($accountId)
                    ->where('is_active', true)
                    ->where('product_id', $data['product_id'] ?? null)
                    ->where('checkout_id', $data['checkout_id'] ?? null)
                    ->get()
                    ->first(function ($rule) use ($recipientIds) {
                        $existingIds = collect($rule->recipients ?? [])->pluck('recipient_id')->sort()->values()->toArray();
                        return $existingIds === $recipientIds;
                    });
                if ($existing) {
                    return $existing;
                }
            }

            // If setting as default, unset other defaults for this account
            if (!empty($data['is_default'])) {
                SplitRule::forAccount($accountId)->update(['is_default' => false]);
            }

            $rule = SplitRule::create([
                'id' => Str::uuid()->toString(),
                'account_id' => $accountId,
                'name' => $data['name'],
                'product_id' => $data['product_id'] ?? null,
                'checkout_id' => $data['checkout_id'] ?? null,
                'is_default' => $data['is_default'] ?? false,
                'is_active' => $data['is_active'] ?? true,
                'priority' => $data['priority'] ?? 0,
                'recipients' => $data['recipients'],
                'charge_fee_to_producer' => $data['charge_fee_to_producer'] ?? true,
                'minimum_amount' => $data['minimum_amount'] ?? 0,
                'auto_process' => $data['auto_process'] ?? true,
                'transfer_delay_hours' => $data['transfer_delay_hours'] ?? 0,
            ]);

            Log::info('Split rule created', [
                'rule_id' => $rule->id,
                'account_id' => $accountId,
                'name' => $rule->name,
                'recipients_count' => count($rule->recipients),
            ]);

            return $rule;
        });
    }

    /**
     * Update an existing split rule.
     */
    public function updateRule(string $accountId, string $ruleId, array $data): ?SplitRule
    {
        return DB::transaction(function () use ($accountId, $ruleId, $data) {
            $rule = SplitRule::forAccount($accountId)->find($ruleId);
            if (!$rule) {
                return null;
            }

            // If setting as default, unset other defaults
            if (!empty($data['is_default']) && !$rule->is_default) {
                SplitRule::forAccount($accountId)
                    ->where('id', '!=', $ruleId)
                    ->update(['is_default' => false]);
            }

            $rule->update(array_filter($data, fn($v) => $v !== null));

            Log::info('Split rule updated', ['rule_id' => $ruleId, 'account_id' => $accountId]);

            return $rule->fresh();
        });
    }

    /**
     * Delete a split rule.
     */
    public function deleteRule(string $accountId, string $ruleId): bool
    {
        $rule = SplitRule::forAccount($accountId)->find($ruleId);
        if (!$rule) {
            return false;
        }

        $rule->delete();
        Log::info('Split rule deleted', ['rule_id' => $ruleId, 'account_id' => $accountId]);
        return true;
    }

    // ══════════════════════════════════════════════════════
    //  AUTOMATIC SPLIT RESOLUTION
    // ══════════════════════════════════════════════════════

    /**
     * Find the best matching split rule for a charge.
     *
     * Priority resolution:
     * 1. Product + Checkout specific rule (highest priority value)
     * 2. Product-specific rule
     * 3. Checkout-specific rule
     * 4. Default rule for account
     * 5. Legacy affiliate-based split (backward compat)
     */
    public function resolveRule(Charge $charge): ?SplitRule
    {
        $accountId = $charge->account_id;

        // Try product+checkout specific first
        if ($charge->product_id && $charge->checkout_id) {
            $rule = SplitRule::forAccount($accountId)
                ->active()
                ->where('product_id', $charge->product_id)
                ->where('checkout_id', $charge->checkout_id)
                ->orderByDesc('priority')
                ->first();
            if ($rule && $rule->appliesToAmount($charge->amount)) {
                return $rule;
            }
        }

        // Try product-specific
        if ($charge->product_id) {
            $rule = SplitRule::forAccount($accountId)
                ->active()
                ->where('product_id', $charge->product_id)
                ->whereNull('checkout_id')
                ->orderByDesc('priority')
                ->first();
            if ($rule && $rule->appliesToAmount($charge->amount)) {
                return $rule;
            }
        }

        // Try checkout-specific
        if ($charge->checkout_id) {
            $rule = SplitRule::forAccount($accountId)
                ->active()
                ->whereNull('product_id')
                ->where('checkout_id', $charge->checkout_id)
                ->orderByDesc('priority')
                ->first();
            if ($rule && $rule->appliesToAmount($charge->amount)) {
                return $rule;
            }
        }

        // Fall back to default rule
        $rule = SplitRule::forAccount($accountId)
            ->active()
            ->where('is_default', true)
            ->orderByDesc('priority')
            ->first();
        if ($rule && $rule->appliesToAmount($charge->amount)) {
            return $rule;
        }

        return null;
    }

    // ══════════════════════════════════════════════════════
    //  SPLIT CREATION (AUTOMATIC)
    // ══════════════════════════════════════════════════════

    /**
     * Create splits for a paid charge — AUTOMATIC.
     *
     * Resolution order:
     * 1. Inline split_rules from charge metadata (API-provided)
     * 2. Database SplitRule (resolved by account/product/checkout)
     * 3. Legacy affiliate-based split
     * 4. 100% producer split (fallback)
     */
    public function createSplitsForCharge(Charge $charge): array
    {
        if (!$charge->isPaid()) {
            return [];
        }

        $transaction = Transaction::where('charge_id', $charge->id)->first();
        if (!$transaction) {
            Log::warning('SplitService: No transaction found for charge', ['charge_id' => $charge->id]);
            return [];
        }

        // Idempotency: don't create duplicates
        if (Split::where('transaction_id', $transaction->id)->exists()) {
            return Split::where('transaction_id', $transaction->id)->get()->toArray();
        }

        // 1. Check for inline split_rules in charge metadata (API-provided)
        $metadata = $charge->metadata ?? [];
        $inlineRules = $metadata['split_rules'] ?? null;
        if (!empty($inlineRules) && is_array($inlineRules)) {
            return $this->createSplitsFromInlineRules($inlineRules, $transaction, $charge);
        }

        // 2. Resolve SplitRule from database
        $rule = $this->resolveRule($charge);
        if ($rule) {
            return $this->createSplitsFromRule($rule, $transaction, $charge);
        }

        // 3. Legacy fallback: affiliate-based split
        return $this->createLegacySplits($transaction, $charge);
    }

    /**
     * Create splits from inline split_rules (passed via API in charge metadata).
     *
     * Supports both 'amount' (centavos) and 'percentage' fields.
     * Auto-processes immediately (credits recipient wallets).
     */
    private function createSplitsFromInlineRules(array $inlineRules, Transaction $transaction, Charge $charge): array
    {
        // Normaliza o formato da API (amount/percentage) para o formato interno (type/value).
        $recipients = [];
        foreach ($inlineRules as $index => $rule) {
            $recipientId = $rule['recipient_id'] ?? null;
            if (!$recipientId) {
                Log::warning('Inline split_rule missing recipient_id', [
                    'charge_id' => $charge->id,
                    'rule_index' => $index,
                ]);
                continue;
            }

            $type = ($rule['type'] ?? 'fixed') === 'percentage' ? 'percentage' : 'fixed';

            $recipients[] = [
                'recipient_id' => $recipientId,
                'recipient_type' => $rule['recipient_type'] ?? 'account',
                'type' => $type,
                'value' => $type === 'percentage'
                    ? (float) ($rule['percentage'] ?? 0)
                    : (float) ($rule['amount'] ?? 0),
                'description' => $rule['description'] ?? '',
            ];
        }

        $feeMode = collect($inlineRules)->contains(fn ($r) => !empty($r['charge_processing_fee']))
            ? SplitCalculator::FEE_PROPORTIONAL
            : SplitCalculator::FEE_PRODUCER;

        return $this->persistSplits(
            recipients: $recipients,
            transaction: $transaction,
            charge: $charge,
            feeMode: $feeMode,
            autoProcess: true,
            context: ['source' => 'inline_api'],
        );
    }

    /**
     * Create splits from a SplitRule (multi-recipient).
     */
    private function createSplitsFromRule(SplitRule $rule, Transaction $transaction, Charge $charge): array
    {
        return $this->persistSplits(
            recipients: $rule->recipients ?? [],
            transaction: $transaction,
            charge: $charge,
            feeMode: $rule->charge_fee_to_producer
                ? SplitCalculator::FEE_PRODUCER
                : SplitCalculator::FEE_PROPORTIONAL,
            autoProcess: $rule->auto_process && $rule->transfer_delay_hours === 0,
            context: [
                'source' => 'rule',
                'rule_id' => $rule->id,
                'rule_name' => $rule->name,
            ],
        );
    }

    /**
     * Calcula, grava e (opcionalmente) processa as parcelas de um split.
     *
     * Caminho único de criação — regra do banco e split inline da API passam
     * por aqui, então o rateio nunca diverge entre os dois.
     *
     * @param  array<int, array<string, mixed>>  $recipients
     */
    private function persistSplits(
        array $recipients,
        Transaction $transaction,
        Charge $charge,
        string $feeMode,
        bool $autoProcess,
        array $context = [],
    ): array {
        $splits = DB::transaction(function () use ($recipients, $transaction, $charge, $feeMode, $autoProcess, $context) {
            $result = $this->calculator->calculate(
                recipients: $recipients,
                grossAmount: (int) $charge->amount,
                feeAmount: (int) ($charge->fee_amount ?? 0),
                producerAccountId: $charge->account_id,
                feeMode: $feeMode,
            );

            // Se o rateio não fecha, algo está errado no cálculo — não movimenta dinheiro.
            if (!$result->isBalanced()) {
                Log::error('Split rejeitado: rateio não fecha com o líquido da cobrança', [
                    'charge_id' => $charge->id,
                    'result' => $result->toArray(),
                ] + $context);

                throw new \RuntimeException('Split inválido: a soma das parcelas não confere com o valor da cobrança.');
            }

            $created = [];
            foreach ($result->persistable() as $allocation) {
                $created[] = $this->createSplitRecord($allocation, $transaction);
            }

            Log::info('Splits criados', [
                'charge_id' => $charge->id,
                'transaction_id' => $transaction->id,
                'gross_amount' => $result->grossAmount,
                'fee_amount' => $result->feeAmount,
                'distributable' => $result->distributable,
                'to_recipients' => $result->distributedToRecipients(),
                'to_producer' => $result->producerAmount(),
                'splits_count' => count($created),
                'fee_mode' => $feeMode,
                'auto_process' => $autoProcess,
            ] + $context);

            return $created;
        });

        if ($autoProcess) {
            foreach ($splits as $split) {
                try {
                    $this->processSplit($split);
                } catch (\Throwable $e) {
                    Log::error('Falha ao processar split automaticamente', [
                        'split_id' => $split->id,
                        'charge_id' => $charge->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return array_map(fn (Split $s) => $s->fresh()->toArray(), $splits);
    }

    private function createSplitRecord(SplitAllocation $allocation, Transaction $transaction): Split
    {
        return Split::create([
            'id' => Str::uuid()->toString(),
            'transaction_id' => $transaction->id,
            'recipient_id' => $allocation->recipientId,
            'recipient_type' => $allocation->recipientType,
            // A tabela só aceita 'percentage' ou 'fixed'; 'remainder' é conceito interno.
            'type' => $allocation->type === 'percentage' ? 'percentage' : 'fixed',
            'amount' => $allocation->gross,
            'percentage' => $allocation->type === 'percentage' ? $allocation->value : null,
            'fee_amount' => $allocation->fee,
            'net_amount' => $allocation->net,
            'status' => 'pending',
        ]);
    }

    /**
     * Legacy split creation (backward compatible with affiliate system).
     */
    private function createLegacySplits(Transaction $transaction, Charge $charge): array
    {
        $affiliateId = $charge->affiliate_id;

        if (!$affiliateId) {
            $split = $this->createProducerSplit($transaction, $charge);
            return [$split->toArray()];
        }

        $affiliate = Affiliate::find($affiliateId);
        if (!$affiliate) {
            $split = $this->createProducerSplit($transaction, $charge);
            return [$split->toArray()];
        }

        return DB::transaction(function () use ($transaction, $charge, $affiliate) {
            $totalAmount = $charge->net_amount ?? $charge->amount;
            $feeAmount = $charge->fee_amount ?? 0;

            $commissionType = $affiliate->commission_type ?? 'percentage';
            $commissionValue = $affiliate->commission_value ?? 0;

            $affiliateAmount = 0;
            if ($commissionType === 'percentage') {
                $affiliateAmount = (int) round($totalAmount * ($commissionValue / 100));
            } else {
                $affiliateAmount = (int) min($commissionValue, $totalAmount);
            }

            $producerAmount = $totalAmount - $affiliateAmount;
            $splits = [];

            $producerSplit = Split::create([
                'id' => Str::uuid()->toString(),
                'transaction_id' => $transaction->id,
                'recipient_id' => $charge->account_id,
                'recipient_type' => 'account',
                'type' => 'percentage',
                'amount' => $producerAmount,
                'percentage' => $totalAmount > 0 ? round(($producerAmount / $totalAmount) * 100, 2) : 100,
                'fee_amount' => $feeAmount,
                'net_amount' => $producerAmount,
                'status' => 'pending',
            ]);
            $splits[] = $producerSplit;

            if ($affiliateAmount > 0) {
                $affiliateSplit = Split::create([
                    'id' => Str::uuid()->toString(),
                    'transaction_id' => $transaction->id,
                    'recipient_id' => $affiliate->id,
                    'recipient_type' => 'affiliate',
                    'type' => $commissionType,
                    'amount' => $affiliateAmount,
                    'percentage' => $commissionValue,
                    'fee_amount' => 0,
                    'net_amount' => $affiliateAmount,
                    'status' => 'pending',
                ]);
                $splits[] = $affiliateSplit;
            }

            Log::info('Legacy splits created for charge', [
                'charge_id' => $charge->id,
                'transaction_id' => $transaction->id,
                'producer_amount' => $producerAmount,
                'affiliate_amount' => $affiliateAmount,
            ]);

            return array_map(fn($s) => $s->toArray(), $splits);
        });
    }

    // ══════════════════════════════════════════════════════
    //  SPLIT PROCESSING
    // ══════════════════════════════════════════════════════

    /**
     * Process pending splits — transfer funds to wallets.
     */
    public function processPendingSplits(): array
    {
        $pendingSplits = Split::where('status', 'pending')
            ->with('transaction')
            ->orderBy('created_at')
            ->limit(100)
            ->get();

        $results = ['processed' => 0, 'failed' => 0, 'errors' => []];

        $results['deferred'] = 0;

        foreach ($pendingSplits as $split) {
            try {
                $this->processSplit($split);
                $results['processed']++;
            } catch (InsufficientProducerBalanceException $e) {
                // Continua pendente — entra de novo na próxima rodada.
                $results['deferred']++;
                $results['errors'][] = [
                    'split_id' => $split->id,
                    'error' => $e->getMessage(),
                    'retriable' => true,
                ];
            } catch (\Throwable $e) {
                // processSplit() já gravou o status 'failed' após o rollback.
                $results['failed']++;
                $results['errors'][] = [
                    'split_id' => $split->id,
                    'error' => $e->getMessage(),
                    'retriable' => false,
                ];
                Log::error('Split processing failed', [
                    'split_id' => $split->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $results;
    }

    /**
     * Process a single split — credit the recipient's wallet and create a visible charge record.
     */
    public function processSplit(Split $split): void
    {
        try {
            $this->processSplitTransactionally($split);
        } catch (InsufficientProducerBalanceException $e) {
            // Falta de saldo é transitória: mantém `pending` para ser retentado.
            Log::warning('Split adiado por saldo insuficiente', [
                'split_id' => $split->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        } catch (\Throwable $e) {
            // A transação já sofreu rollback, então marcar como falho lá dentro
            // seria desfeito junto. Só aqui fora o status persiste.
            Split::whereKey($split->id)
                ->where('status', '!=', Split::STATUS_TRANSFERRED)
                ->update(['status' => Split::STATUS_FAILED]);

            throw $e;
        }
    }

    private function processSplitTransactionally(Split $split): void
    {
        DB::transaction(function () use ($split) {
            // Idempotência: relê sob lock e só segue se ainda estiver pendente.
            // Sem isso, uma segunda chamada (job + auto-process, webhook duplicado)
            // creditaria a carteira de novo.
            $locked = Split::whereKey($split->id)->lockForUpdate()->first();
            if (!$locked || $locked->status !== Split::STATUS_PENDING) {
                Log::info('Split ignorado: já processado', [
                    'split_id' => $split->id,
                    'status' => $locked?->status,
                ]);

                return;
            }

            $producerAccountId = $this->producerAccountIdFor($locked);

            // A parcela do produtor não movimenta carteira: o valor líquido inteiro
            // já foi creditado nele em updateWalletOnPayment. O que sai daqui para
            // os outros recebedores é debitado dele logo abaixo.
            if ($producerAccountId && $locked->recipient_type === 'account' && $locked->recipient_id === $producerAccountId) {
                $locked->markAsTransferred();

                Log::info('Split do produtor liquidado (sem movimentação)', [
                    'split_id' => $locked->id,
                    'account_id' => $producerAccountId,
                    'net_amount' => $locked->net_amount,
                ]);

                return;
            }

            $recipientAccountId = $this->recipientValidator->resolvePayoutAccountId(
                $locked->recipient_id,
                $locked->recipient_type,
            );

            // Destinatário inexistente: falha alto. Antes, criava uma carteira
            // fantasma para o UUID e o dinheiro sumia sem rastro.
            // O status 'failed' é gravado por processSplit() após o rollback.
            if (!$recipientAccountId) {
                throw new \RuntimeException(
                    "Destinatário do split não encontrado no sistema: {$locked->recipient_type}:{$locked->recipient_id}"
                );
            }

            $locked->update(['status' => Split::STATUS_PROCESSING]);

            $this->transferBetweenWallets(
                fromAccountId: $producerAccountId,
                toAccountId: $recipientAccountId,
                amount: (int) $locked->net_amount,
                splitId: $locked->id,
            );

            $locked->markAsTransferred();

            $this->createSplitChargeForRecipient($locked, $recipientAccountId);

            Log::info('Split processado', [
                'split_id' => $locked->id,
                'recipient_id' => $locked->recipient_id,
                'recipient_type' => $locked->recipient_type,
                'payout_account_id' => $recipientAccountId,
                'net_amount' => $locked->net_amount,
            ]);
        });
    }

    /**
     * Conta dona da cobrança que originou o split.
     */
    private function producerAccountIdFor(Split $split): ?string
    {
        $transaction = Transaction::find($split->transaction_id);
        if (!$transaction) {
            return null;
        }

        return Charge::whereKey($transaction->charge_id)->value('account_id');
    }

    /**
     * Move o valor da carteira do produtor para a do recebedor.
     *
     * O split redistribui dinheiro que já entrou — não cria saldo novo. Creditar
     * o recebedor sem debitar o produtor faria a plataforma pagar duas vezes a
     * mesma venda.
     */
    private function transferBetweenWallets(
        ?string $fromAccountId,
        string $toAccountId,
        int $amount,
        string $splitId,
    ): void {
        if ($amount <= 0) {
            return;
        }

        if ($fromAccountId) {
            $producerWallet = AccountWallet::where('account_id', $fromAccountId)
                ->lockForUpdate()
                ->first();

            if (!$producerWallet) {
                throw new \RuntimeException("Carteira do produtor não encontrada: {$fromAccountId}");
            }

            if ((int) $producerWallet->available_balance < $amount) {
                throw new InsufficientProducerBalanceException(
                    "Saldo insuficiente na carteira do produtor para o split {$splitId}: "
                    . "disponível {$producerWallet->available_balance}, necessário {$amount}"
                );
            }

            $producerWallet->decrement('available_balance', $amount);
        }

        $recipientWallet = AccountWallet::where('account_id', $toAccountId)
            ->lockForUpdate()
            ->first();

        if (!$recipientWallet) {
            $recipientWallet = AccountWallet::create([
                'id' => Str::uuid()->toString(),
                'account_id' => $toAccountId,
                'available_balance' => 0,
                'pending_balance' => 0,
                'blocked_balance' => 0,
                'currency' => 'BRL',
            ]);
        }

        $recipientWallet->increment('available_balance', $amount);
    }

    /**
     * Create a charge record visible to the split recipient in their dashboard.
     */
    private function createSplitChargeForRecipient(Split $split, string $recipientAccountId): void
    {
        $transaction = Transaction::find($split->transaction_id);
        if (!$transaction) {
            return;
        }

        $originalCharge = Charge::find($transaction->charge_id);
        if (!$originalCharge) {
            return;
        }

        // Don't create split charge for the charge owner (they already see the original)
        if ($recipientAccountId === $originalCharge->account_id) {
            return;
        }

        // Build customer info from original charge for context
        $customer = $originalCharge->customer ?? [];
        $customerName = $customer['name'] ?? 'Cliente';

        Charge::create([
            'id' => Str::uuid()->toString(),
            'account_id' => $recipientAccountId,
            'amount' => $split->net_amount,
            'currency' => $originalCharge->currency ?? 'BRL',
            // Keep a valid payment method value across DB engines; source is tracked in metadata.
            'payment_method' => $originalCharge->payment_method ?? 'pix',
            'status' => 'approved',
            'paid_at' => now(),
            'customer' => [
                'name' => $customerName,
                'email' => $customer['email'] ?? '',
                'document' => $customer['document'] ?? '',
            ],
            'metadata' => [
                'source' => 'split',
                'split_id' => $split->id,
                'original_charge_id' => $originalCharge->id,
                'original_amount' => $originalCharge->amount,
                'split_type' => $split->type,
                'split_percentage' => $split->percentage,
            ],
            'description' => "Recebimento via Split — Venda de {$customerName}",
            'net_amount' => $split->net_amount,
            'fee_amount' => 0,
            'idempotency_key' => 'split_' . $split->id,
        ]);
    }

    // ══════════════════════════════════════════════════════
    //  QUERIES
    // ══════════════════════════════════════════════════════

    /**
     * Get split summary for a charge.
     */
    public function getSplitsForCharge(string $chargeId): array
    {
        $transaction = Transaction::where('charge_id', $chargeId)->first();
        if (!$transaction) {
            return [];
        }

        return Split::where('transaction_id', $transaction->id)
            ->get()
            ->toArray();
    }

    /**
     * Get split config for an account.
     */
    public function getSplitConfig(string $accountId): array
    {
        $defaultRule = SplitRule::forAccount($accountId)
            ->where('is_default', true)
            ->active()
            ->first();

        return [
            'has_rules' => SplitRule::forAccount($accountId)->active()->exists(),
            'default_rule_id' => $defaultRule?->id,
            'default_rule_name' => $defaultRule?->name,
            'total_active_rules' => SplitRule::forAccount($accountId)->active()->count(),
            'auto_process' => $defaultRule?->auto_process ?? true,
            'transfer_delay_hours' => $defaultRule?->transfer_delay_hours ?? 0,
        ];
    }

    /**
     * Simulate a split for a given amount — preview without creating.
     */
    public function simulateSplit(string $ruleId, string $accountId, int $amount, int $feeAmount = 0): array
    {
        $rule = SplitRule::forAccount($accountId)->find($ruleId);
        if (!$rule) {
            return ['error' => 'Regra não encontrada'];
        }

        // Mesmo calculador usado na criação real — a simulação nunca mente.
        $result = $this->calculator->calculate(
            recipients: $rule->recipients ?? [],
            grossAmount: $amount,
            feeAmount: $feeAmount,
            producerAccountId: $accountId,
            feeMode: $rule->charge_fee_to_producer
                ? SplitCalculator::FEE_PRODUCER
                : SplitCalculator::FEE_PROPORTIONAL,
        );

        return [
            'rule_id' => $rule->id,
            'rule_name' => $rule->name,
            'input_amount' => $amount,
            'fee_amount' => $result->feeAmount,
            'distributable_amount' => $result->distributable,
            'distributed_amount' => $result->distributedToRecipients(),
            'remainder' => $result->producerAmount(),
            'balanced' => $result->isBalanced(),
            'recipients' => array_map(
                fn (SplitAllocation $a) => $a->toArray(),
                $result->allocations,
            ),
            'producer' => $result->producer->toArray(),
        ];
    }

    // ══════════════════════════════════════════════════════
    //  PRIVATE HELPERS
    // ══════════════════════════════════════════════════════

    /**
     * Create 100% producer split (no affiliate, no rule).
     */
    private function createProducerSplit(Transaction $transaction, Charge $charge): Split
    {
        $totalAmount = $charge->net_amount ?? $charge->amount;

        return Split::create([
            'id' => Str::uuid()->toString(),
            'transaction_id' => $transaction->id,
            'recipient_id' => $charge->account_id,
            'recipient_type' => 'account',
            'type' => 'percentage',
            'amount' => $totalAmount,
            'percentage' => 100.00,
            'fee_amount' => $charge->fee_amount ?? 0,
            'net_amount' => $totalAmount,
            'status' => 'pending',
        ]);
    }
}
