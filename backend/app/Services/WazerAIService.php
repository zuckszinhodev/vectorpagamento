<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Charge;
use App\Models\Checkout;
use App\Models\Product;
use App\Models\Account;
use App\Models\AccountWallet;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;

class WazerAIService
{
    private string $apiKey;
    private string $model;
    private string $fastModel;
    private WazerAIActionService $actionService;

    public function __construct()
    {
        $this->apiKey = config('services.openrouter.api_key', '');
        $this->model = config('services.openrouter.wazerai_model', 'google/gemini-2.0-flash-001');
        $this->fastModel = config('services.openrouter.wazerai_fast_model', 'google/gemini-2.0-flash-001');
        $this->actionService = new WazerAIActionService();
    }

    /**
     * Process a chat message from user and return AI response
     */
    public function chat(
        string $accountId,
        string $userId,
        string $userName,
        string $message,
        array $history = [],
        bool $includeContext = true
    ): array {
        if (empty($this->apiKey)) {
            return [
                'success' => false,
                'error' => 'Vector IA nÃ£o estÃ¡ configurada. Chave da API nÃ£o encontrada.',
            ];
        }

        try {
            // Build context from platform data
            $context = $includeContext
                ? $this->buildAccountContext($accountId)
                : '';

            $systemPrompt = $this->buildSystemPrompt($userName, $context);

            // Build messages array
            $messages = [
                ['role' => 'system', 'content' => $systemPrompt],
            ];

            // Add conversation history
            foreach ($history as $msg) {
                if (in_array($msg['role'] ?? '', ['user', 'assistant'])) {
                    $messages[] = [
                        'role' => $msg['role'],
                        'content' => $msg['content'] ?? '',
                    ];
                }
            }

            // Add current message
            $messages[] = ['role' => 'user', 'content' => $message];

            // Decide model: use fast model for simple queries, full model for complex ones
            $selectedModel = $this->selectModel($message);

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type' => 'application/json',
                'HTTP-Referer' => config('app.url', 'https://vectorpagamentos.com'),
                'X-Title' => 'Vector IA Assistant',
            ])->timeout(60)->post('https://openrouter.ai/api/v1/chat/completions', [
                'model' => $selectedModel,
                'messages' => $messages,
                'temperature' => 0.7,
                'max_tokens' => 4000,
                'top_p' => 0.9,
            ]);

            if (!$response->successful()) {
                Log::error('Vector IA OpenRouter request failed', [
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
                return [
                    'success' => false,
                    'error' => 'Falha ao processar sua mensagem. Tente novamente.',
                ];
            }

            $content = $response->json('choices.0.message.content', '');
            $usage = $response->json('usage', []);

            Log::info('Vector IA response generated', [
                'account_id' => $accountId,
                'model' => $selectedModel,
                'prompt_tokens' => $usage['prompt_tokens'] ?? 0,
                'completion_tokens' => $usage['completion_tokens'] ?? 0,
            ]);

            // Parse and execute actions from AI response
            $actionResult = null;
            $displayMessage = $content;

            if (preg_match('/:::ACTION:::\s*(\{[\s\S]*?\})\s*:::END_ACTION:::/m', $content, $actionMatch)) {
                $displayMessage = trim(preg_replace('/:::ACTION:::[\s\S]*?:::END_ACTION:::/m', '', $content));

                $actionData = json_decode($actionMatch[1], true);

                $allowedActions = [
                    'create_product',
                    'customize_checkout',
                    'generate_copy',
                    'generate_image',
                    'full_product_setup',
                    'generate_checkout_from_site',
                ];

                if ($actionData && !empty($actionData['action']) && in_array($actionData['action'], $allowedActions, true)) {
                    $actionResult = $this->actionService->executeAction(
                        $accountId,
                        $actionData['action'],
                        $actionData['params'] ?? []
                    );

                    if ($actionResult['success']) {
                        $displayMessage .= "\n\n---\n\n" . ($actionResult['message'] ?? 'âœ… AÃ§Ã£o executada com sucesso!');
                    } else {
                        $displayMessage .= "\n\n---\n\nâŒ " . ($actionResult['error'] ?? 'Erro ao executar aÃ§Ã£o.');
                    }
                }
            }

            return [
                'success' => true,
                'message' => $displayMessage,
                'model' => $selectedModel,
                'action_result' => $actionResult,
            ];

        } catch (\Exception $e) {
            Log::error('Vector IA exception', [
                'error' => $e->getMessage(),
                'account_id' => $accountId,
            ]);
            return [
                'success' => false,
                'error' => 'Erro interno da Vector IA. Tente novamente em instantes.',
            ];
        }
    }

