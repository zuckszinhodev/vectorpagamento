<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\BotConversation;
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
 * Retoma um fluxo que parou num no de espera.
 *
 * Carrega o token do agendamento: se a conversa andou nesse meio tempo (o
 * cliente escreveu, foi transferida, um novo delay entrou), o motor descarta
 * esta retomada e nada e enviado.
 */
class ResumeBotFlowJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $backoff = 30;

    public function __construct(
        private readonly string $conversationId,
        private readonly string $resumeToken,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(BotEngineService $engine, MessagingService $messaging): void
    {
        $conversation = BotConversation::find($this->conversationId);
        if (!$conversation) {
            return;
        }

        $result = $engine->resume($conversation, $this->resumeToken);
        if ($result === null || empty($result['replies'])) {
            return;
        }

        $provider = MessagingProvider::forAccount($conversation->account_id)
            ->where('channel', MessagingProvider::CHANNEL_WHATSAPP)
            ->where('is_active', true)
            ->first();

        if (!$provider) {
            Log::warning('ResumeBotFlowJob: sem provedor ativo para entregar', [
                'conversation_id' => $conversation->id,
            ]);

            return;
        }

        foreach ($result['replies'] as $reply) {
            try {
                $messaging->send($provider, $conversation->contact_phone, $reply, $conversation->contact_name);
            } catch (\Throwable $e) {
                Log::error('ResumeBotFlowJob: falha ao enviar', [
                    'conversation_id' => $conversation->id,
                    'error' => $e->getMessage(),
                ]);
                break;
            }
        }

        // A continuacao pode ter caido em outro no de espera.
        if (!empty($result['scheduled'])) {
            self::scheduleFor($conversation->fresh());
        }
    }

    /** Agenda a retomada gravada na conversa, se houver. */
    public static function scheduleFor(?BotConversation $conversation): void
    {
        if (!$conversation || !$conversation->resume_at || !$conversation->resume_token) {
            return;
        }

        self::dispatch($conversation->id, $conversation->resume_token)
            ->delay($conversation->resume_at);
    }
}
