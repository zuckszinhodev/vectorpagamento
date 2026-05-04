<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\LandingPage;
use App\Models\Product;
use App\Models\Checkout;
use App\Services\LandingPageGeneratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class LandingPageController extends Controller
{
    private LandingPageGeneratorService $generatorService;

    public function __construct(LandingPageGeneratorService $generatorService)
    {
        $this->generatorService = $generatorService;
    }

    /**
     * Allowed columns for sorting (whitelist to prevent SQL injection).
     */
    private const ALLOWED_SORT_COLUMNS = [
        'created_at',
        'updated_at',
        'name',
        'slug',
        'status',
        'views',
        'conversions',
    ];

    /**
     * List all landing pages for the authenticated user
     */
    public function index(Request $request): JsonResponse
    {
        try {
        $user = Auth::user();
        $query = LandingPage::forAccount($user->account_id)
            ->with(['product:id,name,price,image_url', 'checkout:id,name,slug']);

        // Filters
        if ($request->filled('status')) {
            // Whitelist allowed status values
            $allowedStatuses = ['draft', 'published', 'archived'];
            if (in_array($request->status, $allowedStatuses, true)) {
                $query->where('status', $request->status);
            }
        }

        if ($request->filled('search')) {
            // Sanitize search input - limit length and escape special chars
            $search = mb_substr($request->search, 0, 100);
            $search = addcslashes($search, '%_');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        // SECURITY FIX: Validate sort column against whitelist to prevent SQL injection
        $sortBy = $request->input('sort_by', 'created_at');
        if (!in_array($sortBy, self::ALLOWED_SORT_COLUMNS, true)) {
            $sortBy = 'created_at'; // Default to safe value
        }
        
        // Validate sort direction
        $sortDir = strtolower($request->input('sort_dir', 'desc'));
        if (!in_array($sortDir, ['asc', 'desc'], true)) {
            $sortDir = 'desc';
        }
        
        $query->orderBy($sortBy, $sortDir);

        // Pagination
        $perPage = min($request->input('per_page', 15), 100);
        $landingPages = $query->paginate($perPage);

        return response()->json([
            'success' => true,
            'data' => $landingPages->items(),
            'meta' => [
                'current_page' => $landingPages->currentPage(),
                'last_page' => $landingPages->lastPage(),
                'per_page' => $landingPages->perPage(),
                'total' => $landingPages->total(),
            ],
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('LandingPageController::index error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * Get a specific landing page
     */
    public function show(string $id): JsonResponse
    {
        try {
        $user = Auth::user();
        $landingPage = LandingPage::forAccount($user->account_id)
            ->with(['product', 'checkout', 'variants'])
            ->find($id);

        if (!$landingPage) {
            return response()->json([
                'success' => false,
                'message' => 'Landing page não encontrada',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => $landingPage,
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('LandingPageController::show error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * Generate a new landing page with AI
     */
    public function generate(Request $request): JsonResponse
    {
        try {
        $validator = Validator::make($request->all(), [
            'prompt' => 'required|string|min:10|max:2000',
            'product_id' => 'nullable|uuid|exists:products,id',
            'checkout_id' => 'nullable|uuid|exists:checkouts,id',
            'name' => 'nullable|string|max:255',
            'template' => 'nullable|string|in:modern,bold,minimal,dark,gradient,video',
            'custom_domain' => 'nullable|string|max:255',
            'options' => 'nullable|array',
            'options.tone' => 'nullable|string|in:professional,casual,urgent,luxury',
            'options.target_audience' => 'nullable|string|max:500',
            'options.language' => 'nullable|string|in:pt-BR,en-US,es',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = Auth::user();
        
        // Load product and checkout if provided
        $product = null;
        $checkout = null;
        
        if ($request->filled('product_id')) {
            $product = Product::where('account_id', $user->account_id)
                ->find($request->product_id);
                
            if (!$product) {
                return response()->json([
                    'success' => false,
                    'message' => 'Produto não encontrado',
                ], 404);
            }
        }

        if ($request->filled('checkout_id')) {
            $checkout = Checkout::where('account_id', $user->account_id)
                ->find($request->checkout_id);
                
            if (!$checkout) {
                return response()->json([
                    'success' => false,
                    'message' => 'Checkout não encontrado',
                ], 404);
            }
            
            // If checkout has product and product not specified, use checkout's product
            if (!$product && $checkout->product_id) {
                $product = $checkout->product;
            }
        }

        try {
            // Generate page content with AI
            $template = $request->input('template', 'modern');
            $options = $request->input('options', []);
            
            $generatedContent = $this->generatorService->generate(
                $request->prompt,
                $product,
                $checkout,
                $template,
                $options
            );

            // Create landing page
            $name = $request->name ?? ($product ? "LP - {$product->name}" : 'Nova Landing Page');
            
            // Save generated product image to product if it doesn't have one yet
            if ($product && empty($product->image_url) && !empty($generatedContent['_generatedImages']['heroUrl'])) {
                $product->image_url = $generatedContent['_generatedImages']['heroUrl'];
                $product->save();
                Log::info('Product image updated from AI generation', [
                    'product_id' => $product->id,
                    'image_url' => $product->image_url,
                ]);
            }
            
            // Remove internal image data before saving
            unset($generatedContent['_generatedImages']);

            $createData = [
                'account_id' => $user->account_id,
                'product_id' => $product?->id,
                'checkout_id' => $checkout?->id,
                'name' => $name,
                'slug' => LandingPage::generateUniqueSlug($name),
                'prompt' => $request->prompt,
                'ai_model' => 'google/gemini-2.0-flash-001',
                'content' => $generatedContent,
                'sections' => $generatedContent['sections'] ?? [],
                'template' => $template,
                'seo' => $generatedContent['meta'] ?? [],
                'status' => 'draft',
            ];

            if ($request->filled('custom_domain')) {
                $createData['custom_domain'] = $request->custom_domain;
            }

            $landingPage = LandingPage::create($createData);

            return response()->json([
                'success' => true,
                'message' => 'Landing page gerada com sucesso',
                'data' => $landingPage->load(['product:id,name,price', 'checkout:id,name,slug']),
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao gerar landing page',
                'error' => $e->getMessage(),
            ], 500);
        }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('LandingPageController::generate error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * Update a landing page
     */
    public function update(Request $request, string $id): JsonResponse
    {
        try {
        $user = Auth::user();
        $landingPage = LandingPage::forAccount($user->account_id)->find($id);

        if (!$landingPage) {
            return response()->json([
                'success' => false,
                'message' => 'Landing page não encontrada',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'name' => 'string|max:255',
            'slug' => 'string|max:255|unique:landing_pages,slug,' . $id,
            'description' => 'nullable|string|max:1000',
            'content' => 'array',
            'sections' => 'array',
            'styles' => 'nullable|array',
            'seo' => 'nullable|array',
            'template' => 'string|in:modern,bold,minimal,dark,gradient,video',
            'color_scheme' => 'string|in:auto,light,dark,custom',
            'custom_colors' => 'nullable|array',
            'status' => 'string|in:draft,published,archived',
            'custom_domain' => 'nullable|string|max:255',
            'subdomain' => 'nullable|string|max:63',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos',
                'errors' => $validator->errors(),
            ], 422);
        }

        // Save version before updating content
        if ($request->has('content') || $request->has('sections')) {
            $landingPage->saveVersion();
        }

        $landingPage->update($request->only([
            'name',
            'slug',
            'description',
            'content',
            'sections',
            'styles',
            'seo',
            'template',
            'color_scheme',
            'custom_colors',
            'status',
            'custom_domain',
            'subdomain',
        ]));

        // Update published_at if publishing
        if ($request->status === 'published' && !$landingPage->published_at) {
            $landingPage->update(['published_at' => now()]);
        }

        return response()->json([
            'success' => true,
            'message' => 'Landing page atualizada',
            'data' => $landingPage->fresh()->load(['product:id,name,price', 'checkout:id,name,slug']),
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('LandingPageController::update error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * Delete a landing page
     */
    public function destroy(string $id): JsonResponse
    {
        try {
        $user = Auth::user();
        $landingPage = LandingPage::forAccount($user->account_id)->find($id);

        if (!$landingPage) {
            return response()->json([
                'success' => false,
                'message' => 'Landing page não encontrada',
            ], 404);
        }

        $landingPage->delete();

        return response()->json([
            'success' => true,
            'message' => 'Landing page removida',
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('LandingPageController::destroy error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * Duplicate a landing page
     */
    public function duplicate(string $id): JsonResponse
    {
        try {
        $user = Auth::user();
        $landingPage = LandingPage::forAccount($user->account_id)->find($id);

        if (!$landingPage) {
            return response()->json([
                'success' => false,
                'message' => 'Landing page não encontrada',
            ], 404);
        }

        $newName = $landingPage->name . ' (Cópia)';
        
        $duplicate = $landingPage->replicate();
        $duplicate->name = $newName;
        $duplicate->slug = LandingPage::generateUniqueSlug($newName);
        $duplicate->status = 'draft';
        $duplicate->published_at = null;
        $duplicate->views_count = 0;
        $duplicate->clicks_count = 0;
        $duplicate->conversions_count = 0;
        $duplicate->version = 1;
        $duplicate->version_history = null;
        $duplicate->save();

        return response()->json([
            'success' => true,
            'message' => 'Landing page duplicada',
            'data' => $duplicate->load(['product:id,name,price', 'checkout:id,name,slug']),
        ], 201);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('LandingPageController::duplicate error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * Publish a landing page
     */
    public function publish(string $id): JsonResponse
    {
        try {
        $user = Auth::user();
        $landingPage = LandingPage::forAccount($user->account_id)->find($id);

        if (!$landingPage) {
            return response()->json([
                'success' => false,
                'message' => 'Landing page não encontrada',
            ], 404);
        }

        $landingPage->publish();

        return response()->json([
            'success' => true,
            'message' => 'Landing page publicada',
            'data' => [
                'url' => $landingPage->getUrl(),
                'status' => $landingPage->status,
            ],
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('LandingPageController::publish error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * Unpublish a landing page
     */
    public function unpublish(string $id): JsonResponse
    {
        try {
        $user = Auth::user();
        $landingPage = LandingPage::forAccount($user->account_id)->find($id);

        if (!$landingPage) {
            return response()->json([
                'success' => false,
                'message' => 'Landing page não encontrada',
            ], 404);
        }

        $landingPage->unpublish();

        return response()->json([
            'success' => true,
            'message' => 'Landing page despublicada',
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('LandingPageController::unpublish error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * Preview landing page HTML
     */
    public function preview(string $id): JsonResponse
    {
        try {
        $user = Auth::user();
        $landingPage = LandingPage::forAccount($user->account_id)->find($id);

        if (!$landingPage) {
            return response()->json([
                'success' => false,
                'message' => 'Landing page não encontrada',
            ], 404);
        }

        $html = $this->generatorService->renderToHtml($landingPage);

        return response()->json([
            'success' => true,
            'data' => [
                'html' => $html,
                'url' => $landingPage->getUrl(),
            ],
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('LandingPageController::preview error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * Edit landing page via AI chat
     */
    public function chatEdit(Request $request, string $id): JsonResponse
    {
        try {
        $validator = Validator::make($request->all(), [
            'message' => 'required|string|min:3|max:2000',
            'history' => 'nullable|array',
            'history.*.role' => 'required_with:history|string|in:user,assistant',
            'history.*.content' => 'required_with:history|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = Auth::user();
        $landingPage = LandingPage::forAccount($user->account_id)->find($id);

        if (!$landingPage) {
            return response()->json([
                'success' => false,
                'message' => 'Landing page não encontrada',
            ], 404);
        }

        try {
            $newContent = $this->generatorService->editWithAI(
                $landingPage,
                $request->message,
                $request->input('history', [])
            );

            $landingPage->saveVersion();
            $landingPage->update([
                'content' => $newContent,
                'sections' => $newContent['sections'] ?? [],
            ]);

            // Generate updated HTML preview
            $html = $this->generatorService->renderToHtml($landingPage->fresh());

            return response()->json([
                'success' => true,
                'message' => 'Landing page atualizada pela IA',
                'data' => [
                    'landing_page' => $landingPage->fresh()->load(['product:id,name,price', 'checkout:id,name,slug']),
                    'html' => $html,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('LandingPageController::chatEdit error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * Regenerate specific section
     */
    public function regenerateSection(Request $request, string $id): JsonResponse
    {
        try {
        $validator = Validator::make($request->all(), [
            'section_id' => 'required|string',
            'prompt' => 'required|string|min:5|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos',
                'errors' => $validator->errors(),
            ], 422);
        }

        $user = Auth::user();
        $landingPage = LandingPage::forAccount($user->account_id)->find($id);

        if (!$landingPage) {
            return response()->json([
                'success' => false,
                'message' => 'Landing page não encontrada',
            ], 404);
        }

        try {
            $newSections = $this->generatorService->regenerateSection(
                $landingPage,
                $request->section_id,
                $request->prompt
            );

            $landingPage->saveVersion();
            $landingPage->update(['sections' => $newSections]);

            return response()->json([
                'success' => true,
                'message' => 'Seção regenerada',
                'data' => $landingPage->fresh(),
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao regenerar seção',
                'error' => $e->getMessage(),
            ], 500);
        }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('LandingPageController::regenerateSection error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * Get available templates
     */
    public function templates(): JsonResponse
    {
        try {
        return response()->json([
            'success' => true,
            'data' => LandingPage::getTemplates(),
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('LandingPageController::templates error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * Get available section types
     */
    public function sectionTypes(): JsonResponse
    {
        try {
        return response()->json([
            'success' => true,
            'data' => LandingPage::getSectionTypes(),
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('LandingPageController::sectionTypes error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * Get landing page analytics
     */
    public function analytics(string $id): JsonResponse
    {
        try {
        $user = Auth::user();
        $landingPage = LandingPage::forAccount($user->account_id)->find($id);

        if (!$landingPage) {
            return response()->json([
                'success' => false,
                'message' => 'Landing page não encontrada',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'data' => [
                'views' => $landingPage->views_count,
                'clicks' => $landingPage->clicks_count,
                'conversions' => $landingPage->conversions_count,
                'conversion_rate' => $landingPage->getConversionRate(),
                'url' => $landingPage->getUrl(),
            ],
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('LandingPageController::analytics error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }

    /**
     * Set or remove custom domain for a landing page
     */
    public function setDomain(Request $request, string $id): JsonResponse
    {
        try {
        $user = Auth::user();
        $landingPage = LandingPage::forAccount($user->account_id)->find($id);

        if (!$landingPage) {
            return response()->json([
                'success' => false,
                'message' => 'Landing page não encontrada',
            ], 404);
        }

        $validator = Validator::make($request->all(), [
            'custom_domain' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Dados inválidos',
                'errors' => $validator->errors(),
            ], 422);
        }

        $domain = $request->input('custom_domain');

        if ($domain) {
            // Validate that the domain belongs to this account and is verified
            $customDomain = \App\Models\CustomDomain::where('account_id', $user->account_id)
                ->where('domain', strtolower($domain))
                ->where('status', 'verified')
                ->first();

            if (!$customDomain) {
                return response()->json([
                    'success' => false,
                    'message' => 'Domínio não encontrado ou não verificado. Adicione e verifique o domínio primeiro em Configurações > Domínios.',
                ], 400);
            }

            // Check if domain is already used by another LP
            $existingLp = LandingPage::where('custom_domain', strtolower($domain))
                ->where('id', '!=', $id)
                ->first();

            if ($existingLp) {
                return response()->json([
                    'success' => false,
                    'message' => 'Este domínio já está sendo usado por outra landing page.',
                ], 400);
            }

            $landingPage->update(['custom_domain' => strtolower($domain)]);
        } else {
            $landingPage->update(['custom_domain' => null]);
        }

        return response()->json([
            'success' => true,
            'message' => $domain ? 'Domínio configurado com sucesso' : 'Domínio removido',
            'data' => $landingPage->fresh()->load(['product:id,name,price', 'checkout:id,name,slug']),
        ]);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::error('LandingPageController::setDomain error', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);
            return response()->json([
                'success' => false,
                'error' => ['code' => 'server_error', 'message' => 'Erro interno do servidor.'],
            ], 500);
        }
    }
}
