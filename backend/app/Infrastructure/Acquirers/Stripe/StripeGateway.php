<?php

declare(strict_types=1);

namespace Infrastructure\Acquirers\Stripe;

use Domain\Acquirer\Contracts\AcquirerGatewayInterface;
use Domain\Acquirer\ValueObjects\AcquirerResponse;
use Domain\Payment\Entities\Charge;
use Domain\Payment\ValueObjects\CardData;
use Domain\Shared\ValueObjects\Money;
use Stripe\StripeClient;
use Stripe\Exception\ApiErrorException;

class StripeGateway implements AcquirerGatewayInterface
{
    private ?StripeClient $client = null;
    private array $credentials = [];
    private bool $available = true;

    public function __construct()
    {
        // Default credentials from config
        if (config('acquirers.stripe.secret_key')) {
            $this->credentials = [
                'secret_key' => config('acquirers.stripe.secret_key'),
                'public_key' => config('acquirers.stripe.public_key'),
                'webhook_secret' => config('acquirers.stripe.webhook_secret'),
            ];
            $this->initClient();
        }
    }

    public function setCredentials(array $credentials): void
    {
        $this->credentials = $credentials;
        $this->initClient();
    }

    private function initClient(): void
    {
        if (!empty($this->credentials['secret_key'])) {
            $this->client = new StripeClient($this->credentials['secret_key']);
            $this->available = true;
        }
    }

    public function getIdentifier(): string
    {
        return 'stripe';
    }

    public function getName(): string
    {
        return 'Stripe';
    }

    public function isAvailable(): bool
    {
        return $this->available && $this->client !== null;
    }

    public function getSupportedMethods(): array
    {
        return ['credit_card', 'debit_card', 'pix'];
    }

    public function processCard(
        Charge $charge,
        CardData $cardData,
        int $installments = 1
    ): AcquirerResponse {
        try {
            // Create payment method
            $paymentMethod = $this->client->paymentMethods->create([
                'type' => 'card',
                'card' => [
                    'number' => $cardData->getNumber(),
                    'exp_month' => (int) $cardData->getExpirationMonth(),
                    'exp_year' => (int) $cardData->getExpirationYear(),
                    'cvc' => $cardData->getCvv(),
                ],
                'billing_details' => [
                    'name' => $cardData->getHolderName(),
                    'email' => $charge->getCustomer()['email'] ?? null,
                ],
            ]);

            // Create and confirm payment intent
            $paymentIntent = $this->client->paymentIntents->create([
                'amount' => $charge->getAmount()->getAmount(),
                'currency' => strtolower($charge->getAmount()->getCurrency()),
                'payment_method' => $paymentMethod->id,
                'confirm' => true,
                'automatic_payment_methods' => [
                    'enabled' => true,
                    'allow_redirects' => 'never',
                ],
                'metadata' => [
                    'charge_id' => $charge->getId()->toString(),
                    'external_id' => $charge->getExternalId(),
                    'installments' => $installments,
                ],
            ]);

            if ($paymentIntent->status === 'succeeded') {
                return AcquirerResponse::success(
                    transactionId: $paymentIntent->id,
                    authorizationCode: $paymentIntent->latest_charge ?? $paymentIntent->id,
                    nsu: $paymentIntent->id,
                    status: 'approved',
                    data: [
                        'payment_method_id' => $paymentMethod->id,
                        'card_brand' => $cardData->getBrand(),
                        'card_last_four' => $cardData->getLastFourDigits(),
                    ],
                    rawResponse: $paymentIntent->toArray()
                );
            }

            if ($paymentIntent->status === 'requires_action') {
                return AcquirerResponse::pending(
                    transactionId: $paymentIntent->id,
                    status: 'pending_authentication',
                    data: [
                        'client_secret' => $paymentIntent->client_secret,
                        'requires_action' => true,
                    ],
                    rawResponse: $paymentIntent->toArray()
                );
            }

            return AcquirerResponse::failure(
                errorCode: $paymentIntent->status,
                errorMessage: 'Payment not completed',
                status: 'declined',
                transactionId: $paymentIntent->id,
                rawResponse: $paymentIntent->toArray()
            );

        } catch (ApiErrorException $e) {
            return AcquirerResponse::failure(
                errorCode: $e->getStripeCode() ?? 'stripe_error',
                errorMessage: $e->getMessage(),
                status: 'failed',
                rawResponse: ['error' => $e->getError()?->toArray()]
            );
        } catch (\Exception $e) {
            return AcquirerResponse::error(
                errorCode: 'gateway_error',
                errorMessage: $e->getMessage()
            );
        }
    }

