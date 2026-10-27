<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Charge;
use App\Services\PaymentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Aplica um webhook da Koku consultando a cobrança na API (o corpo do webhook nunca é confiado).
 * Repetir é seguro: a confirmação de pagamento trava a cobrança e ignora o que já foi aplicado.
 */
class SyncKokuPayChargeJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $timeout = 60;

    /** @var array<int, int> */
    public array $backoff = [5, 15, 60, 180];

    public function __construct(private readonly string $chargeId)
    {
        $this->onQueue('payments');
    }

    public function handle(PaymentService $paymentService): void
    {
        $charge = Charge::find($this->chargeId);
        if (!$charge) {
            return;
        }

        $result = $paymentService->syncChargeStatus($charge);

        // Consulta indisponível: tenta de novo mais tarde (o polling periódico também cobre)
        if (!($result['synced'] ?? false) && $this->attempts() < $this->tries) {
            $this->release($this->backoff[$this->attempts() - 1] ?? 180);
        }
    }
}
