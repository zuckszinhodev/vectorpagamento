<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\UtmifyIntegration;
use App\Services\UtmifyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class UtmifyIntegrationController extends Controller
{
    public function __construct(
        private UtmifyService $utmifyService,
    ) {}

    /**
     * GET /api/v1/integrations/utmify
     * Get the current account's UTMify integration (or null).
     */
    public function show(Request $request): JsonResponse
    {
        $accountId = $this->getAccountId($request);

        $integration = UtmifyIntegration::where('account_id', $accountId)->first();

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
     * POST /api/v1/integrations/utmify
     * Create or update the UTMify integration for the account.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'api_token' => 'required|string|min:10|max:255',
        ]);

        $accountId = $this->getAccountId($request);
        $apiToken  = $request->input('api_token');

        // Save the integration first — never block on external validation
        $integration = UtmifyIntegration::updateOrCreate(
            ['account_id' => $accountId],
            [
                'api_token' => $apiToken,
                'is_active' => true,
            ]
        );

        Log::info('UTMify integration saved', [
            'account_id'     => $accountId,
            'integration_id' => $integration->id,
        ]);

        // Optional background validation against UTMify API (non-blocking)
        $tokenWarning = null;
        try {
            $testResult = $this->utmifyService->testToken($apiToken);
            if (!$testResult['valid']) {
                $tokenWarning = 'Token salvo, porém não foi possível validar junto à UTMify. Verifique se o token está correto.';
                Log::warning('UTMify token validation failed after save', [
                    'account_id' => $accountId,
                    'status'     => $testResult['status'] ?? null,
                    'error'      => $testResult['error'] ?? $testResult['body'] ?? null,
                ]);
            }
        } catch (\Exception $e) {
            $tokenWarning = 'Token salvo, mas a validação com a UTMify não pôde ser realizada no momento.';
            Log::warning('UTMify token validation exception after save', [
                'account_id' => $accountId,
                'error'      => $e->getMessage(),
            ]);
        }

        return response()->json([
            'message' => $tokenWarning ?? 'Integração UTMify configurada com sucesso!',
            'warning' => $tokenWarning,
            'data' => [
                'id'           => $integration->id,
                'is_active'    => $integration->is_active,
                'masked_token' => $integration->masked_token,
            ],
        ], 201);
    }

    /**
     * PUT /api/v1/integrations/utmify/toggle
     * Toggle the integration on/off.
     */
    public function toggle(Request $request): JsonResponse
    {
        $accountId = $this->getAccountId($request);

        $integration = UtmifyIntegration::where('account_id', $accountId)->first();

        if (!$integration) {
            return response()->json(['message' => 'Integração UTMify não encontrada.'], 404);
        }

        $integration->update(['is_active' => !$integration->is_active]);

        return response()->json([
            'message'   => $integration->is_active ? 'Integração ativada.' : 'Integração desativada.',
            'is_active' => $integration->is_active,
        ]);
    }

    /**
     * DELETE /api/v1/integrations/utmify
     * Remove the UTMify integration.
     */
    public function destroy(Request $request): JsonResponse
    {
        $accountId = $this->getAccountId($request);

        $deleted = UtmifyIntegration::where('account_id', $accountId)->delete();

        if (!$deleted) {
            return response()->json(['message' => 'Integração UTMify não encontrada.'], 404);
        }

        return response()->json(['message' => 'Integração UTMify removida com sucesso.']);
    }

    /**
     * POST /api/v1/integrations/utmify/test
     * Test the token without saving.
     */
    public function test(Request $request): JsonResponse
    {
        $request->validate([
            'api_token' => 'required|string|min:10|max:255',
        ]);

        $result = $this->utmifyService->testToken($request->input('api_token'));

        return response()->json([
            'valid'           => $result['valid'],
            'status'          => $result['status'],
            'utmify_response' => $result['body'] ?? $result['error'] ?? null,
        ], $result['valid'] ? 200 : 422);
    }

    // ----------------------------------------------------------------

    private function getAccountId(Request $request): string
    {
        return $request->user()->account_id;
    }
}