    public function generatePix(Charge $charge): AcquirerResponse
    {
        try {
            $paymentIntent = $this->client->paymentIntents->create([
                'amount' => $charge->getAmount()->getAmount(),
                'currency' => 'brl',
                'payment_method_types' => ['pix'],
                'metadata' => [
                    'charge_id' => $charge->getId()->toString(),
                ],
            ]);

            // Confirm to generate PIX
            $paymentIntent = $this->client->paymentIntents->confirm($paymentIntent->id, [
                'payment_method_data' => [
                    'type' => 'pix',
                ],
            ]);

            $pixAction = $paymentIntent->next_action?->pix_display_qr_code ?? null;

            return AcquirerResponse::pending(
                transactionId: $paymentIntent->id,
                status: 'waiting_payment',
                data: [
                    'pix_qr_code' => $pixAction?->image_url_png ?? null,
                    'pix_copy_paste' => $pixAction?->data ?? null,
                    'expires_at' => $pixAction?->expires_at ?? null,
                ],
                rawResponse: $paymentIntent->toArray()
            );

        } catch (ApiErrorException $e) {
            return AcquirerResponse::failure(
                errorCode: $e->getStripeCode() ?? 'stripe_error',
                errorMessage: $e->getMessage(),
                status: 'failed'
            );
        }
    }

    public function generateBoleto(Charge $charge): AcquirerResponse
    {
        try {
            $customer = $charge->getCustomer();

            $paymentIntent = $this->client->paymentIntents->create([
                'amount' => $charge->getAmount()->getAmount(),
                'currency' => 'brl',
                'payment_method_types' => ['boleto'],
                'payment_method_data' => [
                    'type' => 'boleto',
                    'boleto' => [
                        'tax_id' => $customer['document'] ?? '',
                    ],
                    'billing_details' => [
                        'name' => $customer['name'],
                        'email' => $customer['email'],
                        'address' => [
                            'line1' => $charge->getBilling()['street'] ?? '',
                            'city' => $charge->getBilling()['city'] ?? '',
                            'state' => $charge->getBilling()['state'] ?? '',
                            'postal_code' => $charge->getBilling()['zip_code'] ?? '',
                            'country' => 'BR',
                        ],
                    ],
                ],
                'confirm' => true,
                'metadata' => [
                    'charge_id' => $charge->getId()->toString(),
                ],
            ]);

            $boletoAction = $paymentIntent->next_action?->boleto_display_details ?? null;

            return AcquirerResponse::pending(
                transactionId: $paymentIntent->id,
                status: 'waiting_payment',
                data: [
                    'boleto_url' => $boletoAction?->hosted_voucher_url ?? null,
                    'boleto_barcode' => $boletoAction?->number ?? null,
                    'boleto_digitable_line' => $boletoAction?->number ?? null,
                    'expires_at' => $boletoAction?->expires_at ?? null,
                ],
                rawResponse: $paymentIntent->toArray()
            );

        } catch (ApiErrorException $e) {
            return AcquirerResponse::failure(
                errorCode: $e->getStripeCode() ?? 'stripe_error',
                errorMessage: $e->getMessage(),
                status: 'failed'
            );
        }
    }

