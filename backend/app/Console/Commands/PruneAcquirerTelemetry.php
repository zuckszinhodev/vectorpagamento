<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Routing\Contracts\MetricsRepositoryInterface;
use App\Models\AcquirerEvent;
use App\Models\AcquirerRoutingLog;
use Illuminate\Console\Command;

/**
 * Enforces the retention windows for routing telemetry.
 *
 * Without this the metric buckets grow by (acquirers × methods × 1440) rows a
 * day and the routing log by one row per transaction.
 */
class PruneAcquirerTelemetry extends Command
{
    protected $signature = 'acquirer:prune-telemetry
                            {--dry-run : Mostra quantos registros seriam apagados}';

    protected $description = 'Remove métricas, logs de roteamento e eventos de adquirentes fora da janela de retenção';

    public function handle(MetricsRepositoryInterface $metrics): int
    {
        $retention = (array) config('acquirer_routing.retention', []);

        $metricDays = max(1, (int) ($retention['metric_buckets_days'] ?? 30));
        $logDays = max(1, (int) ($retention['routing_logs_days'] ?? 60));
        $eventDays = max(1, (int) ($retention['events_days'] ?? 90));

        if ($this->option('dry-run')) {
            $this->table(
                ['Tabela', 'Retenção (dias)', 'Registros a remover'],
                [
                    ['acquirer_metric_buckets', $metricDays, \App\Models\AcquirerMetricBucket::query()->where('bucket_started_at', '<', now()->subDays($metricDays))->count()],
                    ['acquirer_routing_logs', $logDays, AcquirerRoutingLog::query()->where('created_at', '<', now()->subDays($logDays))->count()],
                    ['acquirer_events', $eventDays, AcquirerEvent::query()->where('occurred_at', '<', now()->subDays($eventDays))->count()],
                ]
            );

            return self::SUCCESS;
        }

        $prunedMetrics = $metrics->prune($metricDays);

        $prunedLogs = AcquirerRoutingLog::query()
            ->where('created_at', '<', now()->subDays($logDays))
            ->delete();

        $prunedEvents = AcquirerEvent::query()
            ->where('occurred_at', '<', now()->subDays($eventDays))
            ->delete();

        $this->info(sprintf(
            'Removidos: %d métricas, %d logs de roteamento, %d eventos.',
            $prunedMetrics,
            $prunedLogs,
            $prunedEvents
        ));

        return self::SUCCESS;
    }
}
