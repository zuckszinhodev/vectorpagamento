<?php

declare(strict_types=1);

namespace App\Infrastructure\Acquirers\Webhooks;

use App\Infrastructure\Acquirers\KokuPayAcquirer;
use App\Jobs\SyncKokuPayChargeJob;
use App\Models\Charge;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Webhook da Koku: só um aviso. Nada no corpo muda dinheiro ou status —
 * a cobrança é sempre reconsultada na API da Koku (SyncKokuPayChargeJob).
 *
 * Responde 2xx em poucos milissegundos (a Koku exige até 5 s) e processa na fila.
 */
class KokuPayWebhookHandler
{
    private const CHARGE_EVENTS = [
        'charge.pending', 'charge.paid', 'charge.expired', 'charge.failed',
        'charge.refunded', 'charge.duplicate_payment',
    ];

    public function handle(Request $request): JsonResponse
    {
        $koku = KokuPayAcquirer::fromStoredCredentials();
        $rawBody = $request->getContent();

        if (!$koku || $koku->webhookSecret() === '') {
            Log::warning('Koku webhook recebido sem a Koku configurada (falta webhook_secret)');

            return response()->json(['status' => 'error'], 503);
        }

        if (!KokuPayAcquirer::verifySignature($rawBody, $request->header('x-koku-signature'), $koku->webhookSecret())) {
            Log::warning('Koku webhook com assinatura inválida', ['ip' => $request->ip()]);

            return response()->json(['status' => 'invalid_signature'], 400);
        }

        $event = json_decode($rawBody, true);
        $type = is_array($event) ? (string) ($event['type'] ?? '') : '';
        $chargeId = is_array($event) ? (string) ($event['data']['charge_id'] ?? '') : '';

        Log::info('Koku webhook', [
            'event_id' => $event['id'] ?? $request->header('x-koku-event-id'),
            'type' => $type,
            'koku_charge_id' => $chargeId,
        ]);

        if (!in_array($type, self::CHARGE_EVENTS, true) || $chargeId === '') {
            // Casos, saldo, saques e tarifas: só registro (saques não são feitos pela Koku aqui)
            return response()->json(['status' => 'received']);
        }

        $charge = Charge::where('acquirer', KokuPayAcquirer::KEY)
            ->where('acquirer_transaction_id', $chargeId)
            ->first();

        if (!$charge) {
            Log::warning('Koku webhook: cobrança não encontrada', ['type' => $type, 'koku_charge_id' => $chargeId]);

            return response()->json(['status' => 'received']);
        }

        if ($type === 'charge.duplicate_payment') {
            // O valor extra fica retido pela Koku para devolução ao pagador; não é venda
            Log::warning('Koku: pagamento duplicado retido para devolução', [
                'charge_id' => $charge->id,
                'case_id' => $event['data']['case_id'] ?? null,
                'amount_cents' => $event['data']['amount_cents'] ?? null,
            ]);
        }

        SyncKokuPayChargeJob::dispatch($charge->id);

        return response()->json(['status' => 'queued']);
    }
}