    public function refund(string $transactionId, Money $amount): AcquirerResponse
    {
        try {
            $refund = $this->client->refunds->create([
                'payment_intent' => $transactionId,
                'amount' => $amount->getAmount(),
            ]);

            return AcquirerResponse::success(
                transactionId: $refund->id,
                authorizationCode: $refund->id,
                nsu: $refund->id,
                status: $refund->status === 'succeeded' ? 'approved' : 'pending',
                rawResponse: $refund->toArray()
            );

        } catch (ApiErrorException $e) {
            return AcquirerResponse::failure(
                errorCode: $e->getStripeCode() ?? 'refund_error',
                errorMessage: $e->getMessage(),
                status: 'failed'
            );
        }
    }

    public function capture(string $transactionId, Money $amount): AcquirerResponse
    {
        try {
            $paymentIntent = $this->client->paymentIntents->capture($transactionId, [
                'amount_to_capture' => $amount->getAmount(),
            ]);

            return AcquirerResponse::success(
                transactionId: $paymentIntent->id,
                authorizationCode: $paymentIntent->latest_charge ?? $paymentIntent->id,
                nsu: $paymentIntent->id,
                status: 'approved',
                rawResponse: $paymentIntent->toArray()
            );

        } catch (ApiErrorException $e) {
            return AcquirerResponse::failure(
                errorCode: $e->getStripeCode() ?? 'capture_error',
                errorMessage: $e->getMessage(),
                status: 'failed'
            );
        }
    }

    public function cancel(string $transactionId): AcquirerResponse
    {
        try {
            $paymentIntent = $this->client->paymentIntents->cancel($transactionId);

            return AcquirerResponse::success(
                transactionId: $paymentIntent->id,
                authorizationCode: $paymentIntent->id,
                nsu: $paymentIntent->id,
                status: 'cancelled',
                rawResponse: $paymentIntent->toArray()
            );

        } catch (ApiErrorException $e) {
            return AcquirerResponse::failure(
                errorCode: $e->getStripeCode() ?? 'cancel_error',
                errorMessage: $e->getMessage(),
                status: 'failed'
            );
        }
    }

    public function getStatus(string $transactionId): AcquirerResponse
    {
        try {
            $paymentIntent = $this->client->paymentIntents->retrieve($transactionId);

            $status = match ($paymentIntent->status) {
                'succeeded' => 'approved',
                'processing' => 'processing',
                'requires_payment_method', 'requires_confirmation', 'requires_action' => 'pending',
                'canceled' => 'cancelled',
                default => 'pending',
            };

            return AcquirerResponse::pending(
                transactionId: $paymentIntent->id,
                status: $status,
                rawResponse: $paymentIntent->toArray()
            );

        } catch (ApiErrorException $e) {
            return AcquirerResponse::error(
                errorCode: $e->getStripeCode() ?? 'status_error',
                errorMessage: $e->getMessage()
            );
        }
    }

    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        try {
            \Stripe\Webhook::constructEvent(
                $payload,
                $signature,
                $this->credentials['webhook_secret'] ?? ''
            );
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function calculateFee(Money $amount, string $paymentMethod, int $installments = 1): Money
    {
        // Stripe Brazil fees (approximate)
        $baseRate = match ($paymentMethod) {
            'credit_card', 'debit_card' => 0.0399, // 3.99%
            'pix' => 0.0099, // 0.99%
            'boleto' => 0.0, // Fixed fee only
            default => 0.0399,
        };

        $fixedFee = match ($paymentMethod) {
            'credit_card', 'debit_card' => 39, // R$ 0.39
            'pix' => 0,
            'boleto' => 350, // R$ 3.50
            default => 39,
        };

        // Installment fee increase
        if ($installments > 1 && in_array($paymentMethod, ['credit_card'])) {
            $baseRate += ($installments - 1) * 0.01; // +1% per installment
        }

        $percentageFee = (int) round($amount->getAmount() * $baseRate);
        $totalFee = $percentageFee + $fixedFee;

        return Money::fromCents($totalFee, $amount->getCurrency());
    }
}
