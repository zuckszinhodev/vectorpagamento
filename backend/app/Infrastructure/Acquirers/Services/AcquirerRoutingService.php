<?php

declare(strict_types=1);

namespace Infrastructure\Acquirers\Services;

use Domain\Acquirer\Contracts\AcquirerGatewayInterface;
use Domain\Acquirer\ValueObjects\RoutingRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Infrastructure\Acquirers\Stripe\StripeGateway;
use Infrastructure\Acquirers\Pagarme\PagarmeGateway;
use Infrastructure\Acquirers\MercadoPago\MercadoPagoGateway;
use Infrastructure\Acquirers\Babylon\BabylonGateway;

class AcquirerRoutingService
{
    private array $gateways = [];
    private array $healthStatus = [];

    public function __construct()
    {
        $this->initializeGateways();
    }

    private function initializeGateways(): void
    {
        // Initialize available gateways
        if (config('acquirers.stripe.enabled')) {
            $this->gateways['stripe'] = new StripeGateway();
        }

        if (config('acquirers.pagarme.enabled')) {
            $this->gateways['pagarme'] = new PagarmeGateway();
        }

        if (config('acquirers.mercadopago.enabled')) {
            $this->gateways['mercadopago'] = new MercadoPagoGateway();
        }

        if (config('acquirers.babylon.enabled')) {
            $this->gateways['babylon'] = new BabylonGateway();
        }
    }

    public function getAcquirer(
        string $accountId,
        int $amount,
        string $paymentMethod
    ): ?AcquirerGatewayInterface {
        return $this->getDefaultAcquirer($paymentMethod);
    }

    public function getAcquirerById(string $acquirerId): ?AcquirerGatewayInterface
    {
        $slug = DB::table('acquirers')
            ->where('id', $acquirerId)
            ->value('slug');

        return $this->gateways[$slug] ?? null;
    }

    public function getAcquirerId(string $identifier): string
    {
        return Cache::remember("acquirer_id_{$identifier}", 3600, function () use ($identifier) {
            return DB::table('acquirers')
                ->where('slug', $identifier)
                ->value('id');
        });
    }

    private function getRoutingRules(string $accountId, string $paymentMethod): array
    {
        $records = DB::table('routing_rules')
            ->where('account_id', $accountId)
            ->whereRaw('"is_active" = true')
            ->where(function ($query) use ($paymentMethod) {
                $query->whereNull('payment_method')
                    ->orWhere('payment_method', $paymentMethod);
            })
            ->orderBy('priority')
            ->get();

        $rules = [];
        foreach ($records as $record) {
            $rules[] = new RoutingRule(
                acquirerId: $record->acquirer_id,
                priority: $record->priority,
                paymentMethod: $record->payment_method,
                minAmount: $record->min_amount ? (int) $record->min_amount : null,
                maxAmount: $record->max_amount ? (int) $record->max_amount : null,
                conditions: json_decode($record->conditions, true) ?? [],
                isActive: (bool) $record->is_active
            );
        }

        return $rules;
    }

    private function isAcquirerHealthy(string $acquirerId): bool
    {
        $cacheKey = "acquirer_health_{$acquirerId}";

        return Cache::remember($cacheKey, 60, function () use ($acquirerId) {
            // Check recent failure rate
            $recentTransactions = DB::table('transactions')
                ->where('acquirer_id', $acquirerId)
                ->where('created_at', '>=', now()->subMinutes(5))
                ->selectRaw('
                    COUNT(*) as total,
                    SUM(CASE WHEN status = \'failed\' THEN 1 ELSE 0 END) as failed
                ')
                ->first();

            if (!$recentTransactions || $recentTransactions->total < 10) {
                return true; // Not enough data
            }

            $failureRate = $recentTransactions->failed / $recentTransactions->total;

            return $failureRate < 0.3; // Less than 30% failure rate
        });
    }

    private function hasCredentials(string $accountId, string $acquirerId): bool
    {
        return DB::table('account_acquirers')
            ->where('account_id', $accountId)
            ->where('acquirer_id', $acquirerId)
            ->whereRaw('"is_active" = true')
            ->exists();
    }

    private function getGatewayWithCredentials(
        string $accountId,
        string $acquirerId
    ): ?AcquirerGatewayInterface {
        $accountAcquirer = DB::table('account_acquirers')
            ->where('account_id', $accountId)
            ->where('acquirer_id', $acquirerId)
            ->whereRaw('"is_active" = true')
            ->first();

        if (!$accountAcquirer) {
            return null;
        }

        $slug = DB::table('acquirers')
            ->where('id', $acquirerId)
            ->value('slug');

        if (!isset($this->gateways[$slug])) {
            return null;
        }

        // Decrypt credentials and configure gateway
        $credentials = $this->decryptCredentials($accountAcquirer->credentials);
        $gateway = clone $this->gateways[$slug];
        
        if (method_exists($gateway, 'setCredentials')) {
            $gateway->setCredentials($credentials);
        }

        return $gateway;
    }

    private function getDefaultAcquirer(string $paymentMethod): ?AcquirerGatewayInterface
    {
        try {
            $orderedKeys = DB::table('acquirer_configs')
                ->whereRaw('"enabled" = true')
                ->where(function ($query) use ($paymentMethod) {
                    $query->whereJsonContains('supported_methods', strtoupper($paymentMethod))
                        ->orWhereJsonContains('supported_methods', strtolower($paymentMethod));
                })
                ->orderBy('priority')
                ->pluck('acquirer_key');

            if ($orderedKeys->isEmpty()) {
                $orderedKeys = DB::table('acquirer_configs')
                    ->whereRaw('"enabled" = true')
                    ->orderBy('priority')
                    ->pluck('acquirer_key');
            }

            foreach ($orderedKeys as $key) {
                $gateway = $this->gateways[$key] ?? null;
                if (!$gateway) {
                    continue;
                }

                $supportedMethods = array_map('strtolower', $gateway->getSupportedMethods());
                if (in_array(strtolower($paymentMethod), $supportedMethods, true) && $gateway->isAvailable()) {
                    return $gateway;
                }
            }
        } catch (\Throwable $e) {
            // Fall through to the legacy in-memory order.
        }

        foreach ($this->gateways as $gateway) {
            $supportedMethods = array_map('strtolower', $gateway->getSupportedMethods());
            if (in_array(strtolower($paymentMethod), $supportedMethods, true) && $gateway->isAvailable()) {
                return $gateway;
            }
        }

        return null;
    }

    private function getFallbackAcquirer(string $accountId, string $paymentMethod): ?AcquirerGatewayInterface
    {
        return $this->getDefaultAcquirer($paymentMethod);
    }

    private function decryptCredentials(string $encrypted): array
    {
        // Use Laravel's encryption
        try {
            $decrypted = decrypt($encrypted);
            return is_array($decrypted) ? $decrypted : json_decode($decrypted, true);
        } catch (\Exception $e) {
            return [];
        }
    }

    public function markAcquirerUnhealthy(string $acquirerId): void
    {
        Cache::put("acquirer_health_{$acquirerId}", false, 300); // 5 minutes
    }

    public function getAvailableAcquirers(): array
    {
        return array_keys($this->gateways);
    }
}
