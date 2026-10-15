<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\BotConversation;
use App\Models\BotFlow;
use App\Models\MessagingProvider;
use App\Services\Bot\BotAIService;
use App\Services\Bot\BotEngineService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class BotController extends Controller
{
    public function __construct(
        private readonly BotEngineService $engine,
        private readonly BotAIService $ai,
    ) {}

    /** GET /api/v1/bots */
    public function index(Request $request): JsonResponse
    {
        $bots = BotFlow::forAccount($request->user()->account_id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (BotFlow $b) => $this->format($b));

        return response()->json([
            'success' => true,
            'data' => $bots,
            'ai_available' => $this->ai->isConfigured(),
            'inbound_url' => $this->inboundUrl($request->user()->account_id),
        ]);
    }

    /** POST /api/v1/bots */
    public function store(Request $request): JsonResponse
    {
        $data = $this->validatePayload($request);
        $data['account_id'] = $request->user()->account_id;

        // Bot de IA nasce so com leitura; acoes que mexem em algo sao opt-in.
        if (($data['mode'] ?? 'flow') === BotFlow::MODE_AI) {
            $data['ai_config'] = $this->sanitizeAiConfig($data['ai_config'] ?? []);
        }

        $bot = BotFlow::create($data);
        $this->ensureSingleActive($bot);

        return response()->json(['success' => true, 'data' => $this->format($bot)], 201);
    }

    /** PUT /api/v1/bots/{id} */
    public function update(Request $request, string $id): JsonResponse
    {
        $bot = BotFlow::forAccount($request->user()->account_id)->findOrFail($id);

        $data = $this->validatePayload($request);
        if (array_key_exists('ai_config', $data)) {
            $data['ai_config'] = $this->sanitizeAiConfig($data['ai_config'] ?? []);
        }

        $bot->update($data);
        $this->ensureSingleActive($bot);

        return response()->json(['success' => true, 'data' => $this->format($bot->fresh())]);
    }

    /** DELETE /api/v1/bots/{id} */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $bot = BotFlow::forAccount($request->user()->account_id)->findOrFail($id);
        $bot->delete();

        return response()->json(['success' => true]);
    }

    /**
     * POST /api/v1/bots/{id}/simulate
     *
     * Roda o bot numa conversa descartavel para o dono testar antes de
     * conectar o WhatsApp. Nao envia nada para fora.
     */
    public function simulate(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'message' => 'required|string|max:2000',
            'reset' => 'sometimes|boolean',
        ]);

        $accountId = $request->user()->account_id;
        $bot = BotFlow::forAccount($accountId)->findOrFail($id);

        // Numero reservado para o simulador, isolado das conversas reais.
        $testPhone = 'sim-' . substr($bot->id, 0, 8);

        if ($request->boolean('reset')) {
            BotConversation::forAccount($accountId)->where('contact_phone', $testPhone)->delete();
        }

        // O simulador testa ESTE bot, mesmo que ele esteja inativo ou que
        // outro bot casaria a palavra-chave primeiro.
        $wasActive = $bot->is_active;
        if (!$wasActive) {
            $bot->forceFill(['is_active' => true])->saveQuietly();
        }

        try {
            // simulation=true: nos de espera viram anotacao, sem agendar job.
            $result = $this->engine->handleIncoming(
                $accountId,
                $testPhone,
                $request->string('message')->toString(),
                'Teste',
                true,
            );
        } catch (\Throwable $e) {
            Log::error('BotController::simulate falhou', ['bot_id' => $bot->id, 'error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'error' => ['code' => 'simulation_failed', 'message' => 'Nao consegui rodar o teste: ' . $e->getMessage()],
            ], 500);
        } finally {
            if (!$wasActive) {
                $bot->forceFill(['is_active' => false])->saveQuietly();
            }
        }

        return response()->json([
            'success' => true,
            'data' => [
                'replies' => $result['replies'],
                'handoff' => $result['handoff'],
                'status' => $result['conversation']->status,
                'context' => $result['conversation']->context,
            ],
        ]);
    }

    /** GET /api/v1/bots/conversations */
    public function conversations(Request $request): JsonResponse
    {
        $conversations = BotConversation::forAccount($request->user()->account_id)
            ->with('flow:id,name,mode')
            ->orderByDesc('last_message_at')
            ->limit(50)
            ->get()
            ->map(fn (BotConversation $c) => [
                'id' => $c->id,
                'contact_phone' => $c->contact_phone,
                'contact_name' => $c->contact_name,
                'status' => $c->status,
                'message_count' => $c->message_count,
                'last_message_at' => $c->last_message_at?->toIso8601String(),
                'bot' => $c->flow ? ['id' => $c->flow->id, 'name' => $c->flow->name, 'mode' => $c->flow->mode] : null,
            ]);

        return response()->json(['success' => true, 'data' => $conversations]);
    }

    /** POST /api/v1/bots/inbound-token — (re)gera a URL que o provedor chama. */
    public function regenerateInboundToken(Request $request): JsonResponse
    {
        $accountId = $request->user()->account_id;

        $provider = MessagingProvider::forAccount($accountId)
            ->where('channel', MessagingProvider::CHANNEL_WHATSAPP)
            ->orderByDesc('is_active')
            ->first();

        if (!$provider) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'no_provider', 'message' => 'Configure um provedor de WhatsApp antes.'],
            ], 422);
        }

        $provider->update(['inbound_token' => Str::random(48)]);

        return response()->json([
            'success' => true,
            'data' => ['inbound_url' => url("/api/v1/messaging/inbound/{$provider->inbound_token}")],
        ]);
    }

    // ================================================================

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'name' => 'sometimes|required|string|max:120',
            'mode' => 'sometimes|required|in:flow,ai',
            'is_active' => 'sometimes|boolean',
            'flow_data' => 'sometimes|nullable|array',
            'flow_data.nodes' => 'sometimes|array|max:100',
            'ai_config' => 'sometimes|nullable|array',
            'trigger_keywords' => 'sometimes|nullable|array|max:30',
            'trigger_keywords.*' => 'string|max:60',
            'only_business_hours' => 'sometimes|boolean',
            'business_hours' => 'sometimes|nullable|array',
        ]);
    }

    /**
     * Only actions on the allowlist survive, so a crafted payload cannot grant
     * the AI powers the owner never enabled.
     */
    private function sanitizeAiConfig(array $config): array
    {
        $requested = (array) ($config['allowed_actions'] ?? BotFlow::AI_DEFAULT_ACTIONS);
        $config['allowed_actions'] = array_values(array_intersect(BotFlow::AI_ACTIONS, $requested));

        return $config;
    }

    /** Um bot ativo por conta evita dois fluxos disputando a mesma conversa. */
    private function ensureSingleActive(BotFlow $bot): void
    {
        if (!$bot->is_active) {
            return;
        }

        BotFlow::forAccount($bot->account_id)
            ->where('id', '!=', $bot->id)
            ->where('is_active', true)
            ->update(['is_active' => false]);
    }

    private function inboundUrl(string $accountId): ?string
    {
        $provider = MessagingProvider::forAccount($accountId)
            ->where('channel', MessagingProvider::CHANNEL_WHATSAPP)
            ->whereNotNull('inbound_token')
            ->orderByDesc('is_active')
            ->first();

        return $provider ? url("/api/v1/messaging/inbound/{$provider->inbound_token}") : null;
    }

    private function format(BotFlow $bot): array
    {
        return [
            'id' => $bot->id,
            'name' => $bot->name,
            'mode' => $bot->mode,
            'is_active' => $bot->is_active,
            'flow_data' => $bot->flow_data ?? ['nodes' => []],
            'ai_config' => $bot->ai_config ?? [],
            'trigger_keywords' => $bot->trigger_keywords ?? [],
            'only_business_hours' => $bot->only_business_hours,
            'business_hours' => $bot->business_hours,
            'conversations_count' => $bot->conversations_count,
            'messages_count' => $bot->messages_count,
            'created_at' => $bot->created_at?->toIso8601String(),
        ];
    }
}
