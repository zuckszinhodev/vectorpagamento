<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\PixNominalNameService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class DailyRefreshAcquirerNominalsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 180;

    public function handle(PixNominalNameService $service): void
    {
        $results = $service->refreshAllAcquirerNominals();

        $successCount = count(array_filter($results, static fn (array $result): bool => (bool) ($result['success'] ?? false)));

        Log::info('Daily acquirer nominal refresh completed', [
            'total' => count($results),
            'success' => $successCount,
            'failed' => count($results) - $successCount,
            'results' => $results,
        ]);
    }
}
