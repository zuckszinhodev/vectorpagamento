<?php

declare(strict_types=1);

namespace App\Infrastructure\Routing\Persistence;

use App\Domain\Routing\Contracts\MetricsRepositoryInterface;
use App\Domain\Routing\Enums\PaymentMethod;
use App\Domain\Routing\ValueObjects\AcquirerMetrics;
use App\Models\AcquirerMetricBucket;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Metrics aggregated into one-minute buckets.
 *
 * The write path is a single "insert or increment" statement, so recording a
 * transaction costs one round trip regardless of throughput. Metrics failures
 * never propagate: losing a counter must not fail a payment.
 */
final class EloquentMetricsRepository implements MetricsRepositoryInterface
{
    public function recordAttempt(
        string $acquirerKey,
        PaymentMethod $method,
        bool $succeeded,
        int $latencyMs,
        int $amountCents = 0,
        bool $isTimeout = false,
        bool $isFallback = false,
    ): void {
        $this->bump($acquirerKey, $method, [
            'attempts' => 1,
            'successes' => $succeeded ? 1 : 0,
            'failures' => $succeeded ? 0 : 1,
            'timeouts' => $isTimeout ? 1 : 0,
            'fallback_ins' => $isFallback ? 1 : 0,
            'latency_sum_ms' => max(0, $latencyMs),
            'volume_cents' => $succeeded ? max(0, $amountCents) : 0,
        ], max(0, $latencyMs));
    }

    public function recordFallbackOut(string $acquirerKey, PaymentMethod $method): void
    {
        $this->bump($acquirerKey, $method, ['fallback_outs' => 1]);
    }

    public function recordRejection(string $acquirerKey, PaymentMethod $method): void
    {
        $this->bump($acquirerKey, $method, ['rejections' => 1]);
    }

    public function forAcquirer(string $acquirerKey, PaymentMethod $method, ?int $windowMinutes = null): AcquirerMetrics
    {
        return $this->forAcquirers([$acquirerKey], $method, $windowMinutes)[$acquirerKey]
            ?? AcquirerMetrics::empty($acquirerKey, $windowMinutes ?? $this->defaultWindow());
    }

    public function forAcquirers(array $acquirerKeys, PaymentMethod $method, ?int $windowMinutes = null): array
    {
        $window = $windowMinutes ?? $this->defaultWindow();
        $result = [];

        foreach ($acquirerKeys as $key) {
            $result[$key] = AcquirerMetrics::empty($key, $window);
        }

        if ($acquirerKeys === []) {
            return $result;
        }

        try {
            $rows = AcquirerMetricBucket::query()
                ->selectRaw('acquirer_key')
                ->selectRaw('SUM(attempts) as attempts')
                ->selectRaw('SUM(successes) as successes')
                ->selectRaw('SUM(failures) as failures')
                ->selectRaw('SUM(timeouts) as timeouts')
                ->selectRaw('SUM(fallback_ins) as fallback_ins')
                ->selectRaw('SUM(fallback_outs) as fallback_outs')
                ->selectRaw('SUM(latency_sum_ms) as latency_sum_ms')
                ->selectRaw('MAX(latency_max_ms) as latency_max_ms')
                ->selectRaw('SUM(volume_cents) as volume_cents')
                ->whereIn('acquirer_key', $acquirerKeys)
                ->where('payment_method', $method->value)
                ->where('bucket_started_at', '>=', now()->subMinutes($window))
                ->groupBy('acquirer_key')
                ->get();

            foreach ($rows as $row) {
                $result[$row->acquirer_key] = $this->hydrate((string) $row->acquirer_key, $row, $window);
            }
        } catch (\Throwable $e) {
            Log::warning('Falha ao agregar métricas das adquirentes', [
                'method' => $method->value,
                'error' => $e->getMessage(),
            ]);
        }

        return $result;
    }

    public function overview(int $windowMinutes = 60): array
    {
        try {
            $rows = AcquirerMetricBucket::query()
                ->selectRaw('acquirer_key')
                ->selectRaw('SUM(attempts) as attempts')
                ->selectRaw('SUM(successes) as successes')
                ->selectRaw('SUM(failures) as failures')
                ->selectRaw('SUM(timeouts) as timeouts')
                ->selectRaw('SUM(fallback_ins) as fallback_ins')
                ->selectRaw('SUM(fallback_outs) as fallback_outs')
                ->selectRaw('SUM(latency_sum_ms) as latency_sum_ms')
                ->selectRaw('MAX(latency_max_ms) as latency_max_ms')
                ->selectRaw('SUM(volume_cents) as volume_cents')
                ->where('bucket_started_at', '>=', now()->subMinutes($windowMinutes))
                ->groupBy('acquirer_key')
                ->get();

            $result = [];

            foreach ($rows as $row) {
                $result[$row->acquirer_key] = $this->hydrate((string) $row->acquirer_key, $row, $windowMinutes);
            }

            return $result;
        } catch (\Throwable $e) {
            Log::warning('Falha ao montar visão geral de métricas', ['error' => $e->getMessage()]);

            return [];
        }
    }

