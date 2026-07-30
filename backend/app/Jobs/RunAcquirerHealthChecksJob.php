<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Routing\AcquirerHealthMonitor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Scheduled health checks for every enabled acquirer.
 *
 * Replaces HealthCheckAcquirersJob, which probed `{base_url}/health` — an
 * endpoint none of the integrated PSPs expose — and wrote its verdict to the
 * `acquirers` table while routing read availability from somewhere else.
 */
class RunAcquirerHealthChecksJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 120;

    public function __construct(
        private readonly bool $force = false,
    ) {
    }

    public function handle(AcquirerHealthMonitor $monitor): void
    {
        $startedAt = microtime(true);
        $results = $monitor->checkAll($this->force);

        $healthy = count(array_filter($results, static fn (array $r): bool => ($r['healthy'] ?? false) && !($r['skipped'] ?? false)));
        $skipped = count(array_filter($results, static fn (array $r): bool => $r['skipped'] ?? false));

        Log::info('Health checks das adquirentes concluídos', [
            'checked' => count($results),
            'healthy' => $healthy,
            'unhealthy' => count($results) - $healthy - $skipped,
            'skipped' => $skipped,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);
    }
}
