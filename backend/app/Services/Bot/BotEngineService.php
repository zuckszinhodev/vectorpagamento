<?php

declare(strict_types=1);

namespace App\Services\Bot;

use App\Models\BotConversation;
use App\Models\BotConversationMessage;
use App\Models\BotFlow;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Motor do bot de atendimento.
 *
 * Recebe uma mensagem do cliente e devolve as respostas a enviar. Nao conhece
 * WhatsApp: quem entrega e o chamador (job do webhook ou o simulador da tela),
 * o que torna o fluxo testavel sem numero conectado.
 */
class BotEngineService
{
    /** Trava contra fluxo mal montado que aponte para si mesmo. */
    private const MAX_STEPS = 25;

    /** Teto da espera agendada: 7 dias. */
    private const MAX_DELAY_SECONDS = 604800;

    public function __construct(
        private readonly BotAIService $ai,
        private readonly BotGatewayService $gateway,
        private readonly BotHttpService $http,
    ) {}

    /**
     * @return array{replies: string[], conversation: BotConversation, handoff: bool}
     */
    public function handleIncoming(
        string $accountId,
        string $contactPhone,
        string $message,
        ?string $contactName = null,
        bool $simulation = false,
    ): array {
        $conversation = $this->resolveConversation($accountId, $contactPhone, $contactName);

        // Mensagem nova cancela espera pendente: o cliente falou antes da hora.
        $conversation->cancelScheduledResume();

        $this->record($conversation, BotConversationMessage::DIRECTION_IN, $message);

        // Conversa entregue a um humano nao volta para o bot sozinha.
        if (!$conversation->isBotHandling()) {
            $conversation->save();

            return ['replies' => [], 'conversation' => $conversation, 'handoff' => true];
        }

        $flow = $this->resolveFlow($accountId, $message, $conversation);
        if (!$flow) {
            $conversation->save();

            return ['replies' => [], 'conversation' => $conversation, 'handoff' => false];
        }

        if (!$flow->withinBusinessHours()) {
            $offHours = (string) ($flow->ai_config['off_hours_message']
                ?? 'Nosso atendimento esta fora do horario. Retornamos assim que abrirmos.');

            return $this->finish($conversation, $flow, [$offHours], false);
        }

        if ($conversation->flow_id !== $flow->id) {
            $conversation->flow_id = $flow->id;
            $conversation->current_node_id = null;
            $flow->increment('conversations_count');
        }

        $result = $flow->isAi()
            ? $this->runAi($flow, $conversation, $message)
            : $this->runFlow($flow, $conversation, $message, $simulation);

        return $this->finish($conversation, $flow, $result['replies'], $result['handoff'], $result['scheduled'] ?? false);
    }

    /**
     * Retoma um fluxo parado num no de espera, sem mensagem do cliente.
     *
     * O token precisa bater com o gravado: se a conversa andou depois do
     * agendamento (cliente escreveu, foi transferida, outro delay entrou),
     * a retomada antiga e descartada em vez de duplicar mensagens.
     *
     * @return array{replies: string[], conversation: BotConversation, handoff: bool}|null
     */
    public function resume(BotConversation $conversation, string $token): ?array
    {
        if ($conversation->resume_token !== $token || !$conversation->resume_node_id) {
            Log::info('BotEngineService: retomada obsoleta descartada', [
                'conversation_id' => $conversation->id,
            ]);

            return null;
        }

        if (!$conversation->isBotHandling()) {
            return null;
        }

        $flow = $conversation->flow_id
            ? BotFlow::forAccount($conversation->account_id)->active()->find($conversation->flow_id)
            : null;

        if (!$flow || $flow->isAi()) {
            return null;
        }

        $startNode = $conversation->resume_node_id;
        $conversation->cancelScheduledResume();

        $result = $this->walk($flow, $conversation, $startNode, false);

        return $this->finish($conversation, $flow, $result['replies'], $result['handoff'], $result['scheduled'] ?? false);
    }

    // ================================================================
    //  MODO IA
    // ================================================================

    private function runAi(BotFlow $flow, BotConversation $conversation, string $message): array
    {
        $result = $this->ai->reply($flow, $conversation, $message);

        return ['replies' => [$result['reply']], 'handoff' => $result['handoff']];
    }

