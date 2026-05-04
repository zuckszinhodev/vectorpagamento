<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\N8nIntegration;
use App\Services\N8nWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;

class N8nIntegrationController extends Controller
{
    private N8nWebhookService $webhookService;

    public function __construct(N8nWebhookService $webhookService)
    {
        $this->webhookService = $webhookService;
    }

    /**
     * List all n8n integrations for the authenticated user
     */
    public function index(Request $request): JsonResponse
    {
        try {
        $query = N8nIntegration::query();

        // Filter by account if not admin
        $user = Auth::user();
        if (!$user->isAdmin()) {
            $query->where('account_id', $user->account_id);
        }

        // Apply filters
        if ($request->filled('status')) {
            $query->where('is_active', $request->status === 'active');
        }

        if ($request->filled('event')) {
            $query->forEvent($request->event);
        }

        // Pagination
        $perPage = min($request->input('per_page', 15), 100);
        $integrations = $query->orderBy('created_at', 'desc')->paginate($perPage);

        // Add stats to each integration
        $integrations->getCollection()->transform(function ($integration) {
            $integration->stats = $this->webhookService->getStats($integration);
            return $integration;
        });

        return response()->json([
            'success' => true,
            'data' => $integrations,
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('N8nIntegrationController::index error', [
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
     * Create a new n8n integration
     */
    public function store(Request $request): JsonResponse
    {
        try {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:1000',
            'webhook_url' => 'required|url|max:2048',
            'events' => 'required|array|min:1',
            'events.*' => 'string|in:' . implode(',', array_merge(
                array_keys(N8nIntegration::getAvailableEvents()),
                ['*'],
                array_map(fn($cat) => "{$cat}.*", array_keys(N8nIntegration::getEventCategories()))
            )),
            'is_active' => 'boolean',
            'retry_count' => 'integer|min:0|max:10',
            'retry_delay' => 'integer|min:10|max:3600',
            'timeout' => 'integer|min:5|max:120',
            'headers' => 'nullable|array',
            'metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = Auth::user();

        $integration = N8nIntegration::create([
            'name' => $request->name,
            'description' => $request->description,
            'account_id' => $user->isAdmin() ? $request->input('account_id') : $user->account_id,
            'webhook_url' => $request->webhook_url,
            'events' => $request->events,
            'secret_key' => N8nIntegration::generateSecretKey(),
            'is_active' => $request->input('is_active', true),
            'retry_count' => $request->input('retry_count', 3),
            'retry_delay' => $request->input('retry_delay', 60),
            'timeout' => $request->input('timeout', 30),
            'headers' => $request->input('headers', []),
            'metadata' => $request->input('metadata', []),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Integração n8n criada com sucesso',
            'data' => $integration,
            'secret_key' => $integration->secret_key, // Only returned once on creation
        ], 201);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('N8nIntegrationController::store error', [
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
     * Show a specific n8n integration
     */
    public function show(string $integrationId): JsonResponse
    {
        try {
        $integration = $this->getIntegration($integrationId);

        if (!$integration) {
            return response()->json([
                'success' => false,
                'message' => 'Integração não encontrada',
            ], 404);
        }

        $integration->stats = $this->webhookService->getStats($integration);

        return response()->json([
            'success' => true,
            'data' => $integration,
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('N8nIntegrationController::show error', [
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
     * Update an n8n integration
     */
    public function update(Request $request, string $integrationId): JsonResponse
    {
        try {
        $integration = $this->getIntegration($integrationId);

        if (!$integration) {
            return response()->json([
                'success' => false,
                'message' => 'Integração não encontrada',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'string|max:255',
            'description' => 'nullable|string|max:1000',
            'webhook_url' => 'url|max:2048',
            'events' => 'array|min:1',
            'events.*' => 'string|in:' . implode(',', array_merge(
                array_keys(N8nIntegration::getAvailableEvents()),
                ['*'],
                array_map(fn($cat) => "{$cat}.*", array_keys(N8nIntegration::getEventCategories()))
            )),
            'is_active' => 'boolean',
            'retry_count' => 'integer|min:0|max:10',
            'retry_delay' => 'integer|min:10|max:3600',
            'timeout' => 'integer|min:5|max:120',
            'headers' => 'nullable|array',
            'metadata' => 'nullable|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos',
                'errors' => $validator->errors(),
            ], 422);
        }

        $integration->update($request->only([
            'name',
            'description',
            'webhook_url',
            'events',
            'is_active',
            'retry_count',
            'retry_delay',
            'timeout',
            'headers',
            'metadata',
        ]));

        return response()->json([
            'success' => true,
            'message' => 'Integração atualizada com sucesso',
            'data' => $integration->fresh(),
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('N8nIntegrationController::update error', [
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
     * Delete an n8n integration
     */
    public function destroy(string $integrationId): JsonResponse
    {
        try {
        $integration = $this->getIntegration($integrationId);

        if (!$integration) {
            return response()->json([
                'success' => false,
                'message' => 'Integração não encontrada',
            ], 404);
        }

        $integration->delete();

        return response()->json([
            'success' => true,
            'message' => 'Integração removida com sucesso',
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('N8nIntegrationController::destroy error', [
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
     * Regenerate secret key
     */
    public function regenerateSecret(string $integrationId): JsonResponse
    {
        try {
        $integration = $this->getIntegration($integrationId);

        if (!$integration) {
            return response()->json([
                'success' => false,
                'message' => 'Integração não encontrada',
            ], 404);
        }

        $newSecret = N8nIntegration::generateSecretKey();
        $integration->update(['secret_key' => $newSecret]);

        return response()->json([
            'success' => true,
            'message' => 'Secret key regenerada com sucesso',
            'secret_key' => $newSecret,
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('N8nIntegrationController::regenerateSecret error', [
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
     * Test webhook connection
     */
    public function test(string $integrationId): JsonResponse
    {
        try {
        $integration = $this->getIntegration($integrationId);

        if (!$integration) {
            return response()->json([
                'success' => false,
                'message' => 'Integração não encontrada',
            ], 404);
        }

        $result = $this->webhookService->test($integration);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['success'] ? 'Teste enviado com sucesso' : 'Falha no teste',
            'data' => $result,
        ], $result['success'] ? 200 : 400);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('N8nIntegrationController::test error', [
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
     * Get delivery logs for an integration
     */
    public function logs(Request $request, string $integrationId): JsonResponse
    {
        try {
        $integration = $this->getIntegration($integrationId);

        if (!$integration) {
            return response()->json([
                'success' => false,
                'message' => 'Integração não encontrada',
            ], 404);
        }

        // In a real implementation, you'd have a delivery_logs table
        // For now, return basic stats
        return response()->json([
            'success' => true,
            'data' => [
                'stats' => $this->webhookService->getStats($integration),
                'last_response' => $integration->last_response,
                'last_status' => $integration->last_status,
                'last_triggered_at' => $integration->last_triggered_at?->toIso8601String(),
            ],
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('N8nIntegrationController::logs error', [
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
     * Get available events list
     */
    public function events(): JsonResponse
    {
        try {
        return response()->json([
            'success' => true,
            'data' => [
                'events' => N8nIntegration::getAvailableEvents(),
                'categories' => N8nIntegration::getEventCategories(),
            ],
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('N8nIntegrationController::events error', [
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
     * Toggle integration status
     */
    public function toggle(string $integrationId): JsonResponse
    {
        try {
        $integration = $this->getIntegration($integrationId);

        if (!$integration) {
            return response()->json([
                'success' => false,
                'message' => 'Integração não encontrada',
            ], 404);
        }

        $integration->update(['is_active' => !$integration->is_active]);

        return response()->json([
            'success' => true,
            'message' => $integration->is_active ? 'Integração ativada' : 'Integração desativada',
            'data' => $integration->fresh(),
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('N8nIntegrationController::toggle error', [
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
     * Manual trigger for testing
     */
    public function trigger(Request $request, string $integrationId): JsonResponse
    {
        try {
        $integration = $this->getIntegration($integrationId);

        if (!$integration) {
            return response()->json([
                'success' => false,
                'message' => 'Integração não encontrada',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'event' => 'required|string',
            'data' => 'required|array',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos',
                'errors' => $validator->errors(),
            ], 422);
        }

        if (!$integration->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'Integração está desativada',
            ], 400);
        }

        $result = $this->webhookService->send($integration, $request->event, $request->data);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['success'] ? 'Webhook enviado com sucesso' : 'Falha ao enviar webhook',
            'data' => $result,
        ], $result['success'] ? 200 : 400);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('N8nIntegrationController::trigger error', [
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
     * Get integration by ID with access control
     */
    private function getIntegration(string $id): ?N8nIntegration
    {
        $user = Auth::user();

        $query = N8nIntegration::where('id', $id);

        if (!$user->isAdmin()) {
            $query->where('account_id', $user->account_id);
        }

        return $query->first();
    }
}
