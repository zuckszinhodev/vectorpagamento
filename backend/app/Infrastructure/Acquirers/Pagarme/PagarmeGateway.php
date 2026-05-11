<?php

declare(strict_types=1);

namespace Infrastructure\Acquirers\Pagarme;

use Domain\Acquirer\Contracts\AcquirerGatewayInterface;
use Domain\Acquirer\ValueObjects\AcquirerResponse;
use Domain\Payment\Entities\Charge;
use Domain\Payment\ValueObjects\CardData;
use Domain\Shared\ValueObjects\Money;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class PagarmeGateway implements AcquirerGatewayInterface
{
    private ?Client $client = null;
    private array $credentials = [];
    private bool $available = true;
    private string $baseUrl = 'https://api.pagar.me/core/v5';

    public function __construct()
    {
        if (config('acquirers.pagarme.api_key')) {
            $this->credentials = [
                'api_key' => config('acquirers.pagarme.api_key'),
                'encryption_key' => config('acquirers.pagarme.encryption_key'),
                'webhook_secret' => config('acquirers.pagarme.webhook_secret'),
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
        if (!empty($this->credentials['api_key'])) {
            $this->client = new Client([
                'base_uri' => $this->baseUrl,
                'headers' => [
                    'Authorization' => 'Basic ' . base64_encode($this->credentials['api_key'] . ':'),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
            ]);
            $this->available = true;
        }
    }

    public function getIdentifier(): string
    {
        return 'pagarme';
    }

    public function getName(): string
    {
        return 'Pagar.me';
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

            $payload = [
                'items' => [
                    [
                        'amount' => $charge->getAmount()->getAmount(),
                        'description' => 'Payment ' . $charge->getId()->toString(),
                        'quantity' => 1,
                    ],
                ],
                'customer' => [
                    'name' => $customer['name'],
                    'email' => $customer['email'],
                    'type' => 'individual',
                    'document' => $customer['document'] ?? '',
                    'phones' => [
                        'mobile_phone' => [
                            'country_code' => '55',
                            'area_code' => substr($customer['phone'] ?? '11999999999', 0, 2),
                            'number' => substr($customer['phone'] ?? '11999999999', 2),
                        ],
                    ],
                ],
                'payments' => [
                    [
                        'payment_method' => 'credit_card',
                        'credit_card' => [
                            'installments' => $installments,
                            'statement_descriptor' => 'VECTOR',
                            'card' => [
                                'number' => $cardData->getNumber(),
                                'holder_name' => $cardData->getHolderName(),
                                'exp_month' => (int) $cardData->getExpirationMonth(),
                                'exp_year' => (int) $cardData->getExpirationYear(),
                                'cvv' => $cardData->getCvv(),
                            ],
                        ],
                    ],
                ],
                'metadata' => [
                    'charge_id' => $charge->getId()->toString(),
                ],
            ];

            $response = $this->client->post('/orders', [
                'json' => $payload,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            if ($data['status'] === 'paid') {
                $transaction = $data['charges'][0] ?? [];

                return AcquirerResponse::success(
                    transactionId: $data['id'],
                    authorizationCode: $transaction['last_transaction']['acquirer_auth_code'] ?? $data['id'],
                    nsu: $transaction['last_transaction']['acquirer_nsu'] ?? $data['id'],
                    status: 'approved',
                    data: [
                        'card_brand' => $transaction['last_transaction']['card']['brand'] ?? null,
                        'card_last_four' => $transaction['last_transaction']['card']['last_four_digits'] ?? null,
                    ],
                    rawResponse: $data
                );
            }

            if ($data['status'] === 'pending') {
                return AcquirerResponse::pending(
                    transactionId: $data['id'],
                    status: 'processing',
                    rawResponse: $data
                );
            }

            return AcquirerResponse::failure(
                errorCode: $data['status'] ?? 'unknown',
                errorMessage: $data['charges'][0]['last_transaction']['gateway_response']['message'] ?? 'Transaction declined',
                status: 'declined',
                transactionId: $data['id'],
                rawResponse: $data
            );

        } catch (GuzzleException $e) {
            return $this->handleException($e);
        }
    }

    public function generatePix(Charge $charge): AcquirerResponse
    {
        try {
            $customer = $charge->getCustomer();

            $payload = [
                'items' => [
                    [
                        'amount' => $charge->getAmount()->getAmount(),
                        'description' => 'Payment ' . $charge->getId()->toString(),
                        'quantity' => 1,
                    ],
                ],
                'customer' => [
                    'name' => $customer['name'],
                    'email' => $customer['email'],
                    'type' => 'individual',
                    'document' => $customer['document'] ?? '',
                ],
                'payments' => [
                    [
                        'payment_method' => 'pix',
                        'pix' => [
                            'expires_in' => 3600, // 1 hour
                        ],
                    ],
                ],
                'metadata' => [
                    'charge_id' => $charge->getId()->toString(),
                ],
            ];

            $response = $this->client->post('/orders', [
                'json' => $payload,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            $pixCharge = $data['charges'][0] ?? [];
            $pixTransaction = $pixCharge['last_transaction'] ?? [];

            return AcquirerResponse::pending(
                transactionId: $data['id'],
                status: 'waiting_payment',
                data: [
                    'pix_qr_code' => $pixTransaction['qr_code_url'] ?? null,
                    'pix_copy_paste' => $pixTransaction['qr_code'] ?? null,
                    'expires_at' => $pixTransaction['expires_at'] ?? null,
                ],
                rawResponse: $data
            );

        } catch (GuzzleException $e) {
            return $this->handleException($e);
        }
    }

    public function generateBoleto(Charge $charge): AcquirerResponse
    {
        try {
            $customer = $charge->getCustomer();
            $billing = $charge->getBilling() ?? [];

            $payload = [
                'items' => [
                    [
                        'amount' => $charge->getAmount()->getAmount(),
                        'description' => 'Payment ' . $charge->getId()->toString(),
                        'quantity' => 1,
                    ],
                ],
                'customer' => [
                    'name' => $customer['name'],
                    'email' => $customer['email'],
                    'type' => 'individual',
                    'document' => $customer['document'] ?? '',
                    'address' => [
                        'line_1' => ($billing['street'] ?? '') . ', ' . ($billing['number'] ?? ''),
                        'line_2' => $billing['complement'] ?? '',
                        'zip_code' => $billing['zip_code'] ?? '',
                        'city' => $billing['city'] ?? '',
                        'state' => $billing['state'] ?? '',
                        'country' => 'BR',
                    ],
                ],
                'payments' => [
                    [
                        'payment_method' => 'boleto',
                        'boleto' => [
                            'instructions' => 'Pagar até o vencimento',
                            'due_at' => now()->addDays(3)->toIso8601String(),
                        ],
                    ],
                ],
                'metadata' => [
                    'charge_id' => $charge->getId()->toString(),
                ],
            ];

            $response = $this->client->post('/orders', [
                'json' => $payload,
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            $boletoCharge = $data['charges'][0] ?? [];
            $boletoTransaction = $boletoCharge['last_transaction'] ?? [];

            return AcquirerResponse::pending(
                transactionId: $data['id'],
                status: 'waiting_payment',
                data: [
                    'boleto_url' => $boletoTransaction['pdf'] ?? null,
                    'boleto_barcode' => $boletoTransaction['barcode'] ?? null,
                    'boleto_digitable_line' => $boletoTransaction['line'] ?? null,
                    'expires_at' => $boletoTransaction['due_at'] ?? null,
                ],
                rawResponse: $data
            );

        } catch (GuzzleException $e) {
            return $this->handleException($e);
        }
    }

    public function refund(string $transactionId, Money $amount): AcquirerResponse
    {
        try {
            $response = $this->client->post("/charges/{$transactionId}/cancel", [
                'json' => [
                    'amount' => $amount->getAmount(),
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return AcquirerResponse::success(
                transactionId: $data['id'],
                authorizationCode: $data['id'],
                nsu: $data['id'],
                status: 'approved',
                rawResponse: $data
            );

        } catch (GuzzleException $e) {
            return $this->handleException($e);
        }
    }

    public function capture(string $transactionId, Money $amount): AcquirerResponse
    {
        try {
            $response = $this->client->post("/charges/{$transactionId}/capture", [
                'json' => [
                    'amount' => $amount->getAmount(),
                ],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return AcquirerResponse::success(
                transactionId: $data['id'],
                authorizationCode: $data['id'],
                nsu: $data['id'],
                status: 'approved',
                rawResponse: $data
            );

        } catch (GuzzleException $e) {
            return $this->handleException($e);
        }
    }

    public function cancel(string $transactionId): AcquirerResponse
    {
        try {
            $response = $this->client->delete("/orders/{$transactionId}");

            $data = json_decode($response->getBody()->getContents(), true);

            return AcquirerResponse::success(
                transactionId: $data['id'],
                authorizationCode: $data['id'],
                nsu: $data['id'],
                status: 'cancelled',
                rawResponse: $data
            );

        } catch (GuzzleException $e) {
            return $this->handleException($e);
        }
    }

    public function getStatus(string $transactionId): AcquirerResponse
    {
        try {
            $response = $this->client->get("/orders/{$transactionId}");

            $data = json_decode($response->getBody()->getContents(), true);

            $status = match ($data['status']) {
                'paid' => 'approved',
                'pending' => 'pending',
                'canceled' => 'cancelled',
                'failed' => 'failed',
                default => 'pending',
            };

            return AcquirerResponse::pending(
                transactionId: $data['id'],
                status: $status,
                rawResponse: $data
            );

        } catch (GuzzleException $e) {
            return $this->handleException($e);
        }
    }

    public function verifyWebhookSignature(string $payload, string $signature): bool
    {
        $expectedSignature = hash_hmac('sha256', $payload, $this->credentials['webhook_secret'] ?? '');
        return hash_equals($expectedSignature, $signature);
    }

    public function calculateFee(Money $amount, string $paymentMethod, int $installments = 1): Money
    {
        // Pagar.me fees (approximate)
        $baseRate = match ($paymentMethod) {
            'credit_card' => 0.0399, // 3.99%
            'debit_card' => 0.0249, // 2.49%
            'pix' => 0.0099, // 0.99%
            'boleto' => 0.0, // Fixed fee only
            default => 0.0399,
        };

        $fixedFee = match ($paymentMethod) {
            'boleto' => 350, // R$ 3.50
            default => 0,
        };

        // Installment fee increase
        if ($installments > 1 && in_array($paymentMethod, ['credit_card'])) {
            $baseRate += ($installments - 1) * 0.0099;
        }

        $percentageFee = (int) round($amount->getAmount() * $baseRate);
        $totalFee = $percentageFee + $fixedFee;

        return Money::fromCents($totalFee, $amount->getCurrency());
    }

    private function handleException(GuzzleException $e): AcquirerResponse
    {
        $response = $e->getResponse();
        $body = $response ? json_decode($response->getBody()->getContents(), true) : [];

        return AcquirerResponse::failure(
            errorCode: $body['errors'][0]['type'] ?? 'pagarme_error',
            errorMessage: $body['message'] ?? $e->getMessage(),
            status: 'failed',
            rawResponse: $body
        );
    }
}
