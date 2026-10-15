<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Charge;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Services\PaymentService;
use Illuminate\Support\Facades\Cache;

class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $paymentService) {}

    /**
     * Process payment for an existing charge
     */
    public function process(Request $request, string $chargeId): JsonResponse
    {
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

        $paymentMethod = $request->input('payment_method', $charge->payment_method);
        $request->merge(['payment_method' => $paymentMethod]);
        $request->validate([
            'payment_method' => ['required', 'in:credit_card,pix,boleto'],
            'card' => ['required_if:payment_method,credit_card', 'array'],
            'card.number' => ['required_with:card', 'string'],
            'card.holder_name' => ['required_with:card', 'string'],
            'card.expiration_month' => ['required_with:card', 'string'],
            'card.expiration_year' => ['required_with:card', 'string'],
            'card.cvv' => ['required_with:card', 'string'],
            'installments' => ['nullable', 'integer', 'min:1', 'max:12'],
        ]);

        $lock = Cache::lock('payment:process:' . $chargeId, 180);
        if (!$lock->get()) {
            return response()->json(['success' => false, 'error' => [
                'code' => 'payment_in_progress', 'message' => 'Pagamento já está em processamento.',
            ]], 409);
        }

        try {
            // Claim this charge in the database as well as in the cache. A
            // second request must not send another payment after lock expiry.
            $claimed = Charge::whereKey($chargeId)->where('status', 'pending')
                ->whereNull('acquirer_transaction_id')
                ->whereNull('metadata->external_processing_started_at')
                ->update(['status' => 'processing']);
            if (!$claimed) {
                return response()->json(['success' => false, 'error' => [
                    'code' => 'payment_already_processed', 'message' => 'Esta cobrança já foi enviada para processamento.',
                ]], 409);
            }
            $charge->refresh();
            $charge->update([
                'payment_method' => $paymentMethod,
                'installments' => $request->input('installments', $charge->installments ?? 1),
                'metadata' => array_merge($charge->metadata ?? [], ['external_processing_started_at' => now()->toIso8601String()]),
            ]);
            $result = $this->paymentService->processPayment($charge, $request->input('card', []));
            $charge->refresh();
            if (!($result['success'] ?? false)) {
                Charge::whereKey($chargeId)->whereIn('status', ['pending', 'processing'])
                    ->update(['status' => 'failed']);
                return response()->json(['success' => false, 'error' => [
                    'code' => 'payment_failed', 'message' => 'Não foi possível processar o pagamento.',
                ]], 400);
            }
            // Status, transactions and wallet credit belong to the payment
            // service/webhook. Do not manufacture a local capture here.
        } finally {
            $lock->release();
        }

        $statusCode = in_array($charge->status, ['paid', 'approved'], true) ? 200 : 202;

        return response()->json([
            'success' => true,
            'data' => [
                'charge_id' => $chargeId,
                'transaction_id' => $result['transaction_id'] ?? $charge->acquirer_transaction_id,
                'status' => $charge->status,
                'authorization_code' => $result['authorization_code'] ?? null,
                'payment_method' => $paymentMethod,
                'pix_qr_code' => $result['pix_qr_code'] ?? $charge->pix_qr_code,
                'pix_copy_paste' => $result['pix_copy_paste'] ?? $charge->pix_copy_paste,
                'boleto_url' => $result['boleto_url'] ?? $charge->boleto_url,
                'boleto_barcode' => $result['boleto_barcode'] ?? $charge->boleto_barcode,
                'processed_at' => now()->toIso8601String(),
            ],
        ], $statusCode);
    }

    /**
     * Get payment methods available for account
     */
    public function methods(Request $request): JsonResponse
    {
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
    }

    /**
     * Get installment options for credit card
     */
    public function installments(Request $request): JsonResponse
    {
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
    }

    /**
     * Capture a pre-authorized transaction
     */
    public function capture(Request $request, string $transactionId): JsonResponse
    {
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

        // Current acquirer adapters do not expose a capture contract. Never
        // report a successful capture without confirmation from the provider.
        return response()->json([
            'success' => false,
            'error' => [
                'code' => 'capture_not_supported',
                'message' => 'Captura de pre-autorizacao indisponivel para esta integracao.',
            ],
        ], 422);
    }
}
