<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Charge;
use App\Models\Chargeback;
use App\Models\AccountWallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ChargebackService
{
    /**
     * Open a new chargeback for a charge.
     */
    public function open(array $data): Chargeback
    {
        $charge = Charge::findOrFail($data['charge_id']);

        // Check if chargeback already exists for this charge
        $existing = Chargeback::where('charge_id', $charge->id)
            ->active()
            ->first();

        if ($existing) {
            throw new \RuntimeException('Já existe um chargeback ativo para esta cobrança');
        }

        $chargeback = DB::transaction(function () use ($charge, $data) {
            $cb = Chargeback::create([
                'id' => Str::uuid()->toString(),
                'charge_id' => $charge->id,
                'transaction_id' => $data['transaction_id'] ?? $charge->transactions()->latest()->value('id'),
                'account_id' => $charge->account_id,
                'amount' => $data['amount'] ?? $charge->amount,
                'reason_code' => $data['reason_code'] ?? null,
                'reason' => $data['reason'] ?? null,
                'status' => Chargeback::STATUS_OPEN,
                'acquirer_reference' => $data['acquirer_reference'] ?? null,
                'deadline_at' => $data['deadline_at'] ?? now()->addDays(14),
            ]);

            // Mark charge as chargeback
            $charge->update(['status' => 'chargeback']);

            // Block the amount in the merchant's wallet
            $wallet = AccountWallet::where('account_id', $charge->account_id)->first();
            if ($wallet) {
                $wallet->increment('blocked_balance', $cb->amount);
                $wallet->decrement('available_balance', min($wallet->available_balance, $cb->amount));
            }

            return $cb;
        });

        Log::info('Chargeback opened', [
            'chargeback_id' => $chargeback->id,
            'charge_id' => $charge->id,
            'amount' => $chargeback->amount,
        ]);

        return $chargeback;
    }

    /**
     * Submit evidence for a chargeback.
     */
    public function submitEvidence(Chargeback $chargeback, array $evidence): Chargeback
    {
        if ($chargeback->isResolved()) {
            throw new \RuntimeException('Chargeback já resolvido');
        }

        $chargeback->update([
            'evidence' => $evidence,
            'status' => Chargeback::STATUS_EVIDENCE_SENT,
        ]);

        Log::info('Chargeback evidence submitted', [
            'chargeback_id' => $chargeback->id,
        ]);

        return $chargeback->fresh();
    }

    /**
     * Resolve a chargeback (won or lost).
     */
    public function resolve(Chargeback $chargeback, string $outcome, string $notes, ?string $resolvedBy = null): Chargeback
    {
        if ($chargeback->isResolved()) {
            throw new \RuntimeException('Chargeback já resolvido');
        }

        $status = $outcome === 'won' ? Chargeback::STATUS_WON : Chargeback::STATUS_LOST;

        DB::transaction(function () use ($chargeback, $status, $notes, $resolvedBy) {
            $chargeback->update([
                'status' => $status,
                'resolution_notes' => $notes,
                'resolved_at' => now(),
                'resolved_by' => $resolvedBy,
            ]);

            $wallet = AccountWallet::where('account_id', $chargeback->account_id)->first();

            if ($status === Chargeback::STATUS_WON) {
                // Merchant won: unblock the amount, return to available
                if ($wallet) {
                    $wallet->decrement('blocked_balance', $chargeback->amount);
                    $wallet->increment('available_balance', $chargeback->amount);
                }
                // Restore charge status
                $chargeback->charge()->update(['status' => 'paid']);
            } else {
                // Merchant lost: deduct from blocked
                if ($wallet) {
                    $wallet->decrement('blocked_balance', $chargeback->amount);
                }
                // Charge stays as chargeback
            }
        });

        Log::info('Chargeback resolved', [
            'chargeback_id' => $chargeback->id,
            'outcome' => $status,
        ]);

        return $chargeback->fresh();
    }
}
