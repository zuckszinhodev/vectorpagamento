<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Charge;
use App\Services\LiveMapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LiveMapController extends Controller
{
    public function __construct(private LiveMapService $liveMapService)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $accountId = (string) $request->user()->account_id;
        $today = now()->startOfDay();

        $approvedCharges = Charge::query()
            ->where('account_id', $accountId)
            ->whereIn('status', ['approved', 'authorized'])
            ->where('created_at', '>=', $today);

        return response()->json([
            'success' => true,
            'data' => [
                'events' => $this->liveMapService->events($accountId),
                'summary' => [
                    'revenue_today' => (int) (clone $approvedCharges)->sum('amount'),
                    'sales_today' => (int) (clone $approvedCharges)->count(),
                    'visits_now' => $this->liveMapService->activeVisits($accountId),
                ],
            ],
        ]);
    }
}
