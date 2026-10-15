<?php

declare(strict_types=1);

namespace App\Services\Bot;

use App\Models\BotFlow;
use App\Models\Charge;
use App\Models\Checkout;
use App\Models\Product;
use Illuminate\Support\Facades\Log;

/**
 * Ponte entre o bot de atendimento e os dados reais do gateway.
 *
 * Leitura (pedidos do contato) alimenta o contexto do bot em toda conversa.
 * Escrita/entrega de dado sensivel so roda via execute() e apenas quando o
 * dono do bot habilitou a acao na configuracao.
 */
class BotGatewayService
{
    /** Quantas cobrancas recentes do contato entram no contexto. */
    private const MAX_ORDERS = 5;

    /**
     * Cobrancas do contato, identificadas pelo telefone do cliente.
     *
     * O telefone fica dentro do JSON `customer`, em formato livre. Pre-filtra
     * no banco pelos ultimos 4 digitos (contiguos em qualquer formatacao) e
     * confirma em PHP comparando so os digitos.
     */
    public function ordersForPhone(string $accountId, string $phone): array
    {
        $digits = $this->onlyDigits($phone);
        if (strlen($digits) < 8) {
            return [];
        }

        try {
            $candidates = Charge::where('account_id', $accountId)
                ->where('customer->phone', 'like', '%' . substr($digits, -4))
                ->orderByDesc('created_at')
                ->limit(50)
                ->get();
        } catch (\Throwable $e) {
            Log::warning('BotGatewayService: falha ao consultar cobrancas', [
                'account_id' => $accountId,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $matches = [];
        foreach ($candidates as $charge) {
            $chargePhone = $this->onlyDigits((string) ($charge->customer['phone'] ?? ''));
            if ($chargePhone === '' || !$this->samePhone($digits, $chargePhone)) {
                continue;
            }

            $matches[] = $this->summarizeCharge($charge);
            if (count($matches) >= self::MAX_ORDERS) {
                break;
            }
        }

        return $matches;
    }

    /**
     * Executa uma acao no gateway, respeitando a allowlist do bot.
     *
     * @return array{success:bool, message:string}
     */
    public function execute(BotFlow $flow, string $action, array $params, string $contactPhone): array
    {
        if (!$flow->aiActionAllowed($action)) {
            Log::info('BotGatewayService: acao bloqueada pela configuracao', [
                'flow_id' => $flow->id,
                'action' => $action,
            ]);

            return [
                'success' => false,
                'message' => 'Essa acao nao esta habilitada para este atendimento.',
            ];
        }

        try {
            return match ($action) {
                'resend_pix' => $this->resendPix($flow->account_id, $contactPhone),
                'resend_access' => $this->resendAccess($flow->account_id, $contactPhone),
                'create_payment_link' => $this->createPaymentLink($flow->account_id, $params),
                default => ['success' => false, 'message' => 'Acao desconhecida.'],
            };
        } catch (\Throwable $e) {
            Log::error('BotGatewayService: acao falhou', [
                'flow_id' => $flow->id,
                'action' => $action,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => 'Nao consegui completar essa acao agora.'];
        }
    }

    /** Copia e cola do PIX da cobranca pendente mais recente do contato. */
    private function resendPix(string $accountId, string $phone): array
    {
        $orders = $this->ordersForPhone($accountId, $phone);

        foreach ($orders as $order) {
            if (in_array($order['status'], ['pending', 'waiting_payment'], true) && !empty($order['pix_code'])) {
                return [
                    'success' => true,
                    'message' => "Segue o codigo PIX do pedido {$order['id']}:\n\n{$order['pix_code']}",
                ];
            }
        }

        return ['success' => false, 'message' => 'Nao encontrei nenhum PIX pendente nesse numero.'];
    }

    /** Link da area de membros, apenas para quem tem cobranca paga. */
    private function resendAccess(string $accountId, string $phone): array
    {
        $orders = $this->ordersForPhone($accountId, $phone);

        $paid = array_filter($orders, fn ($o) => $o['status'] === 'paid');
        if (empty($paid)) {
            return ['success' => false, 'message' => 'Nao encontrei nenhuma compra aprovada nesse numero.'];
        }

        $baseUrl = rtrim((string) (config('app.frontend_url') ?? config('app.url')), '/');

        return [
            'success' => true,
            'message' => "Seu acesso esta liberado. Entre em {$baseUrl}/membros usando o e-mail da compra.",
        ];
    }

    /** Link de checkout de um produto ativo da conta. */
    private function createPaymentLink(string $accountId, array $params): array
    {
        $productName = trim((string) ($params['product'] ?? $params['product_name'] ?? ''));

        $query = Product::where('account_id', $accountId)->where('is_active', true);
        if ($productName !== '') {
            $query->where('name', 'like', "%{$productName}%");
        }

        $product = $query->orderByDesc('created_at')->first();
        if (!$product) {
            return ['success' => false, 'message' => 'Nao encontrei esse produto.'];
        }

        $checkout = Checkout::where('account_id', $accountId)
            ->where('product_id', $product->id)
            ->where('is_active', true)
            ->orderByDesc('created_at')
            ->first();

        if (!$checkout) {
            return ['success' => false, 'message' => 'Esse produto ainda nao tem checkout publicado.'];
        }

        return [
            'success' => true,
            'message' => "Link de pagamento de {$product->name}: {$checkout->url}",
        ];
    }

    private function summarizeCharge(Charge $charge): array
    {
        return [
            'id' => $charge->id,
            'status' => (string) $charge->status,
            'amount' => 'R$ ' . number_format(((int) $charge->amount) / 100, 2, ',', '.'),
            'payment_method' => (string) $charge->payment_method,
            'product' => $charge->product?->name ?? $charge->description ?? '',
            'created_at' => $charge->created_at?->format('d/m/Y H:i'),
            'paid_at' => $charge->paid_at?->format('d/m/Y H:i'),
            'pix_code' => $charge->pix_copy_paste ?? $charge->pix_qr_code ?? null,
        ];
    }

    private function onlyDigits(string $value): string
    {
        return preg_replace('/\D/', '', $value) ?? '';
    }

    /**
     * Compara telefones ignorando DDI e zeros a esquerda: basta os ultimos
     * 8 digitos baterem, que e a parte estavel entre formatos brasileiros.
     */
    private function samePhone(string $a, string $b): bool
    {
        return substr($a, -8) === substr($b, -8);
    }
}
