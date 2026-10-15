<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessInboundBotMessageJob;
use App\Models\MessagingProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Recebe mensagens vindas do provedor de WhatsApp.
 *
 * Rota publica: o provedor nao tem sessao. A autenticacao e o token opaco da
 * URL, que identifica o provedor e, por ele, a conta. Responde 200 sempre que
 * o token confere, para o provedor nao entrar em retry por erro nosso.
 */
class MessagingInboundController extends Controller
{
    public function handle(Request $request, string $token): JsonResponse
    {
        $provider = MessagingProvider::where('inbound_token', $token)->first();

        if (!$provider) {
            Log::warning('Inbound webhook: token invalido', ['ip' => $request->ip()]);

            return response()->json(['success' => false], 404);
        }

        if (!$provider->is_active) {
            return response()->json(['success' => true, 'ignored' => 'provider_inactive']);
        }

        $parsed = $this->parseEvolutionPayload($request->all());

        if (!$parsed) {
            return response()->json(['success' => true, 'ignored' => 'no_text_message']);
        }

        ProcessInboundBotMessageJob::dispatch(
            $provider->account_id,
            $provider->id,
            $parsed['phone'],
            $parsed['text'],
            $parsed['name'],
        );

        return response()->json(['success' => true]);
    }

    /**
     * Extrai telefone, nome e texto do evento messages.upsert da Evolution API.
     * Ignora eco das proprias mensagens e grupos.
     *
     * @return array{phone:string, text:string, name:?string}|null
     */
    private function parseEvolutionPayload(array $payload): ?array
    {
        $event = (string) ($payload['event'] ?? '');
        if ($event !== '' && !in_array(strtolower($event), ['messages.upsert', 'messages_upsert'], true)) {
            return null;
        }

        $data = $payload['data'] ?? [];
        if (isset($data[0]) && is_array($data[0])) {
            $data = $data[0];
        }

        if (!empty($data['key']['fromMe'])) {
            return null;
        }

        $jid = (string) ($data['key']['remoteJid'] ?? '');
        if ($jid === '' || str_contains($jid, '@g.us') || str_contains($jid, 'status@')) {
            return null;
        }

        $phone = preg_replace('/\D/', '', explode('@', $jid)[0]) ?? '';
        if (strlen($phone) < 8) {
            return null;
        }

        $message = $data['message'] ?? [];
        $text = $message['conversation']
            ?? $message['extendedTextMessage']['text']
            ?? $message['imageMessage']['caption']
            ?? $message['videoMessage']['caption']
            ?? $message['buttonsResponseMessage']['selectedDisplayText']
            ?? $message['listResponseMessage']['title']
            ?? null;

        $text = is_string($text) ? trim($text) : '';
        if ($text === '') {
            return null;
        }

        return [
            'phone' => $phone,
            'text' => $text,
            'name' => isset($data['pushName']) ? (string) $data['pushName'] : null,
        ];
    }
}
