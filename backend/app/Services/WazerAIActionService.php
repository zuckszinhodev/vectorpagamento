<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Checkout;
use App\Models\Product;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ZipArchive;

class WazerAIActionService
{
    private string $apiKey;
    private string $model;

    public function __construct()
    {
        $this->apiKey = config('services.openrouter.api_key', '');
        $this->model = config('services.openrouter.wazerai_model', 'google/gemini-2.0-flash-001');
    }

    /**
     * Execute an AI-proposed action
     */
    public function executeAction(string $accountId, string $action, array $params): array
    {
        return match ($action) {
            'create_product' => $this->createProduct($accountId, $params),
            'customize_checkout' => $this->customizeCheckout($accountId, $params),
            'generate_copy' => $this->generateCopy($accountId, $params),
            'generate_image' => $this->generateImage($accountId, $params),
            'full_product_setup' => $this->fullProductSetup($accountId, $params),
            'generate_checkout_from_site' => $this->generateCheckoutFromSite($accountId, $params),
            default => ['success' => false, 'error' => 'Ação não reconhecida.'],
        };
    }

    /**
     * Create a product with AI-generated data
     */
    private function createProduct(string $accountId, array $params): array
    {
        try {
            $name = $params['name'] ?? 'Novo Produto';
            $description = $params['description'] ?? '';
            $price = (int) ($params['price'] ?? 2997); // default R$29,97
            $type = $params['type'] ?? 'digital';

            if (!in_array($type, ['digital', 'physical', 'service'])) {
                $type = 'digital';
            }

            if ($price < 100) {
                $price = 100;
            }

            $baseSlug = Str::slug($name);
            if (empty($baseSlug)) {
                $baseSlug = 'produto';
            }
            $slug = $baseSlug . '-' . Str::lower(Str::random(6));
            while (Product::withTrashed()->where('slug', $slug)->exists()) {
                $slug = $baseSlug . '-' . Str::lower(Str::random(6));
            }

            $product = Product::create([
                'id' => Str::uuid()->toString(),
                'account_id' => $accountId,
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
                'type' => $type,
                'price' => $price,
                'currency' => 'BRL',
                'images' => [],
                'image_url' => $params['image_url'] ?? null,
                'metadata' => $params['metadata'] ?? [],
                'is_active' => true,
            ]);

            // Auto-create checkout
            $checkoutSlug = $slug;
            while (Checkout::withTrashed()->where('slug', $checkoutSlug)->exists()) {
                $checkoutSlug = $baseSlug . '-' . Str::lower(Str::random(6));
            }

            $checkout = Checkout::create([
                'id' => Str::uuid()->toString(),
                'account_id' => $accountId,
                'product_id' => $product->id,
                'name' => $name,
                'slug' => $checkoutSlug,
                'type' => 'redirect',
                'status' => 'active',
                'theme' => $params['theme'] ?? [],
                'fields' => ['name' => true, 'email' => true, 'phone' => true, 'document' => true],
                'settings' => $params['checkout_settings'] ?? [],
                'views_count' => 0,
                'conversions_count' => 0,
            ]);

            $product->update(['checkout_id' => $checkout->id]);

            // Clear AI context cache
            Cache::forget("wazerai_context_{$accountId}");

            return [
                'success' => true,
                'message' => "Produto **{$name}** criado com sucesso!",
                'data' => [
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'product_slug' => $product->slug,
                    'price' => $product->price,
                    'checkout_id' => $checkout->id,
                    'checkout_slug' => $checkout->slug,
                    'checkout_url' => $checkout->url,
                ],
            ];
        } catch (\Exception $e) {
            Log::error('Vector IA create product error', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => 'Erro ao criar produto: ' . $e->getMessage()];
        }
    }

    /**
     * Customize a checkout with AI-generated theme and settings
     */
    private function customizeCheckout(string $accountId, array $params): array
    {
        try {
            $checkoutId = $params['checkout_id'] ?? null;
            $productId = $params['product_id'] ?? null;

            $checkout = null;
            if ($checkoutId) {
                $checkout = Checkout::where('id', $checkoutId)->where('account_id', $accountId)->first();
            } elseif ($productId) {
                $checkout = Checkout::where('product_id', $productId)->where('account_id', $accountId)->first();
            }

            if (!$checkout) {
                return ['success' => false, 'error' => 'Checkout não encontrado.'];
            }

            $updateData = [];

            if (!empty($params['theme'])) {
                $updateData['theme'] = array_merge($checkout->theme ?? [], $params['theme']);
            }

            if (!empty($params['settings'])) {
                $updateData['settings'] = array_merge($checkout->settings ?? [], $params['settings']);
            }

            if (!empty($updateData)) {
                $checkout->update($updateData);
            }

            return [
                'success' => true,
                'message' => "Checkout **{$checkout->name}** personalizado com sucesso!",
                'data' => [
                    'checkout_id' => $checkout->id,
                    'checkout_slug' => $checkout->slug,
                    'checkout_url' => $checkout->url,
                    'theme' => $checkout->theme,
                    'settings' => $checkout->settings,
                ],
            ];
        } catch (\Exception $e) {
            Log::error('Vector IA customize checkout error', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => 'Erro ao personalizar checkout.'];
        }
    }

    /**
     * Generate marketing copy using AI
     */
    private function generateCopy(string $accountId, array $params): array
    {
        if (empty($this->apiKey)) {
            return ['success' => false, 'error' => 'API key não configurada.'];
        }

        try {
            $productName = $params['product_name'] ?? $params['name'] ?? 'Produto';
            $productDescription = $params['description'] ?? '';
            $targetAudience = $params['target_audience'] ?? '';
            $copyType = $params['copy_type'] ?? 'full'; // full, title, description, benefits, testimonials

            $prompt = $this->buildCopyPrompt($productName, $productDescription, $targetAudience, $copyType);

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type' => 'application/json',
                'HTTP-Referer' => config('app.url', 'https://vectorpagamentos.com'),
                'X-Title' => 'Vector IA Copy Generator',
            ])->withoutVerifying()->timeout(45)->post('https://openrouter.ai/api/v1/chat/completions', [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => 'Você é um copywriter profissional brasileiro especializado em vendas de produtos digitais e infoprodutos. Gere textos persuasivos, otimizados para conversão, em português brasileiro. Responda APENAS com JSON válido, sem markdown.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.8,
                'max_tokens' => 3000,
                'response_format' => ['type' => 'json_object'],
            ]);

