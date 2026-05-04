<?php

namespace App\Domain\Acquirer\Services;

use App\Domain\Acquirer\Entities\Acquirer;
use App\Domain\Payment\Entities\Charge;
use App\Domain\Acquirer\Contracts\AcquirerInterface;
use App\Infrastructure\Acquirers\StripeAcquirer;
use App\Infrastructure\Acquirers\PagarmeAcquirer;
use App\Infrastructure\Acquirers\PayFortAcquirer;
use App\Infrastructure\Acquirers\BluPayAcquirer;
use App\Infrastructure\Acquirers\BabylonAcquirer;
use App\Infrastructure\Acquirers\AsaasAcquirer;
use App\Models\AcquirerConfig;
use App\Services\AcquirerFactory;
use Illuminate\Support\Facades\Log;

class RoutingService
{
    /**
     * Select best acquirer for payment processing.
     *
     * Uses the global AcquirerConfig (admin panel) priority ordering first,
     * then falls back to the legacy Acquirer model. Lower priority number =
     * higher precedence.
     */
    public function selectAcquirer(string $paymentMethod, ?Charge $charge = null): AcquirerInterface
    {
        try {
            $configs = AcquirerConfig::where('enabled', true)
                ->where(function ($query) use ($paymentMethod) {
                    $query->whereJsonContains('supported_methods', strtoupper($paymentMethod))
                          ->orWhereJsonContains('supported_methods', strtolower($paymentMethod));
                })
                ->orderBy('priority', 'asc')
                ->get();

            if ($configs->isEmpty()) {
                // Fallback: get all enabled acquirers ordered by priority
                $configs = AcquirerConfig::where('enabled', true)
                    ->orderBy('priority', 'asc')
                    ->get();
            }

            foreach ($configs as $config) {
                $instance = $this->getAcquirerInstanceByKey($config->acquirer_key);
                if ($instance) {
                    Log::debug('RoutingService: acquirer selected by global priority', [
                        'acquirer' => $config->acquirer_key,
                        'priority' => $config->priority,
                        'payment_method' => $paymentMethod,
                    ]);
                    return $instance;
                }
            }
        } catch (\Exception $e) {
            Log::warning('RoutingService: DB lookup failed, falling back to Acquirer model', [
                'error' => $e->getMessage(),
            ]);
        }

        // -----------------------------------------------------------
        // Legacy fallback: Acquirer model
        // -----------------------------------------------------------
        $acquirers = Acquirer::enabled()
            ->healthy()
            ->supporting($paymentMethod)
            ->orderBy('priority', 'asc')
            ->get();

        if ($acquirers->isEmpty()) {
            throw new \Exception("Nenhuma adquirente disponível para {$paymentMethod}");
        }

        $selectedAcquirer = $acquirers->first();

        return $this->getAcquirerInstance($selectedAcquirer->type);
    }

    /**
     * Get acquirer by name
     */
    public function getAcquirerByName(string $name): AcquirerInterface
    {
        // Try by acquirer_key in acquirer_configs first
        $instance = $this->getAcquirerInstanceByKey($name);
        if ($instance) {
            return $instance;
        }

        // Legacy lookup
        $acquirer = Acquirer::where('name', $name)
            ->orWhere('slug', $name)
            ->first();

        if (!$acquirer) {
            throw new \Exception("Adquirente {$name} não encontrada");
        }

        return $this->getAcquirerInstance($acquirer->type);
    }

    /**
     * Get acquirer instance by acquirer_key (payfort, blupay, etc.)
     * Uses AcquirerFactory for proper initialization with credentials.
     */
    private function getAcquirerInstanceByKey(string $key): ?AcquirerInterface
    {
        $factory = AcquirerFactory::instance();

        return match ($key) {
            'payfort'    => $factory->payfort(),
            'blupay'     => $factory->blupay(),
            'enki'       => $factory->enki(),
            'witetec'    => $factory->witetec(),
            'asaas'      => $factory->asaas(),
            'huntersub'  => $factory->huntersub(),
            'stripe'     => app(StripeAcquirer::class),
            'pagarme'    => app(PagarmeAcquirer::class),
            'babylon'    => app(BabylonAcquirer::class),
            default      => null,
        };
    }

    /**
     * Get acquirer instance by legacy type string
     */
    private function getAcquirerInstance(string $type): AcquirerInterface
    {
        // Try via key first (handles all known acquirers)
        $instance = $this->getAcquirerInstanceByKey($type);
        if ($instance) {
            return $instance;
        }

        return match ($type) {
            'stripe' => app(StripeAcquirer::class),
            'pagarme' => app(PagarmeAcquirer::class),
            default => throw new \Exception("Tipo de adquirente {$type} não suportado"),
        };
    }

    /**
     * Fallback to next acquirer if current fails.
     * 
    * Uses only the global admin priority for fallback ordering.
     * 
     * @param string $currentAcquirerName The acquirer that failed
     * @param string $paymentMethod The payment method
     * @param string|null $accountId The account ID (if available)
     * @return AcquirerInterface|null Next acquirer or null if none available
     */
    public function fallbackAcquirer(string $currentAcquirerName, string $paymentMethod, ?string $accountId = null): ?AcquirerInterface
    {
        try {
            $configs = AcquirerConfig::where('enabled', true)
                ->where('acquirer_key', '!=', $currentAcquirerName)
                ->where(function ($query) use ($paymentMethod) {
                    $query->whereJsonContains('supported_methods', strtoupper($paymentMethod))
                          ->orWhereJsonContains('supported_methods', strtolower($paymentMethod));
                })
                ->orderBy('priority', 'asc')
                ->get();

            foreach ($configs as $config) {
                $instance = $this->getAcquirerInstanceByKey($config->acquirer_key);
                if ($instance) {
                    Log::info('RoutingService: fallback acquirer selected', [
                        'acquirer' => $config->acquirer_key,
                        'priority' => $config->priority,
                        'skipped' => $currentAcquirerName,
                    ]);
                    return $instance;
                }
            }
        } catch (\Exception $e) {
            // Fall through to legacy
        }

        // Legacy fallback
        $acquirers = Acquirer::enabled()
            ->healthy()
            ->supporting($paymentMethod)
            ->where('name', '!=', $currentAcquirerName)
            ->orderBy('priority', 'asc')
            ->get();

        if ($acquirers->isEmpty()) {
            return null;
        }

        return $this->getAcquirerInstance($acquirers->first()->type);
    }
}
