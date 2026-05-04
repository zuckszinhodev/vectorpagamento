<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\FacebookPixelIntegration;
use App\Services\FacebookConversionsApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FacebookPixelIntegrationController extends Controller
{
    public function __construct(
        private FacebookConversionsApiService $facebookService,
    ) {}

    /**
     * GET /api/v1/integrations/facebook-pixel
     * Get the current account's Facebook Pixel integration (or null).
     */
    public function show(Request $request): JsonResponse
    {
        try {
            $accountId = $this->getAccountId($request);

            $integration = FacebookPixelIntegration::where('account_id', $accountId)->first();

            if (!$integration) {
                return response()->json(['data' => null]);
            }

            return response()->json([
                'data' => [
                    'id'                      => $integration->id,
                    'pixel_id'                => $integration->pixel_id,
                    'is_active'               => $integration->is_active,
                    'masked_token'            => $integration->masked_token,
                    'test_event_code'         => $integration->test_event_code,
                    'track_purchase'          => $integration->track_purchase,
                    'track_initiate_checkout' => $integration->track_initiate_checkout,
                    'track_add_payment_info'  => $integration->track_add_payment_info,
                    'track_page_view'         => $integration->track_page_view,
                    'last_sent_at'            => $integration->last_sent_at?->toIso8601String(),
                    'last_status'             => $integration->last_status,
                    'success_count'           => $integration->success_count,
                    'failure_count'           => $integration->failure_count,
                    'created_at'              => $integration->created_at?->toIso8601String(),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('FacebookPixelIntegrationController::show failed', ['error' => $e->getMessage()]);
            return response()->json(['data' => null]);
        }
    }

    /**
     * POST /api/v1/integrations/facebook-pixel
     * Create or update the Facebook Pixel integration for the account.
     */
    public function store(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'pixel_id'                => 'required|string|regex:/^\d{10,20}$/',
                'access_token'            => 'required|string|min:10|max:500',
                'test_event_code'         => 'nullable|string|max:50',
                'track_purchase'          => 'nullable|boolean',
                'track_initiate_checkout' => 'nullable|boolean',
                'track_add_payment_info'  => 'nullable|boolean',
                'track_page_view'         => 'nullable|boolean',
            ]);

            $accountId = $this->getAccountId($request);

            $integration = FacebookPixelIntegration::updateOrCreate(
                ['account_id' => $accountId],
                [
                    'pixel_id'                => $request->input('pixel_id'),
                    'access_token'            => $request->input('access_token'),
                    'test_event_code'         => $request->input('test_event_code'),
                    'is_active'               => true,
                    'track_purchase'          => $request->input('track_purchase', true),
                    'track_initiate_checkout' => $request->input('track_initiate_checkout', true),
                    'track_add_payment_info'  => $request->input('track_add_payment_info', true),
                    'track_page_view'         => $request->input('track_page_view', true),
                ]
            );

            Log::info('Facebook Pixel integration saved', [
                'account_id'     => $accountId,
                'integration_id' => $integration->id,
                'pixel_id'       => $integration->pixel_id,
            ]);

            // Validate connection with Meta API (non-blocking)
            $warning = null;
            try {
                $testResult = $this->facebookService->testConnection(
                    $integration->pixel_id,
                    $request->input('access_token'),
                    $integration->test_event_code
                );

                if (!$testResult['valid']) {
                    $warning = 'Configuração salva, porém não foi possível validar junto ao Facebook. Verifique o Pixel ID e o Access Token. Erro: ' . ($testResult['error'] ?? 'Desconhecido');
                    Log::warning('Facebook Pixel validation failed after save', [
                        'account_id' => $accountId,
                        'status'     => $testResult['status'] ?? null,
                        'error'      => $testResult['error'] ?? null,
                    ]);
                }
            } catch (\Exception $e) {
                $warning = 'Configuração salva, mas a validação com o Facebook não pôde ser realizada no momento.';
                Log::warning('Facebook Pixel validation exception after save', [
                    'account_id' => $accountId,
                    'error'      => $e->getMessage(),
                ]);
            }

            return response()->json([
                'message' => $warning ?? 'Facebook Pixel configurado com sucesso!',
                'warning' => $warning,
                'data' => [
                    'id'           => $integration->id,
                    'pixel_id'     => $integration->pixel_id,
                    'is_active'    => $integration->is_active,
                    'masked_token' => $integration->masked_token,
                ],
            ], 201);
        } catch (\Throwable $e) {
            Log::error('FacebookPixelIntegrationController::store failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => ['code' => 'internal_error', 'message' => 'Erro ao salvar integração Facebook Pixel']], 500);
        }
    }

    /**
     * PUT /api/v1/integrations/facebook-pixel/toggle
     * Toggle the integration on/off.
     */
    public function toggle(Request $request): JsonResponse
    {
        try {
            $accountId = $this->getAccountId($request);

            $integration = FacebookPixelIntegration::where('account_id', $accountId)->first();

            if (!$integration) {
                return response()->json(['message' => 'Integração Facebook Pixel não encontrada.'], 404);
            }

            $integration->update(['is_active' => !$integration->is_active]);

            return response()->json([
                'message'   => $integration->is_active ? 'Integração ativada.' : 'Integração desativada.',
                'is_active' => $integration->is_active,
            ]);
        } catch (\Throwable $e) {
            Log::error('FacebookPixelIntegrationController::toggle failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => ['code' => 'internal_error', 'message' => 'Erro ao alternar integração']], 500);
        }
    }

    /**
     * PUT /api/v1/integrations/facebook-pixel/events
     * Update which events are tracked.
     */
    public function updateEvents(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'track_purchase'          => 'nullable|boolean',
                'track_initiate_checkout' => 'nullable|boolean',
                'track_add_payment_info'  => 'nullable|boolean',
                'track_page_view'         => 'nullable|boolean',
            ]);

            $accountId = $this->getAccountId($request);

            $integration = FacebookPixelIntegration::where('account_id', $accountId)->first();

            if (!$integration) {
                return response()->json(['message' => 'Integração Facebook Pixel não encontrada.'], 404);
            }

            $integration->update($request->only([
                'track_purchase',
                'track_initiate_checkout',
                'track_add_payment_info',
                'track_page_view',
            ]));

            return response()->json([
                'message' => 'Eventos atualizados com sucesso.',
                'data' => [
                    'track_purchase'          => $integration->track_purchase,
                    'track_initiate_checkout' => $integration->track_initiate_checkout,
                    'track_add_payment_info'  => $integration->track_add_payment_info,
                    'track_page_view'         => $integration->track_page_view,
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('FacebookPixelIntegrationController::updateEvents failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => ['code' => 'internal_error', 'message' => 'Erro ao atualizar eventos']], 500);
        }
    }

    /**
     * DELETE /api/v1/integrations/facebook-pixel
     * Remove the Facebook Pixel integration.
     */
    public function destroy(Request $request): JsonResponse
    {
        try {
            $accountId = $this->getAccountId($request);

            $deleted = FacebookPixelIntegration::where('account_id', $accountId)->delete();

            if (!$deleted) {
                return response()->json(['message' => 'Integração Facebook Pixel não encontrada.'], 404);
            }

            return response()->json(['message' => 'Integração Facebook Pixel removida com sucesso.']);
        } catch (\Throwable $e) {
            Log::error('FacebookPixelIntegrationController::destroy failed', ['error' => $e->getMessage()]);
            return response()->json(['success' => false, 'error' => ['code' => 'internal_error', 'message' => 'Erro ao remover integração']], 500);
        }
    }

    /**
     * POST /api/v1/integrations/facebook-pixel/test
     * Test the pixel + token without saving.
     */
    public function test(Request $request): JsonResponse
    {
        try {
            $request->validate([
                'pixel_id'        => 'required|string|regex:/^\d{10,20}$/',
                'access_token'    => 'required|string|min:10|max:500',
                'test_event_code' => 'nullable|string|max:50',
            ]);

            $result = $this->facebookService->testConnection(
                $request->input('pixel_id'),
                $request->input('access_token'),
                $request->input('test_event_code')
            );

            return response()->json([
                'valid'    => $result['valid'],
                'status'   => $result['status'],
                'message'  => $result['message'] ?? $result['error'] ?? null,
                'response' => $result['body'] ?? null,
            ], $result['valid'] ? 200 : 422);
        } catch (\Throwable $e) {
            Log::error('FacebookPixelIntegrationController::test failed', ['error' => $e->getMessage()]);
            return response()->json(['valid' => false, 'status' => 'error', 'message' => 'Erro ao testar conexão com Facebook'], 500);
        }
    }

    // ----------------------------------------------------------------

    private function getAccountId(Request $request): string
    {
        return $request->user()->account_id;
    }
}