    // ================================================================
    //  MODO FLUXO
    // ================================================================

    /**
     * Caminha pela arvore a partir do no atual. Nos que falam seguem adiante
     * sozinhos; nos que perguntam param e esperam a proxima mensagem.
     */
    private function runFlow(BotFlow $flow, BotConversation $conversation, string $message, bool $simulation = false): array
    {
        $nodes = $flow->nodesById();
        if (empty($nodes)) {
            return ['replies' => [], 'handoff' => false];
        }

        // Se ja estava parado numa pergunta, a mensagem atual e a resposta dela.
        $current = $conversation->current_node_id;
        if ($current && isset($nodes[$current]) && ($nodes[$current]['type'] ?? '') === 'question') {
            $next = $this->answerQuestion($nodes[$current], $conversation, $message);
            if ($next === null) {
                $retry = (string) ($nodes[$current]['invalid_message'] ?? 'Nao entendi. Pode escolher uma das opcoes acima?');

                return ['replies' => [$retry], 'handoff' => false];
            }
            $nodeId = $next;
        } else {
            // Qualquer outra parada (ou nenhuma) recomeca do inicio.
            $nodeId = $flow->startNodeId();
        }

        return $this->walk($flow, $conversation, $nodeId, $simulation);
    }

    /**
     * Percorre os nos a partir de um ponto. Usado tanto pela mensagem recebida
     * quanto pela retomada agendada de um no de espera.
     */
    private function walk(BotFlow $flow, BotConversation $conversation, ?string $nodeId, bool $simulation): array
    {
        $nodes = $flow->nodesById();
        $replies = [];
        $handoff = false;

        $steps = 0;
        while ($nodeId && isset($nodes[$nodeId])) {
            if (++$steps > self::MAX_STEPS) {
                Log::warning('BotEngineService: limite de passos atingido', ['flow_id' => $flow->id]);
                break;
            }

            $node = $nodes[$nodeId];
            $type = (string) ($node['type'] ?? 'message');

            if ($type === 'message') {
                $replies[] = $this->render($node['text'] ?? '', $conversation);
                $nodeId = $node['next'] ?? null;
                continue;
            }

            if ($type === 'question') {
                $replies[] = $this->renderQuestion($node, $conversation);
                $conversation->current_node_id = $nodeId;

                return ['replies' => $replies, 'handoff' => false];
            }

            if ($type === 'condition') {
                $nodeId = $this->evaluateCondition($node, $flow, $conversation)
                    ? ($node['next_true'] ?? null)
                    : ($node['next_false'] ?? null);
                continue;
            }

            if ($type === 'action') {
                $result = $this->gateway->execute(
                    $flow,
                    (string) ($node['action'] ?? ''),
                    (array) ($node['params'] ?? []),
                    $conversation->contact_phone,
                );
                $replies[] = $result['message'];
                $nodeId = $result['success']
                    ? ($node['next'] ?? null)
                    : ($node['next_error'] ?? $node['next'] ?? null);
                continue;
            }

            if ($type === 'delay') {
                $seconds = $this->delaySeconds($node);
                $next = $node['next'] ?? null;

                // No teste ninguem espera de verdade: anota e segue.
                if ($simulation) {
                    $replies[] = '⏱ (aqui o bot esperaria ' . $this->humanizeDelay($seconds) . ')';
                    $nodeId = $next;
                    continue;
                }

                // Sem proximo no, esperar nao leva a lugar nenhum.
                if (!$next || !isset($nodes[$next])) {
                    break;
                }

                $conversation->resume_node_id = $next;
                $conversation->resume_at = now()->addSeconds($seconds);
                $conversation->resume_token = (string) Str::uuid();
                $conversation->current_node_id = null;

                return ['replies' => $replies, 'handoff' => $handoff, 'scheduled' => true];
            }

            // Define variaveis livres, com template resolvido na hora.
            if ($type === 'set') {
                foreach ((array) ($node['assignments'] ?? []) as $assignment) {
                    $key = trim((string) ($assignment['key'] ?? ''));
                    if ($key !== '') {
                        $conversation->putContext($key, $this->render((string) ($assignment['value'] ?? ''), $conversation));
                    }
                }
                $nodeId = $node['next'] ?? null;
                continue;
            }

            // Traz dados do gateway para dentro das variaveis, so se o usuario pediu.
            if ($type === 'system_data') {
                $this->loadSystemData($flow, $conversation);
                $nodeId = $node['next'] ?? null;
                continue;
            }

            if ($type === 'http') {
                $result = $this->callHttp($node, $conversation);
                if (!empty($node['reply_with_message'])) {
                    $replies[] = $result['message'];
                }
                $nodeId = $result['success']
                    ? ($node['next'] ?? null)
                    : ($node['next_error'] ?? $node['next'] ?? null);
                continue;
            }

            if ($type === 'ai') {
                $aiResult = $this->ai->reply($flow, $conversation, $this->lastUserMessage($conversation));
                $replies[] = $aiResult['reply'];
                $handoff = $handoff || $aiResult['handoff'];
                $nodeId = $node['next'] ?? null;
                continue;
            }

            if ($type === 'handoff') {
                $replies[] = $this->render($node['text'] ?? 'Vou te transferir para um atendente.', $conversation);
                $handoff = true;
                break;
            }

            if ($type === 'end') {
                if (!empty($node['text'])) {
                    $replies[] = $this->render($node['text'], $conversation);
                }
                $conversation->status = BotConversation::STATUS_ENDED;
                $conversation->current_node_id = null;

                return ['replies' => $replies, 'handoff' => false];
            }

            Log::warning('BotEngineService: tipo de no desconhecido', ['type' => $type]);
            break;
        }

        // Chegou ao fim da arvore: proxima mensagem recomeca do inicio.
        $conversation->current_node_id = null;

        return ['replies' => $replies, 'handoff' => $handoff];
    }

