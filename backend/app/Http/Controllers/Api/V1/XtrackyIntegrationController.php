<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\XtrackyIntegration;
use App\Services\XtrackyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class XtrackyIntegrationController extends Controller
{
    public function __construct(
        private XtrackyService $xtrackyService,
    ) {}

    /**
     * GET /api/v1/integrations/xtracky
     */
    public function show(Request $request): JsonResponse
    {
        $accountId = $this->getAccountId($request);

        $integration = XtrackyIntegration::where('account_id', $accountId)->first();

        if (!$integration) {
            return response()->json(['data' => null]);
        }

        return response()->json([
            'data' => [
                'id'            => $integration->id,
                'is_active'     => $integration->is_active,
                'masked_token'  => $integration->masked_token,
                'last_sent_at'  => $integration->last_sent_at?->toIso8601String(),
                'last_status'   => $integration->last_status,
                'success_count' => $integration->success_count,
                'failure_count' => $integration->failure_count,
                'created_at'    => $integration->created_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * POST /api/v1/integrations/xtracky
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'api_token' => 'required|string|min:10|max:255',
        ]);

        $accountId = $this->getAccountId($request);
        $apiToken  = $request->input('api_token');

        $integration = XtrackyIntegration::updateOrCreate(
            ['account_id' => $accountId],
            [
                'api_token' => $apiToken,
                'is_active' => true,
            ]
        );

        Log::info('XTracky integration saved', [
            'account_id'     => $accountId,
            'integration_id' => $integration->id,
        ]);

        // Optional background validation (non-blocking)
        $tokenWarning = null;
        try {
            $testResult = $this->xtrackyService->testToken($apiToken);
            if (!$testResult['valid']) {
                $tokenWarning = 'Token salvo, porém não foi possível validar junto à XTracky. Verifique se o token está correto.';
                Log::warning('XTracky token validation failed after save', [
                    'account_id' => $accountId,
                    'status'     => $testResult['status'] ?? null,
                    'error'      => $testResult['error'] ?? $testResult['body'] ?? null,
                ]);
            }
        } catch (\Exception $e) {
            $tokenWarning = 'Token salvo, mas a validação com a XTracky não pôde ser realizada no momento.';
            Log::warning('XTracky token validation exception after save', [
                'account_id' => $accountId,
                'error'      => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message' => $tokenWarning ?? 'Integração XTracky configurada com sucesso!',
            'warning' => $tokenWarning,
            'data' => [
                'id'           => $integration->id,
                'is_active'    => $integration->is_active,
                'masked_token' => $integration->masked_token,
            ],
        ], 201);
    }

    /**
     * PUT /api/v1/integrations/xtracky/toggle
     */
    public function toggle(Request $request): JsonResponse
    {
        $accountId = $this->getAccountId($request);

        $integration = XtrackyIntegration::where('account_id', $accountId)->first();

        if (!$integration) {
            return response()->json(['message' => 'Integração XTracky não encontrada.'], 404);
        }

        $integration->update(['is_active' => !$integration->is_active]);

        return response()->json([
            'message'   => $integration->is_active ? 'Integração ativada.' : 'Integração desativada.',
            'is_active' => $integration->is_active,
        ]);
    }

    /**
     * DELETE /api/v1/integrations/xtracky
     */
    public function destroy(Request $request): JsonResponse
    {
        $accountId = $this->getAccountId($request);

        $deleted = XtrackyIntegration::where('account_id', $accountId)->delete();

        if (!$deleted) {
            return response()->json(['message' => 'Integração XTracky não encontrada.'], 404);
        }

        return response()->json(['message' => 'Integração XTracky removida com sucesso.']);
    }

    /**
     * POST /api/v1/integrations/xtracky/test
     */
    public function test(Request $request): JsonResponse
    {
        $request->validate([
            'api_token' => 'required|string|min:10|max:255',
        ]);

        $result = $this->xtrackyService->testToken($request->input('api_token'));

        return response()->json([
            'valid'            => $result['valid'],
            'status'           => $result['status'],
            'xtracky_response' => $result['body'] ?? $result['error'] ?? null,
        ], $result['valid'] ? 200 : 422);
    }

    // ----------------------------------------------------------------

    private function getAccountId(Request $request): string
    {
        return $request->user()->account_id;
    }
}
