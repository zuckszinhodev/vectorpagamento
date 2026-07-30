<?php

declare(strict_types=1);

namespace App\Domain\Routing\Enums;

/**
 * Availability of an acquirer as observed by health checks and live traffic.
 *
 * Only {@see self::ONLINE} and {@see self::DEGRADED} are routable. UNKNOWN is
 * routable too — a freshly registered acquirer must be allowed to take its
 * first request, otherwise it can never prove itself healthy.
 */
enum HealthState: string
{
    case ONLINE = 'online';
    case DEGRADED = 'degraded';
    case OFFLINE = 'offline';
    case UNKNOWN = 'unknown';

    public static function tryNormalize(?string $value): self
    {
        $normalized = strtolower(trim((string) $value));

        return match ($normalized) {
            'online', 'healthy', 'up', 'ok' => self::ONLINE,
            'degraded', 'partial', 'slow' => self::DEGRADED,
            'offline', 'unhealthy', 'down', 'error' => self::OFFLINE,
            default => self::UNKNOWN,
        };
    }

    public function isRoutable(): bool
    {
        return $this !== self::OFFLINE;
    }

    public function label(): string
    {
        return match ($this) {
            self::ONLINE => 'Online',
            self::DEGRADED => 'Degradado',
            self::OFFLINE => 'Offline',
            self::UNKNOWN => 'Desconhecido',
        };
    }
}
