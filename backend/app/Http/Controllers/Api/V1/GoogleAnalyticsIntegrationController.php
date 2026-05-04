<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\GoogleAnalyticsIntegration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class GoogleAnalyticsIntegrationController extends Controller
{
    /**
     * GET /api/v1/integrations/google-analytics
     */
    public function show(Request $request): JsonResponse
    {
        $accountId = $this->getAccountId($request);

        $integration = GoogleAnalyticsIntegration::where('account_id', $accountId)->first();

        if (!$integration) {
            return response()->json(['data' => null]);
        }

        return response()->json([
            'data' => [
                'id'                   => $integration->id,
                'measurement_id'       => $integration->measurement_id,
                'is_active'            => $integration->is_active,
                'masked_secret'        => $integration->masked_secret,
                'track_purchase'       => $integration->track_purchase,
                'track_begin_checkout' => $integration->track_begin_checkout,
                'track_page_view'      => $integration->track_page_view,
                'last_sent_at'         => $integration->last_sent_at?->toIso8601String(),
                'last_status'          => $integration->last_status,
                'success_count'        => $integration->success_count,
                'failure_count'        => $integration->failure_count,
                'created_at'           => $integration->created_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * POST /api/v1/integrations/google-analytics
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'measurement_id' => ['required', 'string', 'regex:/^G-[A-Z0-9]+$/'],
            'api_secret'     => 'nullable|string|min:5|max:200',
        ]);

        $accountId = $this->getAccountId($request);

        $integration = GoogleAnalyticsIntegration::updateOrCreate(
            ['account_id' => $accountId],
            [
                'measurement_id' => $request->input('measurement_id'),
                'api_secret'     => $request->input('api_secret'),
                'is_active'      => true,
            ]
        );

        Log::info('Google Analytics integration saved', [
            'account_id'     => $accountId,
            'integration_id' => $integration->id,
            'measurement_id' => $integration->measurement_id,
        ]);

        return response()->json([
            'message' => 'Google Analytics configurado com sucesso!',
            'data' => [
                'id'             => $integration->id,
                'measurement_id' => $integration->measurement_id,
                'masked_secret'  => $integration->masked_secret,
                'is_active'      => $integration->is_active,
            ],
        ]);
    }

    /**
     * PUT /api/v1/integrations/google-analytics/toggle
     */
    public function toggle(Request $request): JsonResponse
    {
        $accountId = $this->getAccountId($request);
        $integration = GoogleAnalyticsIntegration::where('account_id', $accountId)->firstOrFail();

        $integration->update(['is_active' => !$integration->is_active]);

        return response()->json([
            'message' => $integration->is_active ? 'Google Analytics ativado' : 'Google Analytics desativado',
            'data' => ['is_active' => $integration->is_active],
        ]);
    }

    /**
     * DELETE /api/v1/integrations/google-analytics
     */
    public function destroy(Request $request): JsonResponse
    {
        $accountId = $this->getAccountId($request);
        GoogleAnalyticsIntegration::where('account_id', $accountId)->delete();

        Log::info('Google Analytics integration removed', ['account_id' => $accountId]);

        return response()->json(['message' => 'Google Analytics removido com sucesso']);
    }

    private function getAccountId(Request $request): string
    {
        return $request->user()->account_id;
    }
}