    /**
     * Casa a resposta do cliente com uma das opcoes da pergunta. Aceita o
     * numero da opcao ou parte do texto dela.
     */
    private function answerQuestion(array $node, BotConversation $conversation, string $message): ?string
    {
        $options = (array) ($node['options'] ?? []);
        $answer = mb_strtolower(trim($message));

        // Pergunta aberta: guarda o texto e segue.
        if (empty($options)) {
            if (!empty($node['save_as'])) {
                $conversation->putContext((string) $node['save_as'], trim($message));
            }

            return $node['next'] ?? null;
        }

        foreach ($options as $index => $option) {
            $label = mb_strtolower(trim((string) ($option['label'] ?? '')));
            $position = (string) ($index + 1);

            if ($answer === $position || ($label !== '' && str_contains($answer, $label))) {
                if (!empty($node['save_as'])) {
                    $conversation->putContext((string) $node['save_as'], $option['label'] ?? $answer);
                }

                return $option['next'] ?? null;
            }
        }

        return null;
    }

    /**
     * Condicoes do sistema consultam o gateway; as demais comparam qualquer
     * variavel da conversa com um operador, o que serve para automacao
     * generica sem depender de pagamento nenhum.
     */
    private function evaluateCondition(array $node, BotFlow $flow, BotConversation $conversation): bool
    {
        $subject = (string) ($node['subject'] ?? '');

        if (in_array($subject, ['has_order', 'has_paid_order', 'has_pending_order'], true)) {
            $orders = $this->gateway->ordersForPhone($flow->account_id, $conversation->contact_phone);
            $conversation->putContext('pedidos_total', count($orders));

            return match ($subject) {
                'has_paid_order' => (bool) array_filter($orders, fn ($o) => $o['status'] === 'paid'),
                'has_pending_order' => (bool) array_filter(
                    $orders,
                    fn ($o) => in_array($o['status'], ['pending', 'waiting_payment'], true),
                ),
                default => !empty($orders),
            };
        }

        // Condicao ainda nao configurada nao pode seguir pelo ramo "sim".
        if ($subject === '') {
            return false;
        }

        $actual = $this->lookup($subject, $conversation);
        $actualText = mb_strtolower(trim(is_scalar($actual) ? (string) $actual : ''));
        $expected = mb_strtolower(trim($this->render((string) ($node['value'] ?? $node['equals'] ?? ''), $conversation)));

        return match ((string) ($node['operator'] ?? 'equals')) {
            'not_equals' => $actualText !== $expected,
            'contains' => $expected !== '' && str_contains($actualText, $expected),
            'not_contains' => $expected === '' || !str_contains($actualText, $expected),
            'starts_with' => $expected !== '' && str_starts_with($actualText, $expected),
            'is_empty' => $actualText === '',
            'is_not_empty' => $actualText !== '',
            'greater_than' => is_numeric($actualText) && is_numeric($expected) && (float) $actualText > (float) $expected,
            'less_than' => is_numeric($actualText) && is_numeric($expected) && (float) $actualText < (float) $expected,
            default => $actualText === $expected,
        };
    }

