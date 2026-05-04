<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\SavedCard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SavedCardController extends Controller
{
    /**
     * List saved cards for authenticated account, filtered by customer email.
     */
    public function index(Request $request): JsonResponse
    {
        $accountId = $request->user()->account_id;

        $query = SavedCard::where('account_id', $accountId)
            ->select(['id', 'customer_email', 'card_brand', 'card_last_four', 'card_holder_name', 'exp_month', 'exp_year', 'is_default', 'created_at'])
            ->orderByDesc('created_at');

        if ($request->filled('email')) {
            $query->where('customer_email', strtolower($request->input('email')));
        }

        $cards = $query->paginate($request->integer('per_page', 20));

        return response()->json([
            'success' => true,
            'data' => $cards,
        ]);
    }

    /**
     * Delete a saved card.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $accountId = $request->user()->account_id;

        $card = SavedCard::where('id', $id)
            ->where('account_id', $accountId)
            ->first();

        if (!$card) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'not_found', 'message' => 'Cartão não encontrado'],
            ], 404);
        }

        $card->delete();

        return response()->json(['success' => true]);
    }
}
