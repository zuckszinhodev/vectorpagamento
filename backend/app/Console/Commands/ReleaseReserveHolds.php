<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AccountWallet;
use App\Models\ReserveHold;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Releases matured rolling-reserve holds: moves funds from the account's
 * blocked balance back to available once the hold period has passed.
 *
 * Schedule this daily (e.g. cron: php artisan reserve:release).
 */
class ReleaseReserveHolds extends Command
{
    protected $signature = 'reserve:release';
    protected $description = 'Release matured rolling-reserve holds back to available balance';

    public function handle(): int
    {
        $due = ReserveHold::where('status', 'held')
            ->where('release_at', '<=', now())
            ->orderBy('release_at')
            ->limit(500)
            ->get();

        if ($due->isEmpty()) {
            $this->info('No reserve holds to release.');
            return self::SUCCESS;
        }

        $released = 0;
        foreach ($due as $hold) {
            try {
                DB::transaction(function () use ($hold) {
                    $wallet = AccountWallet::lockForUpdate()
                        ->where('account_id', $hold->account_id)
                        ->first();

                    if ($wallet) {
                        $take = min((int) $hold->amount, (int) $wallet->blocked_balance);
                        if ($take > 0) {
                            $wallet->decrement('blocked_balance', $take);
                            $wallet->increment('available_balance', $take);
                        }
                    }

                    $hold->update(['status' => 'released', 'released_at' => now()]);
                });
                $released++;
            } catch (\Throwable $e) {
                Log::error('ReleaseReserveHolds failed for hold', ['hold_id' => $hold->id, 'error' => $e->getMessage()]);
            }
        }

        $this->info("Released {$released} reserve hold(s).");
        return self::SUCCESS;
    }
}