    /** Copia dados do gateway para variaveis simples, usaveis em qualquer no. */
    private function loadSystemData(BotFlow $flow, BotConversation $conversation): void
    {
        $orders = $this->gateway->ordersForPhone($flow->account_id, $conversation->contact_phone);

        $conversation->putContext('pedidos_total', count($orders));
        $conversation->putContext('tem_pedido', empty($orders) ? 'nao' : 'sim');
        $conversation->putContext(
            'tem_pedido_pago',
            array_filter($orders, fn ($o) => $o['status'] === 'paid') ? 'sim' : 'nao',
        );

        $last = $orders[0] ?? null;
        $conversation->putContext('pedido_id', $last['id'] ?? '');
        $conversation->putContext('pedido_status', $last['status'] ?? '');
        $conversation->putContext('pedido_valor', $last['amount'] ?? '');
        $conversation->putContext('pedido_produto', $last['product'] ?? '');
        $conversation->putContext('pedido_criado_em', $last['created_at'] ?? '');
    }

    /**
     * Executa a chamada externa e guarda a resposta numa variavel, opcionalmente
     * recortando um caminho do JSON (ex: `dados.usuario.nome`).
     */
    private function callHttp(array $node, BotConversation $conversation): array
    {
        $headers = [];
        foreach ((array) ($node['headers'] ?? []) as $header) {
            $key = trim((string) ($header['key'] ?? ''));
            if ($key !== '') {
                $headers[$key] = $this->render((string) ($header['value'] ?? ''), $conversation);
            }
        }

        $result = $this->http->request(
            (string) ($node['method'] ?? 'GET'),
            $this->render((string) ($node['url'] ?? ''), $conversation),
            $headers,
            isset($node['body']) ? $this->render((string) $node['body'], $conversation) : null,
        );

        $saveAs = trim((string) ($node['save_as'] ?? ''));
        if ($saveAs !== '') {
            $value = $result['data'];

            $path = trim((string) ($node['save_path'] ?? ''));
            if ($path !== '' && is_array($value)) {
                $value = data_get($value, $path);
            }

            $conversation->putContext($saveAs, is_scalar($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE));
        }

        $conversation->putContext('http_status', $result['status'] ?? 0);

        return $result;
    }

    /** Espera em segundos, limitada para nao agendar retomada absurda. */
    private function delaySeconds(array $node): int
    {
        $amount = max(1, (int) ($node['duration'] ?? 1));

        $seconds = match ((string) ($node['unit'] ?? 'minutes')) {
            'seconds' => $amount,
            'hours' => $amount * 3600,
            'days' => $amount * 86400,
            default => $amount * 60,
        };

        return min($seconds, self::MAX_DELAY_SECONDS);
    }

    private function humanizeDelay(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . ' segundo' . ($seconds === 1 ? '' : 's');
        }
        if ($seconds < 3600) {
            $minutes = intdiv($seconds, 60);

            return $minutes . ' minuto' . ($minutes === 1 ? '' : 's');
        }
        if ($seconds < 86400) {
            $hours = intdiv($seconds, 3600);

            return $hours . ' hora' . ($hours === 1 ? '' : 's');
        }

        $days = intdiv($seconds, 86400);