            if (!$response->successful()) {
                return ['success' => false, 'error' => 'Erro ao gerar copy.'];
            }

            $content = $response->json('choices.0.message.content', '{}');
            $copyData = json_decode($content, true) ?: [];

            // If we have a product_id, update the product with the generated copy
            if (!empty($params['product_id'])) {
                $product = Product::where('id', $params['product_id'])->where('account_id', $accountId)->first();
                if ($product) {
                    $updateFields = [];
                    if (!empty($copyData['description'])) {
                        $updateFields['description'] = $copyData['description'];
                    }
                    if (!empty($copyData['seo_title'])) {
                        $updateFields['seo_title'] = $copyData['seo_title'];
                    }
                    if (!empty($copyData['seo_description'])) {
                        $updateFields['seo_description'] = $copyData['seo_description'];
                    }
                    if (!empty($updateFields)) {
                        $product->update($updateFields);
                    }
                }
            }

            // If we have a checkout, update it with benefits/testimonials
            if (!empty($params['checkout_id']) && !empty($copyData['checkout_blocks'])) {
                $checkout = Checkout::where('id', $params['checkout_id'])->where('account_id', $accountId)->first();
                if ($checkout) {
                    $settings = $checkout->settings ?? [];
                    $settings['blocks'] = array_merge($settings['blocks'] ?? [], $copyData['checkout_blocks']);
                    $checkout->update(['settings' => $settings]);
                }
            }

