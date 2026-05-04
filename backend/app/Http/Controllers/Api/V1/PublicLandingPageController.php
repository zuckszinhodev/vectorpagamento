<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LandingPage;
use App\Services\LandingPageGeneratorService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PublicLandingPageController extends Controller
{
    private LandingPageGeneratorService $generatorService;

    public function __construct(LandingPageGeneratorService $generatorService)
    {
        $this->generatorService = $generatorService;
    }

    /**
     * Show public landing page by slug
     */
    public function show(string $slug): Response
    {
        $landingPage = LandingPage::where('slug', $slug)
            ->published()
            ->first();

        if (!$landingPage) {
            return response('Página não encontrada', 404);
        }

        // Increment view count
        $landingPage->incrementViews();

        // Render HTML
        $html = $this->generatorService->renderToHtml($landingPage);

        return response($html)
            ->header('Content-Type', 'text/html');
    }

    /**
     * Preview landing page by slug (works for draft/unpublished pages too)
     */
    public function preview(string $slug): Response
    {
        $landingPage = LandingPage::where('slug', $slug)->first();

        if (!$landingPage) {
            return response('Página não encontrada', 404);
        }

        // Render HTML (no view increment for previews)
        $html = $this->generatorService->renderToHtml($landingPage);

        return response($html)
            ->header('Content-Type', 'text/html');
    }

    /**
     * Show landing page by custom domain
     */
    public function showByDomain(Request $request): Response
    {
        $domain = $request->getHost();
        
        $landingPage = LandingPage::where('custom_domain', $domain)
            ->published()
            ->first();

        if (!$landingPage) {
            return response('Página não encontrada', 404);
        }

        // Increment view count
        $landingPage->incrementViews();

        // Render HTML
        $html = $this->generatorService->renderToHtml($landingPage);

        return response($html)
            ->header('Content-Type', 'text/html');
    }

    /**
     * Track CTA click
     */
    public function trackClick(Request $request, string $slug)
    {
        $landingPage = LandingPage::where('slug', $slug)
            ->published()
            ->first();

        if ($landingPage) {
            $landingPage->incrementClicks();
        }

        return response()->json(['success' => true]);
    }

    /**
     * Track conversion
     */
    public function trackConversion(Request $request, string $slug)
    {
        $landingPage = LandingPage::where('slug', $slug)
            ->published()
            ->first();

        if ($landingPage) {
            $landingPage->incrementConversions();
        }

        return response()->json(['success' => true]);
    }
}