        return $days . ' dia' . ($days === 1 ? '' : 's');
    }

    /** Resolve `pedido_status` ou `resposta.usuario.nome` no contexto. */
    private function lookup(string $key, BotConversation $conversation): mixed
    {
        $context = array_merge(
            ['nome' => $conversation->contact_name ?? '', 'telefone' => $conversation->contact_phone],
            $conversation->context ?? [],
        );

        return data_get($context, $key);
    }

    private function renderQuestion(array $node, BotConversation $conversation): string
    {
        $text = $this->render($node['text'] ?? '', $conversation);
        $options = (array) ($node['options'] ?? []);

        if (empty($options)) {
            return $text;
        }

        $lines = [$text, ''];
        foreach ($options as $index => $option) {
            $lines[] = ($index + 1) . '. ' . ($option['label'] ?? '');
        }

        return implode("\n", $lines);
    }

    /**
     * Substitui {{variavel}} pelo valor no contexto, aceitando caminho com
     * ponto para respostas de API guardadas como objeto. Variavel inexistente
     * vira string vazia, para nunca vazar `{{...}}` cru para o cliente.
     */
    private function render(string $text, BotConversation $conversation): string
    {
        if (!str_contains($text, '{{')) {
            return $text;
        }

        $context = array_merge(
            ['nome' => $conversation->contact_name ?? 'cliente', 'telefone' => $conversation->contact_phone],
            $conversation->context ?? [],
        );

        return (string) preg_replace_callback(
            '/\{\{\s*([\w.]+)\s*\}\}/',
            function (array $match) use ($context) {
                $value = data_get($context, $match[1]);

                if (is_scalar($value)) {
                    return (string) $value;
                }

                return is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : '';
            },
            $text,
        );
    }

    private function lastUserMessage(BotConversation $conversation): string
    {
        return (string) ($conversation->messages()
            ->where('direction', BotConversationMessage::DIRECTION_IN)
            ->orderByDesc('created_at')
            ->value('body') ?? '');
    }

    // ================================================================
    //  ESTADO
    // ================================================================

    private function resolveConversation(string $accountId, string $phone, ?string $name): BotConversation
    {
        $conversation = BotConversation::forAccount($accountId)
            ->where('contact_phone', $phone)
            ->first();

        if (!$conversation) {
            return BotConversation::create([
                'account_id' => $accountId,
                'contact_phone' => $phone,
                'contact_name' => $name,
                'context' => [],
                'status' => BotConversation::STATUS_ACTIVE,
            ]);
        }

        // Conversa encerrada volta a atender do zero.
        if ($conversation->status === BotConversation::STATUS_ENDED) {
            $conversation->status = BotConversation::STATUS_ACTIVE;
            $conversation->current_node_id = null;
            $conversation->context = [];
        }

        if ($name && !$conversation->contact_name) {
            $conversation->contact_name = $name;
        }

        return $conversation;
    }

    private function resolveFlow(string $accountId, string $message, BotConversation $conversation): ?BotFlow
    {
        // Conversa em andamento continua no mesmo bot, mesmo sem palavra-chave.
        if ($conversation->current_node_id && $conversation->flow_id) {
            $current = BotFlow::forAccount($accountId)->active()->find($conversation->flow_id);
            if ($current) {
                return $current;
            }
        }

        $flows = BotFlow::forAccount($accountId)->active()->orderBy('created_at')->get();

        foreach ($flows as $flow) {
            if ($flow->matchesTrigger($message)) {
                return $flow;
            }
        }

        return null;
    }

    private function finish(
        BotConversation $conversation,
        BotFlow $flow,
        array $replies,
        bool $handoff,
        bool $scheduled = false,
    ): array {
        foreach ($replies as $reply) {
            if (trim((string) $reply) !== '') {
                $this->record($conversation, BotConversationMessage::DIRECTION_OUT, (string) $reply);
            }
        }

        if ($handoff) {
            $conversation->status = BotConversation::STATUS_HANDOFF;
            $conversation->current_node_id = null;
        }

        $conversation->save();

        if (!empty($replies)) {
            $flow->increment('messages_count', count($replies));
        }

        return [
            'replies' => array_values(array_filter($replies, fn ($r) => trim((string) $r) !== '')),
            'conversation' => $conversation,
            'handoff' => $handoff,
            'scheduled' => $scheduled,
        ];
    }

    private function record(BotConversation $conversation, string $direction, string $body): void
    {
        if (!$conversation->exists) {
            $conversation->save();
        }

        BotConversationMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => $direction,
            'body' => $body,
            'node_id' => $conversation->current_node_id,
        ]);

        $conversation->message_count = ($conversation->message_count ?? 0) + 1;
        $conversation->last_message_at = now();
    }
}
