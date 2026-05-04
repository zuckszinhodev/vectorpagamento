<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Charge;
use App\Models\Transaction;
use App\Models\AccountWallet;
use App\Models\AcquirerConfig;
use App\Models\AccountCustomFee;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PaymentController extends Controller
{
    /**
     * Process payment for an existing charge
     */
    public function process(Request $request, string $chargeId): JsonResponse
    {
        try {
        $accountId = $request->user()->account_id;

        $charge = Charge::where('id', $chargeId)
            ->where('account_id', $accountId)
            ->first();

        if (!$charge) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'charge_not_found',
                    'message' => 'Cobrança não encontrada',
                ],
            ], 404);
        }

        if ($charge->status !== 'pending') {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'invalid_charge_status',
                    'message' => 'Cobrança não está disponível para pagamento',
                ],
            ], 422);
        }

        $request->validate([
            'payment_method' => ['sometimes', 'in:credit_card,debit_card,pix,boleto'],
            'card' => ['required_if:payment_method,credit_card,debit_card', 'array'],
            'card.number' => ['required_with:card', 'string'],
            'card.holder_name' => ['required_with:card', 'string'],
            'card.expiration_month' => ['required_with:card', 'string'],
            'card.expiration_year' => ['required_with:card', 'string'],
            'card.cvv' => ['required_with:card', 'string'],
            'installments' => ['nullable', 'integer', 'min:1', 'max:12'],
        ]);

        $paymentMethod = $request->input('payment_method', $charge->payment_method);
        $result = $this->processPaymentMethod($charge, $paymentMethod, $request);

        // Create transaction record
        $transaction = Transaction::create([
            'id' => Str::uuid()->toString(),
            'charge_id' => $chargeId,
            'type' => 'capture',
            'status' => $result['status'],
            'amount' => $charge->amount,
            'provider' => 'internal',
            'provider_transaction_id' => $result['transaction_id'],
            'metadata' => [
                'payment_method' => $paymentMethod,
                'installments' => $request->input('installments', 1),
            ],
        ]);

        // Update charge status
        $chargeUpdate = ['status' => $result['status']];
        if ($result['status'] === 'paid') {
            $chargeUpdate['paid_at'] = now();
            
            // Credit to account wallet
            $this->creditToWallet($charge);
        }
        $charge->update(array_merge($chargeUpdate, $result['payment_data'] ?? []));

        $statusCode = match ($result['status']) {
            'paid' => 200,
            'pending', 'processing' => 202,
            default => 200,
        };

        return response()->json([
            'success' => in_array($result['status'], ['paid', 'pending', 'processing', 'waiting_payment']),
            'data' => [
                'charge_id' => $chargeId,
                'transaction_id' => $transaction->id,
                'status' => $result['status'],
                'authorization_code' => $result['authorization_code'] ?? null,
                'payment_method' => $paymentMethod,
                'pix_qr_code' => $charge->pix_qr_code,
                'pix_copy_paste' => $charge->pix_copy_paste,
                'boleto_url' => $charge->boleto_url,
                'boleto_barcode' => $charge->boleto_barcode,
                'processed_at' => now()->toIso8601String(),
            ],
        ], $statusCode);
        } catch (\Throwable $e) {
            Log::error('PaymentController::process error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * Get payment methods available for account
     */
    public function methods(Request $request): JsonResponse
    {
        try {
        $methods = [
            [
                'id' => 'credit_card',
                'name' => 'Cartão de Crédito',
                'enabled' => true,
                'installments_enabled' => true,
                'max_installments' => 12,
            ],
            [
                'id' => 'debit_card',
                'name' => 'Cartão de Débito',
                'enabled' => true,
                'installments_enabled' => false,
            ],
            [
                'id' => 'pix',
                'name' => 'PIX',
                'enabled' => true,
                'instant' => true,
            ],
            [
                'id' => 'boleto',
                'name' => 'Boleto Bancário',
                'enabled' => true,
                'days_to_expire' => 3,
            ],
        ];

        return response()->json([
            'success' => true,
            'data' => $methods,
        ]);
        } catch (\Throwable $e) {
            Log::error('PaymentController::methods error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * Get installment options for credit card
     */
    public function installments(Request $request): JsonResponse
    {
        try {
        $request->validate([
            'amount' => ['required', 'integer', 'min:100'],
        ]);

        $amount = (int) $request->query('amount');
        $installments = [];
        $maxInstallments = (int) config('payment.max_installments', 12);
        $interestFreeLimit = (int) config('payment.interest_free_installments', 6);
        $interestRate = (float) config('payment.installment_interest_rate', 0.0199);

        for ($i = 1; $i <= $maxInstallments; $i++) {
            $installmentAmount = $amount / $i;
            $hasInterest = $i > $interestFreeLimit;
            
            if ($hasInterest) {
                $installmentAmount = $amount * pow(1 + $interestRate, $i) / $i;
            }

            $installments[] = [
                'number' => $i,
                'amount' => (int) round($installmentAmount),
                'total' => (int) round($installmentAmount * $i),
                'interest_free' => !$hasInterest,
                'interest_rate' => $hasInterest ? round($interestRate * 100, 2) : 0,
            ];
        }

        return response()->json([
            'success' => true,
            'data' => $installments,
        ]);
        } catch (\Throwable $e) {
            Log::error('PaymentController::installments error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * Capture a pre-authorized transaction
     */
    public function capture(Request $request, string $transactionId): JsonResponse
    {
        try {
        $accountId = $request->user()->account_id;

        $transaction = Transaction::where('id', $transactionId)
            ->whereHas('charge', function ($q) use ($accountId) {
                $q->where('account_id', $accountId);
            })
            ->first();

        if (!$transaction) {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'transaction_not_found',
                    'message' => 'Transação não encontrada',
                ],
            ], 404);
        }

        if ($transaction->type !== 'authorization' || $transaction->status !== 'approved') {
            return response()->json([
                'success' => false,
                'error' => [
                    'code' => 'invalid_transaction',
                    'message' => 'Esta transação não pode ser capturada',
                ],
            ], 400);
        }

        $request->validate([
            'amount' => ['nullable', 'integer', 'min:100', 'max:' . $transaction->amount],
        ]);

        $captureAmount = $request->input('amount', $transaction->amount);

        // Create capture transaction
        $captureTransaction = Transaction::create([
            'id' => Str::uuid()->toString(),
            'charge_id' => $transaction->charge_id,
            'type' => 'capture',
            'status' => 'approved',
            'amount' => $captureAmount,
            'provider' => $transaction->provider,
            'provider_transaction_id' => 'cap_' . Str::random(16),
        ]);

        // Update charge
        $transaction->charge->update([
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'transaction_id' => $captureTransaction->id,
                'charge_id' => $transaction->charge_id,
                'amount' => $captureAmount,
                'status' => 'captured',
                'captured_at' => now()->toIso8601String(),
            ],
        ]);
        } catch (\Throwable $e) {
            Log::error('PaymentController::capture error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * Process payment based on method.
     * 
     * NOTE: This controller handles external API key-authenticated payments.
     * Real payment processing (PIX, Card, Boleto) goes through PaymentService
     * which delegates to the configured acquirer (Babylon, PayFort, Stripe, etc).
     * This fallback method should NOT be reached in normal flow.
     */
    private function processPaymentMethod(Charge $charge, string $method, Request $request): array
    {
        $transactionId = 'txn_' . Str::random(16);

        // All real payment processing should go through PaymentService.
        // This is a safety fallback that rejects unhandled methods.
        Log::warning('PaymentController::processPaymentMethod called directly — should use PaymentService', [
            'charge_id' => $charge->id,
            'method' => $method,
        ]);

        return [
            'status' => 'failed',
            'transaction_id' => $transactionId,
            'payment_data' => [],
        ];
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

        // Get acquirer config to calculate fees (R$2.00 fixed + 5% per transaction)
        $acquirerConfig = AcquirerConfig::findByKey($charge->acquirer);

        $feeAmount = 0;
        $netAmount = $charge->amount;

        if ($acquirerConfig) {
            // Check for per-account custom gateway fee
            $customFee = AccountCustomFee::where('account_id', $charge->account_id)->first();
            $customGatewayFee = $customFee?->calculateGatewayFee($charge->amount, $charge->payment_method);

            if (!is_null($customGatewayFee)) {
                $acquirerFee = $acquirerConfig->calculateAcquirerFee($charge->amount);
                $feeAmount = $customGatewayFee + $acquirerFee;
            } else {
                $feeAmount = $acquirerConfig->calculateGatewayFee($charge->amount);
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
}