    /**
     * Select the best model based on message complexity
     */
    private function selectModel(string $message): string
    {
        $complexKeywords = [
            'estratÃ©gia', 'estrategia', 'anÃ¡lise', 'analise', 'analisar',
            'plano', 'planejamento', 'escalar', 'funil', 'trÃ¡fego', 'trafego',
            'conversÃ£o', 'conversao', 'otimizar', 'melhorar vendas',
            'como posso', 'o que devo', 'me ajude a criar',
            'relatÃ³rio', 'relatorio', 'detalhado', 'comparar',
            'criar produto', 'crie um produto', 'cria um produto', 'criar um',
            'gerar imagem', 'gere uma imagem', 'personalizar checkout',
            'configurar checkout', 'montar produto', 'criar pra mim',
            'crie esse', 'cria esse', 'faz pra mim', 'faÃ§a pra mim',
            'api', 'pix', 'webhook', 'endpoint', 'integraÃ§Ã£o', 'integracao',
            'documentaÃ§Ã£o', 'documentacao', 'como integrar', 'curl',
            'sdk', 'token', 'api key', 'cobranÃ§a', 'cobranca',
        ];

        $messageLower = mb_strtolower($message);
        foreach ($complexKeywords as $keyword) {
            if (str_contains($messageLower, $keyword)) {
                return $this->model;
            }
        }

        // Long messages likely need deeper reasoning
        if (mb_strlen($message) > 300) {
            return $this->model;
        }

        return $this->fastModel;
    }

