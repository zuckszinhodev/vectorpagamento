<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\SplitService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessPendingSplitsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 30;

    public function handle(SplitService $splitService): void
    {
        $results = $splitService->processPendingSplits();

        Log::info('ProcessPendingSplitsJob completed', $results);
    }
}
