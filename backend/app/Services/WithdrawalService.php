<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AccountWallet;
use App\Models\SystemSetting;
use App\Models\Withdrawal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Withdrawal Service
 *
 * Processes withdrawals by sending PIX OUT via globally prioritized admin acquirers.
 * Supports instant (synchronous) and automatic (scheduled) withdrawals.
 *
 * Flow:
 * 1. Withdrawal created with status=pending
 * 2. processWithdrawal() sends PIX OUT via acquirer
 * 3. On handoff success → status=processing, acquirer_transfer_id saved
 * 4. Acquirer webhook (transfer_completed) → status=completed
 * 5. On failure → status=failed, funds returned to available_balance
 */
class WithdrawalService
{
    private AcquirerFactory $factory;
    private ?AcquirerRotationService $rotationService = null;

    /** @var array<string, CircuitBreaker> */
    private array $circuitBreakers = [];

    public function __construct()
    {
        $this->factory = AcquirerFactory::instance();
        $this->rotationService = app(AcquirerRotationService::class);

        // PIX OUT circuit breakers follow the globally supported payout acquirers.
        foreach (['payfort', 'blupay', 'enki', 'babylon', 'huntersub', 'sharkhubsub'] as $name) {
            $this->circuitBreakers[$name] = new CircuitBreaker("acquirer:{$name}", 3, 60);
        }
    }

    // ----------------------------------------------------------------
    // Public API
    // ----------------------------------------------------------------

