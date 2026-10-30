<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Fraud\Services\FraudDetectionService;
use App\Models\Charge;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Antifraude em modo observação: grava a nota de risco da cobrança e nunca altera o pagamento.
 * Roda fora do fluxo de pagamento para que um erro aqui não recuse uma venda.
 */
class AnalyzeChargeRiskJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 30;

    public function __construct(
        private readonly string $chargeId,
        private readonly ?string $ipAddress = null,
    ) {
        $this->onQueue('default');
    }

    public function handle(FraudDetectionService $fraudDetection): void
    {
        $charge = Charge::find($this->chargeId);
        if (!$charge || DB::table('risk_scores')->where('charge_id', $charge->id)->exists()) {
            return;
        }

        $customer = $charge->customer ?? [];

        $fraudDetection->analyzeTransaction($charge->id, $charge->account_id, [
            'amount' => $charge->amount,
            'customer_email' => $customer['email'] ?? null,
            'document' => $customer['document'] ?? $customer['cpf'] ?? null,
            'ip_address' => $this->ipAddress,
        ]);
    }
}
