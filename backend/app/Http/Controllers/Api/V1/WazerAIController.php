<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\WazerAIService;
use App\Services\WazerAIActionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WazerAIController extends Controller
{
    private WazerAIService $aiService;
    private WazerAIActionService $actionService;

    public function __construct(WazerAIService $aiService, WazerAIActionService $actionService)
    {
        $this->aiService = $aiService;
        $this->actionService = $actionService;
    }

    /**
     * Send a chat message to WazerAI
     */
    public function chat(Request $request): JsonResponse
    {
        try {
        $validated = $request->validate([
            'message' => ['required', 'string', 'max:2000'],
            'history' => ['nullable', 'array', 'max:50'],
            'history.*.role' => ['required_with:history', 'in:user,assistant'],
            'history.*.content' => ['required_with:history', 'string', 'max:4000'],
        ]);

        $user = $request->user();

        if (!$user || !$user->account_id) {
            return response()->json([
                'success' => false,
                'error' => 'Usuário não autenticado',
            ], 401);
        }

        $result = $this->aiService->chat(
            accountId: $user->account_id,
            userId: $user->id,
            userName: $user->name ?? 'Usuário',
            message: $validated['message'],
            history: $validated['history'] ?? [],
        );

        if (!$result['success']) {
            $status = str_contains($result['error'] ?? '', 'não está configurada') ? 422 : 500;
            return response()->json([
                'success' => false,
                'error' => $result['error'],
            ], $status);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'message' => $result['message'],
                'model' => $result['model'] ?? null,
                'action_result' => $result['action_result'] ?? null,
            ],
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('WazerAIController::chat error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * Execute a direct AI action
     */
    public function executeAction(Request $request): JsonResponse
    {
        try {
        $validated = $request->validate([
            'action' => ['required', 'string', 'in:create_product,customize_checkout,generate_copy,generate_image,full_product_setup,generate_checkout_from_site'],
            'params' => ['required', 'array'],
        ]);

        $user = $request->user();

        if (!$user || !$user->account_id) {
            return response()->json([
                'success' => false,
                'error' => 'Usuário não autenticado',
            ], 401);
        }

        $result = $this->actionService->executeAction(
            $user->account_id,
            $validated['action'],
            $validated['params'],
        );

        if (!$result['success']) {
            return response()->json([
                'success' => false,
                'error' => $result['error'] ?? 'Erro ao executar ação',
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => $result,
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('WazerAIController::executeAction error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * Get quick suggestions for the chat
     */
    public function suggestions(Request $request): JsonResponse
    {
        try {
        $user = $request->user();

        $baseSuggestions = [
            ['text' => 'Como estão minhas vendas?', 'category' => 'metrics', 'icon' => 'chart-bar'],
            ['text' => 'Crie um produto completo pra mim', 'category' => 'action', 'icon' => 'rocket'],
            ['text' => 'Quais são meus produtos?', 'category' => 'products', 'icon' => 'cube'],
            ['text' => 'Qual meu faturamento do mês?', 'category' => 'metrics', 'icon' => 'currency-dollar'],
            ['text' => 'Gere uma copy para meu produto', 'category' => 'action', 'icon' => 'tag'],
            ['text' => 'Personalize meu checkout', 'category' => 'action', 'icon' => 'shopping-cart'],
            ['text' => 'Como melhorar minha conversão?', 'category' => 'strategy', 'icon' => 'arrow-trending-up'],
            ['text' => 'Estratégias para vender mais', 'category' => 'strategy', 'icon' => 'rocket'],
            ['text' => 'Como integrar a API?', 'category' => 'help', 'icon' => 'cog'],
            ['text' => 'Qual meu ticket médio?', 'category' => 'metrics', 'icon' => 'chart-pie'],
            ['text' => 'Analise meu site e gere um checkout pronto para baixar', 'category' => 'action', 'icon' => 'link'],
        ];

        // Add product-specific suggestions if user has products
        if ($user && $user->account_id) {
            $products = \App\Models\Product::where('account_id', $user->account_id)
                ->orderByDesc('updated_at')
                ->limit(3)
                ->get(['name', 'price']);

            foreach ($products as $product) {
                $price = 'R$ ' . number_format(($product->price ?? 0) / 100, 2, ',', '.');
                $baseSuggestions[] = [
                    'text' => "Alterar preço do {$product->name} (atual: {$price})",
                    'category' => 'products',
                    'icon' => 'tag',
                ];
            }
        }

        return response()->json([
            'success' => true,
            'data' => $baseSuggestions,
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('WazerAIController::suggestions error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }
}
