<?php

declare(strict_types=1);

namespace App\Services\Bot;

use App\Models\BotConversation;
use App\Models\BotFlow;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Resposta do bot de atendimento com IA.
 *
 * Os pedidos do contato sao resolvidos ANTES da chamada e entram no prompt,
 * entao a IA ja responde sabendo o status real sem round-trip de ferramenta.
 * Acoes que mudam algo continuam passando pela allowlist do BotGatewayService.
 */
class BotAIService
{
    private const ACTION_PATTERN = '/:::ACTION:::\s*(\{[\s\S]*?\})\s*:::END_ACTION:::/m';

    public function __construct(
        private readonly BotGatewayService $gateway,
    ) {}

    public function isConfigured(): bool
    {
        return !empty((string) config('services.openrouter.api_key', ''));
    }

    /**
     * @return array{success:bool, reply:string, handoff:bool}
     */
    public function reply(BotFlow $flow, BotConversation $conversation, string $message): array
    {
        if (!$this->isConfigured()) {
            return [
                'success' => false,
                'reply' => $this->fallbackMessage($flow),
                'handoff' => true,
            ];
        }

        $config = $flow->ai_config ?? [];
        $orders = $this->gateway->ordersForPhone($flow->account_id, $conversation->contact_phone);

        // A mensagem atual ja foi gravada antes do motor chamar a IA, entao ela
        // costuma ser a ultima do historico. So anexa se ainda nao estiver la.
        $history = $conversation->recentHistory(10);
        $last = end($history) ?: null;
        if (!$last || $last['role'] !== 'user' || $last['content'] !== $message) {
            $history[] = ['role' => 'user', 'content' => $message];
        }

        $messages = array_merge(
            [['role' => 'system', 'content' => $this->buildSystemPrompt($flow, $conversation, $orders)]],
            $history,
        );

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . config('services.openrouter.api_key'),
                'Content-Type' => 'application/json',
                'HTTP-Referer' => config('app.url', 'https://vectorpagamentos.com'),
                'X-Title' => 'Vector Bot Atendimento',
            ])->timeout(45)->post('https://openrouter.ai/api/v1/chat/completions', [
                'model' => $config['model'] ?? config('services.openrouter.wazerai_fast_model', 'google/gemini-2.0-flash-001'),
                'messages' => $messages,
                'temperature' => (float) ($config['temperature'] ?? 0.5),
                'max_tokens' => 800,
            ]);
        } catch (\Throwable $e) {
            Log::error('BotAIService: chamada falhou', ['flow_id' => $flow->id, 'error' => $e->getMessage()]);

            return ['success' => false, 'reply' => $this->fallbackMessage($flow), 'handoff' => true];
        }

        if (!$response->successful()) {
            Log::error('BotAIService: resposta nao ok', [
                'flow_id' => $flow->id,
                'status' => $response->status(),
            ]);

            return ['success' => false, 'reply' => $this->fallbackMessage($flow), 'handoff' => true];
        }

        $content = (string) $response->json('choices.0.message.content', '');

        return $this->resolveActions($flow, $conversation, $content);
    }

    /**
     * Extrai o marcador de acao da resposta, executa o que estiver liberado e
     * devolve o texto limpo para o cliente.
     */
    private function resolveActions(BotFlow $flow, BotConversation $conversation, string $content): array
    {
        $handoff = str_contains($content, ':::HANDOFF:::');
        $text = trim(str_replace(':::HANDOFF:::', '', $content));

        if (preg_match(self::ACTION_PATTERN, $text, $match)) {
            $text = trim((string) preg_replace(self::ACTION_PATTERN, '', $text));

            $payload = json_decode($match[1], true);
            $action = is_array($payload) ? (string) ($payload['action'] ?? '') : '';

            if ($action !== '') {
                $result = $this->gateway->execute(
                    $flow,
                    $action,
                    is_array($payload['params'] ?? null) ? $payload['params'] : [],
                    $conversation->contact_phone,
                );

                $text = trim($text . "\n\n" . $result['message']);

                if (!$result['success']) {
                    $handoff = true;
                }
            }
        }

        if ($text === '') {
            $text = $this->fallbackMessage($flow);
            $handoff = true;
        }

        return ['success' => true, 'reply' => $text, 'handoff' => $handoff];
    }

    /**
     * Monta o prompt com tudo que o dono declarou sobre o negocio, o que a IA
     * deve e nao deve atender, e os pedidos reais do contato.
     */
    private function buildSystemPrompt(BotFlow $flow, BotConversation $conversation, array $orders): string
    {
        $c = $flow->ai_config ?? [];

        $lines = [];
        $lines[] = 'Voce e o atendente virtual de uma empresa e conversa com clientes pelo WhatsApp.';
        $lines[] = '';

        $lines[] = '## Sobre o negocio';
        $lines[] = 'Nome: ' . ($c['business_name'] ?? 'nao informado');
        if (!empty($c['business_description'])) {
            $lines[] = 'O que faz: ' . $c['business_description'];
        }
        if (!empty($c['products_description'])) {
            $lines[] = 'Produtos e servicos: ' . $c['products_description'];
        }
        if (!empty($c['support_hours'])) {
            $lines[] = 'Horario de atendimento: ' . $c['support_hours'];
        }
        if (!empty($c['refund_policy'])) {
            $lines[] = 'Politica de reembolso: ' . $c['refund_policy'];
        }
        $lines[] = '';

        $lines[] = '## Como falar';
        $lines[] = 'Tom: ' . ($c['tone'] ?? 'profissional e cordial');
        $lines[] = 'Idioma: portugues do Brasil.';
        $lines[] = 'Respostas curtas, no maximo 4 linhas, formato de mensagem de WhatsApp.';
        if (!empty($c['agent_name'])) {
            $lines[] = "Voce se chama {$c['agent_name']}. Use esse nome se perguntarem.";
        }
        $lines[] = '';

        if (!empty($c['topics'])) {
            $lines[] = '## O que voce ATENDE';
            $lines[] = $this->asList($c['topics']);
            $lines[] = '';
        }

        if (!empty($c['restrictions'])) {
            $lines[] = '## O que voce NAO faz';
            $lines[] = $this->asList($c['restrictions']);
            $lines[] = '';
        }

        if (!empty($c['faq']) && is_array($c['faq'])) {
            $lines[] = '## Perguntas frequentes (use como fonte de verdade)';
            foreach ($c['faq'] as $item) {
                $q = trim((string) ($item['question'] ?? ''));
                $a = trim((string) ($item['answer'] ?? ''));
                if ($q !== '' && $a !== '') {
                    $lines[] = "- P: {$q}\n  R: {$a}";
                }
            }
            $lines[] = '';
        }

        $lines[] = '## Dados reais deste cliente';
        $lines[] = 'Telefone: ' . $conversation->contact_phone;
        if ($conversation->contact_name) {
            $lines[] = 'Nome: ' . $conversation->contact_name;
        }
        if (empty($orders)) {
            $lines[] = 'Nenhum pedido encontrado para este telefone. Nao invente pedidos.';
        } else {
            $lines[] = 'Pedidos encontrados:';
            foreach ($orders as $o) {
                $lines[] = sprintf(
                    '- Pedido %s | %s | %s | %s | criado em %s%s',
                    $o['id'],
                    $o['product'] ?: 'sem produto',
                    $o['amount'],
                    $this->statusLabel($o['status']),
                    $o['created_at'] ?? '-',
                    $o['paid_at'] ? " | pago em {$o['paid_at']}" : '',
                );
            }
        }
        $lines[] = '';

        $lines[] = '## Regras inegociaveis';
        $lines[] = '- Nunca invente status de pedido, valor, prazo ou politica. Se o dado nao estiver acima, diga que vai verificar e acione a transferencia.';
        $lines[] = '- Nunca peca senha, codigo de cartao, CVV ou dados bancarios completos.';
        $lines[] = '- Nunca prometa reembolso, desconto ou prazo que nao esteja escrito acima.';
        $lines[] = '- Se o cliente pedir algo fora do que voce atende, ou demonstrar irritacao, transfira para humano.';
        $lines[] = '- Para transferir, responda ao cliente e inclua o marcador :::HANDOFF::: no fim da mensagem.';
        $lines[] = '';

        $allowed = array_values(array_filter(
            BotFlow::AI_ACTIONS,
            fn ($a) => $a !== 'lookup_order' && $flow->aiActionAllowed($a),
        ));

        if (!empty($allowed)) {
            $lines[] = '## Acoes que voce pode executar';
            $lines[] = 'Para executar, inclua no fim da mensagem exatamente:';
            $lines[] = ':::ACTION:::{"action":"nome_da_acao","params":{}}:::END_ACTION:::';
            $lines[] = 'Acoes disponiveis:';
            foreach ($allowed as $action) {
                $lines[] = '- ' . $action . ': ' . $this->actionDescription($action);
            }
            $lines[] = 'Use no maximo uma acao por mensagem, e so quando o cliente pedir claramente.';
        } else {
            $lines[] = '## Acoes';
            $lines[] = 'Voce nao pode executar nenhuma acao. Apenas informe e, se precisar de acao, transfira para humano.';
        }

        if (!empty($c['custom_instructions'])) {
            $lines[] = '';
            $lines[] = '## Instrucoes adicionais do dono';
            $lines[] = (string) $c['custom_instructions'];
        }

        return implode("\n", $lines);
    }

    private function actionDescription(string $action): string
    {
        return match ($action) {
            'resend_pix' => 'reenvia o codigo PIX do pedido pendente do cliente.',
            'resend_access' => 'reenvia o link de acesso da area de membros para quem ja pagou.',
            'create_payment_link' => 'gera link de pagamento de um produto. params: {"product":"nome"}.',
            default => '',
        };
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'paid', 'approved' => 'PAGO',
            'pending', 'waiting_payment' => 'AGUARDANDO PAGAMENTO',
            'expired' => 'EXPIRADO',
            'refunded' => 'REEMBOLSADO',
            'cancelled', 'canceled' => 'CANCELADO',
            'failed', 'refused', 'rejected' => 'RECUSADO',
            default => strtoupper($status),
        };
    }

    private function asList(mixed $value): string
    {
        if (is_array($value)) {
            return implode("\n", array_map(fn ($v) => '- ' . trim((string) $v), array_filter($value)));
        }

        return (string) $value;
    }

    private function fallbackMessage(BotFlow $flow): string
    {
        return (string) ($flow->ai_config['fallback_message']
            ?? 'Vou chamar um atendente para te ajudar, so um momento.');
    }
}
