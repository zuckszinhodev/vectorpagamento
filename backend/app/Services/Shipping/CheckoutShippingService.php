<?php

declare(strict_types=1);

namespace App\Services\Shipping;

use App\Models\Checkout;
use Illuminate\Validation\ValidationException;

class CheckoutShippingService
{
    public function __construct(private readonly MelhorEnvioService $carrier) {}

    public function settings(Checkout $checkout): array
    {
        $shipping = (array) data_get($checkout->product?->metadata, 'shipping', []);
        $block = collect(data_get($checkout->settings, 'blocks', []))
            ->first(fn ($block) => ($block['type'] ?? '') === 'shipping' && ($block['enabled'] ?? false));
        $legacy = ($block['settings']['type'] ?? '') === 'physical' ? $block['settings'] : [];

        return [
            'required' => ($shipping['required'] ?? false) || $checkout->product?->type === 'physical' || $legacy !== [],
            'free' => (bool) ($shipping['free_shipping'] ?? false),
            'fixed' => max(0, (int) ($shipping['shipping_price'] ?? 0))
                ?: (empty($legacy['freeShipping']) ? max(0, (int) round(($legacy['shippingPrice'] ?? 0) * 100)) : 0),
            'package' => array_intersect_key($shipping, array_flip(['weight', 'width', 'height', 'length'])),
        ];
    }

    public function quote(Checkout $checkout, string $postalCode): array
    {
        $shipping = $this->settings($checkout);
        if (!$shipping['required'] || $shipping['free']) {
            return ['options' => [], 'reason' => $shipping['free'] ? 'free_shipping' : 'not_physical'];
        }
        if (!$this->carrier->isConfigured()) {
            return ['options' => [], 'reason' => 'not_configured'];
        }
        $options = $this->carrier->quote($postalCode, $shipping['package'], (int) $checkout->product->price);
        return ['options' => $options, 'reason' => $options === [] ? 'no_service' : null];
    }

    /** Recalculate from server data; never accept a price supplied by the buyer. */
    public function calculate(Checkout $checkout, array $address, mixed $serviceId = null): array
    {
        $shipping = $this->settings($checkout);
        if (!$shipping['required']) {
            return ['amount' => 0];
        }
        $postalCode = preg_replace('/\D/', '', (string) ($address['zipcode'] ?? $address['zip_code'] ?? ''));
        if (strlen($postalCode) !== 8) {
            throw ValidationException::withMessages(['address.zipcode' => 'Informe um CEP válido para a entrega.']);
        }
        $destination = ['postal_code' => $postalCode, 'address' => $address];
        if ($shipping['free']) {
            return $destination + ['amount' => 0];
        }

        $quote = $this->quote($checkout, $postalCode);
        if ($serviceId !== null) {
            $option = collect($quote['options'])->first(fn ($option) => (string) $option['id'] === (string) $serviceId);
            if (!$option) {
                throw ValidationException::withMessages(['shipping_service_id' => 'A opção de entrega expirou ou está indisponível. Calcule o frete novamente.']);
            }
            return $destination + ['amount' => $option['price'], 'service_id' => $option['id'], 'service' => $option];
        }
        if ($quote['options'] !== [] || ($this->carrier->isConfigured() && $shipping['fixed'] === 0)) {
            throw ValidationException::withMessages(['shipping_service_id' => 'Selecione uma opção de entrega disponível.']);
        }

        return $destination + ['amount' => $shipping['fixed']];
    }
}
