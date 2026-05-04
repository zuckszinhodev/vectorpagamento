<?php

namespace App\Infrastructure\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HealthCheckAcquirersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 30;

    public function handle(): void
    {
        Log::info("Running acquirer health checks");

        $acquirers = \DB::table('acquirers')
            ->whereRaw('"is_active" = true')
            ->get();

        foreach ($acquirers as $acquirer) {
            $this->checkAcquirerHealth($acquirer);
        }

        Log::info("Acquirer health checks completed", [
            'checked' => $acquirers->count(),
        ]);
    }

    private function checkAcquirerHealth($acquirer): void
    {
        // Skip acquirers without a base_url configured
        if (empty($acquirer->base_url)) {
            Log::debug("Skipping health check for acquirer without base_url", [
                'acquirer' => $acquirer->name,
            ]);
            return;
        }

        $startTime = microtime(true);
        $isHealthy = false;
        $responseTime = 0;

        try {
            // Make a simple health check request
            $response = Http::timeout(10)
                ->get($acquirer->base_url . '/health');

            $responseTime = (microtime(true) - $startTime) * 1000;
            $isHealthy = $response->successful();

            Log::info("Acquirer health check", [
                'acquirer' => $acquirer->name,
                'healthy' => $isHealthy,
                'response_time_ms' => round($responseTime, 2),
            ]);

        } catch (\Exception $e) {
            $responseTime = (microtime(true) - $startTime) * 1000;

            Log::warning("Acquirer health check failed", [
                'acquirer' => $acquirer->name,
                'error' => $e->getMessage(),
            ]);
        }

        // Update acquirer health status
        \DB::table('acquirers')
            ->where('id', $acquirer->id)
            ->update([
                'health_status' => $isHealthy ? 'healthy' : 'unhealthy',
                'last_health_check' => now(),
                'avg_response_time_ms' => $responseTime,
            ]);
    }
}
