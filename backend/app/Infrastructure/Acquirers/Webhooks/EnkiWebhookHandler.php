<?php

declare(strict_types=1);

namespace App\Infrastructure\Acquirers\Webhooks;

use App\Models\Charge;
use App\Services\PaymentService;
use App\Services\WithdrawalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;

class EnkiWebhookHandler
{
    private PaymentService $paymentService;
    private ?WithdrawalService $withdrawalService;

    public function __construct(PaymentService $paymentService)
    {
        $this->paymentService = $paymentService;

        try {
            $this->withdrawalService = app(WithdrawalService::class);
        } catch (\Throwable $e) {
            $this->withdrawalService = null;
        }
    }

    public function handle(Request $request): JsonResponse
    {
        Log::info('Enki webhook received', [
            'ip' => $request->ip(),
            'content_type' => $request->header('Content-Type'),
            'body_size' => strlen($request->getContent()),
        ]);

        try {
            $payload = $request->all();
            $event = strtolower((string) ($payload['event'] ?? ''));

            if ($event === '') {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Missing event',
                ], 400);
            }

            if (str_starts_with($event, 'transaction.')) {
                return $this->handleTransactionEvent($payload, $event);
            }

            if (str_starts_with($event, 'withdrawal.')) {
                return $this->handleWithdrawalEvent($payload, $event);
            }

            return response()->json([
                'status' => 'received',
                'message' => 'Unhandled event',
            ]);
        } catch (\Throwable $e) {
            Log::error('Enki webhook error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Processing failed',
            ], 500);
        }
    }

    private function handleTransactionEvent(array $payload, string $event): JsonResponse
    {
        $transaction = $payload['transaction'] ?? [];
        $transactionId = (string) ($transaction['id'] ?? '');

        if ($transactionId === '') {
            return response()->json([
                'status' => 'received',
                'message' => 'Transaction id missing',
            ]);
        }

        $charge = Charge::where('acquirer', 'enki')
            ->where(function ($query) use ($transactionId) {
                $query->where('acquirer_transaction_id', $transactionId)
                    ->orWhere('external_id', $transactionId);
            })
            ->first();

        if (!$charge) {
            Log::warning('Enki transaction webhook: charge not found', [
                'event' => $event,
                'transaction_id' => $transactionId,
            ]);

            return response()->json([
                'status' => 'received',
                'message' => 'Charge not found',
            ]);
        }

        $webhookData = [
            'id' => $transactionId,
            'status' => $transaction['status'] ?? $this->mapTransactionEventToStatus($event),
            'payment_method' => $transaction['payment_method'] ?? $transaction['method'] ?? 'PIX',
            'amount' => $transaction['amount'] ?? null,
            'fee' => $transaction['fee'] ?? null,
            'net_amount' => $transaction['net_amount'] ?? null,
            'paid_at' => $transaction['paid_at'] ?? null,
            'pix' => [
                'copy_paste' => Arr::get($transaction, 'pix.copy_paste'),
                'endToEndId' => Arr::get($transaction, 'pix.end_to_end'),
                'expires_at' => Arr::get($transaction, 'pix.expires_at'),
            ],
        ];

        $this->paymentService->handleWebhookPayment($charge, $webhookData);

        return response()->json([
            'status' => 'success',
            'charge_id' => $charge->id,
            'charge_status' => $charge->fresh()?->status,
        ]);
    }

    private function handleWithdrawalEvent(array $payload, string $event): JsonResponse
    {
        if (!$this->withdrawalService) {
            return response()->json([
                'status' => 'error',
                'message' => 'Withdrawal service unavailable',
            ], 500);
        }

        $withdrawal = $payload['withdrawal'] ?? [];
        $withdrawalId = (string) ($withdrawal['id'] ?? '');

        if ($withdrawalId === '') {
            return response()->json([
                'status' => 'received',
                'message' => 'Withdrawal id missing',
            ]);
        }

        $status = (string) ($withdrawal['status'] ?? $this->mapWithdrawalEventToStatus($event));
        $this->withdrawalService->handleTransferCompleted($withdrawalId, $status, $withdrawal);

        return response()->json([
            'status' => 'success',
            'withdrawal_id' => $withdrawalId,
            'withdrawal_status' => $status,
        ]);
    }

    private function mapTransactionEventToStatus(string $event): string
    {
        return match ($event) {
            'transaction.paid' => 'PAID',
            'transaction.refunded' => 'REFUNDED',
            default => 'WAITING_PAYMENT',
        };
    }

    private function mapWithdrawalEventToStatus(string $event): string
    {
        return match ($event) {
            'withdrawal.completed' => 'COMPLETED',
            'withdrawal.failed' => 'REFUSED',
            default => 'PROCESSING',
        };
    }
}