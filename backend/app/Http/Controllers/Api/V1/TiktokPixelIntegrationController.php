<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TiktokPixelIntegration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TiktokPixelIntegrationController extends Controller
{
    /**
     * GET /api/v1/integrations/tiktok-pixel
     */
    public function show(Request $request): JsonResponse
    {
        $accountId = $this->getAccountId($request);

        $integration = TiktokPixelIntegration::where('account_id', $accountId)->first();

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
                'track_complete_payment'  => $integration->track_complete_payment,
                'track_initiate_checkout' => $integration->track_initiate_checkout,
                'track_page_view'         => $integration->track_page_view,
                'last_sent_at'            => $integration->last_sent_at?->toIso8601String(),
                'last_status'             => $integration->last_status,
                'success_count'           => $integration->success_count,
                'failure_count'           => $integration->failure_count,
                'created_at'              => $integration->created_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * POST /api/v1/integrations/tiktok-pixel
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'pixel_id'        => 'required|string|min:5|max:50',
            'access_token'    => 'nullable|string|min:10|max:500',
            'test_event_code' => 'nullable|string|max:50',
        ]);

        $accountId = $this->getAccountId($request);

        $integration = TiktokPixelIntegration::updateOrCreate(
            ['account_id' => $accountId],
            [
                'pixel_id'        => $request->input('pixel_id'),
                'access_token'    => $request->input('access_token'),
                'test_event_code' => $request->input('test_event_code'),
                'is_active'       => true,
            ]
        );

        Log::info('TikTok Pixel integration saved', [
            'account_id'     => $accountId,
            'integration_id' => $integration->id,
            'pixel_id'       => $integration->pixel_id,
        ]);

        return response()->json([
            'message' => 'TikTok Pixel configurado com sucesso!',
            'data' => [
                'id'           => $integration->id,
                'pixel_id'     => $integration->pixel_id,
                'masked_token' => $integration->masked_token,
                'is_active'    => $integration->is_active,
            ],
        ]);
    }

    /**
     * PUT /api/v1/integrations/tiktok-pixel/toggle
     */
    public function toggle(Request $request): JsonResponse
    {
        $accountId = $this->getAccountId($request);
        $integration = TiktokPixelIntegration::where('account_id', $accountId)->firstOrFail();

        $integration->update(['is_active' => !$integration->is_active]);

        return response()->json([
            'message' => $integration->is_active ? 'TikTok Pixel ativado' : 'TikTok Pixel desativado',
            'data' => ['is_active' => $integration->is_active],
        ]);
    }

    /**
     * DELETE /api/v1/integrations/tiktok-pixel
     */
    public function destroy(Request $request): JsonResponse
    {
        $accountId = $this->getAccountId($request);
        TiktokPixelIntegration::where('account_id', $accountId)->delete();

        Log::info('TikTok Pixel integration removed', ['account_id' => $accountId]);

        return response()->json(['message' => 'TikTok Pixel removido com sucesso']);
    }

    private function getAccountId(Request $request): string
    {
        return $request->user()->account_id;
    }
}
