<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Checkout;
use App\Services\CheckoutRendererService;
use App\Services\LiveMapService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PublicCheckoutPageController extends Controller
{
    public function __construct(
        private CheckoutRendererService $rendererService,
        private LiveMapService $liveMapService
    ) {
    }

    /**
     * Show public checkout page by slug
     */
    public function show(Request $request, string $slug): Response
    {
        $checkout = Checkout::where('slug', $slug)
            ->where('status', 'active')
            ->with('product')
            ->first();

        if (!$checkout) {
            return response('Checkout não encontrado', 404);
        }

        // Increment views
        $checkout->incrementViews();
        $this->liveMapService->recordCheckoutAccess($checkout, $request, 'checkout', 'Checkout aberto');

        // Render HTML
        $html = $this->rendererService->renderToHtml($checkout);

        return response($html)
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
