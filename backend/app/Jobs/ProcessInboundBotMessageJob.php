<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\MessagingProvider;
use App\Services\Bot\BotEngineService;
use App\Services\MessagingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Roda o bot para uma mensagem recebida e entrega as respostas no WhatsApp.
 *
 * Fica fora do request do webhook porque a chamada de IA leva segundos e o
 * provedor faz retry se a resposta demorar.
 */
class ProcessInboundBotMessageJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $backoff = 15;

    public function __construct(
        private readonly string $accountId,
        private readonly string $providerId,
        private readonly string $contactPhone,
        private readonly string $message,
        private readonly ?string $contactName = null,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(BotEngineService $engine, MessagingService $messaging): void
    {
        $result = $engine->handleIncoming(
            $this->accountId,
            $this->contactPhone,
            $this->message,
            $this->contactName,
        );

        // Um no de espera pode ter agendado a continuacao, mesmo sem resposta agora.
        if (!empty($result['scheduled'])) {
            ResumeBotFlowJob::scheduleFor($result['conversation']);
        }

        if (empty($result['replies'])) {
            return;
        }

        $provider = MessagingProvider::find($this->providerId);
        if (!$provider || !$provider->is_active) {
            Log::warning('ProcessInboundBotMessageJob: provedor indisponivel', [
                'provider_id' => $this->providerId,
            ]);

            return;
        }

        foreach ($result['replies'] as $reply) {
            try {
                $messaging->send($provider, $this->contactPhone, $reply, $this->contactName);
            } catch (\Throwable $e) {
                Log::error('ProcessInboundBotMessageJob: falha ao enviar resposta', [
                    'account_id' => $this->accountId,
                    'error' => $e->getMessage(),
                ]);
                break;
            }
        }

        if ($result['handoff']) {
            Log::info('Bot transferiu conversa para humano', [
                'account_id' => $this->accountId,
                'phone' => $this->contactPhone,
            ]);
        }
    }
}
