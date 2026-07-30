<?php

declare(strict_types=1);

namespace App\Domain\Routing\Enums;

/**
 * Load balancing strategies available per payment method and per establishment.
 */
enum RoutingStrategy: string
{
    case PRIORITY = 'priority';
    case ROUND_ROBIN = 'round_robin';
    case WEIGHTED = 'weighted';
    case LEAST_LATENCY = 'least_latency';
    case BEST_APPROVAL = 'best_approval';
    case LEAST_UTILIZATION = 'least_utilization';

    public static function tryNormalize(?string $value): self
    {
        $normalized = strtolower(trim((string) $value));

        return match ($normalized) {
            'round_robin', 'roundrobin', 'rr' => self::ROUND_ROBIN,
            'weighted', 'weighted_round_robin', 'wrr' => self::WEIGHTED,
            'least_latency', 'lowest_latency', 'latency' => self::LEAST_LATENCY,
            'best_approval', 'approval', 'best_approval_rate' => self::BEST_APPROVAL,
            'least_utilization', 'least_used', 'utilization' => self::LEAST_UTILIZATION,
            default => self::PRIORITY,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::PRIORITY => 'Prioridade',
            self::ROUND_ROBIN => 'Round Robin',
            self::WEIGHTED => 'Round Robin Ponderado',
            self::LEAST_LATENCY => 'Menor Latência',
            self::BEST_APPROVAL => 'Melhor Taxa de Aprovação',
            self::LEAST_UTILIZATION => 'Menor Utilização',
        };
    }
}