    /**
     * Process a pending withdrawal by sending PIX OUT to the acquirer.
     *
     * Returns ['success' => bool, 'transfer_id' => ?string, 'acquirer' => ?string, 'error' => ?string]
     */
    public function processWithdrawal(Withdrawal $withdrawal): array
    {
        $processingEnabled = (bool) SystemSetting::get('withdrawal_processing_enabled', true);
        if (!$processingEnabled) {
            return [
                'success' => false,
                'error' => 'Processamento de saques está temporariamente pausado pela administração',
            ];
        }

        $isRecoverableProcessing = $withdrawal->status === 'processing' && empty($withdrawal->acquirer_transfer_id);

        if ($withdrawal->status !== 'pending' && !$isRecoverableProcessing) {
            return ['success' => false, 'error' => "Withdrawal is not pending (status={$withdrawal->status})"];
        }

        if ($isRecoverableProcessing) {
            Log::warning('WithdrawalService: recovering stuck processing withdrawal without transfer id', [
                'withdrawal_id' => $withdrawal->id,
                'status' => $withdrawal->status,
            ]);
        }

        $destination = $withdrawal->destination;
        $pixKey = $destination['pix_key'] ?? null;

        if (!$pixKey && $withdrawal->method === 'pix') {
            $this->failWithdrawal($withdrawal, 'Chave PIX não configurada no destino');
            return ['success' => false, 'error' => 'No PIX key in destination'];
        }

        Log::info('WithdrawalService: processing withdrawal', [
            'withdrawal_id' => $withdrawal->id,
            'amount'        => $withdrawal->net_amount,
            'method'        => $withdrawal->method,
            'pix_key'       => $pixKey,
            'current_status' => $withdrawal->status,
            'recovery_mode'  => $isRecoverableProcessing,
        ]);

        try {
            // Build transfer data
            $transferData = $this->buildTransferData($withdrawal);

            // Try configured acquirers in fallback order
            $result = $this->sendPixOutWithFallback($transferData);
        } catch (\Exception $e) {
            Log::error('WithdrawalService: error building/sending transfer', [
                'withdrawal_id' => $withdrawal->id,
                'error'         => $e->getMessage(),
            ]);
            $this->failWithdrawal($withdrawal, 'Erro interno: ' . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }

        if ($result['success']) {
            $transferId = (string) ($result['transfer_id'] ?? '');

            if ($transferId === '') {
                $this->failWithdrawal($withdrawal, 'Acquirer PIX OUT did not return transfer_id');
                return ['success' => false, 'error' => 'Acquirer PIX OUT did not return transfer_id'];
            }

            $withdrawal->update([
                'status'               => 'processing',
                'acquirer_transfer_id' => $transferId,
                'acquirer'             => $result['acquirer'] ?? 'payfort',
                'failure_reason'       => null,
                'processed_at'         => null,
            ]);

            Log::info('WithdrawalService: PIX OUT sent successfully', [
                'withdrawal_id' => $withdrawal->id,
                'transfer_id'   => $transferId,
                'acquirer'       => $result['acquirer'] ?? null,
            ]);

            return [
                'success'     => true,
                'transfer_id' => $transferId,
                'acquirer'    => $result['acquirer'] ?? null,
                'status'      => $result['status'] ?? 'processing',
            ];
        }

        // PIX OUT failed — revert
        $this->failWithdrawal($withdrawal, $result['error'] ?? 'Acquirer PIX OUT failed');

        return [
            'success' => false,
            'error'   => $result['error'] ?? 'PIX OUT failed',
        ];
    }

    /**
     * Handle acquirer webhook confirming transfer completion.
     */
    public function handleTransferCompleted(string $transferId, string $acquirerStatus, array $rawData = []): bool
    {
        $withdrawal = Withdrawal::where('acquirer_transfer_id', $transferId)
            ->whereIn('status', ['processing', 'pending'])
            ->first();

        if (!$withdrawal) {
            Log::warning('WithdrawalService: no withdrawal found for transfer', [
                'transfer_id' => $transferId,
            ]);
            return false;
        }

        $mappedStatus = $this->mapTransferStatus($acquirerStatus);

        Log::info('WithdrawalService: transfer webhook received', [
            'withdrawal_id'   => $withdrawal->id,
            'transfer_id'     => $transferId,
            'acquirer_status' => $acquirerStatus,
            'mapped_status'   => $mappedStatus,
        ]);

        if ($mappedStatus === 'completed') {
            return $this->completeWithdrawal($withdrawal, $rawData);
        }

        if ($mappedStatus === 'failed' || $mappedStatus === 'cancelled') {
            $reason = $rawData['failureReason'] ?? $rawData['reason'] ?? "Transfer {$acquirerStatus}";
            $this->failWithdrawal($withdrawal, $reason);
            return true;
        }

        // Still processing — nothing to do
        return true;
    }

    /**
     * Process all pending auto-withdrawals (called by scheduler).
     */
    public function processAutoWithdrawals(): array
    {
        $processingEnabled = (bool) SystemSetting::get('withdrawal_processing_enabled', true);
        if (!$processingEnabled) {
            return ['processed' => 0, 'message' => 'Withdrawal processing is paused'];
        }

        $autoEnabled = SystemSetting::get('auto_withdrawal_enabled', false);
        if (!$autoEnabled) {
            return ['processed' => 0, 'message' => 'Auto-withdrawal is disabled'];
        }

        $pendingWithdrawals = Withdrawal::where('auto_process', true)
            ->where(function ($query) {
                $query->where('status', 'pending')
                    ->orWhere(function ($subQuery) {
                        $subQuery->where('status', 'processing')
                            ->whereNull('acquirer_transfer_id');
                    });
            })
            ->orderBy('created_at', 'asc')
            ->limit(50)
            ->get();

        $results = ['processed' => 0, 'success' => 0, 'failed' => 0, 'errors' => []];

        foreach ($pendingWithdrawals as $withdrawal) {
            $results['processed']++;

            $result = $this->processWithdrawal($withdrawal);

            if ($result['success']) {
                $results['success']++;
            } else {
                $results['failed']++;
                $results['errors'][] = [
                    'withdrawal_id' => $withdrawal->id,
                    'error'         => $result['error'],
                ];
            }
        }

        Log::info('WithdrawalService: auto-withdrawal batch completed', $results);

        return $results;
    }

    /**
     * Check if auto-withdrawal is enabled globally.
     */
    public function isAutoWithdrawalEnabled(): bool
    {
        return (bool) SystemSetting::get('auto_withdrawal_enabled', false);
    }

    /**
     * Check if instant withdrawal (process immediately on creation) is enabled.
     */
    public function isInstantWithdrawalEnabled(): bool
    {
        return (bool) SystemSetting::get('instant_withdrawal_enabled', false);
    }

    /**
     * Cancel a withdrawal from the admin panel.
     *
     * For processing withdrawals already handed off to an acquirer, this method
     * only proceeds when the transfer was not actually created remotely.
     */
    public function adminCancelWithdrawal(Withdrawal $withdrawal, string $reason): array
    {
        if (!in_array($withdrawal->status, ['pending', 'pending_approval', 'processing'], true)) {
            return [
                'success' => false,
                'status' => 422,
                'message' => "Saque não pode ser cancelado nesse estágio (status={$withdrawal->status})",
                'error_code' => 'withdrawal_not_cancellable',
            ];
        }

        if ($withdrawal->status === 'processing' && !empty($withdrawal->acquirer_transfer_id)) {
            return $this->buildUnsupportedAcquirerCancellationResponse($withdrawal);
        }

        try {
            DB::beginTransaction();

            $withdrawal->update([
                'status' => 'cancelled',
                'processed_at' => now(),
                'failure_reason' => $reason,
                'notes' => $reason,
            ]);

            $wallet = AccountWallet::where('account_id', $withdrawal->account_id)
                ->lockForUpdate()
                ->first();

            if ($wallet) {
                $pendingBalance = (float) $wallet->pending_balance;
                $amount = (float) $withdrawal->amount;
                $decrement = min($pendingBalance, $amount);

                if ($decrement > 0) {
                    $wallet->decrement('pending_balance', $decrement);
                }

                $wallet->increment('available_balance', $amount);
            }

            DB::commit();

            return [
                'success' => true,
                'withdrawal' => $withdrawal->fresh(),
                'message' => 'Saque cancelado pela administração com devolução de saldo',
            ];
        } catch (\Throwable $e) {
            DB::rollBack();
            Log::error('Admin withdrawal cancellation failed', [
                'withdrawal_id' => $withdrawal->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'status' => 500,
                'message' => 'Erro ao cancelar saque administrativamente',
                'error_code' => 'admin_withdrawal_cancellation_failed',
            ];
        }
    }

    // ----------------------------------------------------------------
    // Private helpers
    // ----------------------------------------------------------------

    /**
     * Build the acquirer transfer payload from a Withdrawal model.
     */
    private function buildTransferData(Withdrawal $withdrawal): array
    {
        $destination = $withdrawal->destination ?? [];
        $account = $withdrawal->account;
        $netAmountCents = (int) round((float) $withdrawal->net_amount);

        return [
            'pix_key'              => $destination['pix_key'] ?? '',
            'pix_key_type'         => strtoupper($destination['pix_type'] ?? 'CPF'),
            'amount'               => $netAmountCents / 100, // Reais for acquirer (they convert to centavos internally)
            'amount_cents'         => $netAmountCents,
            'description'          => "Saque Vector Pagamentos #{$withdrawal->id}",
            'destination_name'     => $destination['holder_name'] ?? $account?->name ?? 'Destinatário',
            'destination_document' => $destination['holder_document'] ?? $account?->document ?? '',
            'external_ref'         => $withdrawal->id,
            'idempotency_key'      => $withdrawal->id, // UUID ensures idempotency
            'metadata'             => [
                'withdrawal_id' => $withdrawal->id,
                'account_id'    => $withdrawal->account_id,
            ],
        ];
    }

    /**
     * Send PIX OUT using the global admin priority + circuit breaker chain.
     */
    private function sendPixOutWithFallback(array $transferData): array
    {
        $lastError = null;
        $skipReasons = [];
        $attemptedAcquirers = [];
        $withdrawalId = (string) ($transferData['external_ref'] ?? '');
        $acquirerOrder = $this->factory->getPixOutAcquirerOrder();

        if ($acquirerOrder === []) {
            return [
                'success' => false,
                'error' => 'Nenhuma adquirente global ativa está configurada para PIX OUT',
            ];
        }

        foreach ($acquirerOrder as $name) {
            $cb = $this->circuitBreakers[$name] ?? null;

            // Skip if circuit breaker is open
            if ($cb && !$cb->isAvailable()) {
                $reason = "Circuit breaker aberto para {$name}";
                $skipReasons[$name] = $reason;
                Log::warning("WithdrawalService: skipping {$name} PIX OUT", [
                    'withdrawal_id' => $withdrawalId !== '' ? $withdrawalId : null,
                    'reason' => $reason,
                ]);
                continue;
            }

            $instance = $this->factory->getAcquirerInstance($name);

            // Refresh singleton factory if an acquirer instance becomes stale in long-running workers.
            if (!$instance || !method_exists($instance, 'sendPixTransfer')) {
                AcquirerFactory::reset();
                $this->factory = AcquirerFactory::instance();
                $instance = $this->factory->getAcquirerInstance($name);
            }

            if (!$instance || !method_exists($instance, 'sendPixTransfer')) {
                $reason = "Adquirente {$name} indisponível ou sem suporte a PIX OUT";
                $skipReasons[$name] = $reason;
                Log::warning("WithdrawalService: skipping {$name} PIX OUT", [
                    'withdrawal_id' => $withdrawalId !== '' ? $withdrawalId : null,
                    'reason' => $reason,
                ]);
                continue;
            }

            $attemptedAcquirers[] = $name;

            Log::info("WithdrawalService: trying {$name} PIX OUT", [
                'withdrawal_id' => $transferData['external_ref'],
                'amount_reais'  => $transferData['amount'],
                'routing_scope' => 'global_admin',
            ]);

            try {
                $result = $instance->sendPixTransfer($transferData);

                if ($result['success']) {
                    $cb?->recordSuccess();
                    $this->rotationService?->markSuccess('pix_out', $name);
                    $result['acquirer'] = $name;
                    
                    Log::info("WithdrawalService: {$name} PIX OUT successful", [
                        'withdrawal_id' => $transferData['external_ref'],
                        'transfer_id' => $result['transfer_id'] ?? null,
                    ]);
                    
                    return $result;
                }

                $lastError = $result['error'] ?? "{$name} PIX OUT failed";
                $cb?->recordFailure();
                $this->rotationService?->markFailure('pix_out', $name);
                Log::warning("WithdrawalService: {$name} PIX OUT failed", [
                    'withdrawal_id' => $withdrawalId !== '' ? $withdrawalId : null,
                    'error' => $lastError,
                ]);
            } catch (\Exception $e) {
                $lastError = $e->getMessage();
                $cb?->recordFailure();
                $this->rotationService?->markFailure('pix_out', $name);
                Log::error("WithdrawalService: {$name} PIX OUT exception", [
                    'withdrawal_id' => $withdrawalId !== '' ? $withdrawalId : null,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        if ($lastError === null && $skipReasons !== []) {
            $skipParts = [];
            foreach ($skipReasons as $name => $reason) {
                $skipParts[] = "{$name}: {$reason}";
            }

            $lastError = 'Nenhuma tentativa de PIX OUT executada. ' . implode('; ', $skipParts);
        }

        Log::error('WithdrawalService: PIX OUT fallback exhausted', [
            'withdrawal_id' => $withdrawalId !== '' ? $withdrawalId : null,
            'acquirer_order' => $acquirerOrder,
            'attempted_acquirers' => $attemptedAcquirers,
            'skip_reasons' => $skipReasons,
            'last_error' => $lastError,
        ]);

        return [
            'success' => false,
            'error'   => $lastError ?? 'Falha ao processar PIX OUT em todas as adquirentes',
        ];
    }

    /**
     * Mark withdrawal as completed and finalize wallet balance.
     */
    private function completeWithdrawal(Withdrawal $withdrawal, array $rawData = []): bool
    {
        try {
            DB::beginTransaction();

            $withdrawal->markAsCompleted();

            // Move from pending_balance to "withdrawn" (just decrement pending)
            $wallet = AccountWallet::where('account_id', $withdrawal->account_id)->first();
            if ($wallet) {
                $wallet->decrement('pending_balance', (int) $withdrawal->amount);
            }

            DB::commit();

            Log::info('WithdrawalService: withdrawal completed', [
                'withdrawal_id' => $withdrawal->id,
                'net_amount'    => $withdrawal->net_amount,
            ]);

            return true;
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('WithdrawalService: failed to complete withdrawal', [
                'withdrawal_id' => $withdrawal->id,
                'error'         => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Mark withdrawal as failed and return funds to available_balance.
     */
    private function failWithdrawal(Withdrawal $withdrawal, string $reason): void
    {
        try {
            DB::beginTransaction();

            $withdrawal->markAsFailed($reason);

            // Return funds: pending → available
            $wallet = AccountWallet::where('account_id', $withdrawal->account_id)->first();
            if ($wallet) {
                $wallet->decrement('pending_balance', (int) $withdrawal->amount);
                $wallet->increment('available_balance', (int) $withdrawal->amount);
            }

            DB::commit();

            Log::info('WithdrawalService: withdrawal failed, funds returned', [
                'withdrawal_id' => $withdrawal->id,
                'reason'        => $reason,
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('WithdrawalService: failed to revert withdrawal', [
                'withdrawal_id' => $withdrawal->id,
                'error'         => $e->getMessage(),
            ]);
        }
    }

    /**
     * Map acquirer transfer status to internal withdrawal status.
     */
    private function mapTransferStatus(string $status): string
    {
        return match (strtolower($status)) {
            'completed', 'paid', 'approved', 'settled' => 'completed',
            'queued', 'processing', 'pending'           => 'processing',
            'canceled', 'cancelled'                     => 'cancelled',
            'failed', 'refused', 'error'                => 'failed',
            default                                     => 'processing',
        };
    }

    private function buildUnsupportedAcquirerCancellationResponse(Withdrawal $withdrawal): array
    {
        $acquirer = strtolower((string) ($withdrawal->acquirer ?? 'desconhecido'));
        $transferId = (string) $withdrawal->acquirer_transfer_id;

        return match ($acquirer) {
            'payfort' => [
                'success' => false,
                'status' => 422,
                'message' => "O saque já foi enviado ao PayFort (transferência {$transferId}). A API pública do adquirente não oferece cancelamento de PIX OUT. Cancele no painel do PayFort e aguarde o webhook de cancelamento para o estorno automático.",
                'error_code' => 'acquirer_api_cancellation_not_supported',
                'data' => [
                    'acquirer' => 'payfort',
                    'acquirer_transfer_id' => $transferId,
                    'requires_external_cancellation' => true,
                    'manual_action' => 'Cancelar a transferência no painel do PayFort e aguardar o webhook transfer_canceled/transfer_updated.',
                    'documentation_url' => 'https://docs.payfortbank.com/openapi.yaml',
                ],
            ],
            default => [
                'success' => false,
                'status' => 422,
                'message' => "O saque já foi enviado ao adquirente {$acquirer} (transferência {$transferId}) e este fluxo não possui cancelamento automático via API neste projeto.",
                'error_code' => 'acquirer_api_cancellation_not_supported',
                'data' => [
                    'acquirer' => $acquirer,
                    'acquirer_transfer_id' => $transferId,
                    'requires_external_cancellation' => true,
                ],
            ],
        };
    }

}