            return [
                'success' => true,
                'message' => 'Copy gerada com sucesso!',
                'data' => $copyData,
            ];
        } catch (\Exception $e) {
            Log::error('Vector IA generate copy error', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => 'Erro ao gerar copy.'];
        }
    }

    /**
     * Generate a product image using AI
     */
    private function generateImage(string $accountId, array $params): array
    {
        if (empty($this->apiKey)) {
            return ['success' => false, 'error' => 'API key não configurada.'];
        }

        try {
            $productName = $params['product_name'] ?? $params['name'] ?? 'Produto';
            $style = $params['style'] ?? 'modern digital product cover';
            $description = $params['description'] ?? '';

            $imagePrompt = "Create a professional, high-quality product cover image for a digital product called '{$productName}'. Style: {$style}. {$description}. The image should be clean, modern, attractive for sales, with vibrant but professional colors. NO text in the image.";

            // Use OpenRouter with a vision-capable model for image generation
            // We'll use a text-to-image approach via description
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type' => 'application/json',
                'HTTP-Referer' => config('app.url', 'https://vectorpagamentos.com'),
                'X-Title' => 'Vector IA Image Generator',
            ])->withoutVerifying()->timeout(60)->post('https://openrouter.ai/api/v1/chat/completions', [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => 'Você é um designer de capas para produtos digitais. Gere um SVG profissional e bonito que será usado como capa do produto. Use gradientes, formas geométricas e cores vibrantes. O SVG deve ter 800x600 pixels. Responda APENAS com o código SVG, sem markdown, sem explicações.'],
                    ['role' => 'user', 'content' => "Crie uma capa SVG profissional para: {$productName}. Tema: {$style}. {$description}"],
                ],
                'temperature' => 0.9,
                'max_tokens' => 4000,
            ]);

            if (!$response->successful()) {
                return ['success' => false, 'error' => 'Erro ao gerar imagem.'];
            }

            $svgContent = $response->json('choices.0.message.content', '');

            // Extract SVG from response if wrapped in markdown
            if (preg_match('/<svg[\s\S]*?<\/svg>/i', $svgContent, $matches)) {
                $svgContent = $matches[0];
            }

            if (empty($svgContent) || !str_contains($svgContent, '<svg')) {
                return ['success' => false, 'error' => 'Não foi possível gerar a imagem.'];
            }

            // Save SVG as file
            $filename = Str::uuid() . '.svg';
            $path = "products/{$accountId}/{$filename}";
            $disk = \Illuminate\Support\Facades\Storage::disk('public');
            $disk->put($path, $svgContent);
            $imageUrl = url("/storage/{$path}");

            // Update product if specified
            if (!empty($params['product_id'])) {
                $product = Product::where('id', $params['product_id'])->where('account_id', $accountId)->first();
                if ($product) {
                    $product->update([
                        'image_url' => $imageUrl,
                        'images' => [$imageUrl],
                    ]);
                }
            }

            return [
                'success' => true,
                'message' => 'Imagem gerada com sucesso!',
                'data' => [
                    'image_url' => $imageUrl,
                    'path' => $path,
                ],
            ];
        } catch (\Exception $e) {
            Log::error('Vector IA generate image error', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => 'Erro ao gerar imagem.'];
        }
    }

    /**
     * Full product setup: create product + generate copy + generate image + customize checkout
     */
    private function fullProductSetup(string $accountId, array $params): array
    {
        $steps = [];
        $errors = [];

        // Step 1: Generate copy first if not provided
        if (empty($params['description']) && !empty($params['name'])) {
            $copyResult = $this->generateCopy($accountId, $params);
            if ($copyResult['success']) {
                $copyData = $copyResult['data'] ?? [];
                $params['description'] = $copyData['description'] ?? '';
                $params['checkout_blocks'] = $copyData['checkout_blocks'] ?? [];
                $steps[] = '✅ Copy gerada';
            } else {
                $errors[] = 'Copy: ' . ($copyResult['error'] ?? 'erro');
            }
        }

        // Step 2: Create the product
        $productResult = $this->createProduct($accountId, $params);
        if (!$productResult['success']) {
            return [
                'success' => false,
                'error' => 'Erro ao criar produto: ' . ($productResult['error'] ?? 'erro desconhecido'),
                'steps' => $steps,
            ];
        }
        $steps[] = '✅ Produto criado';

        $productId = $productResult['data']['product_id'];
        $checkoutId = $productResult['data']['checkout_id'];
        $checkoutSlug = $productResult['data']['checkout_slug'];

        // Step 3: Generate image
        $imageParams = array_merge($params, ['product_id' => $productId]);
        $imageResult = $this->generateImage($accountId, $imageParams);
        if ($imageResult['success']) {
            $steps[] = '✅ Imagem gerada';
        } else {
            $errors[] = 'Imagem: ' . ($imageResult['error'] ?? 'erro');
        }

        // Step 4: Customize checkout with AI recommendations
        $checkoutParams = $this->buildCheckoutCustomization($params);
        $checkoutParams['checkout_id'] = $checkoutId;

        // Add generated copy blocks to checkout
        if (!empty($params['checkout_blocks'])) {
            $checkoutParams['settings']['blocks'] = $params['checkout_blocks'];
        }

        $checkoutResult = $this->customizeCheckout($accountId, $checkoutParams);
        if ($checkoutResult['success']) {
            $steps[] = '✅ Checkout personalizado';
        } else {
            $errors[] = 'Checkout: ' . ($checkoutResult['error'] ?? 'erro');
        }

        $price = number_format(($params['price'] ?? 2997) / 100, 2, ',', '.');

        return [
            'success' => true,
            'message' => "🚀 Produto configurado com sucesso!\n\n" .
                implode("\n", $steps) .
                (!empty($errors) ? "\n\n⚠️ Avisos:\n" . implode("\n", $errors) : '') .
                "\n\n**Produto:** {$params['name']}\n**Preço:** R$ {$price}\n**Checkout:** /checkout/{$checkoutSlug}",
            'data' => [
                'product_id' => $productId,
                'checkout_id' => $checkoutId,
                'checkout_slug' => $checkoutSlug,
                'steps' => $steps,
                'errors' => $errors,
            ],
        ];
    }

    /**
     * Generate a Vector Pagamentos-ready checkout package from an external site URL.
     */
    private function generateCheckoutFromSite(string $accountId, array $params): array
    {
        try {
            $siteUrl = trim((string) ($params['site_url'] ?? $params['url'] ?? ''));
            if ($siteUrl === '') {
                return ['success' => false, 'error' => 'Informe a URL do site que a IA deve analisar.'];
            }

            $siteUrl = $this->normalizeSiteUrl($siteUrl);
            if (!$this->isValidExternalSiteUrl($siteUrl)) {
                return ['success' => false, 'error' => 'A URL informada não é permitida para análise automática.'];
            }

            $snapshotResult = $this->fetchWebsiteSnapshot($siteUrl);
            if (!$snapshotResult['success']) {
                return $snapshotResult;
            }

            $snapshot = $snapshotResult['data'];
            $blueprint = $this->buildCheckoutBlueprintFromWebsite($siteUrl, $snapshot, $params);

            $productName = trim((string) ($params['name'] ?? $blueprint['product_name'] ?? $snapshot['headline'] ?? $snapshot['title'] ?? 'Novo Produto'));
            if ($productName === '') {
                $productName = 'Novo Produto';
            }

            $productType = (string) ($params['type'] ?? $blueprint['type'] ?? 'digital');
            if (!in_array($productType, ['digital', 'physical', 'service'], true)) {
                $productType = 'digital';
            }

            $priceCents = (int) ($params['price'] ?? $blueprint['price_cents'] ?? $snapshot['price_cents'] ?? 2997);
            if ($priceCents < 100) {
                $priceCents = 2997;
            }

            $productDescription = trim((string) ($params['description'] ?? $blueprint['product_description'] ?? $snapshot['description'] ?? ''));
            if ($productDescription === '') {
                $productDescription = 'Checkout gerado automaticamente pela Vector IA com base no site informado pelo usuário.';
            }

            $baseCustomization = $this->buildCheckoutCustomization([
                'name' => $productName,
                'type' => $productType,
                'price' => $priceCents,
            ]);

            $theme = array_merge(
                $baseCustomization['theme'],
                $this->normalizeThemeOverrides($blueprint['theme'] ?? [], $snapshot['theme_color'] ?? null)
            );

            $settings = array_replace_recursive(
                $baseCustomization['settings'],
                is_array($blueprint['checkout_settings'] ?? null) ? $blueprint['checkout_settings'] : []
            );

            $generatedBlocks = $this->buildSiteBasedBlocks($blueprint, $snapshot);
            if (!empty($generatedBlocks)) {
                $settings['blocks'] = $generatedBlocks;
            }

            $settings['wazer_ai_generated'] = [
                'mode' => 'site_to_checkout',
                'source_url' => $siteUrl,
                'generated_at' => now()->toIso8601String(),
                'detected_title' => $snapshot['title'] ?? null,
            ];

            $createResult = $this->createProduct($accountId, [
                'name' => $productName,
                'description' => $productDescription,
                'price' => $priceCents,
                'type' => $productType,
                'image_url' => $blueprint['hero_image_url'] ?? $snapshot['image_url'] ?? null,
                'theme' => $theme,
                'checkout_settings' => $settings,
                'metadata' => [
                    'generated_by' => 'wazerai',
                    'generation_mode' => 'site_to_checkout',
                    'source_url' => $siteUrl,
                ],
            ]);

            if (!$createResult['success']) {
                return $createResult;
            }

            $checkoutSlug = (string) ($createResult['data']['checkout_slug'] ?? '');
            $checkoutUrl = (string) ($createResult['data']['checkout_url'] ?? (config('app.frontend_url') ? rtrim((string) config('app.frontend_url'), '/') . '/checkout/' . $checkoutSlug : '/checkout/' . $checkoutSlug));

            $packageResult = $this->buildDownloadableCheckoutPackage($accountId, [
                'site_url' => $siteUrl,
                'site_title' => $snapshot['title'] ?? null,
                'product_name' => $productName,
                'product_description' => $productDescription,
                'price_cents' => $priceCents,
                'checkout_url' => $checkoutUrl,
                'checkout_slug' => $checkoutSlug,
                'image_url' => $blueprint['hero_image_url'] ?? $snapshot['image_url'] ?? null,
                'headline' => $blueprint['hero_title'] ?? $productName,
                'subheadline' => $blueprint['hero_subtitle'] ?? $productDescription,
                'cta_label' => $blueprint['cta_label'] ?? 'Pagar com PIX na Vector Pagamentos',
                'benefits' => is_array($blueprint['benefits'] ?? null) ? $blueprint['benefits'] : [],
                'theme' => $theme,
            ]);

            if (!$packageResult['success']) {
                return $packageResult;
            }

            $formattedPrice = 'R$ ' . number_format($priceCents / 100, 2, ',', '.');

            return [
                'success' => true,
                'message' => "Checkout gerado com base no site informado e pacote pronto para baixar.\n\nProduto: {$productName}\nPreço sugerido: {$formattedPrice}\nCheckout público: {$checkoutUrl}",
                'data' => array_merge($createResult['data'], [
                    'source_url' => $siteUrl,
                    'download_url' => $packageResult['download_url'],
                    'preview_url' => $packageResult['preview_url'],
                    'checkout_url' => $checkoutUrl,
                    'detected_title' => $snapshot['title'] ?? null,
                    'price_cents' => $priceCents,
                    'package_path' => $packageResult['path'],
                ]),
            ];
        } catch (\Throwable $e) {
            Log::error('Vector IA generate checkout from site error', [
                'error' => $e->getMessage(),
                'site_url' => $params['site_url'] ?? $params['url'] ?? null,
            ]);

            return ['success' => false, 'error' => 'Erro ao gerar checkout a partir do site informado.'];
        }
    }

    /**
     * Build AI-optimized checkout customization based on product type
     */
    private function buildCheckoutCustomization(array $params): array
    {
        $type = $params['type'] ?? 'digital';
        $price = ($params['price'] ?? 2997) / 100;

        // Choose color scheme based on product category hints
        $name = mb_strtolower($params['name'] ?? '');
        $colorScheme = $this->pickColorScheme($name);

        $theme = [
            'primaryColor' => $colorScheme['primary'],
            'secondaryColor' => $colorScheme['secondary'],
            'backgroundColor' => '#f8fafc',
            'cardBackground' => '#ffffff',
            'textColor' => '#1e293b',
            'mutedColor' => '#64748b',
            'borderRadius' => 'xl',
            'fontFamily' => 'Inter',
            'buttonStyle' => 'gradient',
            'shadow' => 'lg',
        ];

        $settings = [
            'theme_color' => $colorScheme['primary'],
            'collect_phone' => true,
            'payment_methods' => ['credit_card', 'pix', 'boleto'],
            'max_installments' => $price > 100 ? 12 : ($price > 50 ? 6 : 3),
            'timer_enabled' => true,
            'timer_minutes' => 15,
            'exit_popup_enabled' => true,
            'exit_popup_title' => 'Espere! Não vá embora ainda...',
            'exit_popup_message' => 'Temos uma oferta especial para você!',
            'testimonials_enabled' => false,
            'socialProofEnabled' => true,
            'socialProofInterval' => 30,
            'conversion' => [
                'timerEnabled' => true,
                'timerMinutes' => 15,
                'timerMessage' => 'Oferta expira em:',
                'exitPopupEnabled' => true,
                'exitPopupTitle' => 'Espere! Tem certeza?',
                'exitPopupMessage' => 'Esta oferta pode não estar disponível depois.',
                'socialProofEnabled' => true,
                'socialProofInterval' => 25,
            ],
            'payment' => [
                'methods' => ['credit_card', 'pix', 'boleto'],
                'maxInstallments' => $price > 100 ? 12 : ($price > 50 ? 6 : 3),
                'pixDiscount' => $price > 50 ? 10 : 5,
            ],
            'blocks' => [
                ['type' => 'header', 'enabled' => true, 'settings' => ['showLogo' => true, 'showSecureBadge' => true]],
                ['type' => 'product', 'enabled' => true, 'settings' => ['showImage' => true, 'showDescription' => true, 'showOriginalPrice' => true]],
                ['type' => 'timer', 'enabled' => true, 'settings' => []],
                ['type' => 'benefits', 'enabled' => true, 'settings' => ['items' => [
                    ['text' => 'Acesso imediato após a compra'],
                    ['text' => 'Garantia de 7 dias'],
                    ['text' => 'Suporte exclusivo'],
                ]]],
                ['type' => 'payment', 'enabled' => true, 'settings' => ['showInstallments' => true, 'highlightPix' => true]],
                ['type' => 'guarantee', 'enabled' => true, 'settings' => ['days' => 7, 'title' => 'Garantia Incondicional', 'description' => 'Se não ficar satisfeito, devolvemos 100% do seu dinheiro.']],
                ['type' => 'trust_badges', 'enabled' => true, 'settings' => []],
            ],
        ];

        return [
            'theme' => $theme,
            'settings' => $settings,
        ];
    }

    /**
     * Pick a color scheme based on product name keywords
     */
    private function pickColorScheme(string $name): array
    {
        $schemes = [
            'finance' => ['primary' => '#059669', 'secondary' => '#10b981'],
            'health' => ['primary' => '#dc2626', 'secondary' => '#ef4444'],
            'education' => ['primary' => '#2563eb', 'secondary' => '#3b82f6'],
            'marketing' => ['primary' => '#7c3aed', 'secondary' => '#8b5cf6'],
            'default' => ['primary' => '#7c3aed', 'secondary' => '#8b5cf6'],
        ];

        $financeWords = ['financ', 'invest', 'dinheiro', 'renda', 'lucro', 'trader', 'trading', 'cripto', 'bitcoin'];
        $healthWords = ['saúde', 'saude', 'fitness', 'emagrec', 'dieta', 'treino', 'corpo', 'bem-estar'];
        $educationWords = ['curso', 'aula', 'aprend', 'estud', 'ebook', 'guia', 'formação', 'formacao'];
        $marketingWords = ['marketing', 'tráfego', 'trafego', 'vendas', 'copy', 'funil', 'lançamento'];

        foreach ($financeWords as $w) {
            if (str_contains($name, $w)) return $schemes['finance'];
        }
        foreach ($healthWords as $w) {
            if (str_contains($name, $w)) return $schemes['health'];
        }
        foreach ($educationWords as $w) {
            if (str_contains($name, $w)) return $schemes['education'];
        }
        foreach ($marketingWords as $w) {
            if (str_contains($name, $w)) return $schemes['marketing'];
        }

        return $schemes['default'];
    }

    /**
     * Build copy generation prompt
     */
    private function buildCopyPrompt(string $name, string $description, string $targetAudience, string $copyType): string
    {
        $base = "Produto: {$name}";
        if ($description) $base .= "\nDescrição: {$description}";
        if ($targetAudience) $base .= "\nPúblico-alvo: {$targetAudience}";

        if ($copyType === 'full') {
            return <<<PROMPT
{$base}

Gere um JSON com a copy completa para este produto digital:
{
  "title": "Título persuasivo para o produto (max 80 chars)",
  "description": "Descrição completa e persuasiva (2-3 parágrafos)",
  "short_description": "Descrição curta para cards (max 150 chars)",
  "seo_title": "Título SEO otimizado (max 60 chars)",
  "seo_description": "Meta description SEO (max 160 chars)",
  "benefits": ["benefício 1", "benefício 2", "benefício 3", "benefício 4", "benefício 5"],
  "checkout_blocks": [
    {"type": "benefits", "enabled": true, "settings": {"items": [{"text": "benefício 1"}, {"text": "benefício 2"}, {"text": "benefício 3"}]}},
    {"type": "guarantee", "enabled": true, "settings": {"days": 7, "title": "Garantia Incondicional", "description": "texto da garantia"}},
    {"type": "faq", "enabled": true, "settings": {"items": [{"question": "pergunta", "answer": "resposta"}]}}
  ]
}

Foque em:
- Headlines que geram curiosidade e urgência
- Benefícios concretos e transformadores
- Linguagem que conecta com a dor do público
- FAQ que elimina objeções de compra
PROMPT;
        }

        return "{$base}\n\nGere um JSON com 'title' e 'description' persuasivos para este produto.";
    }

    private function normalizeSiteUrl(string $url): string
    {
        $trimmed = trim($url);
        if (!preg_match('/^https?:\/\//i', $trimmed)) {
            $trimmed = 'https://' . $trimmed;
        }

        return $trimmed;
    }

    private function isValidExternalSiteUrl(string $url): bool
    {
        $parsed = parse_url($url);

        if (!$parsed || !isset($parsed['scheme'], $parsed['host'])) {
            return false;
        }

        if (!in_array($parsed['scheme'], ['http', 'https'], true)) {
            return false;
        }

        if (config('app.env') === 'production' && $parsed['scheme'] !== 'https') {
            return false;
        }

        $host = mb_strtolower((string) $parsed['host']);
        if (in_array($host, ['localhost', '127.0.0.1', '0.0.0.0', '::1'], true)) {
            return false;
        }

        $resolvedIp = gethostbyname($host);
        if ($resolvedIp !== $host) {
            $privateRanges = [
                '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16',
                '127.0.0.0/8', '169.254.0.0/16', '0.0.0.0/8',
            ];

            $longIp = ip2long($resolvedIp);
            if ($longIp !== false) {
                foreach ($privateRanges as $range) {
                    [$subnet, $mask] = explode('/', $range);
                    if (($longIp & ~((1 << (32 - (int) $mask)) - 1)) === ip2long($subnet)) {
                        return false;
                    }
                }
            }
        }

        return true;
    }

    private function fetchWebsiteSnapshot(string $siteUrl): array
    {
        try {
            $response = Http::withHeaders([
                'User-Agent' => 'Vector IA Checkout Generator/1.0',
                'Accept' => 'text/html,application/xhtml+xml',
            ])->timeout(20)->get($siteUrl);

            if (!$response->successful()) {
                return ['success' => false, 'error' => 'Não foi possível acessar o site informado para análise.'];
            }

            $html = (string) $response->body();
            $sanitizedHtml = preg_replace('/<(script|style|noscript)[^>]*>.*?<\/\1>/is', ' ', $html) ?? $html;
            $plainText = trim(preg_replace('/\s+/u', ' ', strip_tags($sanitizedHtml)) ?? '');

            $title = $this->extractHtmlTagContent($html, 'title');
            $headline = $this->extractHtmlTagContent($html, 'h1') ?: $title;
            $description = $this->extractMetaContent($html, 'name', 'description')
                ?: $this->extractMetaContent($html, 'property', 'og:description')
                ?: mb_substr($plainText, 0, 280);
            $imageUrl = $this->resolveAbsoluteUrl($siteUrl, $this->extractMetaContent($html, 'property', 'og:image') ?: $this->extractMetaContent($html, 'name', 'twitter:image'));
            $themeColor = $this->sanitizeHexColor($this->extractMetaContent($html, 'name', 'theme-color'));
            $priceCents = $this->extractFirstPriceCents($plainText);

            return [
                'success' => true,
                'data' => [
                    'title' => $title,
                    'headline' => $headline,
                    'description' => $description,
                    'plain_text' => mb_substr($plainText, 0, 6000),
                    'image_url' => $imageUrl,
                    'theme_color' => $themeColor,
                    'price_cents' => $priceCents,
                ],
            ];
        } catch (\Throwable $e) {
            Log::warning('Vector IA website snapshot error', [
                'site_url' => $siteUrl,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'error' => 'Não foi possível analisar o site informado no momento.'];
        }
    }

    private function buildCheckoutBlueprintFromWebsite(string $siteUrl, array $snapshot, array $params): array
    {
        $fallback = [
            'product_name' => $params['name'] ?? $snapshot['headline'] ?? $snapshot['title'] ?? 'Novo Produto',
            'product_description' => $params['description'] ?? $snapshot['description'] ?? 'Checkout gerado pela Vector IA com base no seu site.',
            'price_cents' => (int) ($params['price'] ?? $snapshot['price_cents'] ?? 2997),
            'type' => 'digital',
            'target_audience' => 'Público do site analisado',
            'hero_title' => $snapshot['headline'] ?? $snapshot['title'] ?? 'Checkout pronto para vender',
            'hero_subtitle' => $snapshot['description'] ?? 'Checkout integrado à Vector Pagamentos e pronto para pagamento via PIX.',
            'cta_label' => 'Pagar com PIX na Vector Pagamentos',
            'hero_image_url' => $snapshot['image_url'] ?? null,
            'theme' => $this->normalizeThemeOverrides([], $snapshot['theme_color'] ?? null),
            'benefits' => [
                'Pagamento PIX com confirmação rápida',
                'Checkout hospedado na Vector Pagamentos',
                'Página pronta para publicar ou baixar',
            ],
            'checkout_settings' => [],
            'faq' => [],
        ];

        if (empty($this->apiKey)) {
            return $fallback;
        }

        try {
            $prompt = [
                'site_url' => $siteUrl,
                'title' => $snapshot['title'] ?? null,
                'headline' => $snapshot['headline'] ?? null,
                'description' => $snapshot['description'] ?? null,
                'price_cents_detected' => $snapshot['price_cents'] ?? null,
                'site_excerpt' => $snapshot['plain_text'] ?? '',
                'user_overrides' => $params,
            ];

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->apiKey}",
                'Content-Type' => 'application/json',
                'HTTP-Referer' => config('app.url', 'https://vectorpagamentos.com'),
                'X-Title' => 'Vector IA Site Checkout Generator',
            ])->withoutVerifying()->timeout(45)->post('https://openrouter.ai/api/v1/chat/completions', [
                'model' => $this->model,
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'Você é um especialista em conversão e checkouts da Vector Pagamentos. A partir de um site de origem, gere um JSON válido e conciso para montar um checkout de alta conversão na Vector Pagamentos. Responda APENAS com JSON válido, sem markdown.',
                    ],
                    [
                        'role' => 'user',
                        'content' => json_encode([
                            'task' => 'Analise o site e gere um blueprint de checkout Vector Pagamentos com PIX pronto para baixar.',
                            'required_json_shape' => [
                                'product_name' => 'string',
                                'product_description' => 'string',
                                'price_cents' => 'integer',
                                'type' => 'digital|physical|service',
                                'target_audience' => 'string',
                                'hero_title' => 'string',
                                'hero_subtitle' => 'string',
                                'cta_label' => 'string',
                                'hero_image_url' => 'string|null',
                                'theme' => [
                                    'primaryColor' => '#RRGGBB',
                                    'secondaryColor' => '#RRGGBB',
                                    'backgroundColor' => '#RRGGBB',
                                    'textColor' => '#RRGGBB',
                                ],
                                'benefits' => ['string'],
                                'faq' => [['question' => 'string', 'answer' => 'string']],
                                'checkout_settings' => ['pix_enabled' => true],
                            ],
                            'source_data' => $prompt,
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ],
                ],
                'temperature' => 0.6,
                'max_tokens' => 2200,
                'response_format' => ['type' => 'json_object'],
            ]);

            if (!$response->successful()) {
                return $fallback;
            }

            $content = (string) $response->json('choices.0.message.content', '{}');
            $decoded = json_decode($content, true);
            if (!is_array($decoded)) {
                return $fallback;
            }

            return array_merge($fallback, [
                'product_name' => trim((string) ($decoded['product_name'] ?? $fallback['product_name'])),
                'product_description' => trim((string) ($decoded['product_description'] ?? $fallback['product_description'])),
                'price_cents' => max(100, (int) ($decoded['price_cents'] ?? $fallback['price_cents'])),
                'type' => in_array($decoded['type'] ?? '', ['digital', 'physical', 'service'], true) ? $decoded['type'] : $fallback['type'],
                'target_audience' => trim((string) ($decoded['target_audience'] ?? $fallback['target_audience'])),
                'hero_title' => trim((string) ($decoded['hero_title'] ?? $fallback['hero_title'])),
                'hero_subtitle' => trim((string) ($decoded['hero_subtitle'] ?? $fallback['hero_subtitle'])),
                'cta_label' => trim((string) ($decoded['cta_label'] ?? $fallback['cta_label'])),
                'hero_image_url' => $this->resolveAbsoluteUrl($siteUrl, (string) ($decoded['hero_image_url'] ?? $fallback['hero_image_url'] ?? '')),
                'theme' => $this->normalizeThemeOverrides(is_array($decoded['theme'] ?? null) ? $decoded['theme'] : [], $snapshot['theme_color'] ?? null),
                'benefits' => $this->normalizeStringList($decoded['benefits'] ?? $fallback['benefits']),
                'faq' => $this->normalizeFaqItems($decoded['faq'] ?? []),
                'checkout_settings' => is_array($decoded['checkout_settings'] ?? null) ? $decoded['checkout_settings'] : [],
            ]);
        } catch (\Throwable $e) {
            Log::warning('Vector IA site blueprint generation error', [
                'site_url' => $siteUrl,
                'error' => $e->getMessage(),
            ]);

            return $fallback;
        }
    }

    private function buildSiteBasedBlocks(array $blueprint, array $snapshot): array
    {
        $benefits = $this->normalizeStringList($blueprint['benefits'] ?? []);
        $faqItems = $this->normalizeFaqItems($blueprint['faq'] ?? []);
        $heroImageUrl = $blueprint['hero_image_url'] ?? $snapshot['image_url'] ?? null;

        $blocks = [
            [
                'type' => 'header',
                'enabled' => true,
                'settings' => [
                    'title' => $blueprint['hero_title'] ?? $blueprint['product_name'] ?? null,
                    'subtitle' => $blueprint['hero_subtitle'] ?? $snapshot['description'] ?? null,
                    'showLogo' => false,
                    'showSecureBadge' => true,
                ],
            ],
            [
                'type' => 'product',
                'enabled' => true,
                'settings' => [
                    'showImage' => !empty($heroImageUrl),
                    'showDescription' => true,
                    'showOriginalPrice' => false,
                    'imageUrl' => $heroImageUrl,
                ],
            ],
            [
                'type' => 'payment',
                'enabled' => true,
                'settings' => [
                    'showInstallments' => true,
                    'highlightPix' => true,
                ],
            ],
            [
                'type' => 'trust_badges',
                'enabled' => true,
                'settings' => [],
            ],
        ];

        if (!empty($benefits)) {
            $blocks[] = [
                'type' => 'benefits',
                'enabled' => true,
                'settings' => [
                    'items' => array_map(fn (string $benefit) => ['text' => $benefit], array_slice($benefits, 0, 6)),
                ],
            ];
        }

        if (!empty($faqItems)) {
            $blocks[] = [
                'type' => 'faq',
                'enabled' => true,
                'settings' => ['items' => array_slice($faqItems, 0, 5)],
            ];
        }

        $blocks[] = [
            'type' => 'guarantee',
            'enabled' => true,
            'settings' => [
                'days' => 7,
                'title' => 'Compra segura com Vector Pagamentos',
                'description' => 'Seu cliente finaliza o pagamento em um checkout já conectado ao PIX da Vector Pagamentos.',
            ],
        ];

        return $blocks;
    }

    private function buildDownloadableCheckoutPackage(string $accountId, array $payload): array
    {
        try {
            $exportSlug = Str::slug((string) ($payload['product_name'] ?? 'checkout')) . '-' . Str::lower(Str::random(6));
            $relativeDirectory = "vector-ia/{$accountId}/checkout-exports/{$exportSlug}";
            $disk = Storage::disk('public');
            $disk->makeDirectory($relativeDirectory);

            $html = $this->buildExportHtml($payload);
            $readme = $this->buildExportReadme($payload);
            $manifest = json_encode([
                'generated_at' => now()->toIso8601String(),
                'source_url' => $payload['site_url'] ?? null,
                'checkout_url' => $payload['checkout_url'] ?? null,
                'checkout_slug' => $payload['checkout_slug'] ?? null,
                'product_name' => $payload['product_name'] ?? null,
                'price_cents' => $payload['price_cents'] ?? null,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $disk->put("{$relativeDirectory}/index.html", $html);
            $disk->put("{$relativeDirectory}/README.md", $readme);
            $disk->put("{$relativeDirectory}/checkout-config.json", $manifest ?: '{}');

            $zipRelativePath = "{$relativeDirectory}/vectorpagamentos-checkout-package.zip";
            $zipAbsolutePath = $disk->path($zipRelativePath);

            $zip = new ZipArchive();
            if ($zip->open($zipAbsolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Não foi possível criar o arquivo ZIP do checkout.');
            }

            foreach (['index.html', 'README.md', 'checkout-config.json'] as $filename) {
                $zip->addFile($disk->path("{$relativeDirectory}/{$filename}"), $filename);
            }
            $zip->close();

            return [
                'success' => true,
                'path' => $relativeDirectory,
                'download_url' => url("/storage/{$zipRelativePath}"),
                'preview_url' => url("/storage/{$relativeDirectory}/index.html"),
            ];
        } catch (\Throwable $e) {
            Log::error('Vector IA checkout package error', ['error' => $e->getMessage()]);
            return ['success' => false, 'error' => 'Erro ao gerar o pacote baixável do checkout.'];
        }
    }

    private function buildExportHtml(array $payload): string
    {
        $productName = htmlspecialchars((string) ($payload['product_name'] ?? 'Checkout Vector Pagamentos'), ENT_QUOTES, 'UTF-8');
        $headline = htmlspecialchars((string) ($payload['headline'] ?? $payload['product_name'] ?? 'Checkout pronto para vender'), ENT_QUOTES, 'UTF-8');
        $subheadline = htmlspecialchars((string) ($payload['subheadline'] ?? $payload['product_description'] ?? ''), ENT_QUOTES, 'UTF-8');
        $checkoutUrl = htmlspecialchars((string) ($payload['checkout_url'] ?? '#'), ENT_QUOTES, 'UTF-8');
        $siteUrl = htmlspecialchars((string) ($payload['site_url'] ?? ''), ENT_QUOTES, 'UTF-8');
        $ctaLabel = htmlspecialchars((string) ($payload['cta_label'] ?? 'Pagar com PIX na Vector Pagamentos'), ENT_QUOTES, 'UTF-8');
        $imageUrl = htmlspecialchars((string) ($payload['image_url'] ?? ''), ENT_QUOTES, 'UTF-8');
        $primaryColor = htmlspecialchars((string) ($payload['theme']['primaryColor'] ?? '#7c3aed'), ENT_QUOTES, 'UTF-8');
        $backgroundColor = htmlspecialchars((string) ($payload['theme']['backgroundColor'] ?? '#f8fafc'), ENT_QUOTES, 'UTF-8');
        $textColor = htmlspecialchars((string) ($payload['theme']['textColor'] ?? '#111827'), ENT_QUOTES, 'UTF-8');
        $price = 'R$ ' . number_format(((int) ($payload['price_cents'] ?? 0)) / 100, 2, ',', '.');

        $benefitsHtml = '';
        foreach ($this->normalizeStringList($payload['benefits'] ?? []) as $benefit) {
            $benefitsHtml .= '<li>' . htmlspecialchars($benefit, ENT_QUOTES, 'UTF-8') . '</li>';
        }

        $imageBlock = $imageUrl !== ''
            ? '<div class="hero-image"><img src="' . $imageUrl . '" alt="' . $productName . '"></div>'
            : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{$productName}</title>
  <style>
    :root {
      --primary: {$primaryColor};
      --bg: {$backgroundColor};
      --text: {$textColor};
      --card: #ffffff;
      --muted: #64748b;
    }
    * { box-sizing: border-box; }
    body {
      margin: 0;
      font-family: Arial, sans-serif;
      background: linear-gradient(180deg, var(--bg), #ffffff);
      color: var(--text);
    }
    .wrapper {
      max-width: 1120px;
      margin: 0 auto;
      padding: 48px 24px 64px;
    }
    .hero {
      display: grid;
      gap: 32px;
      align-items: center;
      grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    }
    .eyebrow {
      color: var(--primary);
      font-weight: 700;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      font-size: 12px;
      margin-bottom: 12px;
    }
    h1 { font-size: 42px; line-height: 1.05; margin: 0 0 16px; }
    p.lead { font-size: 18px; line-height: 1.7; color: var(--muted); margin: 0 0 24px; }
    .price {
      font-size: 32px;
      font-weight: 700;
      margin-bottom: 20px;
    }
    .actions {
      display: flex;
      flex-wrap: wrap;
      gap: 12px;
      margin-bottom: 20px;
    }
    .button {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      padding: 14px 22px;
      border-radius: 999px;
      text-decoration: none;
      font-weight: 700;
      transition: opacity 0.2s ease;
    }
    .button.primary { background: var(--primary); color: #fff; }
    .button.secondary { background: #eef2ff; color: var(--primary); }
    .button:hover { opacity: 0.9; }
    .trust {
      color: var(--muted);
      font-size: 14px;
    }
    .hero-image img {
      width: 100%;
      max-width: 520px;
      border-radius: 24px;
      display: block;
      box-shadow: 0 24px 60px rgba(15, 23, 42, 0.15);
    }
    .card {
      margin-top: 40px;
      background: var(--card);
      border-radius: 24px;
      padding: 32px;
      box-shadow: 0 12px 40px rgba(15, 23, 42, 0.08);
    }
    ul { margin: 16px 0 0; padding-left: 20px; }
    li { margin-bottom: 10px; color: var(--muted); }
    .footer-note {
      margin-top: 24px;
      font-size: 13px;
      color: var(--muted);
    }
  </style>
</head>
<body>
  <main class="wrapper">
    <section class="hero">
      <div>
        <div class="eyebrow">Checkout Vector Pagamentos gerado por IA</div>
        <h1>{$headline}</h1>
        <p class="lead">{$subheadline}</p>
        <div class="price">{$price}</div>
        <div class="actions">
          <a class="button primary" href="{$checkoutUrl}" target="_blank" rel="noopener noreferrer">{$ctaLabel}</a>
          <a class="button secondary" href="{$siteUrl}" target="_blank" rel="noopener noreferrer">Ver site original</a>
        </div>
        <div class="trust">Pagamento processado pela Vector Pagamentos com checkout já conectado ao PIX.</div>
      </div>
      {$imageBlock}
    </section>

    <section class="card">
      <h2>O que este pacote entrega</h2>
      <ul>
        <li>Página HTML pronta para publicar ou hospedar em qualquer servidor estático.</li>
        <li>Botão principal conectado ao checkout público da Vector Pagamentos.</li>
        <li>Fluxo de pagamento PIX já operacional na infraestrutura da Vector Pagamentos.</li>
        {$benefitsHtml}
      </ul>
      <div class="footer-note">Origem analisada: {$siteUrl}</div>
    </section>
  </main>
</body>
</html>
HTML;
    }

    private function buildExportReadme(array $payload): string
    {
        $checkoutUrl = (string) ($payload['checkout_url'] ?? '');
        $siteUrl = (string) ($payload['site_url'] ?? '');
        $productName = (string) ($payload['product_name'] ?? 'Checkout Vector Pagamentos');

        return <<<MD
# {$productName}

Este pacote foi gerado automaticamente pela Vector IA com base no site informado pelo usuário.

## O que vem no pacote

- index.html: página pronta para publicar ou editar
- checkout-config.json: manifesto com dados do checkout gerado
- vectorpagamentos-checkout-package.zip: versão compactada para download

## Como usar

1. Publique o arquivo index.html em qualquer hospedagem estática.
2. O botão principal já aponta para o checkout público da Vector Pagamentos.
3. Quando o cliente clicar, ele será enviado para o checkout conectado ao PIX da Vector Pagamentos.

## Links

- Site de origem: {$siteUrl}
- Checkout Vector Pagamentos: {$checkoutUrl}

## Observação técnica

O pacote usa o checkout hospedado da Vector Pagamentos como destino do CTA principal. Isso evita expor chave secreta no navegador e mantém o PIX já conectado com a infraestrutura oficial da plataforma.
MD;
    }

    private function normalizeThemeOverrides(array $theme, ?string $fallbackPrimary = null): array
    {
        $primary = $this->sanitizeHexColor($theme['primaryColor'] ?? $fallbackPrimary ?? '#7c3aed') ?? '#7c3aed';
        $secondary = $this->sanitizeHexColor($theme['secondaryColor'] ?? $primary) ?? $primary;
        $background = $this->sanitizeHexColor($theme['backgroundColor'] ?? '#f8fafc') ?? '#f8fafc';
        $text = $this->sanitizeHexColor($theme['textColor'] ?? '#111827') ?? '#111827';

        return [
            'primaryColor' => $primary,
            'secondaryColor' => $secondary,
            'backgroundColor' => $background,
            'textColor' => $text,
        ];
    }

    private function sanitizeHexColor(?string $value): ?string
    {
        $color = trim((string) $value);
        if ($color === '') {
            return null;
        }

        return preg_match('/^#(?:[0-9a-fA-F]{3}){1,2}$/', $color) === 1 ? $color : null;
    }

    private function normalizeStringList(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }

        return array_values(array_filter(array_map(function ($item) {
            return is_string($item) ? trim($item) : null;
        }, $items)));
    }

    private function normalizeFaqItems(mixed $items): array
    {
        if (!is_array($items)) {
            return [];
        }

        $normalized = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }

            $question = trim((string) ($item['question'] ?? ''));
            $answer = trim((string) ($item['answer'] ?? ''));
            if ($question !== '' && $answer !== '') {
                $normalized[] = ['question' => $question, 'answer' => $answer];
            }
        }

        return $normalized;
    }

    private function extractHtmlTagContent(string $html, string $tagName): ?string
    {
        if (preg_match('/<' . preg_quote($tagName, '/') . '[^>]*>(.*?)<\/' . preg_quote($tagName, '/') . '>/is', $html, $matches)) {
            return trim(html_entity_decode(strip_tags($matches[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return null;
    }

    private function extractMetaContent(string $html, string $attributeName, string $attributeValue): ?string
    {
        $pattern = '/<meta[^>]*' . preg_quote($attributeName, '/') . '=["\']' . preg_quote($attributeValue, '/') . '["\'][^>]*content=["\']([^"\']+)["\'][^>]*>/i';
        if (preg_match($pattern, $html, $matches)) {
            return trim(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        $fallbackPattern = '/<meta[^>]*content=["\']([^"\']+)["\'][^>]*' . preg_quote($attributeName, '/') . '=["\']' . preg_quote($attributeValue, '/') . '["\'][^>]*>/i';
        if (preg_match($fallbackPattern, $html, $matches)) {
            return trim(html_entity_decode($matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }

        return null;
    }

    private function resolveAbsoluteUrl(string $baseUrl, ?string $maybeRelativeUrl): ?string
    {
        $url = trim((string) $maybeRelativeUrl);
        if ($url === '') {
            return null;
        }

        if (preg_match('/^https?:\/\//i', $url)) {
            return $url;
        }

        $base = parse_url($baseUrl);
        if (!$base || empty($base['scheme']) || empty($base['host'])) {
            return null;
        }

        $scheme = $base['scheme'];
        $host = $base['host'];

        if (str_starts_with($url, '//')) {
            return $scheme . ':' . $url;
        }

        if (str_starts_with($url, '/')) {
            return $scheme . '://' . $host . $url;
        }

        $path = isset($base['path']) ? rtrim(str_replace('\\', '/', dirname($base['path'])), '/') : '';
        return $scheme . '://' . $host . ($path !== '' && $path !== '.' ? '/' . ltrim($path, '/') : '') . '/' . ltrim($url, '/');
    }

    private function extractFirstPriceCents(string $text): ?int
    {
        if (preg_match('/R\$\s*([0-9]{1,3}(?:\.[0-9]{3})*(?:,[0-9]{2})|[0-9]+,[0-9]{2})/u', $text, $matches)) {
            $normalized = str_replace('.', '', $matches[1]);
            $normalized = str_replace(',', '.', $normalized);
            return (int) round(((float) $normalized) * 100);
        }

        return null;
    }
}
