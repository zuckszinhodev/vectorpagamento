<?php

declare(strict_types=1);

namespace Infrastructure\Acquirers\MercadoPago;

use Domain\Acquirer\Contracts\AcquirerGatewayInterface;
use Domain\Acquirer\ValueObjects\AcquirerResponse;
use Domain\Payment\Entities\Charge;
use Domain\Payment\ValueObjects\CardData;
use Domain\Shared\ValueObjects\Money;
use MercadoPago\Client\Common\RequestOptions;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\MercadoPagoConfig;

class MercadoPagoGateway implements AcquirerGatewayInterface
{
    private ?PaymentClient $client = null;
    private array $credentials = [];
    private bool $available = true;

    public function __construct()
    {
        if (config('acquirers.mercadopago.access_token')) {
            $this->credentials = [
                'access_token' => config('acquirers.mercadopago.access_token'),
                'public_key' => config('acquirers.mercadopago.public_key'),
                'webhook_secret' => config('acquirers.mercadopago.webhook_secret'),
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
        if (!empty($this->credentials['access_token'])) {
            MercadoPagoConfig::setAccessToken($this->credentials['access_token']);
            $this->client = new PaymentClient();
            $this->available = true;
        }
    }

    public function getIdentifier(): string
    {
        return 'mercadopago';
    }

    public function getName(): string
    {
        return 'Mercado Pago';
    }

    public function isAvailable(): bool
    {
        return $this->available && $this->client !== null;
    }

    public function getSupportedMethods(): array
    {
        return ['credit_card', 'debit_card', 'pix', 'boleto'];
    }

    public function processCard(
        Charge $charge,
        CardData $cardData,
        int $installments = 1
    ): AcquirerResponse {
        try {
            $customer = $charge->getCustomer();

            $paymentData = [
                'transaction_amount' => $charge->getAmount()->toFloat(),
                'token' => $this->tokenizeCard($cardData),
                'description' => 'Payment ' . $charge->getId()->toString(),
                'installments' => $installments,
                'payment_method_id' => $this->mapCardBrand($cardData->getBrand()),
                'payer' => [
                    'email' => $customer['email'],
                    'first_name' => explode(' ', $customer['name'])[0] ?? '',
                    'last_name' => explode(' ', $customer['name'])[1] ?? '',
                    'identification' => [
                        'type' => 'CPF',
                        'number' => $customer['document'] ?? '',
                    ],
                ],
                'metadata' => [
                    'charge_id' => $charge->getId()->toString(),
                ],
                'capture' => true,
            ];

            $requestOptions = new RequestOptions();
            $requestOptions->setCustomHeaders([
                'X-Idempotency-Key' => $charge->getId()->toString(),
            ]);

            $payment = $this->client->create($paymentData, $requestOptions);

            if ($payment->status === 'approved') {
                return AcquirerResponse::success(
                    transactionId: (string) $payment->id,
                    authorizationCode: $payment->authorization_code ?? (string) $payment->id,
                    nsu: (string) $payment->id,
                    status: 'approved',
                    data: [
                        'card_brand' => $payment->payment_method_id,
                        'card_last_four' => $payment->card->last_four_digits ?? null,
                    ],
                    rawResponse: $this->toArray($payment)
                );
            }

            if (in_array($payment->status, ['pending', 'in_process', 'authorized'])) {
                return AcquirerResponse::pending(
                    transactionId: (string) $payment->id,
                    status: 'processing',
                    rawResponse: $this->toArray($payment)
                );
            }

            return AcquirerResponse::failure(
                errorCode: $payment->status_detail ?? 'unknown',
                errorMessage: $this->getStatusDetailMessage($payment->status_detail ?? ''),
                status: 'declined',
                transactionId: (string) $payment->id,
                rawResponse: $this->toArray($payment)
            );

        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function generatePix(Charge $charge): AcquirerResponse
    {
        try {
            $customer = $charge->getCustomer();

            $paymentData = [
                'transaction_amount' => $charge->getAmount()->toFloat(),
                'payment_method_id' => 'pix',
                'description' => 'Payment ' . $charge->getId()->toString(),
                'payer' => [
                    'email' => $customer['email'],
                    'first_name' => explode(' ', $customer['name'])[0] ?? '',
                    'last_name' => explode(' ', $customer['name'])[1] ?? '',
                    'identification' => [
                        'type' => 'CPF',
                        'number' => $customer['document'] ?? '',
                    ],
                ],
                'metadata' => [
                    'charge_id' => $charge->getId()->toString(),
                ],
            ];

            $requestOptions = new RequestOptions();
            $requestOptions->setCustomHeaders([
                'X-Idempotency-Key' => $charge->getId()->toString() . '_pix',
            ]);

            $payment = $this->client->create($paymentData, $requestOptions);

            $pixData = $payment->point_of_interaction->transaction_data ?? null;

            return AcquirerResponse::pending(
                transactionId: (string) $payment->id,
                status: 'waiting_payment',
                data: [
                    'pix_qr_code' => $pixData?->qr_code_base64 ?? null,
                    'pix_copy_paste' => $pixData?->qr_code ?? null,
                    'expires_at' => $payment->date_of_expiration ?? null,
                ],
                rawResponse: $this->toArray($payment)
            );

        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function generateBoleto(Charge $charge): AcquirerResponse
    {
        try {
            $customer = $charge->getCustomer();
            $billing = $charge->getBilling() ?? [];

            $paymentData = [
                'transaction_amount' => $charge->getAmount()->toFloat(),
                'payment_method_id' => 'bolbradesco', // Bradesco Boleto
                'description' => 'Payment ' . $charge->getId()->toString(),
                'payer' => [
                    'email' => $customer['email'],
                    'first_name' => explode(' ', $customer['name'])[0] ?? '',
                    'last_name' => explode(' ', $customer['name'])[1] ?? '',
                    'identification' => [
                        'type' => 'CPF',
                        'number' => $customer['document'] ?? '',
                    ],
                    'address' => [
                        'zip_code' => $billing['zip_code'] ?? '',
                        'street_name' => $billing['street'] ?? '',
                        'street_number' => $billing['number'] ?? '',
                        'neighborhood' => $billing['neighborhood'] ?? '',
                        'city' => $billing['city'] ?? '',
                        'federal_unit' => $billing['state'] ?? '',
                    ],
                ],
                'metadata' => [
                    'charge_id' => $charge->getId()->toString(),
                ],
            ];

            $requestOptions = new RequestOptions();
            $requestOptions->setCustomHeaders([
                'X-Idempotency-Key' => $charge->getId()->toString() . '_boleto',
            ]);

            $payment = $this->client->create($paymentData, $requestOptions);

            $boletoData = $payment->transaction_details ?? null;

            return AcquirerResponse::pending(
                transactionId: (string) $payment->id,
                status: 'waiting_payment',
                data: [
                    'boleto_url' => $boletoData?->external_resource_url ?? null,
                    'boleto_barcode' => $payment->barcode->content ?? null,
                    'boleto_digitable_line' => $payment->barcode?->content ?? null,
                    'expires_at' => $payment->date_of_expiration ?? null,
                ],
                rawResponse: $this->toArray($payment)
            );

        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function refund(string $transactionId, Money $amount): AcquirerResponse
    {
        try {
            $refundClient = new \MercadoPago\Client\Payment\PaymentRefundClient();

            $refund = $refundClient->refund(
                (int) $transactionId,
                $amount->toFloat()
            );

            return AcquirerResponse::success(
                transactionId: (string) $refund->id,
                authorizationCode: (string) $refund->id,
                nsu: (string) $refund->id,
                status: 'approved',
                rawResponse: $this->toArray($refund)
            );

        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function capture(string $transactionId, Money $amount): AcquirerResponse
    {
        try {
            $payment = $this->client->capture(
                (int) $transactionId,
                $amount->toFloat()
            );

            return AcquirerResponse::success(
                transactionId: (string) $payment->id,
                authorizationCode: $payment->authorization_code ?? (string) $payment->id,
                nsu: (string) $payment->id,
                status: 'approved',
                rawResponse: $this->toArray($payment)
            );

        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function cancel(string $transactionId): AcquirerResponse
    {
        try {
            $payment = $this->client->cancel((int) $transactionId);

            return AcquirerResponse::success(
                transactionId: (string) $payment->id,
                authorizationCode: (string) $payment->id,
                nsu: (string) $payment->id,
                status: 'cancelled',
                rawResponse: $this->toArray($payment)
            );

        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function getStatus(string $transactionId): AcquirerResponse
    {
        try {
            $payment = $this->client->get((int) $transactionId);

            $status = match ($payment->status) {
                'approved' => 'approved',
                'pending', 'in_process', 'authorized' => 'pending',
                'cancelled', 'refunded', 'charged_back' => 'cancelled',
                'rejected' => 'declined',
                default => 'pending',
            };

            return AcquirerResponse::pending(
                transactionId: (string) $payment->id,
                status: $status,
                rawResponse: $this->toArray($payment)
            );

        } catch (\Exception $e) {
            return $this->handleException($e);
        }
    }

    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        // MercadoPago uses x-signature header with format: ts=xxx,v1=xxx
        $signatureParts = [];
        foreach (explode(',', $signature) as $part) {
            [$key, $value] = explode('=', $part, 2);
            $signatureParts[$key] = $value;
        }

        $timestamp = $signatureParts['ts'] ?? '';
        $receivedSignature = $signatureParts['v1'] ?? '';

        $manifest = "id:" . json_decode($payload, true)['data']['id'] ?? '';
        $manifest .= ";request-id:" . request()->header('x-request-id', '');
        $manifest .= ";ts:" . $timestamp . ";";

        $expectedSignature = hash_hmac('sha256', $manifest, $this->credentials['webhook_secret'] ?? '');

        return hash_equals($expectedSignature, $receivedSignature);
    }

    public function calculateFee(Money $amount, string $paymentMethod, int $installments = 1): Money
    {
        // MercadoPago fees (approximate)
        $baseRate = match ($paymentMethod) {
            'credit_card' => 0.0499, // 4.99%
            'debit_card' => 0.0199, // 1.99%
            'pix' => 0.0099, // 0.99%
            'boleto' => 0.0, // Fixed fee only
            default => 0.0499,
        };

        $fixedFee = match ($paymentMethod) {
            'boleto' => 395, // R$ 3.95
            default => 0,
        };

        // Installment fee increase
        if ($installments > 1 && in_array($paymentMethod, ['credit_card'])) {
            $baseRate += ($installments - 1) * 0.0149;
        }

        $percentageFee = (int) round($amount->getAmount() * $baseRate);
        $totalFee = $percentageFee + $fixedFee;

        return Money::fromCents($totalFee, $amount->getCurrency());
    }

    /**
     * Tokenize card for server-side processing
     * In production, this should be done client-side
     */
    private function tokenizeCard(CardData $cardData): string
    {
        // Note: In production, tokenization should be done client-side with MercadoPago.js
        // This is a simplified server-side approach for demonstration
        $cardTokenClient = new \MercadoPago\Client\CardToken\CardTokenClient();

        $cardToken = $cardTokenClient->create([
            'card_number' => $cardData->getNumber(),
            'expiration_month' => (int) $cardData->getExpirationMonth(),
            'expiration_year' => (int) $cardData->getExpirationYear(),
            'security_code' => $cardData->getCvv(),
            'cardholder' => [
                'name' => $cardData->getHolderName(),
            ],
        ]);

        return $cardToken->id;
    }

    private function mapCardBrand(string $brand): string
    {
        return match (strtolower($brand)) {
            'visa' => 'visa',
            'mastercard', 'master' => 'master',
            'amex', 'american_express' => 'amex',
            'elo' => 'elo',
            'hipercard' => 'hipercard',
            default => 'visa',
        };
    }

    private function getStatusDetailMessage(string $statusDetail): string
    {
        return match ($statusDetail) {
            'cc_rejected_bad_filled_card_number' => 'Número do cartão inválido',
            'cc_rejected_bad_filled_date' => 'Data de validade inválida',
            'cc_rejected_bad_filled_other' => 'Dados do cartão inválidos',
            'cc_rejected_bad_filled_security_code' => 'CVV inválido',
            'cc_rejected_blacklist' => 'Cartão não aceito',
            'cc_rejected_call_for_authorize' => 'Ligue para autorização',
            'cc_rejected_card_disabled' => 'Cartão desabilitado',
            'cc_rejected_duplicated_payment' => 'Pagamento duplicado',
            'cc_rejected_high_risk' => 'Transação rejeitada por segurança',
            'cc_rejected_insufficient_amount' => 'Saldo insuficiente',
            'cc_rejected_invalid_installments' => 'Parcelas inválidas',
            'cc_rejected_max_attempts' => 'Limite de tentativas excedido',
            default => 'Transação não autorizada',
        };
    }

    private function toArray(object $obj): array
    {
        return json_decode(json_encode($obj), true) ?? [];
    }

    private function handleException(\Exception $e): AcquirerResponse
    {
        return AcquirerResponse::failure(
            errorCode: 'mercadopago_error',
            errorMessage: $e->getMessage(),
            status: 'failed'
        );
    }
}