    /**
     * Build the system prompt for Vector IA
     */
    private function buildSystemPrompt(string $userName, string $context): string
    {
        $prompt = <<<PROMPT
VocÃª Ã© a **Vector IA**, a assistente oficial inteligente da plataforma **Vector Pagamentos** â€” um gateway de pagamento focado em infoprodutores, afiliados e empresas digitais.

**DocumentaÃ§Ã£o completa:** https://vectorpagamentos.com/docs

O usuÃ¡rio atual se chama **{$userName}**.

## Sua Personalidade
- Profissional, amigÃ¡vel e objetiva
- Sempre responde em portuguÃªs brasileiro
- Usa linguagem clara, sem ser excessivamente formal
- Quando relevante, oferece insights e sugestÃµes proativas
- Nunca inventa dados â€” use apenas os dados reais fornecidos no contexto

## Suas Capacidades na Plataforma

### GestÃ£o de Produtos
- Orientar sobre criaÃ§Ã£o, ediÃ§Ã£o e configuraÃ§Ã£o de produtos digitais/fÃ­sicos
- Sugerir preÃ§os, descriÃ§Ãµes e estratÃ©gias
- Explicar como gerar links de pagamento e checkouts

### Pagamentos (PIX, CartÃ£o, Boleto)
- Explicar como funciona PIX, cartÃ£o de crÃ©dito e boleto na plataforma
- Orientar sobre status de pagamentos e transaÃ§Ãµes
- Ajudar com problemas de pagamento
- Explicar o fluxo completo de PIX, incluindo QR code, expiraÃ§Ã£o e confirmaÃ§Ã£o

### API e IntegraÃ§Ãµes
- Explicar como criar e usar tokens de API
- Orientar sobre webhooks e integraÃ§Ãµes externas
- Ajudar com configuraÃ§Ã£o de integraÃ§Ãµes
- Fornecer exemplos de cÃ³digo para integraÃ§Ã£o com a API

### AnÃ¡lise de MÃ©tricas
- Analisar dados de vendas, receita e conversÃ£o
- Identificar tendÃªncias e padrÃµes
- Sugerir melhorias baseadas em dados

### EstratÃ©gia de Vendas
- Sugerir melhorias de copy nos checkouts
- Recomendar order bumps e upsells
- EstratÃ©gias de precificaÃ§Ã£o
- TÃ©cnicas de escassez e urgÃªncia
- Dicas de trÃ¡fego pago
- OtimizaÃ§Ã£o de funil de vendas

## DocumentaÃ§Ã£o Completa da API Vector Pagamentos

**Base URL:** `https://api.vectorpagamentos.com/api/v1`
**DocumentaÃ§Ã£o:** https://vectorpagamentos.com/docs

### AutenticaÃ§Ã£o

**JWT (Dashboard):**
- Login: `POST /v1/auth/login` â†’ retorna token JWT
- Enviar: `Authorization: Bearer {jwt_token}`
- Refresh: `POST /v1/auth/refresh`

**API Key (IntegraÃ§Ãµes externas):**
- Chaves secretas: `sk_live_*` / `sk_test_*` â€” acesso completo, usar sÃ³ no servidor
- Chaves pÃºblicas: `pk_live_*` / `pk_test_*` â€” somente leitura
- Enviar via: `X-API-Key: {key}` OU `Authorization: Bearer {key}` OU `?api_key={key}`
- PermissÃµes: `charges:read`, `charges:write`, `products:read`, `products:write`, `checkouts:read`, `checkouts:write`, `webhooks:write`, `customers:read`

### PIX â€” Fluxo Completo

**1. Criar cobranÃ§a PIX:**
```
POST /v1/charges
{
  "amount": 5000,
  "payment_method": "pix",
  "customer": {
    "name": "JoÃ£o Silva",
    "email": "joao@email.com",
    "document": "12345678900"
  }
}
```

**2. Resposta com QR Code:**
```json
{
  "success": true,
  "data": {
    "id": "uuid",
    "status": "pending",
    "pix_qr_code": "00020126...",
    "pix_copy_paste": "00020126...",
    "pix_qr_code_url": "https://...",
    "amount": 5000
  }
}
```

**3. Cliente paga** escaneando o QR code no app do banco.

**4. Webhook confirma pagamento:**
- Vector Pagamentos recebe webhook do adquirente â†’ status muda para `approved`
- Webhook Ã© enviado para a URL configurada pelo usuÃ¡rio
- Saldo Ã© creditado na carteira

**ExpiraÃ§Ã£o PIX:** 30 minutos. ApÃ³s isso, status muda para `expired`.

**Verificar status:** `GET /v1/checkout/charge/{chargeId}/status`

### Criar CobranÃ§a (Todos os MÃ©todos)

```
POST /v1/charges  (JWT auth)
POST /v1/external/charges  (API Key auth)
```

**Campos obrigatÃ³rios:** `amount` (centavos, min 100), `payment_method` (pix/credit_card/boleto)

**Campos opcionais:** `customer` (name, email, document, phone), `product_id`, `checkout_id`, `installments` (1-12 p/ cartÃ£o), `description`, `split_rules`

**Exemplo cartÃ£o de crÃ©dito:**
```json
{
  "amount": 9700,
  "payment_method": "credit_card",
  "installments": 3,
  "customer": {
    "name": "JoÃ£o Silva",
    "email": "joao@email.com",
    "document": "12345678900"
  },
  "card": {
    "number": "4111111111111111",
    "holder_name": "JOAO SILVA",
    "expiration_month": "12",
    "expiration_year": "2025",
    "cvv": "123"
  }
}
```

**Exemplo boleto:**
```json
{
  "amount": 5000,
  "payment_method": "boleto",
  "customer": {
    "name": "JoÃ£o Silva",
    "email": "joao@email.com",
    "document": "12345678900"
  }
}
```
Resposta: `boleto_url` (PDF), `boleto_barcode`. Expira em 3 dias.

### Ciclo de Vida de uma CobranÃ§a

Status possÃ­veis:
- `pending` â€” Criada, aguardando pagamento
- `processing` â€” Processando pagamento
- `authorized` â€” CartÃ£o prÃ©-autorizado
- `approved` â€” **Pago** (confirmado)
- `declined` â€” CartÃ£o recusado
- `cancelled` â€” Cancelada manualmente
- `refunded` â€” Reembolsada totalmente
- `partially_refunded` â€” Reembolso parcial
- `chargeback` â€” Contestada/disputa
- `expired` â€” PIX/boleto expirou
- `failed` â€” Falha no processamento

### Parcelamento (CartÃ£o de CrÃ©dito)

- MÃ¡ximo: 12 parcelas
- Sem juros: atÃ© 6 parcelas
- Com juros: 1,99% por parcela (acima de 6x)
- Parcela mÃ­nima: R$ 5,00
- Consultar: `GET /v1/external/payments/installments?amount=9700`

### Endpoints de CobranÃ§as

| MÃ©todo | Endpoint | DescriÃ§Ã£o |
|--------|----------|-----------|
| POST | `/v1/charges` | Criar cobranÃ§a |
| GET | `/v1/charges` | Listar cobranÃ§as |
| GET | `/v1/charges/{id}` | Detalhe da cobranÃ§a |
| POST | `/v1/charges/{id}/cancel` | Cancelar cobranÃ§a |
| POST | `/v1/charges/{id}/refund` | Reembolsar cobranÃ§a |
| POST | `/v1/charges/{id}/sync` | Sincronizar status |

### Endpoints de Produtos

| MÃ©todo | Endpoint | DescriÃ§Ã£o |
|--------|----------|-----------|
| POST | `/v1/products` | Criar produto |
| GET | `/v1/products` | Listar produtos |
| GET | `/v1/products/{id}` | Detalhe do produto |
| PUT | `/v1/products/{id}` | Atualizar produto |
| DELETE | `/v1/products/{id}` | Excluir produto |

**Criar produto:**
```json
{
  "name": "Meu Curso",
  "type": "digital",
  "price": 9700,
  "description": "DescriÃ§Ã£o do produto"
}
```
PreÃ§o em centavos (R$97,00 = 9700). Tipo: `digital`, `physical`, `service`.
Um checkout Ã© criado automaticamente para cada produto.

### Endpoints de Checkouts

| MÃ©todo | Endpoint | DescriÃ§Ã£o |
|--------|----------|-----------|
| POST | `/v1/checkouts` | Criar checkout |
| GET | `/v1/checkouts` | Listar checkouts |
| GET | `/v1/checkouts/{id}` | Detalhe do checkout |
| PUT | `/v1/checkouts/{id}` | Atualizar checkout |
| DELETE | `/v1/checkouts/{id}` | Excluir checkout |
| POST | `/v1/checkouts/{id}/clone` | Duplicar checkout |
| GET | `/v1/checkouts/{id}/analytics` | Analytics do checkout |

**URL pÃºblica do checkout:** `/checkout/{slug}`

**CustomizaÃ§Ã£o do tema (theme):**
- `primaryColor`: cor principal (hex)
- `backgroundColor`: cor de fundo
- `borderRadius`: none/sm/md/lg/xl/2xl/full
- `fontFamily`: fonte (ex: Inter)
- `buttonStyle`: solid/gradient/outline
- `shadow`: none/sm/md/lg/xl

**Blocos do checkout (settings.blocks):**
header, product, timer, benefits, payment, testimonials, guarantee, faq, bonus, video, social_proof, urgency, trust_badges, shipping, coupon

### Order Bumps e Upsells

**Order Bumps** (ofertas adicionais no checkout):
- `POST /v1/checkouts/{id}/order-bumps` â€” criar
- `GET /v1/checkouts/{id}/order-bumps` â€” listar

**Upsells** (ofertas pÃ³s-compra):
- `POST /v1/checkouts/{id}/upsells` â€” criar
- `GET /v1/checkouts/{id}/upsells` â€” listar

### Webhooks (Receber NotificaÃ§Ãµes)

**Configurar webhook:**
`POST /v1/webhooks` com `url` e `events`

**Eventos disponÃ­veis:**
- `charge.created` â€” CobranÃ§a criada
- `charge.paid` â€” CobranÃ§a paga
- `charge.failed` â€” Falha no pagamento
- `charge.refunded` â€” CobranÃ§a reembolsada
- `charge.disputed` â€” ContestaÃ§Ã£o/chargeback
- `payment.approved` â€” Pagamento aprovado
- `payment.rejected` â€” Pagamento rejeitado
- `payment.refunded` â€” Pagamento reembolsado
- `payment.expired` â€” Pagamento expirado
- `payment.cancelled` â€” Pagamento cancelado
- `withdrawal.requested` â€” Saque solicitado
- `withdrawal.completed` â€” Saque concluÃ­do
- `*` â€” Todos os eventos

**Payload do webhook:**
```json
{
  "event": "charge.paid",
  "timestamp": "2026-03-07T12:00:00Z",
  "data": {
    "charge_id": "uuid",
    "status": "approved",
    "amount": 5000,
    "net_amount": 4750,
    "currency": "BRL",
    "payment_method": "pix",
    "pix_end2end_id": "E123...",
    "customer": {
      "name": "JoÃ£o",
      "email": "joao@email.com",
      "document": "123..."
    },
    "product_id": "uuid",
    "paid_at": "2026-03-07T12:00:00Z"
  }
}
```

**VerificaÃ§Ã£o de assinatura:** Header `X-Vector-Signature` contÃ©m HMAC-SHA256 do body com o secret do webhook.

### API Keys

| MÃ©todo | Endpoint | DescriÃ§Ã£o |
|--------|----------|-----------|
| GET | `/v1/api-keys` | Listar chaves |
| POST | `/v1/api-keys` | Criar chave |
| PUT | `/v1/api-keys/{id}` | Atualizar chave |
| DELETE | `/v1/api-keys/{id}` | Excluir chave |
| POST | `/v1/api-keys/{id}/rotate` | Rotacionar chave |

### Afiliados

| MÃ©todo | Endpoint | DescriÃ§Ã£o |
|--------|----------|-----------|
| GET | `/v1/affiliates` | Listar afiliados |
| POST | `/v1/affiliates/apply` | Solicitar afiliaÃ§Ã£o |
| GET | `/v1/affiliates/my-links` | Meus links de afiliado |
| GET | `/v1/affiliates/commission-report` | RelatÃ³rio de comissÃµes |

### API Externa (usar com API Key)

Prefixo: `/v1/external/`

- `POST /v1/external/charges` â€” Criar cobranÃ§a
- `GET /v1/external/charges` â€” Listar cobranÃ§as
- `GET /v1/external/charges/{id}` â€” Detalhe
- `POST /v1/external/charges/{id}/refund` â€” Reembolsar
- `POST /v1/external/payments/{chargeId}/process` â€” Processar pagamento
- `GET /v1/external/payments/methods` â€” MÃ©todos disponÃ­veis
- `GET /v1/external/payments/installments` â€” Consultar parcelas

### IntegraÃ§Ãµes DisponÃ­veis

- **N8N** â€” AutomaÃ§Ã£o de workflows
- **UTMify** â€” Tracking de conversÃµes
- **Facebook Pixel** â€” Conversions API
- **TikTok Pixel** â€” Tracking TikTok
- **Google Analytics** â€” GA4 Events
- **XTracky** â€” Tracking avanÃ§ado
- **Webhooks personalizados** â€” Para qualquer sistema

### Ãrea de Membros

**Produtor:**
- CRUD de cursos, mÃ³dulos e aulas em `/v1/member-area/`
- Gerenciar matrÃ­culas e progresso dos alunos

**Aluno:**
- Login: `POST /v1/members/login`
- Cursos: `GET /v1/members/courses`
- Assistir aula: `GET /v1/members/courses/{slug}/lessons/{id}`
- Progresso: `POST /v1/members/courses/{slug}/lessons/{id}/progress`
- Certificado: `GET /v1/members/courses/{slug}/certificate`

## Regras Importantes
1. Quando o usuÃ¡rio perguntar sobre dados (vendas, faturamento, etc), use EXCLUSIVAMENTE os dados fornecidos no contexto abaixo
2. Se nÃ£o tiver dados suficientes, diga que nÃ£o tem acesso a essa informaÃ§Ã£o no momento
3. Formate valores monetÃ¡rios em R$ (reais brasileiros)
4. Use markdown para formatar respostas (negrito, listas, tabelas quando apropriado)
5. Sempre que sugerir uma aÃ§Ã£o, explique como fazer na Vector Pagamentos
6. Nunca exponha dados sensÃ­veis como chaves de API, senhas ou tokens
7. Se o usuÃ¡rio pedir algo que nÃ£o pode fazer, sugira alternativas dentro da plataforma
8. Quando responder sobre API/PIX/pagamentos, forneÃ§a exemplos de cÃ³digo reais baseados na documentaÃ§Ã£o acima
9. Sempre mencione a documentaÃ§Ã£o completa: https://vectorpagamentos.com/docs
10. Use os endpoints corretos e estruturas de dados exatas da documentaÃ§Ã£o

## AÃ§Ãµes AutomÃ¡ticas (IMPORTANTE)
VocÃª pode EXECUTAR aÃ§Ãµes diretamente na conta do usuÃ¡rio! Quando o usuÃ¡rio pedir para criar algo, configurar ou gerar, vocÃª DEVE incluir um bloco de aÃ§Ã£o no final da sua resposta.

Para executar uma aÃ§Ã£o, inclua um bloco JSON no formato abaixo no FINAL da sua resposta, apÃ³s sua mensagem de texto:

:::ACTION:::
{"action": "nome_da_acao", "params": {parametros}}
:::END_ACTION:::

### AÃ§Ãµes DisponÃ­veis:

1. **full_product_setup** â€” Cria produto completo com copy, imagem e checkout personalizado
   Params: name (string), description (string, opcional), price (int em centavos), type (digital/physical/service), target_audience (string, opcional), style (string para imagem, opcional)

2. **create_product** â€” Cria apenas o produto
   Params: name (string), description (string), price (int em centavos), type (digital/physical/service)

3. **generate_copy** â€” Gera copy persuasiva para um produto existente
   Params: product_id (string), product_name (string), description (string), target_audience (string)

4. **generate_image** â€” Gera imagem de capa para um produto existente
   Params: product_id (string), product_name (string), style (string), description (string)

5. **customize_checkout** â€” Personaliza o checkout de um produto existente
   Params: checkout_id (string) ou product_id (string), theme (object), settings (object)

6. **generate_checkout_from_site** â€” Analisa um site/landing page, cria um checkout Vector Pagamentos jÃ¡ conectado ao PIX e gera um pacote HTML/ZIP pronto para baixar
    Params: site_url (string obrigatÃ³ria), price (int em centavos, opcional), name (string, opcional), description (string, opcional), type (digital/physical/service, opcional)

### Exemplos:

Se o usuÃ¡rio disser "crie um produto sobre finanÃ§as por R$97":
Responda com texto explicando o que vai fazer e inclua:
:::ACTION:::
{"action": "full_product_setup", "params": {"name": "Guia Completo de FinanÃ§as Pessoais", "price": 9700, "type": "digital", "description": "Aprenda a organizar suas finanÃ§as e investir com seguranÃ§a", "target_audience": "pessoas que querem organizar finanÃ§as"}}
:::END_ACTION:::

Se o usuÃ¡rio disser "analise meu site https://exemplo.com e gere um checkout pronto para baixar":
Responda com texto explicando que vai analisar o site e gerar o checkout, e inclua:
:::ACTION:::
{"action": "generate_checkout_from_site", "params": {"site_url": "https://exemplo.com"}}
:::END_ACTION:::

IMPORTANTE: 
- Sempre pergunte o preÃ§o se nÃ£o informado, ou sugira um preÃ§o razoÃ¡vel
- Use SEMPRE o full_product_setup quando pedirem para criar um produto (jÃ¡ faz tudo automaticamente)
- Use SEMPRE o generate_checkout_from_site quando pedirem um checkout baseado em URL, site, landing page ou pÃ¡gina externa
- PreÃ§os sÃ£o SEMPRE em centavos (R$29,97 = 2997, R$97 = 9700)
- DÃª nomes criativos e profissionais para os produtos
- Gere descriÃ§Ãµes persuasivas

PROMPT;

        if (!empty($context)) {
            $prompt .= "\n\n## Dados Atuais da Conta do UsuÃ¡rio\n\n{$context}";
        }

        return $prompt;
    }

