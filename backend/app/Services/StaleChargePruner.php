<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Charge;
use Illuminate\Support\Facades\Log;

class StaleChargePruner
{
    public function prune(int $olderThanHours = 6): int
    {
        $cutoff = now()->subHours($olderThanHours);
        $deleted = 0;

        Charge::query()
            ->whereIn('status', [
                Charge::STATUS_FAILED,
                Charge::STATUS_PENDING,
                Charge::STATUS_PROCESSING,
                Charge::STATUS_EXPIRED,
            ])
            ->where(function ($query) use ($cutoff) {
                $query->where('updated_at', '<', $cutoff)
                    ->orWhere(function ($inner) use ($cutoff) {
                        $inner->whereNotNull('expires_at')
                            ->where('expires_at', '<', $cutoff);
                    });
            })
            ->chunkById(100, function ($charges) use (&$deleted): void {
                foreach ($charges as $charge) {
                    try {
                        $charge->forceDelete();
                        $deleted++;
                    } catch (\Throwable $e) {
                        Log::warning('StaleChargePruner: failed to delete charge', [
                            'charge_id' => $charge->id,
                            'status' => $charge->status,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }, 'id');

        return $deleted;
    }
}
