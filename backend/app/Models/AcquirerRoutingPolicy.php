<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Routing\Enums\PaymentMethod;
use App\Domain\Routing\Enums\RoutingStrategy;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * Balancing strategy for a payment method, either globally or scoped to one
 * establishment (account). An account-scoped row always wins over the global one.
 */
class AcquirerRoutingPolicy extends Model
{
    use HasUuids;

    public const SCOPE_GLOBAL = 'global';
    public const SCOPE_ACCOUNT = 'account';

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'scope',
        'account_id',
        'payment_method',
        'strategy',
        'failover_enabled',
        'max_attempts',
        'total_budget_ms',
        'strict_health_check',
        'settings',
    ];

    protected $casts = [
        'failover_enabled' => 'boolean',
        'strict_health_check' => 'boolean',
        'max_attempts' => 'integer',
        'total_budget_ms' => 'integer',
        'settings' => 'array',
    ];

    public function strategyEnum(): RoutingStrategy
    {
        return RoutingStrategy::tryNormalize($this->strategy);
    }

    public function methodEnum(): PaymentMethod
    {
        return PaymentMethod::normalize($this->payment_method);
    }

    /**
     * Policy used when nothing is configured in the database.
     */
    public static function defaultFor(PaymentMethod $method, ?string $accountId = null): self
    {
        $defaults = (array) config('acquirer_routing.defaults', []);

        return new self([
            'scope' => $accountId ? self::SCOPE_ACCOUNT : self::SCOPE_GLOBAL,
            'account_id' => $accountId,
            'payment_method' => $method->value,
            'strategy' => (string) ($defaults['strategy'] ?? 'priority'),
            'failover_enabled' => (bool) ($defaults['failover_enabled'] ?? true),
            'max_attempts' => (int) ($defaults['max_attempts'] ?? 3),
            'total_budget_ms' => (int) ($defaults['total_budget_ms'] ?? 25000),
            'strict_health_check' => (bool) ($defaults['strict_health_check'] ?? true),
            'settings' => [],
        ]);
    }
}
