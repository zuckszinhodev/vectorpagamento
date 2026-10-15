<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Checkout;
use App\Services\Shipping\CheckoutShippingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Cotação de frete para o checkout público.
 *
 * Rota sem autenticação porque quem chama é o cliente final no navegador. Por
 * isso o peso e as dimensões vêm do produto no banco, nunca do request: aceitar
 * pacote do cliente deixaria qualquer um cotar o que quisesse com nosso token.
 */
class ShippingQuoteController extends Controller
{
    public function __construct(private readonly CheckoutShippingService $shipping) {}

    public function quote(Request $request, string $slug): JsonResponse
    {
        $request->validate([
            'postal_code' => ['required', 'string', 'max:20'],
        ]);

        $checkout = Checkout::where('slug', $slug)->where('status', 'active')->with('product')->first();
        $product = $checkout?->product;

        if (!$product || !$product->is_active) {
            return response()->json([
                'success' => false,
                'error' => ['code' => 'checkout_not_found', 'message' => 'Checkout não encontrado.'],
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $this->shipping->quote($checkout, $request->string('postal_code')->toString()),
        ]);
    }
}
