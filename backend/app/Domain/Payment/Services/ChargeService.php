<?php

namespace App\Domain\Payment\Services;

use App\Models\Charge;
use App\Models\Transaction;
use App\Domain\Payment\Events\ChargePaid;
use App\Domain\Payment\Events\ChargeFailed;
use App\Domain\Acquirer\Services\RoutingService;
use App\Domain\Product\Entities\Product;
use App\Domain\Fraud\Services\FraudDetectionService;
use App\Models\AccountWallet;
use App\Models\AcquirerConfig;
use App\Models\AccountCustomFee;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

class ChargeService
{
    public function __construct(
        private RoutingService $routingService,
        private FraudDetectionService $fraudService
    ) {}

    public function list(string $accountId, array $filters)
    {
        $query = Charge::where('account_id', $accountId);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (!empty($filters['payment_method'])) {
            $query->where('payment_method', $filters['payment_method']);
        }

        if (!empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (!empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        if (!empty($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('id', 'like', "%{$filters['search']}%")
                  ->orWhereJsonContains('customer->email', $filters['search'])
                  ->orWhereJsonContains('customer->name', $filters['search']);
            });
        }

        return $query->with(['product', 'transactions'])
                     ->orderBy('created_at', 'desc')
                     ->paginate($filters['per_page'] ?? 20);
    }

    public function create(string $accountId, array $data): Charge
    {
        return DB::transaction(function () use ($accountId, $data) {
            // Validate product and stock
            if (!empty($data['product_id'])) {
                $product = Product::findOrFail($data['product_id']);
                
                if (!$product->hasStock()) {
                    throw new \Exception('Produto sem estoque disponível');
                }
            }

            // Create charge
            $charge = Charge::create([
                'account_id' => $accountId,
                'product_id' => $data['product_id'] ?? null,
                'checkout_id' => $data['checkout_id'] ?? null,
                'amount' => $data['amount'],
                'currency' => 'BRL',
                'status' => 'pending',
                'payment_method' => $data['payment_method'],
                'installments' => $data['installments'] ?? 1,
                'customer' => $data['customer'],
                'billing_address' => $data['billing_address'] ?? null,
                'metadata' => $data['metadata'] ?? [],
                'expires_at' => $data['expires_at'] ?? now()->addHours(24),
            ]);

            // Fraud detection
            if (isset($data['device_fingerprint'])) {
                $fraudAnalysis = $this->fraudService->analyzeTransaction(
                    $charge->id,
                    $accountId,
                    array_merge($data, [
                        'device_fingerprint' => $data['device_fingerprint'],
                        'ip_address' => request()->ip(),
                    ])
                );

                if ($fraudAnalysis['should_block']) {
                    $charge->update(['status' => 'blocked']);
                    throw new \Exception('Transação bloqueada por suspeita de fraude');
                }
            }

            // Process payment based on method
            if ($data['payment_method'] === 'pix') {
                return $this->generatePix($charge);
            }

            if ($data['payment_method'] === 'boleto') {
                return $this->generateBoleto($charge);
            }

            if (in_array($data['payment_method'], ['credit_card', 'debit_card'])) {
                if (empty($data['card'])) {
                    throw new \Exception('Dados do cartão são obrigatórios');
                }
                
                return $this->processCardPayment($charge, $data['card']);
            }

            return $charge;
        });
    }

    private function processCardPayment(Charge $charge, array $cardData): Charge
    {
        $acquirer = $this->routingService->selectAcquirer($charge->payment_method, $charge);

        try {
            $result = $acquirer->processPayment($charge, $cardData);

            // Create transaction record
            Transaction::create([
                'charge_id' => $charge->id,
                'type' => 'payment',
                'amount' => $charge->amount,
                'status' => $result['status'] === 'paid' ? 'success' : 'failed',
                'acquirer_name' => $result['acquirer_name'],
                'acquirer_transaction_id' => $result['transaction_id'],
                'authorization_code' => $result['authorization_code'] ?? null,
                'nsu' => $result['nsu'] ?? null,
                'response_code' => $result['response_code'] ?? null,
                'response_message' => $result['response_message'] ?? null,
            ]);

            $charge->update([
                'status' => $result['status'],
                'acquirer' => $this->getAcquirerName($acquirer),
                'acquirer_name' => $result['acquirer_name'],
                'transaction_id' => $result['transaction_id'],
                'card_brand' => $result['card_brand'] ?? null,
                'card_last_digits' => $result['card_last_digits'] ?? null,
                'paid_at' => $result['status'] === 'paid' ? now() : null,
            ]);

            if ($result['status'] === 'paid') {
                // Decrease product stock
                if ($charge->product_id) {
                    $charge->product->decrementStock();
                }

                // Credit to account wallet
                $this->creditToWallet($charge);

                Event::dispatch(new ChargePaid($charge));
            } else {
                Event::dispatch(new ChargeFailed($charge, $result['response_message'] ?? 'Falha no pagamento'));
            }

            return $charge->fresh();
        } catch (\Exception $e) {
            $charge->update(['status' => 'failed']);
            
            Event::dispatch(new ChargeFailed($charge, $e->getMessage()));
            
            throw $e;
        }
    }

    private function generatePix(Charge $charge): Charge
    {
        $acquirer = $this->routingService->selectAcquirer('pix', $charge);
        $pixData = $acquirer->generatePixQrCode($charge);

        $charge->update([
            'status' => 'waiting_payment',
            'acquirer' => $this->getAcquirerName($acquirer),
            'pix_qr_code' => $pixData['qr_code'],
            'pix_code' => $pixData['qr_code_text'],
        ]);

        return $charge;
    }

    private function generateBoleto(Charge $charge): Charge
    {
        $acquirer = $this->routingService->selectAcquirer('boleto', $charge);
        $boletoData = $acquirer->generateBoleto($charge);

        $charge->update([
            'status' => 'waiting_payment',
            'acquirer' => $this->getAcquirerName($acquirer),
            'boleto_url' => $boletoData['url'],
            'boleto_barcode' => $boletoData['barcode'],
        ]);

        return $charge;
    }

    public function find(string $accountId, string $chargeId): ?Charge
    {
        return Charge::where('account_id', $accountId)
                     ->where('id', $chargeId)
                     ->with(['product', 'transactions', 'refunds'])
                     ->first();
    }

    public function cancel(string $accountId, string $chargeId): Charge
    {
        $charge = $this->find($accountId, $chargeId);

        if (!$charge) {
            throw new \Exception('Cobrança não encontrada');
        }

        if (!$charge->canBeCancelled()) {
            throw new \Exception('Apenas cobranças pendentes podem ser canceladas');
        }

        $charge->update(['status' => 'cancelled']);

        return $charge;
    }

    public function refund(string $accountId, string $chargeId, float $amount, ?string $reason): \App\Domain\Payment\Entities\Refund
    {
        $charge = $this->find($accountId, $chargeId);

        if (!$charge || !$charge->canBeRefunded()) {
            throw new \Exception('Cobrança não encontrada ou não pode ser reembolsada');
        }

        if ($amount > $charge->getAvailableRefundAmount()) {
            throw new \Exception('Valor de reembolso excede o valor disponível');
        }

        return DB::transaction(function () use ($charge, $amount, $reason) {
            $acquirer = $this->routingService->getAcquirerByName($charge->acquirer_name);
            $refundResult = $acquirer->refund($charge, $amount);

            $refund = \App\Domain\Payment\Entities\Refund::create([
                'charge_id' => $charge->id,
                'amount' => $amount,
                'reason' => $reason,
                'status' => 'completed',
                'acquirer_refund_id' => $refundResult['refund_id'] ?? null,
                'processed_at' => now(),
            ]);

            // Update charge status if fully refunded
            if ($charge->getAvailableRefundAmount() <= 0) {
                $charge->update(['status' => 'refunded']);
            }

            return $refund;
        });
    }

    /**
     * Credit amount to account wallet after payment
     */
    private function creditToWallet(Charge $charge): void
    {
        $wallet = AccountWallet::firstOrCreate(
            ['account_id' => $charge->account_id],
            [
                'currency' => 'BRL',
                'available_balance' => 0,
                'pending_balance' => 0,
                'blocked_balance' => 0,
            ]
        );

        // Get acquirer config to calculate fees
        $acquirerConfig = AcquirerConfig::findByKey($charge->acquirer);
        
        $feeAmount = 0;
        $netAmount = $charge->amount;

        if ($acquirerConfig) {
            // Check for per-account custom gateway fee
            $customFee = AccountCustomFee::where('account_id', $charge->account_id)->first();
            $customGatewayFee = $customFee?->calculateGatewayFee($charge->amount, $charge->payment_method);

            if (!is_null($customGatewayFee)) {
                // Custom gateway fee + acquirer fee
                $acquirerFee = $acquirerConfig->calculateAcquirerFee($charge->amount);
                $feeAmount = $customGatewayFee + $acquirerFee;
            } else {
                // Default: total fee from acquirer config
                $feeAmount = $acquirerConfig->calculateTotalFee($charge->amount);
            }

            $netAmount = $charge->amount - $feeAmount;
        }

        // Update charge with fee information
        $charge->update([
            'fee_amount' => $feeAmount,
            'net_amount' => $netAmount,
        ]);

        // Credit net amount to wallet
        $wallet->increment('available_balance', $netAmount);
    }

    /**
     * Get acquirer name from acquirer instance
     */
    private function getAcquirerName($acquirer): string
    {
        $className = get_class($acquirer);
        
        // Extract acquirer name from class name
        // e.g., App\Infrastructure\Acquirers\PayFortAcquirer -> payfort
        if (preg_match('/\\\\([A-Za-z]+)Acquirer$/', $className, $matches)) {
            return strtolower($matches[1]);
        }
        
        return 'unknown';
    }
}
