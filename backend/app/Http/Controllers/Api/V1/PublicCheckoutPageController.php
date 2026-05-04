<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Checkout;
use App\Services\CheckoutRendererService;
use Illuminate\Http\Response;

class PublicCheckoutPageController extends Controller
{
    private CheckoutRendererService $rendererService;

    public function __construct(CheckoutRendererService $rendererService)
    {
        $this->rendererService = $rendererService;
    }

    /**
     * Show public checkout page by slug
     */
    public function show(string $slug): Response
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

        // Render HTML
        $html = $this->rendererService->renderToHtml($checkout);

        return response($html)
            ->header('Content-Type', 'text/html; charset=UTF-8');
    }
}
