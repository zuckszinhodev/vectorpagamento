<?php

namespace App\Infrastructure\Acquirers;

use App\Domain\Acquirer\Contracts\AcquirerInterface;
use App\Domain\Payment\Entities\Charge;
use Stripe\StripeClient;
use Stripe\Exception\ApiErrorException;

class StripeAcquirer implements AcquirerInterface
{
    private StripeClient $stripe;

    public function __construct(array $credentials)
    {
        $this->stripe = new StripeClient($credentials['secret_key'] ?? '');
    }

    /**
     * Process payment
     */
    public function processPayment(Charge $charge, array $paymentData): array
    {
        try {
            $paymentIntent = $this->stripe->paymentIntents->create([
                'amount' => $charge->amount * 100, // Stripe uses cents
                'currency' => strtolower($charge->currency ?? 'brl'),
                'description' => $charge->description,
                'metadata' => [
                    'charge_id' => $charge->id,
                    'account_id' => $charge->account_id,
                ],
                'payment_method_types' => ['card'],
                'payment_method' => $this->createPaymentMethod($paymentData),
                'confirm' => true,
                'return_url' => config('app.url') . '/payment/return',
            ]);

            return [
                'success' => $paymentIntent->status === 'succeeded',
                'external_id' => $paymentIntent->id,
                'status' => $this->mapStatus($paymentIntent->status),
                'data' => [
                    'payment_intent_id' => $paymentIntent->id,
                    'client_secret' => $paymentIntent->client_secret,
                ],
            ];
        } catch (ApiErrorException $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'error_code' => $e->getError()->code ?? 'unknown',
            ];
        }
    }

    /**
     * Refund payment
     */
    public function refund(Charge $charge, ?float $amount = null): array
    {
        try {
            $refund = $this->stripe->refunds->create([
                'payment_intent' => $charge->external_id,
                'amount' => $amount ? ($amount * 100) : null,
                'metadata' => [
                    'charge_id' => $charge->id,
                ],
            ]);

            return [
                'success' => true,
                'external_id' => $refund->id,
                'amount' => $refund->amount / 100,
                'status' => $refund->status,
            ];
        } catch (ApiErrorException $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Generate PIX QR Code
     */
    public function generatePixQrCode(Charge $charge): array
    {
        // Stripe doesn't support PIX directly
        // You would need to use a Brazilian payment provider
        return [
            'success' => false,
            'error' => 'PIX is not supported by Stripe',
        ];
    }

    /**
     * Generate Boleto
     */
    public function generateBoleto(Charge $charge): array
    {
        // Stripe doesn't support Boleto directly
        return [
            'success' => false,
            'error' => 'Boleto is not supported by Stripe',
        ];
    }

    /**
     * Check health status
     */
    public function checkHealth(): bool
    {
        try {
            $this->stripe->balance->retrieve();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Create payment method from card data
     */
    private function createPaymentMethod(array $paymentData): string
    {
        $card = $paymentData['card'] ?? [];

        $paymentMethod = $this->stripe->paymentMethods->create([
            'type' => 'card',
            'card' => [
                'number' => $card['number'],
                'exp_month' => $card['exp_month'],
                'exp_year' => $card['exp_year'],
                'cvc' => $card['cvv'],
            ],
            'billing_details' => [
                'name' => $card['holder_name'],
                'email' => $paymentData['customer_email'] ?? null,
            ],
        ]);

        return $paymentMethod->id;
    }

    /**
     * Map Stripe status to internal status
     */
    private function mapStatus(string $stripeStatus): string
    {
        return match($stripeStatus) {
            'succeeded' => 'paid',
            'processing' => 'processing',
            'requires_payment_method', 'requires_confirmation', 'requires_action' => 'pending',
            'canceled' => 'cancelled',
            'failed' => 'failed',
            default => 'pending',
        };
    }
}