    public function timeSeries(?string $acquirerKey, int $windowMinutes = 60): array
    {
        try {
            $query = AcquirerMetricBucket::query()
                ->selectRaw('bucket_started_at')
                ->selectRaw('SUM(attempts) as attempts')
                ->selectRaw('SUM(successes) as successes')
                ->selectRaw('SUM(failures) as failures')
                ->selectRaw('SUM(latency_sum_ms) as latency_sum_ms')
                ->selectRaw('SUM(volume_cents) as volume_cents')
                ->where('bucket_started_at', '>=', now()->subMinutes($windowMinutes))
                ->groupBy('bucket_started_at')
                ->orderBy('bucket_started_at');

            if ($acquirerKey !== null) {
                $query->where('acquirer_key', $acquirerKey);
            }

            return $query->get()->map(static function ($row): array {
                $attempts = (int) $row->attempts;

                return [
                    'at' => Carbon::parse($row->bucket_started_at)->toIso8601String(),
                    'attempts' => $attempts,
                    'successes' => (int) $row->successes,
                    'failures' => (int) $row->failures,
                    'approval_rate' => $attempts > 0 ? round((int) $row->successes / $attempts, 4) : null,
                    'avg_latency_ms' => $attempts > 0 ? round((int) $row->latency_sum_ms / $attempts, 2) : null,
                    'volume_cents' => (int) $row->volume_cents,
                ];
            })->all();
        } catch (\Throwable $e) {
            Log::warning('Falha ao montar série temporal de métricas', ['error' => $e->getMessage()]);

            return [];
        }
    }

    public function prune(int $olderThanDays): int
    {
        try {
            return AcquirerMetricBucket::query()
                ->where('bucket_started_at', '<', now()->subDays($olderThanDays))
                ->delete();
        } catch (\Throwable $e) {
            Log::warning('Falha ao limpar métricas antigas', ['error' => $e->getMessage()]);

            return 0;
        }
    }

    /**
     * Insert-or-increment for the current minute bucket.
     *
     * @param array<string, int> $increments
     */
    private function bump(string $acquirerKey, PaymentMethod $method, array $increments, int $latencyMs = 0): void
    {
        $bucket = now()->startOfMinute();

        try {
            $affected = AcquirerMetricBucket::query()
                ->where('acquirer_key', $acquirerKey)
                ->where('payment_method', $method->value)
                ->where('bucket_started_at', $bucket)
                ->update($this->incrementExpressions($increments, $latencyMs));

            if ($affected > 0) {
                return;
            }

            AcquirerMetricBucket::query()->create(array_merge(
                [
                    'id' => (string) Str::uuid(),
                    'acquirer_key' => $acquirerKey,
                    'payment_method' => $method->value,
                    'bucket_started_at' => $bucket,
                    'latency_max_ms' => $latencyMs,
                ],
                $increments
            ));
        } catch (\Throwable $e) {
            // A concurrent insert lost the unique-key race: retry as an update.
            try {
                AcquirerMetricBucket::query()
                    ->where('acquirer_key', $acquirerKey)
                    ->where('payment_method', $method->value)
                    ->where('bucket_started_at', $bucket)
                    ->update($this->incrementExpressions($increments, $latencyMs));
            } catch (\Throwable $retry) {
                // Metrics are observability, never a reason to fail a payment.
                Log::warning('Falha ao registrar métrica da adquirente', [
                    'acquirer' => $acquirerKey,
                    'method' => $method->value,
                    'error' => $retry->getMessage(),
                ]);
            }
        }
    }

    /**
     * @param array<string, int> $increments
     * @return array<string, mixed>
     */
    private function incrementExpressions(array $increments, int $latencyMs): array
    {
        $expressions = [];

        foreach ($increments as $column => $delta) {
            if ($delta === 0) {
                continue;
            }

            $expressions[$column] = DB::raw("{$column} + {$delta}");
        }

        if ($latencyMs > 0) {
            // GREATEST is not available on SQLite; MAX() with two args is.
            $expressions['latency_max_ms'] = DB::connection()->getDriverName() === 'sqlite'
                ? DB::raw("MAX(latency_max_ms, {$latencyMs})")
                : DB::raw("GREATEST(latency_max_ms, {$latencyMs})");
        }

        $expressions['updated_at'] = now();

        return $expressions;
    }

    private function hydrate(string $acquirerKey, object $row, int $windowMinutes): AcquirerMetrics
    {
        return new AcquirerMetrics(
            acquirerKey: $acquirerKey,
            attempts: (int) $row->attempts,
            successes: (int) $row->successes,
            failures: (int) $row->failures,
            timeouts: (int) $row->timeouts,
            fallbackIns: (int) $row->fallback_ins,
            fallbackOuts: (int) $row->fallback_outs,
            latencySumMs: (int) $row->latency_sum_ms,
            latencyMaxMs: (int) $row->latency_max_ms,
            volumeCents: (int) $row->volume_cents,
            windowMinutes: $windowMinutes,
        );
    }

    private function defaultWindow(): int
    {
        return max(1, (int) config('acquirer_routing.scoring.window_minutes', 15));
    }
}