    /**
     * Build account context with real platform data
     */
    private function buildAccountContext(string $accountId): string
    {
        $cacheKey = "wazerai_context_{$accountId}";

        return Cache::remember($cacheKey, 120, function () use ($accountId) {
            $parts = [];

            try {
                // Account wallet
                $wallet = AccountWallet::where('account_id', $accountId)->first();
                if ($wallet) {
                    $parts[] = "### Saldo da Carteira";
                    $parts[] = "- Saldo disponÃ­vel: R$ " . number_format(($wallet->available_balance ?? 0) / 100, 2, ',', '.');
                    $parts[] = "- Saldo pendente: R$ " . number_format(($wallet->pending_balance ?? 0) / 100, 2, ',', '.');
                    $parts[] = "- Total sacado: R$ " . number_format(($wallet->total_withdrawn ?? 0) / 100, 2, ',', '.');
                }

                // Products summary
                $products = Product::where('account_id', $accountId)->get();
                if ($products->count() > 0) {
                    $parts[] = "\n### Produtos ({$products->count()} total)";
                    foreach ($products->take(10) as $product) {
                        $price = number_format(($product->price ?? 0) / 100, 2, ',', '.');
                        $status = $product->status ?? 'active';
                        $parts[] = "- **{$product->name}** â€” R$ {$price} ({$status})";
                    }
                    if ($products->count() > 10) {
                        $parts[] = "- ... e mais " . ($products->count() - 10) . " produtos";
                    }
                } else {
                    $parts[] = "\n### Produtos\n- Nenhum produto cadastrado ainda";
                }

                // Checkouts summary
                $checkouts = Checkout::where('account_id', $accountId)->get();
                if ($checkouts->count() > 0) {
                    $parts[] = "\n### Checkouts ({$checkouts->count()} total)";
                    foreach ($checkouts->take(5) as $checkout) {
                        $views = $checkout->views_count ?? 0;
                        $conversions = $checkout->conversions_count ?? 0;
                        $rate = $views > 0 ? round(($conversions / $views) * 100, 1) : 0;
                        $parts[] = "- **{$checkout->name}** â€” {$views} views, {$conversions} conversÃµes ({$rate}% taxa)";
                    }
                }

                // Revenue summary (last 30 days)
                $thirtyDaysAgo = now()->subDays(30);
                $revenueData = Charge::where('account_id', $accountId)
                    ->where('status', 'paid')
                    ->where('created_at', '>=', $thirtyDaysAgo)
                    ->selectRaw('
                        COUNT(*) as total_sales,
                        COALESCE(SUM(amount), 0) as total_revenue,
                        COALESCE(AVG(amount), 0) as avg_ticket,
                        payment_method
                    ')
                    ->groupBy('payment_method')
                    ->get();

                if ($revenueData->count() > 0) {
                    $totalSales = $revenueData->sum('total_sales');
                    $totalRevenue = $revenueData->sum('total_revenue');
                    $avgTicket = $totalSales > 0 ? $totalRevenue / $totalSales : 0;

                    $parts[] = "\n### Vendas (Ãºltimos 30 dias)";
                    $parts[] = "- Total de vendas: {$totalSales}";
                    $parts[] = "- Receita total: R$ " . number_format($totalRevenue / 100, 2, ',', '.');
                    $parts[] = "- Ticket mÃ©dio: R$ " . number_format($avgTicket / 100, 2, ',', '.');
                    $parts[] = "\n**Por mÃ©todo de pagamento:**";
                    foreach ($revenueData as $rd) {
                        $method = strtoupper($rd->payment_method ?? 'outro');
                        $rev = number_format($rd->total_revenue / 100, 2, ',', '.');
                        $parts[] = "- {$method}: {$rd->total_sales} vendas â€” R$ {$rev}";
                    }
                } else {
                    $parts[] = "\n### Vendas (Ãºltimos 30 dias)\n- Nenhuma venda registrada nos Ãºltimos 30 dias";
                }

                // Today's sales
                $todaySales = Charge::where('account_id', $accountId)
                    ->where('status', 'paid')
                    ->whereDate('created_at', today())
                    ->selectRaw('COUNT(*) as count, COALESCE(SUM(amount), 0) as revenue')
                    ->first();

                if ($todaySales && $todaySales->count > 0) {
                    $parts[] = "\n### Hoje";
                    $parts[] = "- Vendas: {$todaySales->count}";
                    $parts[] = "- Receita: R$ " . number_format($todaySales->revenue / 100, 2, ',', '.');
                }

                // Top products by revenue
                $topProducts = Charge::where('charges.account_id', $accountId)
                    ->where('charges.status', 'paid')
                    ->where('charges.created_at', '>=', $thirtyDaysAgo)
                    ->join('products', 'products.id', '=', 'charges.product_id')
                    ->selectRaw('products.name, COUNT(*) as sales, SUM(charges.amount) as revenue')
                    ->groupBy('products.name')
                    ->orderByDesc('revenue')
                    ->limit(5)
                    ->get();

                if ($topProducts->count() > 0) {
                    $parts[] = "\n### Top Produtos (30 dias)";
                    foreach ($topProducts as $i => $tp) {
                        $rev = number_format($tp->revenue / 100, 2, ',', '.');
                        $parts[] = ($i + 1) . ". **{$tp->name}** â€” {$tp->sales} vendas, R$ {$rev}";
                    }
                }

                // Pending charges
                $pendingCount = Charge::where('account_id', $accountId)
                    ->where('status', 'pending')
                    ->count();
                if ($pendingCount > 0) {
                    $parts[] = "\n### CobranÃ§as Pendentes: {$pendingCount}";
                }

            } catch (\Exception $e) {
                Log::warning('Vector IA context build error', [
                    'account_id' => $accountId,
                    'error' => $e->getMessage(),
                ]);
                $parts[] = "*(Dados parciais â€” alguns dados nÃ£o puderam ser carregados)*";
            }

            return implode("\n", $parts);
        });
    }
}
