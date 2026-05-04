<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Affiliate;
use App\Models\AccountWallet;
use App\Models\Charge;
use App\Models\Split;
use App\Models\SplitRule;
use App\Models\Transaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SplitService
{
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
            // Deduplicate: if a rule with same recipients already exists, return it
            $recipientIds = collect($data['recipients'] ?? [])->pluck('recipient_id')->sort()->values()->toArray();
            if (!empty($recipientIds)) {
                $existing = SplitRule::forAccount($accountId)
                    ->whereRaw('"is_active" = true')
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
        return DB::transaction(function () use ($inlineRules, $transaction, $charge) {
            $totalAmount = $charge->net_amount ?? $charge->amount;
            $feeAmount = $charge->fee_amount ?? 0;
            $splits = [];
            $distributedAmount = 0;

            foreach ($inlineRules as $index => $rule) {
                $recipientId = $rule['recipient_id'] ?? null;
                if (!$recipientId) {
                    Log::warning('Inline split_rule missing recipient_id', [
                        'charge_id' => $charge->id,
                        'rule_index' => $index,
                    ]);
                    continue;
                }

                $recipientType = $rule['recipient_type'] ?? 'account';
                $splitType = $rule['type'] ?? 'fixed';
                // DB enum only supports 'percentage' and 'fixed'; map 'flat' → 'fixed'
                $dbType = $splitType === 'flat' ? 'fixed' : $splitType;
                $chargeProcessingFee = $rule['charge_processing_fee'] ?? false;

                // Calculate amount
                $recipientAmount = 0;
                if ($splitType === 'percentage') {
                    $percentage = (float) ($rule['percentage'] ?? 0);
                    $recipientAmount = (int) round($totalAmount * ($percentage / 100));
                } else {
                    // fixed/flat — 'amount' is in centavos
                    $fixedAmount = (int) ($rule['amount'] ?? 0);
                    $recipientAmount = (int) min($fixedAmount, $totalAmount - $distributedAmount);
                }

                if ($recipientAmount <= 0) {
                    continue;
                }

                // Fee: if charge_processing_fee, this recipient absorbs fees
                $recipientFee = $chargeProcessingFee ? $feeAmount : 0;
                $netAmount = max(0, $recipientAmount - $recipientFee);
                $distributedAmount += $recipientAmount;

                $split = Split::create([
                    'id' => Str::uuid()->toString(),
                    'transaction_id' => $transaction->id,
                    'recipient_id' => $recipientId,
                    'recipient_type' => $recipientType,
                    'type' => $dbType,
                    'amount' => $recipientAmount,
                    'percentage' => $dbType === 'percentage' ? (float) ($rule['percentage'] ?? 0) : null,
                    'fee_amount' => $recipientFee,
                    'net_amount' => $netAmount,
                    'status' => 'pending',
                ]);

                $splits[] = $split;
            }

            // Remaining amount goes to charge owner (gateway/producer)
            $remainingAmount = $totalAmount - $distributedAmount;
            if ($remainingAmount > 0) {
                $producerFee = $feeAmount - collect($splits)->sum('fee_amount');
                $producerSplit = Split::create([
                    'id' => Str::uuid()->toString(),
                    'transaction_id' => $transaction->id,
                    'recipient_id' => $charge->account_id,
                    'recipient_type' => 'account',
                    'type' => 'fixed',
                    'amount' => $remainingAmount,
                    'percentage' => null,
                    'fee_amount' => max(0, $producerFee),
                    'net_amount' => max(0, $remainingAmount - max(0, $producerFee)),
                    'status' => 'pending',
                ]);
                $splits[] = $producerSplit;
            }

            Log::info('Inline splits created from API split_rules', [
                'charge_id' => $charge->id,
                'transaction_id' => $transaction->id,
                'total_amount' => $totalAmount,
                'recipients_count' => count($splits),
                'distributed' => $distributedAmount,
                'remaining_to_producer' => $remainingAmount ?? 0,
            ]);

            // Auto-process: credit wallets immediately
            foreach ($splits as $split) {
                try {
                    $this->processSplit($split);
                } catch (\Exception $e) {
                    Log::error('Auto-process inline split failed', [
                        'split_id' => $split->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            return array_map(fn($s) => $s->fresh()->toArray(), $splits);
        });
    }

    /**
     * Create splits from a SplitRule (multi-recipient).
     */
    private function createSplitsFromRule(SplitRule $rule, Transaction $transaction, Charge $charge): array
    {
        return DB::transaction(function () use ($rule, $transaction, $charge) {
            $totalAmount = $charge->net_amount ?? $charge->amount;
            $feeAmount = $charge->fee_amount ?? 0;
            $splits = [];
            $distributedAmount = 0;

            $recipients = $rule->recipients;

            foreach ($recipients as $index => $recipient) {
                $recipientId = $recipient['recipient_id'];
                $recipientType = $recipient['recipient_type'] ?? 'account';
                $splitType = $recipient['type'] ?? 'percentage';
                $value = (float) ($recipient['value'] ?? 0);

                // Calculate amount
                $recipientAmount = 0;
                if ($splitType === 'percentage') {
                    $recipientAmount = (int) round($totalAmount * ($value / 100));
                } else {
                    $recipientAmount = (int) min($value, $totalAmount - $distributedAmount);
                }

                // Fee distribution
                $recipientFee = 0;
                if ($rule->charge_fee_to_producer && $index === 0) {
                    $recipientFee = $feeAmount;
                } elseif (!$rule->charge_fee_to_producer) {
                    $recipientFee = $totalAmount > 0
                        ? (int) round($feeAmount * ($recipientAmount / $totalAmount))
                        : 0;
                }

                $netAmount = max(0, $recipientAmount - $recipientFee);
                $distributedAmount += $recipientAmount;

                $split = Split::create([
                    'id' => Str::uuid()->toString(),
                    'transaction_id' => $transaction->id,
                    'recipient_id' => $recipientId,
                    'recipient_type' => $recipientType,
                    'type' => $splitType,
                    'amount' => $recipientAmount,
                    'percentage' => $splitType === 'percentage' ? $value : null,
                    'fee_amount' => $recipientFee,
                    'net_amount' => $netAmount,
                    'status' => 'pending',
                ]);

                $splits[] = $split;
            }

            // Fix rounding errors (max ±1 cent per recipient) — give to last recipient
            $remainder = $totalAmount - $distributedAmount;
            if ($remainder > 0 && $remainder <= count($recipients) && !empty($splits)) {
                $lastSplit = end($splits);
                $lastSplit->increment('amount', $remainder);
                $lastSplit->increment('net_amount', $remainder);
            }

            Log::info('Auto-splits created from rule', [
                'rule_id' => $rule->id,
                'rule_name' => $rule->name,
                'charge_id' => $charge->id,
                'transaction_id' => $transaction->id,
                'total_amount' => $totalAmount,
                'recipients_count' => count($splits),
                'auto_process' => $rule->auto_process,
            ]);

            // Auto-process if configured
            if ($rule->auto_process && $rule->transfer_delay_hours === 0) {
                foreach ($splits as $split) {
                    try {
                        $this->processSplit($split);
                    } catch (\Exception $e) {
                        Log::error('Auto-process split failed', [
                            'split_id' => $split->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

            return array_map(fn($s) => $s->fresh()->toArray(), $splits);
        });
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

        foreach ($pendingSplits as $split) {
            try {
                $this->processSplit($split);
                $results['processed']++;
            } catch (\Exception $e) {
                $split->markAsFailed();
                $results['failed']++;
                $results['errors'][] = [
                    'split_id' => $split->id,
                    'error' => $e->getMessage(),
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
        DB::transaction(function () use ($split) {
            $split->update(['status' => 'processing']);

            $recipientAccountId = null;

            if ($split->recipient_type === 'account') {
                $recipientAccountId = $split->recipient_id;
                $wallet = AccountWallet::where('account_id', $split->recipient_id)
                    ->lockForUpdate()
                    ->first();
                if (!$wallet) {
                    $wallet = AccountWallet::create([
                        'id' => Str::uuid()->toString(),
                        'account_id' => $split->recipient_id,
                        'available_balance' => 0,
                        'pending_balance' => 0,
                    ]);
                    $wallet = $wallet->fresh();
                }
                $wallet->increment('available_balance', $split->net_amount);
            } elseif ($split->recipient_type === 'affiliate') {
                $affiliate = Affiliate::find($split->recipient_id);
                if ($affiliate && $affiliate->affiliate_account_id) {
                    $recipientAccountId = $affiliate->affiliate_account_id;
                    $wallet = AccountWallet::where('account_id', $affiliate->affiliate_account_id)
                        ->lockForUpdate()
                        ->first();
                    if ($wallet) {
                        $wallet->increment('available_balance', $split->net_amount);
                    }
                }
            }

            $split->markAsTransferred();

            // Create a visible charge record for the recipient
            if ($recipientAccountId) {
                $this->createSplitChargeForRecipient($split, $recipientAccountId);
            }

            Log::info('Split processed', [
                'split_id' => $split->id,
                'recipient_id' => $split->recipient_id,
                'recipient_type' => $split->recipient_type,
                'net_amount' => $split->net_amount,
            ]);
        });
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

        $totalAmount = $amount - $feeAmount;
        $recipients = $rule->recipients;
        $result = [];
        $distributedAmount = 0;

        foreach ($recipients as $index => $recipient) {
            $splitType = $recipient['type'] ?? 'percentage';
            $value = (float) ($recipient['value'] ?? 0);

            $recipientAmount = 0;
            if ($splitType === 'percentage') {
                $recipientAmount = (int) round($totalAmount * ($value / 100));
            } else {
                $recipientAmount = (int) min($value, $totalAmount - $distributedAmount);
            }

            $recipientFee = 0;
            if ($rule->charge_fee_to_producer && $index === 0) {
                $recipientFee = $feeAmount;
            } elseif (!$rule->charge_fee_to_producer) {
                $recipientFee = $totalAmount > 0
                    ? (int) round($feeAmount * ($recipientAmount / $totalAmount))
                    : 0;
            }

            $netAmount = max(0, $recipientAmount - $recipientFee);
            $distributedAmount += $recipientAmount;

            $result[] = [
                'recipient_id' => $recipient['recipient_id'],
                'recipient_type' => $recipient['recipient_type'] ?? 'account',
                'description' => $recipient['description'] ?? '',
                'type' => $splitType,
                'value' => $value,
                'gross_amount' => $recipientAmount,
                'fee_amount' => $recipientFee,
                'net_amount' => $netAmount,
            ];
        }

        return [
            'rule_id' => $rule->id,
            'rule_name' => $rule->name,
            'input_amount' => $amount,
            'fee_amount' => $feeAmount,
            'distributable_amount' => $totalAmount,
            'distributed_amount' => $distributedAmount,
            'remainder' => $totalAmount - $distributedAmount,
            'recipients' => $result,
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
