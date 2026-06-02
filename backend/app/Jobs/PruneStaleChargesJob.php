<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\StaleChargePruner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PruneStaleChargesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 120;

    public function handle(StaleChargePruner $pruner): void
    {
        $deleted = $pruner->prune();

        Log::info('PruneStaleChargesJob completed', [
            'deleted' => $deleted,
            'cutoff' => now()->subHours(6)->toIso8601String(),
        ]);
    }
}
