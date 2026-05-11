<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LandingPage;
use App\Models\Product;
use App\Models\Checkout;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Landing Page Generator Service
 * 
 * Generates high-converting landing pages using AI
 */
class LandingPageGeneratorService
{
    private string $openrouterApiKey;
    private string $togetherApiKey;
    private string $defaultModel = 'google/gemini-2.0-flash-001';
    private string $imageModel = 'black-forest-labs/FLUX.1-schnell-Free';
    
    public function __construct()
    {
        $this->openrouterApiKey = config('services.openrouter.api_key') ?? env('OPENROUTER_API_KEY') ?? '';
        $this->togetherApiKey = config('services.together.api_key') ?? env('TOGETHER_API_KEY') ?? '';
        $imageModel = config('services.together.image_model');
        if ($imageModel) {
            $this->imageModel = $imageModel;
        }
    }

    /**
     * Generate a complete landing page from a prompt
     */
    public function generate(
        string $prompt,
        ?Product $product = null,
        ?Checkout $checkout = null,
        string $template = 'modern',
        array $options = []
    ): array {
        // Build context from product and checkout
        $context = $this->buildContext($product, $checkout, $options);
        
        // Generate page structure with AI
        $pageStructure = $this->generateWithAI($prompt, $context, $template);
        
        // Image generation disabled â€” skip generateProductImages
        // Remove any image fields from generated sections
        $pageStructure = $this->stripImageFields($pageStructure);
        
        // Enhance with conversion elements
        $enhancedStructure = $this->enhanceForConversion($pageStructure, $product, $checkout);
        
        return $enhancedStructure;
    }

    /**
     * Generate product images using AI-generated prompts + Pollinations.ai
     */
    private function generateProductImages(array $pageStructure, ?Product $product): array
    {
        $productName = $product->name ?? 'produto';
        $productDescription = $product->description ?? '';
        $productType = $product->type ?? 'digital';

        // Generate image prompts using AI
        $imagePrompts = $this->generateImagePromptsWithAI($productName, $productDescription, $productType);

        // Apply hero image
        if (!empty($imagePrompts['hero'])) {
            $heroImageUrl = $this->buildPollinationsUrl($imagePrompts['hero'], 1200, 800);
            foreach ($pageStructure['sections'] as &$section) {
                if ($section['type'] === 'hero') {
                    $section['content']['image'] = $heroImageUrl;
                    $section['content']['imagePrompt'] = $imagePrompts['hero'];
                    break;
                }
            }
        }

        // Apply testimonial avatars
        if (!empty($imagePrompts['testimonials'])) {
            foreach ($pageStructure['sections'] as &$section) {
                if ($section['type'] === 'testimonials' && isset($section['content']['items'])) {
                    foreach ($section['content']['items'] as $i => &$item) {
                        if (isset($imagePrompts['testimonials'][$i])) {
                            $item['photo'] = $this->buildPollinationsUrl(
                                $imagePrompts['testimonials'][$i], 200, 200
                            );
                        }
                    }
                    break;
                }
            }
        }

        // Store product image URL for later use
        $pageStructure['_generatedImages'] = [
            'hero' => $imagePrompts['hero'] ?? null,
            'heroUrl' => isset($imagePrompts['hero']) ? $this->buildPollinationsUrl($imagePrompts['hero'], 1200, 800) : null,
        ];

        return $pageStructure;
    }

    /**
     * Generate image prompts using AI based on product info
     */
    private function generateImagePromptsWithAI(string $productName, string $productDescription, string $productType): array
    {
        if (empty($this->openrouterApiKey)) {
            return $this->generateFallbackImagePrompts($productName, $productDescription, $productType);
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->openrouterApiKey}",
                'Content-Type' => 'application/json',
                'HTTP-Referer' => config('app.url', 'http://localhost:8000'),
                'X-Title' => 'Vector Pagamentos Image Generator',
            ])->withoutVerifying()->timeout(60)->post('https://openrouter.ai/api/v1/chat/completions', [
                'model' => $this->defaultModel,
                'messages' => [
                    ['role' => 'system', 'content' => $this->buildImagePromptSystemPrompt()],
                    ['role' => 'user', 'content' => "Produto: {$productName}\nDescri\u{00E7}\u{00E3}o: {$productDescription}\nTipo: {$productType}"],
                ],
                'temperature' => 0.8,
                'max_tokens' => 2000,
            ]);

            if ($response->successful()) {
                $content = $response->json('choices.0.message.content');
                $parsed = $this->extractJson($content);
                
                if ($parsed !== null && isset($parsed['hero'])) {
                    Log::info('AI generated image prompts successfully', ['prompts' => $parsed]);
                    return $parsed;
                }
            }

            Log::warning('Failed to generate image prompts via AI', [
                'status' => $response->status(),
            ]);
        } catch (\Exception $e) {
            Log::error('Image prompt generation error', ['error' => $e->getMessage()]);
        }

        return $this->generateFallbackImagePrompts($productName, $productDescription, $productType);
    }

    /**
     * Build system prompt for image prompt generation
     */
    private function buildImagePromptSystemPrompt(): string
    {
        return <<<PROMPT
Voc\u{00EA} \u{00E9} um especialista em gerar prompts para gera\u{00E7}\u{00E3}o de imagens com IA.

Sua tarefa \u{00E9} criar prompts em INGL\u{00CA}S para gerar imagens profissionais de produtos para landing pages de vendas.

REGRAS:
1. Prompts devem ser em INGL\u{00CA}S (melhor qualidade de imagem)
2. Prompts devem ser descritivos, profissionais e focados em marketing/vendas
3. N\u{00E3}o inclua texto nas imagens (sem letras, palavras ou n\u{00FA}meros visÃ­veis)
4. Use estilos modernos: ilumina\u{00E7}\u{00E3}o profissional, cores vibrantes, alta qualidade
5. Para produtos digitais (cursos, ebooks): use mockups, telas, ambientes de estudo/trabalho
6. Para produtos f\u{00ED}sicos: use fotos realistas com fundo clean
7. RESPONDA APENAS COM JSON PURO

RESPONDA COM ESTE JSON:
{
  "hero": "prompt detalhado para imagem principal do hero em ingl\u{00EA}s, professional product photography style, 16:9 aspect ratio",
  "testimonials": [
    "professional headshot of a happy Brazilian woman, natural lighting, warm smile, casual business attire",
    "professional headshot of a confident Brazilian man, studio lighting, friendly expression",
    "professional headshot of a young Brazilian woman, bright background, genuine smile"
  ]
}
PROMPT;
    }

    /**
     * Generate fallback image prompts when AI is unavailable
     */
    private function generateFallbackImagePrompts(string $productName, string $productDescription, string $productType): array
    {
        $heroPrompt = match($productType) {
            'digital', 'course', 'ebook' => "professional digital product mockup for {$productName}, modern laptop and tablet displaying course content, clean minimalist desk setup, soft studio lighting, high quality commercial photography, 16:9",
            'physical' => "professional product photography of {$productName}, clean white background, studio lighting, commercial quality, sharp details, 16:9",
            'service' => "professional business service concept for {$productName}, modern office environment, happy professionals collaborating, warm natural lighting, 16:9",
            'subscription' => "premium subscription service concept for {$productName}, digital interface on modern devices, futuristic clean design, professional studio shot, 16:9",
            default => "professional product showcase for {$productName}, modern clean design, studio lighting, high quality commercial photography, vibrant colors, 16:9",
        };

        return [
            'hero' => $heroPrompt,
            'testimonials' => [
                'professional headshot of a happy Brazilian woman in her 30s, natural lighting, warm genuine smile, casual business attire, blurred office background',
                'professional headshot of a confident Brazilian man in his 40s, studio lighting, friendly approachable expression, smart casual',
                'professional headshot of a young Brazilian woman in her 20s, bright natural background, genuine enthusiastic smile, casual style',
            ],
        ];
    }

    /**
     * Build an AI image URL from a prompt using available services
     */
    private function buildPollinationsUrl(string $prompt, int $width = 800, int $height = 600): string
    {
        return $this->buildImageUrlFromKeywords($prompt, $width, $height);
    }

    /**
     * Extract meaningful keywords from a text prompt for image search
     */
    private function extractImageKeywords(string $text): array
    {
        $stopWords = [
            'a', 'an', 'the', 'of', 'for', 'in', 'on', 'at', 'to', 'and', 'or', 'but',
            'is', 'are', 'was', 'were', 'be', 'been', 'have', 'has', 'do', 'does', 'did',
            'will', 'would', 'shall', 'should', 'may', 'might', 'must', 'can', 'could',
            'professional', 'high', 'quality', 'clean', 'beautiful', 'lighting',
            'studio', 'photography', 'photo', 'image', 'picture', 'background', 'style',
            'design', 'concept', 'natural', 'warm', 'soft', 'sharp', 'details', 'vibrant',
            'colors', 'commercial', 'premium', 'elegant', 'minimalist', 'genuine', 'happy',
            'confident', 'friendly', 'enthusiastic', 'approachable', 'casual', 'smart',
            'blurred', 'with', 'from', 'this', 'that', 'these', 'those', 'very', 'real',
            'displaying', 'showing', 'using', 'creating', 'about', 'como', 'para', 'de',
            'um', 'uma', 'dos', 'das', 'no', 'na', 'com', 'por', 'mais', 'seu', 'sua',
            'img', 'content', 'attire', 'expression', 'smile', 'setup', 'shot', 'mockup',
        ];

        $text = strtolower(trim($text));
        // Remove [IMG:...] wrapper if present
        $text = preg_replace('/^\[img:\s*/i', '', $text);
        $text = preg_replace('/\]$/', '', $text);
        $words = preg_split('/[\s,.:;!?]+/', $text);
        $keywords = [];

        foreach ($words as $word) {
            $word = trim($word);
            if (strlen($word) > 2 && !in_array($word, $stopWords) && !is_numeric($word)) {
                $keywords[] = $word;
            }
        }

        return array_values(array_unique(array_slice($keywords, 0, 5)));
    }

    /**
     * Build image URL from keyword text using loremflickr.com for relevant results
     */
    private function buildImageUrlFromKeywords(string $text, int $width = 800, int $height = 600): string
    {
        $keywords = $this->extractImageKeywords($text);
        $seed = abs(crc32($text)) % 100000;

        if (!empty($keywords)) {
            $keywordsPath = implode(',', array_slice($keywords, 0, 3));
            return "https://loremflickr.com/{$width}/{$height}/{$keywordsPath}?lock={$seed}";
        }

        return "https://picsum.photos/seed/{$seed}/{$width}/{$height}";
    }

    /**
     * Determine if a hex color is dark based on luminance
     */
    private function isColorDark(string $hexColor): bool
    {
        $hex = ltrim($hexColor, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        if (strlen($hex) !== 6) return false;
        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));
        // Relative luminance (ITU-R BT.709)
        $luminance = (0.2126 * $r + 0.7152 * $g + 0.0722 * $b) / 255;
        return $luminance < 0.5;
    }

    /**
     * Adapt a section backgroundColor for the current theme.
     * If the section bg is light but the overall theme is dark (or vice versa),
     * replace it with a compatible alternative.
     */
    private function adaptSectionBg(string $bgColor, array $theme): string
    {
        if ($bgColor === 'transparent' || $bgColor === 'gradient') {
            return $bgColor;
        }
        $themeBg = $theme['backgroundColor'] ?? '#ffffff';
        $themeIsDark = $this->isColorDark($themeBg);
        $sectionIsDark = $this->isColorDark($bgColor);

        // If theme is dark but section bg is light, darken the section bg
        if ($themeIsDark && !$sectionIsDark) {
            // Map common light backgrounds to dark equivalents
            $lightToDark = [
                '#ffffff' => $themeBg,
                '#fafafa' => $themeBg,
                '#f9fafb' => '#111827',
                '#f3f4f6' => '#1f2937',
                '#f0fdf4' => '#052e16',
                '#fdf4ff' => '#2e1065',
                '#fffbeb' => '#451a03',
            ];
            return $lightToDark[strtolower($bgColor)] ?? $themeBg;
        }

        // If theme is light but section bg is dark (and it's not the primary color), lighten
        if (!$themeIsDark && $sectionIsDark) {
            $primaryColor = $theme['primaryColor'] ?? '';
            if (strtolower($bgColor) !== strtolower($primaryColor)) {
                return $themeBg;
            }
        }

        return $bgColor;
    }

    /**
     * Sanitize AI-generated content: strip HTML tags from text fields
     * Prevents HTML injection in headlines, descriptions, etc.
     */
    /**
     * Recursively decode unicode escape sequences in all string values
     * Handles both \u{XXXX} (PHP-style) and \uXXXX (JSON-style) patterns
     */
    private function decodeUnicodeEscapes($data)
    {
        if (is_string($data)) {
            // Replace \u{XXXX} patterns (PHP unicode escape syntax)
            $data = preg_replace_callback('/\\\\u\{([0-9a-fA-F]{1,6})\}/', function ($matches) {
                return mb_chr(hexdec($matches[1]), 'UTF-8');
            }, $data);

            // Replace \uXXXX patterns (JSON unicode escape syntax that wasn't decoded)
            $data = preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/', function ($matches) {
                return mb_chr(hexdec($matches[1]), 'UTF-8');
            }, $data);

            return $data;
        }

        if (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->decodeUnicodeEscapes($value);
            }
        }

        return $data;
    }

    private function sanitizeContent(array $content): array
    {
        // Decode any unicode escape sequences (\u{XXXX} or \uXXXX) in all string values
        $content = $this->decodeUnicodeEscapes($content);

        // Text fields that should never contain HTML
        $textFields = ['headline', 'subheadline', 'description', 'ctaText', 'ctaSecondary', 
                       'badge', 'title', 'subtitle', 'name', 'role', 'text', 'question', 'answer',
                       'label', 'value', 'guarantee'];

        if (isset($content['sections']) && is_array($content['sections'])) {
            foreach ($content['sections'] as &$section) {
                if (isset($section['content']) && is_array($section['content'])) {
                    // Sanitize top-level text fields in content
                    foreach ($textFields as $field) {
                        if (isset($section['content'][$field]) && is_string($section['content'][$field])) {
                            $section['content'][$field] = strip_tags($section['content'][$field]);
                        }
                    }
                    // Sanitize items arrays (benefits, features, testimonials, FAQ, etc.)
                    if (isset($section['content']['items']) && is_array($section['content']['items'])) {
                        foreach ($section['content']['items'] as &$item) {
                            if (is_array($item)) {
                                foreach ($textFields as $field) {
                                    if (isset($item[$field]) && is_string($item[$field])) {
                                        $item[$field] = strip_tags($item[$field]);
                                    }
                                }
                            }
                        }
                    }
                    // Sanitize pricing tiers
                    if (isset($section['content']['tiers']) && is_array($section['content']['tiers'])) {
                        foreach ($section['content']['tiers'] as &$tier) {
                            if (is_array($tier)) {
                                foreach ($textFields as $field) {
                                    if (isset($tier[$field]) && is_string($tier[$field])) {
                                        $tier[$field] = strip_tags($tier[$field]);
                                    }
                                }
                                if (isset($tier['features']) && is_array($tier['features'])) {
                                    $tier['features'] = array_map(function ($f) {
                                        return is_string($f) ? strip_tags($f) : $f;
                                    }, $tier['features']);
                                }
                            }
                        }
                    }
                }
            }
        }

        // Sanitize meta fields
        if (isset($content['meta']) && is_array($content['meta'])) {
            foreach (['title', 'description'] as $field) {
                if (isset($content['meta'][$field]) && is_string($content['meta'][$field])) {
                    $content['meta'][$field] = strip_tags($content['meta'][$field]);
                }
            }
        }

        return $content;
    }

    /**
     * Build context from product and checkout data
     */
    private function buildContext(?Product $product, ?Checkout $checkout, array $options): array
    {
        $context = [
            'language' => $options['language'] ?? 'pt-BR',
            'tone' => $options['tone'] ?? 'professional', // professional, casual, urgent, luxury
            'target_audience' => $options['target_audience'] ?? null,
        ];

        if ($product) {
            $context['product'] = [
                'name' => $product->name,
                'description' => $product->description,
                'price' => $product->price / 100, // Convert from cents
                'original_price' => $product->original_price ? $product->original_price / 100 : null,
                'type' => $product->type,
                'category' => $product->category?->name ?? null,
                'features' => $product->features ?? [],
                'benefits' => $product->benefits ?? [],
                'images' => $product->images ?? [],
            ];
        }

        if ($checkout) {
            $context['checkout'] = [
                'id' => $checkout->id,
                'slug' => $checkout->slug,
                'url' => url("/checkout/{$checkout->slug}"),
                'payment_methods' => $checkout->settings['payment_methods'] ?? ['pix', 'credit_card'],
                'max_installments' => $checkout->settings['max_installments'] ?? 12,
            ];
        }

        return $context;
    }

    /**
     * Generate page structure using OpenAI
     */
    private function generateWithAI(string $prompt, array $context, string $template): array
    {
        $systemPrompt = $this->buildSystemPrompt($template, $context);
        $userPrompt = $this->buildUserPrompt($prompt, $context);

        // Check if we have an API key
        if (empty($this->openrouterApiKey)) {
            Log::warning('OpenRouter API key not configured, using fallback generator');
            return $this->generateFallback($prompt, $context, $template);
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->openrouterApiKey}",
                'Content-Type' => 'application/json',
                'HTTP-Referer' => config('app.url', 'http://localhost:8000'),
                'X-Title' => 'Vector Pagamentos Landing Page Generator',
            ])->withoutVerifying()->timeout(120)->post('https://openrouter.ai/api/v1/chat/completions', [
                'model' => $this->defaultModel,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user', 'content' => $userPrompt],
                ],
                'temperature' => 0.7,
                'max_tokens' => 8000,
            ]);

            if ($response->successful()) {
                $content = $response->json('choices.0.message.content');
                $parsed = $this->extractJson($content);
                
                if ($parsed !== null && isset($parsed['sections'])) {
                    Log::info('OpenRouter AI generated landing page successfully');
                    return $this->sanitizeContent($parsed);
                }
                
                Log::warning('OpenRouter response did not contain valid sections', [
                    'content_preview' => substr($content ?? '', 0, 500),
                ]);
            }

            Log::error('OpenRouter API error', [
                'status' => $response->status(),
                'body' => substr($response->body(), 0, 1000),
            ]);

        } catch (\Exception $e) {
            Log::error('OpenRouter API exception', ['error' => $e->getMessage()]);
        }

        // Fallback to template-based generation
        return $this->generateFallback($prompt, $context, $template);
    }

    /**
     * Build system prompt for AI
     */
    private function buildSystemPrompt(string $template, array $context): string
    {
        $language = $context['language'] ?? 'pt-BR';
        $tone = $context['tone'] ?? 'professional';
        $templateGuide = $this->getTemplateDesignGuide($template);
        $toneGuide = $this->getToneGuide($tone);
        $randomSeed = mt_rand(1000, 9999);
        
        return <<<PROMPT
VocÃª Ã© um designer web e copywriter de elite. Sua especialidade Ã© criar pÃ¡ginas de vendas que parecem ter sido criadas artesanalmente por uma agÃªncia premium â€” NUNCA com cara de "feita por IA" ou "template genÃ©rico".

MISSÃƒO: Criar uma landing page ÃšNICA, MEMORÃVEL e de ALTA CONVERSÃƒO.

PRINCÃPIOS DE DESIGN (obrigatÃ³rios):
- Cada pÃ¡gina deve parecer um projeto exclusivo de R$ 15.000+
- NUNCA use frases genÃ©ricas como "Transforme sua vida" ou "A soluÃ§Ã£o definitiva" â€” seja ESPECÃFICO ao produto
- Headlines devem ser provocativas, curiosas e impossÃ­veis de ignorar
- Use nÃºmeros Ã­mpares e dados especÃ­ficos (ex: "127 alunos jÃ¡ aplicaram", "Em 23 minutos")
- SubtÃ­tulos devem resolver uma objeÃ§Ã£o ou ampliar a curiosidade
- CTAs devem ser conversacionais e urgentes, nunca genÃ©ricos (nada de "Saiba mais" ou "Comprar")
- BenefÃ­cios com exemplos concretos e tangÃ­veis, nÃ£o abstraÃ§Ãµes
- Depoimentos que pareÃ§am REAIS â€” com detalhes especÃ­ficos, incertezas iniciais, resultados mensurÃ¡veis
- Use humor sutil, empatia e storytelling quando apropriado

SEMENTE CRIATIVA: {$randomSeed} (use para variar abordagens â€” cada pÃ¡gina deve ser diferente)

{$templateGuide}

{$toneGuide}

REGRAS TÃ‰CNICAS:
1. Linguagem: {$language}
2. RESPONDA APENAS COM JSON PURO â€” sem markdown, sem backticks, sem comentÃ¡rios
3. NUNCA inclua HTML nos valores (sem <span>, <b>, etc.) â€” apenas texto puro
4. Gere entre 7-10 seÃ§Ãµes para uma pÃ¡gina completa e rica
5. Ãcones vÃ¡lidos: rocket, shield-check, users, star, lightning, chart-bar, clock, heart, fire, target, trophy, diamond, zap, crown, gift, lock, check-circle, arrow-right, sparkles, globe
6. imagePrompt DEVE ser em INGLÃŠS, descritivo e profissional

ESTRUTURA JSON:
{
  "meta": {
    "title": "TÃ­tulo SEO otimizado (50-60 chars)",
    "description": "Meta description persuasiva (120-155 chars)",
    "keywords": ["keyword1", "keyword2", "keyword3"]
  },
  "theme": {
    "primaryColor": "#HEX",
    "secondaryColor": "#HEX",
    "backgroundColor": "#HEX",
    "textColor": "#HEX",
    "accentColor": "#HEX",
    "fontFamily": "nome-da-fonte",
    "borderRadius": "sm|md|lg|xl|2xl|3xl|full",
    "style": "{$template}"
  },
  "sections": [
    {
      "type": "hero|benefits|features|testimonials|pricing|faq|video|guarantee|about|countdown|cta|stats|logos",
      "id": "unique-id",
      "settings": {
        "backgroundColor": "#HEX ou gradient ou transparent",
        "padding": "py-16 lg:py-24",
        "alignment": "center|left",
        "layout": "split|centered|asymmetric",
        "animation": "fade-up|fade-left|fade-right|zoom-in|slide-up|blur-in|none"
      },
      "content": {}
    }
  ]
}

TIPOS DE SEÃ‡ÃƒO:

1. hero:
{
  "headline": "Headline provocativa e impossÃ­vel de ignorar",
  "subheadline": "Expande a curiosidade ou resolve objeÃ§Ã£o principal",
  "description": "ParÃ¡grafo que cria conexÃ£o emocional â€” nÃ£o venda, converse",
  "ctaText": "CTA urgente e conversacional (ex: Quero meu acesso agora)",
  "ctaSecondary": "Micro-compromisso (ex: Cancele quando quiser â€¢ Sem taxas ocultas)",
  "image": null,
  "imagePrompt": "English prompt: professional, cinematic, no text in image",
  "badge": "Texto curto destacado (ex: VAGAS LIMITADAS ou NOVO ou 70% OFF HOJE)",
  "videoUrl": "URL do vÃ­deo (apenas para template VSL)"
}

2. stats:
{
  "items": [
    { "value": "12.847+", "label": "Clientes satisfeitos" },
    { "value": "4.9/5", "label": "AvaliaÃ§Ã£o mÃ©dia" },
    { "value": "97%", "label": "Taxa de aprovaÃ§Ã£o" },
    { "value": "24h", "label": "Suporte disponÃ­vel" }
  ]
}

3. benefits:
{
  "title": "TÃ­tulo que gera identificaÃ§Ã£o",
  "subtitle": "SubtÃ­tulo com empatia",
  "items": [
    {
      "icon": "nome-do-icone",
      "title": "BenefÃ­cio especÃ­fico e tangÃ­vel",
      "description": "ExplicaÃ§Ã£o com exemplo real ou nÃºmero concreto"
    }
  ]
}

4. features:
{
  "title": "O que estÃ¡ incluso",
  "subtitle": "SubtÃ­tulo",
  "items": [
    {
      "icon": "icone",
      "title": "Feature concreta",
      "description": "DescriÃ§Ã£o com valor percebido"
    }
  ]
}

5. testimonials:
{
  "title": "Resultados reais de pessoas reais",
  "subtitle": "SubtÃ­tulo",
  "items": [
    {
      "name": "Nome brasileiro realista",
      "photo": null,
      "role": "ProfissÃ£o/Cidade especÃ­fica",
      "text": "Depoimento com: 1) incerteza inicial, 2) momento de virada, 3) resultado especÃ­fico. Minimize 50 palavras.",
      "rating": 5
    }
  ]
}

6. pricing:
{
  "title": "Headline sobre valor, nÃ£o preÃ§o",
  "subtitle": "Ancoragem de preÃ§o inteligente",
  "originalPrice": "R$ X.XXX",
  "currentPrice": "R$ XXX",
  "discount": "XX% OFF",
  "installments": "ou 12x de R$ XX,XX sem juros",
  "features": ["item com check incluÃ­do", "outro item valioso"],
  "ctaText": "CTA URGENTE E CLARO",
  "urgency": "Mensagem de escassez autÃªntica"
}

7. faq:
{
  "title": "Suas dÃºvidas, respondidas",
  "items": [
    {
      "question": "Pergunta que o cliente realmente faria?",
      "answer": "Resposta honesta, direta e que supera a objeÃ§Ã£o"
    }
  ]
}

8. guarantee:
{
  "title": "Garantia clara (ex: 7 dias sem risco)",
  "description": "Texto humanizado que elimina o risco percebido",
  "icon": "shield-check"
}

9. countdown:
{
  "title": "Headline de urgÃªncia",
  "endDate": "data-futura",
  "urgencyText": "Mensagem de escassez"
}

10. cta:
{
  "title": "Headline final que fecha o ciclo emocional",
  "description": "Ãšltimo argumento â€” fale com quem ainda tem dÃºvida",
  "buttonText": "CTA FINAL PODEROSO",
  "buttonSecondary": "Garantia + seguranÃ§a"
}

11. logos (prova social):
{
  "title": "JÃ¡ usado por profissionais de",
  "items": ["Empresa 1", "Empresa 2", "Empresa 3", "Empresa 4", "Empresa 5"]
}
PROMPT;
    }

    /**
     * Get design guide specific to each template style
     */
    private function getTemplateDesignGuide(string $template): string
    {
        $guides = [
            'modern' => <<<GUIDE
TEMPLATE: MODERNO
Filosofia: Design limpo, sofisticado e atual â€” inspirado em sites como Linear, Vercel, Stripe.
Cores: Use tons de indigo/violet como primÃ¡rio com backgrounds brancos e off-whites. Acentos em verde ou cyan.
Tipografia: Clean, sem serif, peso bold nos tÃ­tulos e light nos textos.
Layout: Generoso em espaÃ§o branco. Hero com layout split (texto esquerda, imagem direita). Cards com bordas suaves (rounded-2xl), sombras sutis.
Detalhes: Gradient text nos tÃ­tulos principais. Micro-sombras nos cards. Badges com backgrounds suaves. CTAs com gradiente sutil.
SeÃ§Ãµes obrigatÃ³rias: hero (layout split), stats, benefits, features, testimonials, pricing, guarantee, faq, cta.
AnimaÃ§Ãµes: Fade-up suave ao scroll. Cards com hover elevation.
Paleta sugerida: primary #6366f1, secondary #8b5cf6, bg #ffffff, text #0f172a, accent #10b981
GUIDE,
            'bold' => <<<GUIDE
TEMPLATE: IMPACTANTE
Filosofia: Audacioso, energÃ©tico, impossÃ­vel de ignorar â€” inspirado em pÃ¡ginas de lanÃ§amento digital, estilo guru americano.
Cores: Vermelho/laranja como primÃ¡rio, amarelo como acento. Backgrounds claros com seÃ§Ãµes em cores fortes.
Tipografia: BOLD e GRANDE. TÃ­tulos enormes, uppercase quando apropriado. NÃºmeros XXL.
Layout: Full-width hero. SeÃ§Ãµes com backgrounds alternando entre branco e cores vibrantes. Pricing com destaque mÃ¡ximo.
Detalhes: Badges chamativos, emojis nos benefÃ­cios, urgÃªncia real. Countdown section obrigatÃ³rio. MÃºltiplos CTAs.
SeÃ§Ãµes obrigatÃ³rias: hero (centered, grande), stats, benefits (com emojis), testimonials, pricing (destacado), countdown, guarantee, faq, cta.
AnimaÃ§Ãµes: Slide-up rÃ¡pido, pulse nos CTAs, shake na urgÃªncia.
Paleta sugerida: primary #dc2626, secondary #ea580c, bg #fafafa, text #171717, accent #eab308
GUIDE,
            'minimal' => <<<GUIDE
TEMPLATE: MINIMALISTA
Filosofia: Menos Ã© mais â€” elegÃ¢ncia atravÃ©s da simplicidade. Inspirado em Apple, Muji, design japonÃªs.
Cores: Preto e branco predominantes. MÃ¡ximo 2 cores. Acentos mÃ­nimos e pontuais.
Tipografia: Leve, espaÃ§osa. Tamanhos modestos. Muito espaÃ§o entre letras. Lowercase preferÃ­vel.
Layout: AbundÃ¢ncia de espaÃ§o branco. Hero centralizado sem imagem (ou com imagem sutil). Grid simples. Zero decoraÃ§Ã£o desnecessÃ¡ria.
Detalhes: Bordas finas. Sem sombras pesadas. Ãcones line-style mÃ­nimos. CTAs com outline ou com fundo sÃ³lido mÃ­nimo.
SeÃ§Ãµes obrigatÃ³rias: hero (centered, clean), benefits (grid simples), testimonials (citaÃ§Ã£o minimalista), pricing (clean), faq, cta.
AnimaÃ§Ãµes: Fade-in lento e suave. Sem exageros. Blur-in para textos.
Paleta sugerida: primary #18181b, secondary #3f3f46, bg #ffffff, text #27272a, accent #6366f1
GUIDE,
            'dark' => <<<GUIDE
TEMPLATE: DARK MODE
Filosofia: Profissional, tecnolÃ³gico, premium â€” inspirado em sites de SaaS, cripto, tech. Fundo escuro com elementos que brilham.
Cores: Background #0a0a0f ou prÃ³ximo. PrimÃ¡rio em roxo/violeta neon. Acentos em cyan/teal que brilham.
Tipografia: Branca/clara. Titulos com glow sutil. Contraste alto.
Layout: Hero com gradientes sutis no background. Cards com backgrounds semi-transparentes e borders luminosos. Efeito glassmorphism.
Detalhes: Bordas que brilham (border com opacity). Gradientes nos Ã­cones. Cards com backdrop-blur. Efeito glow nos botÃµes.
SeÃ§Ãµes obrigatÃ³rias: hero (centered com glow), stats (nÃºmeros brilhantes), benefits (cards glass), testimonials, pricing (card com borda glow), guarantee, faq, cta (com gradiente).
AnimaÃ§Ãµes: Fade-up com glow. Zoom-in suave. Hover com brilho.
Paleta sugerida: primary #8b5cf6, secondary #a855f7, bg #0a0a0f, text #f1f5f9, accent #22d3ee
GUIDE,
            'gradient' => <<<GUIDE
TEMPLATE: GRADIENTE
Filosofia: Moderno e vibrante â€” inspirado em Instagram, Figma, designs contemporÃ¢neos. Gradientes como elemento central.
Cores: Gradientes primÃ¡rios (pinkâ†’purple ou blueâ†’cyan). Background claro ou levemente colorido. Sections com gradient backgrounds.
Tipografia: Semi-bold, cores vibrantes. TÃ­tulos com gradient text. SubtÃ­tulos com cores complementares.
Layout: Hero com background gradient grande. Cards com gradient borders. Ãcones com gradient backgrounds. Formas orgÃ¢nicas.
Detalhes: Gradient text extensivo. Blobs decorativos com blur como background. Cards com gradient border (1px). BotÃµes com gradient animado.
SeÃ§Ãµes obrigatÃ³rias: hero (centered, gradient bg), stats, benefits (gradient icon bg), testimonials (gradient avatars), pricing (gradient header), guarantee, faq, cta (gradient section).
AnimaÃ§Ãµes: Slide-up fluido. Gradient shift animation nos botÃµes. Blob floating.
Paleta sugerida: primary #ec4899, secondary #8b5cf6, bg #fdf4ff, text #1e1b4b, accent #06b6d4
GUIDE,
            'video' => <<<GUIDE
TEMPLATE: VSL (Video Sales Letter)
Filosofia: A pÃ¡gina inteira serve ao vÃ­deo. O vÃ­deo Ã© o vendedor, a pÃ¡gina Ã© o suporte â€” inspirado em funnels de Russell Brunson, ClickFunnels.
Cores: Fundo escuro para o vÃ­deo ter destaque. Cores quentes e urgentes. Vermelho/laranja nos CTAs.
Tipografia: Grande e impactante no hero. Restante discreto para nÃ£o competir com o vÃ­deo.
Layout: Hero dominado por embed de vÃ­deo (16:9) centralizado. Abaixo poucas seÃ§Ãµes de suporte. Pricing direto. Menos texto, mais visual.
Detalhes: Player de vÃ­deo destacado com sombra. Headline antes do vÃ­deo (curiosidade). CTA logo abaixo do vÃ­deo. Social proof mÃ­nimo mas impactante. Countdown se houver urgÃªncia.
SeÃ§Ãµes obrigatÃ³rias: hero (com videoUrl e headline provocativa), stats (prova social rÃ¡pida), benefits (resumido, 3-4 itens), pricing (direto e urgente), guarantee, cta.
AnimaÃ§Ãµes: Fade-in no vÃ­deo. Pulse no CTA abaixo do vÃ­deo.
Paleta sugerida: primary #dc2626, secondary #f97316, bg #111111, text #f5f5f5, accent #fbbf24
GUIDE,
        ];

        return $guides[$template] ?? $guides['modern'];
    }

    /**
     * Get tone-specific copywriting guide
     */
    private function getToneGuide(string $tone): string
    {
        $guides = [
            'professional' => <<<TONE
TOM: PROFISSIONAL
- Linguagem clara, direta e confiÃ¡vel
- Dados e evidÃªncias ao invÃ©s de emoÃ§Ã£o pura
- Evite exclamaÃ§Ãµes excessivas e caixa alta abusiva
- Use termos tÃ©cnicos do nicho quando relevante
- Credibilidade antes de emoÃ§Ã£o
- CTAs assertivos mas elegantes (ex: "ComeÃ§ar agora" vs "COMPRE JÃÃÃ")
TONE,
            'casual' => <<<TONE
TOM: CASUAL E AMIGÃVEL
- Escreva como se estivesse conversando com um amigo
- Use contraÃ§Ãµes e linguagem informal (mas nÃ£o vulgar)
- Humor leve e referÃªncias do dia a dia
- Emojis com moderaÃ§Ã£o nos badges e CTAs
- Perguntas retÃ³ricas para engajar
- CTAs descontraÃ­dos (ex: "Bora lÃ¡?" ou "Quero essa!")
TONE,
            'urgent' => <<<TONE
TOM: URGÃŠNCIA E ESCASSEZ
- Crie senso de oportunidade Ãºnica
- Use nÃºmeros decrescentes e prazos especÃ­ficos
- Palavras-chave: "Ãºltimas vagas", "oferta encerra", "apenas hoje", "restam poucas"
- Countdown section Ã© OBRIGATÃ“RIO
- Destaque o que a pessoa PERDE se nÃ£o agir agora
- CTAs com urgÃªncia explÃ­cita (ex: "GARANTIR ANTES QUE ACABE")
- Badge no hero DEVE ter urgÃªncia
TONE,
            'luxury' => <<<TONE
TOM: LUXO E EXCLUSIVIDADE
- Linguagem sofisticada e aspiracional
- Menos Ã© mais â€” cada palavra deve ter peso
- Palavras-chave: "exclusivo", "seleto", "premium", "reservado", "curadoria"
- NUNCA use "barato" ou "desconto" â€” use "investimento", "condiÃ§Ã£o especial", "acesso antecipado"
- Evite emojis e exclamaÃ§Ãµes
- CTAs elegantes (ex: "Solicitar acesso" ou "Reservar minha vaga")
- Depoimentos de figuras de autoridade ou pessoas com cargos impressionantes
TONE,
        ];

        return $guides[$tone] ?? $guides['professional'];
    }

    /**
     * Build user prompt with product context
     */
    private function buildUserPrompt(string $prompt, array $context): string
    {
        $productInfo = '';
        if (isset($context['product'])) {
            $p = $context['product'];
            $price = $p['price'] ?? 0;
            $originalPrice = $p['original_price'] ?? null;
            $priceInfo = "R$ " . number_format($price, 2, ',', '.');
            if ($originalPrice) {
                $priceInfo .= " (de R$ " . number_format($originalPrice, 2, ',', '.') . ")";
            }
            $productInfo = <<<INFO

INFORMAÃ‡Ã•ES DO PRODUTO:
- Nome: {$p['name']}
- DescriÃ§Ã£o: {$p['description']}
- PreÃ§o: {$priceInfo}
- Tipo: {$p['type']}
INFO;
            if (!empty($p['features'])) {
                $features = implode(', ', array_slice($p['features'], 0, 5));
                $productInfo .= "\n- Features: {$features}";
            }
            if (!empty($p['benefits'])) {
                $benefits = implode(', ', array_slice($p['benefits'], 0, 5));
                $productInfo .= "\n- BenefÃ­cios: {$benefits}";
            }
        }

        $checkoutInfo = '';
        if (isset($context['checkout'])) {
            $c = $context['checkout'];
            $checkoutInfo = "\n\nURL DO CHECKOUT: {$c['url']}";
            $methods = implode(', ', $c['payment_methods'] ?? ['pix', 'credit_card']);
            $checkoutInfo .= "\nMÃ©todos de pagamento: {$methods}";
            if (isset($c['max_installments']) && $c['max_installments'] > 1) {
                $checkoutInfo .= "\nParcelamento: atÃ© {$c['max_installments']}x";
            }
        }

        return <<<USERPROMPT
Crie uma landing page de vendas ÃšNICA e de ALTA CONVERSÃƒO. FaÃ§a parecer que foi criada por uma agÃªncia de design premium â€” NÃƒO deve parecer genÃ©rica ou feita por IA.

DESCRIÃ‡ÃƒO DO USUÃRIO:
{$prompt}
{$productInfo}
{$checkoutInfo}

INSTRUÃ‡Ã•ES ESPECÃFICAS:
1. A headline deve ser IMPOSSÃVEL DE IGNORAR â€” use curiosidade, dados especÃ­ficos ou uma promessa ousada
2. Gere 3 depoimentos REALISTAS com nomes brasileiros, profissÃµes diversas e histÃ³rias com detalhes especÃ­ficos
3. Inclua pelo menos: hero, stats ou logos (prova social), benefits, testimonials, pricing, guarantee, faq (5+ perguntas) e cta final
4. Os preÃ§os devem ser baseados nos dados reais do produto acima
5. NÃƒO inclua imagens na pÃ¡gina â€” deixe campos "image" e "photo" vazios (string vazia ""). O sistema nÃ£o suporta imagens no momento
6. Cada seÃ§Ã£o deve ter uma animation definida nos settings (fade-up, fade-left, fade-right, zoom-in, blur-in)
7. Use o theme.style do template escolhido para definir cores coerentes
USERPROMPT;
    }

    /**
     * Extract JSON from AI response, handling markdown code fences
     */
    private function extractJson(?string $content): ?array
    {
        if (empty($content)) {
            return null;
        }

        // Pre-process: convert \u{XXXX} (PHP-style) to actual UTF-8 chars before JSON parsing
        // This pattern is not valid JSON and would cause json_decode to fail
        $content = preg_replace_callback('/\\\\u\{([0-9a-fA-F]{1,6})\}/', function ($matches) {
            return mb_chr(hexdec($matches[1]), 'UTF-8');
        }, $content);

        // Try direct parse first
        $parsed = json_decode($content, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $parsed;
        }

        // Strip markdown code fences (```json ... ``` or ``` ... ```)
        $cleaned = preg_replace('/^```(?:json)?\s*\n?/i', '', trim($content));
        $cleaned = preg_replace('/\n?```\s*$/i', '', $cleaned);
        $cleaned = trim($cleaned);

        $parsed = json_decode($cleaned, true);
        if (json_last_error() === JSON_ERROR_NONE) {
            return $parsed;
        }

        // Try to find JSON object in the content
        if (preg_match('/\{[\s\S]*\}/m', $content, $matches)) {
            $parsed = json_decode($matches[0], true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $parsed;
            }
        }

        Log::warning('Failed to extract JSON from AI response', [
            'content_preview' => substr($content, 0, 300),
            'json_error' => json_last_error_msg(),
        ]);

        return null;
    }

    /**
     * Fallback generator when AI is not available
     */
    private function generateFallback(string $prompt, array $context, string $template): array
    {
        $product = $context['product'] ?? null;
        $checkout = $context['checkout'] ?? null;
        $tone = $context['tone'] ?? 'professional';
        
        $productName = $product['name'] ?? 'Produto IncrÃ­vel';
        $productDescription = $product['description'] ?? 'A soluÃ§Ã£o que vocÃª precisa';
        $price = $product['price'] ?? 497;
        $originalPrice = $product['original_price'] ?? $price * 2;
        $checkoutUrl = $checkout['url'] ?? '#checkout';
        $productType = $product['type'] ?? 'digital';

        // Template colors based on selected template
        $themes = [
            'modern' => [
                'primaryColor' => '#6366f1',
                'secondaryColor' => '#8b5cf6',
                'backgroundColor' => '#ffffff',
                'textColor' => '#0f172a',
                'accentColor' => '#10b981',
                'style' => 'modern',
            ],
            'bold' => [
                'primaryColor' => '#dc2626',
                'secondaryColor' => '#ea580c',
                'backgroundColor' => '#fafafa',
                'textColor' => '#171717',
                'accentColor' => '#eab308',
                'style' => 'bold',
            ],
            'minimal' => [
                'primaryColor' => '#18181b',
                'secondaryColor' => '#3f3f46',
                'backgroundColor' => '#ffffff',
                'textColor' => '#27272a',
                'accentColor' => '#6366f1',
                'style' => 'minimal',
            ],
            'dark' => [
                'primaryColor' => '#8b5cf6',
                'secondaryColor' => '#a855f7',
                'backgroundColor' => '#0a0a0f',
                'textColor' => '#f1f5f9',
                'accentColor' => '#22d3ee',
                'style' => 'dark',
            ],
            'gradient' => [
                'primaryColor' => '#ec4899',
                'secondaryColor' => '#8b5cf6',
                'backgroundColor' => '#fdf4ff',
                'textColor' => '#1e1b4b',
                'accentColor' => '#06b6d4',
                'style' => 'gradient',
            ],
            'video' => [
                'primaryColor' => '#dc2626',
                'secondaryColor' => '#f97316',
                'backgroundColor' => '#111111',
                'textColor' => '#f5f5f5',
                'accentColor' => '#fbbf24',
                'style' => 'video',
            ],
        ];

        $theme = $themes[$template] ?? $themes['modern'];
        $discount = round((1 - $price / max($originalPrice, 1)) * 100);
        $installmentValue = number_format($price / 12, 2, ',', '.');

        // Generate dynamic content based on product name
        $heroHeadline = $this->generateFallbackHeadline($productName, $productType, $tone);
        $heroSubheadline = $this->generateFallbackSubheadline($productName, $productType, $tone);

        $sections = [
            [
                'type' => 'hero',
                'id' => 'hero-section',
                'settings' => [
                    'backgroundColor' => 'gradient',
                    'padding' => 'py-20 lg:py-32',
                    'alignment' => 'center',
                    'animation' => 'fade-up',
                ],
                'content' => [
                    'badge' => $tone === 'urgent' ? 'â° ÃšLTIMAS VAGAS' : ($tone === 'luxury' ? 'ACESSO EXCLUSIVO' : 'OFERTA ESPECIAL'),
                    'headline' => $heroHeadline,
                    'subheadline' => $heroSubheadline,
                    'description' => $productDescription,
                    'ctaText' => $this->generateFallbackCTA($tone),
                    'ctaSecondary' => 'âœ“ Garantia de 7 dias â€¢ Pagamento 100% seguro',
                    'image' => null,
                    'imagePrompt' => "professional product showcase for {$productName}, modern clean design, studio lighting, commercial photography",
                ],
            ],
            [
                'type' => 'stats',
                'id' => 'stats-section',
                'settings' => [
                    'backgroundColor' => $theme['backgroundColor'],
                    'padding' => 'py-12',
                    'animation' => 'fade-up',
                ],
                'content' => [
                    'items' => [
                        ['value' => '2.847+', 'label' => 'Clientes satisfeitos'],
                        ['value' => '4.9/5', 'label' => 'AvaliaÃ§Ã£o mÃ©dia'],
                        ['value' => '97%', 'label' => 'Taxa de aprovaÃ§Ã£o'],
                        ['value' => '24h', 'label' => 'Suporte disponÃ­vel'],
                    ],
                ],
            ],
            [
                'type' => 'benefits',
                'id' => 'benefits-section',
                'settings' => [
                    'backgroundColor' => $theme['backgroundColor'],
                    'padding' => 'py-16 lg:py-24',
                    'animation' => 'fade-up',
                ],
                'content' => [
                    'title' => 'Por que mais de 2.800 pessoas escolheram ' . $productName,
                    'subtitle' => 'Resultados reais que falam por si',
                    'items' => [
                        [
                            'icon' => 'rocket',
                            'title' => 'Resultados em atÃ© 7 dias',
                            'description' => 'Nossos clientes relatam primeiros resultados visÃ­veis jÃ¡ na primeira semana de uso.',
                        ],
                        [
                            'icon' => 'shield-check',
                            'title' => 'Garantia total de satisfaÃ§Ã£o',
                            'description' => 'Se nÃ£o funcionar para vocÃª, devolvemos 100% do valor. Sem perguntas, sem burocracia.',
                        ],
                        [
                            'icon' => 'users',
                            'title' => 'Suporte humano dedicado',
                            'description' => 'Equipe brasileira pronta para ajudar via chat e email, de segunda a sexta.',
                        ],
                        [
                            'icon' => 'star',
                            'title' => 'AtualizaÃ§Ãµes constantes',
                            'description' => 'ConteÃºdo atualizado mensalmente com as melhores prÃ¡ticas do mercado.',
                        ],
                    ],
                ],
            ],
            [
                'type' => 'testimonials',
                'id' => 'testimonials-section',
                'settings' => [
                    'backgroundColor' => $this->isColorDark($theme['backgroundColor']) ? $this->lightenColor($theme['backgroundColor'], 5) : '#f8fafc',
                    'padding' => 'py-16 lg:py-24',
                    'animation' => 'fade-up',
                ],
                'content' => [
                    'title' => 'Veja o que dizem quem jÃ¡ experimentou',
                    'subtitle' => 'HistÃ³rias reais de transformaÃ§Ã£o',
                    'items' => [
                        [
                            'name' => 'Carla Mendes',
                            'photo' => null,
                            'role' => 'EmpresÃ¡ria â€¢ SÃ£o Paulo, SP',
                            'text' => "Confesso que estava cÃ©tica no inÃ­cio â€” jÃ¡ tinha tentado outras soluÃ§Ãµes antes. Mas em 3 semanas usando {$productName}, meus resultados mudaram completamente. JÃ¡ recomendei para 4 amigas.",
                            'rating' => 5,
                        ],
                        [
                            'name' => 'Rafael Oliveira',
                            'photo' => null,
                            'role' => 'Analista de Marketing â€¢ BH, MG',
                            'text' => "O que mais me impressionou foi a praticidade. Em menos de 15 minutos por dia, consegui implementar tudo. O suporte me ajudou nas dÃºvidas iniciais e hoje nÃ£o largo mais.",
                            'rating' => 5,
                        ],
                        [
                            'name' => 'Amanda Costa',
                            'photo' => null,
                            'role' => 'Estudante de AdministraÃ§Ã£o â€¢ Curitiba, PR',
                            'text' => "Melhor investimento que fiz esse ano, sem exagero. O conteÃºdo Ã© extremamente didÃ¡tico e os resultados apareceram antes do que eu esperava. Nota 10!",
                            'rating' => 5,
                        ],
                    ],
                ],
            ],
                [
                    'type' => 'pricing',
                    'id' => 'pricing-section',
                    'settings' => [
                        'backgroundColor' => $theme['backgroundColor'],
                        'padding' => 'py-16 lg:py-24',
                        'animation' => 'zoom-in',
                    ],
                    'content' => [
                        'title' => 'Invista no seu prÃ³ximo nÃ­vel',
                        'subtitle' => 'Uma fraÃ§Ã£o do que vocÃª gastaria sem a soluÃ§Ã£o certa',
                        'originalPrice' => 'R$ ' . number_format($originalPrice, 2, ',', '.'),
                        'currentPrice' => 'R$ ' . number_format($price, 2, ',', '.'),
                        'discount' => $discount . '% OFF',
                        'installments' => 'ou 12x de R$ ' . $installmentValue . ' sem juros',
                        'features' => [
                            'Acesso completo e imediato',
                            'Suporte prioritÃ¡rio por email e chat',
                            'Todas as atualizaÃ§Ãµes futuras incluÃ­das',
                            'BÃ´nus exclusivos para novos membros',
                            'Comunidade VIP de networking',
                            'Certificado de conclusÃ£o',
                        ],
                        'ctaText' => $this->generateFallbackCTA($tone),
                        'urgency' => $tone === 'urgent' ? 'â° Apenas 23 vagas restantes nesta condiÃ§Ã£o!' : 'CondiÃ§Ã£o especial por tempo limitado',
                    ],
                ],
                [
                    'type' => 'guarantee',
                    'id' => 'guarantee-section',
                    'settings' => [
                        'backgroundColor' => $this->isColorDark($theme['backgroundColor']) ? $this->lightenColor($theme['backgroundColor'], 5) : '#f0fdf4',
                        'padding' => 'py-12 lg:py-16',
                        'animation' => 'fade-up',
                    ],
                    'content' => [
                        'title' => 'Garantia incondicional de 7 dias',
                        'description' => "Acreditamos tanto no {$productName} que oferecemos uma garantia completa. Se nos primeiros 7 dias vocÃª sentir que nÃ£o Ã© para vocÃª, basta enviar um email e devolvemos 100% do seu investimento. Sem letras miÃºdas, sem perguntas constrangedoras. O risco Ã© todo nosso.",
                        'icon' => 'shield-check',
                    ],
                ],
                [
                    'type' => 'faq',
                    'id' => 'faq-section',
                    'settings' => [
                        'backgroundColor' => $theme['backgroundColor'],
                        'padding' => 'py-16 lg:py-24',
                        'animation' => 'fade-up',
                    ],
                    'content' => [
                        'title' => 'Suas dÃºvidas, respondidas',
                        'items' => [
                            [
                                'question' => "Para quem Ã© o {$productName}?",
                                'answer' => "Ã‰ para qualquer pessoa que busca resultados reais e estÃ¡ disposta a dedicar pelo menos 15 minutos por dia. NÃ£o importa se vocÃª Ã© iniciante ou avanÃ§ado â€” o conteÃºdo se adapta ao seu nÃ­vel.",
                            ],
                            [
                                'question' => 'Quanto tempo leva para ver resultados?',
                                'answer' => 'A maioria dos nossos clientes relata primeiros resultados entre 5 e 14 dias. Claro, isso depende da sua dedicaÃ§Ã£o e ponto de partida, mas o mÃ©todo foi desenhado para ser o mais rÃ¡pido possÃ­vel.',
                            ],
                            [
                                'question' => 'E se nÃ£o funcionar para mim?',
                                'answer' => 'VocÃª tem 7 dias de garantia incondicional. Se por qualquer motivo nÃ£o ficar satisfeito, devolvemos 100% do valor. Sem perguntas, sem burocracia.',
                            ],
                            [
                                'question' => 'Quais formas de pagamento sÃ£o aceitas?',
                                'answer' => 'Aceitamos PIX (com aprovaÃ§Ã£o instantÃ¢nea), cartÃ£o de crÃ©dito em atÃ© 12x sem juros, e boleto bancÃ¡rio. Todos os pagamentos sÃ£o processados com seguranÃ§a total.',
                            ],
                            [
                                'question' => 'Por quanto tempo terei acesso?',
                                'answer' => 'Seu acesso Ã© vitalÃ­cio. Uma vez adquirido, vocÃª pode acessar o conteÃºdo quando e onde quiser, pelo tempo que precisar. Inclui todas as atualizaÃ§Ãµes futuras.',
                            ],
                        ],
                    ],
                ],
                [
                    'type' => 'cta',
                    'id' => 'cta-section',
                    'settings' => [
                        'backgroundColor' => $theme['primaryColor'],
                        'padding' => 'py-16 lg:py-24',
                        'animation' => 'fade-up',
                    ],
                    'content' => [
                        'title' => 'Chegou a sua vez',
                        'description' => "Mais de 2.800 pessoas jÃ¡ deram esse passo com {$productName}. A Ãºnica pergunta Ã©: vocÃª vai continuar adiando ou vai comeÃ§ar hoje?",
                        'buttonText' => $this->generateFallbackCTA($tone),
                        'buttonSecondary' => 'Garantia de 7 dias â€¢ Pagamento seguro â€¢ Acesso imediato',
                    ],
                ],
        ];

        $result = [
            'meta' => [
                'title' => $productName . ' - Oferta Especial',
                'description' => mb_substr($productDescription, 0, 155),
                'keywords' => ['oferta', strtolower($productName), 'desconto'],
            ],
            'theme' => $theme,
            'sections' => $sections,
            'checkoutUrl' => $checkoutUrl,
        ];

        // Add countdown for urgent tone
        if ($tone === 'urgent') {
            $countdownSection = [
                'type' => 'countdown',
                'id' => 'countdown-section',
                'settings' => [
                    'backgroundColor' => $this->isColorDark($theme['backgroundColor']) ? $this->lightenColor($theme['backgroundColor'], 5) : '#fef3c7',
                    'padding' => 'py-12',
                    'animation' => 'fade-up',
                ],
                'content' => [
                    'title' => 'Esta oferta expira em:',
                    'endDate' => date('Y-m-d\T23:59:59', strtotime('+2 days')),
                    'urgencyText' => 'Depois desse prazo, o preÃ§o volta ao normal!',
                ],
            ];
            // Insert before pricing
            array_splice($result['sections'], 3, 0, [$countdownSection]);
        }

        return $result;
    }

    /**
     * Generate dynamic headline for fallback based on product type and tone
     */
    private function generateFallbackHeadline(string $productName, string $productType, string $tone): string
    {
        $headlines = match($tone) {
            'urgent' => [
                "Ãšltimas vagas: {$productName} com condiÃ§Ã£o que nÃ£o vai se repetir",
                "{$productName}: oferta encerra em poucas horas",
            ],
            'luxury' => [
                "{$productName}: acesso reservado para quem busca excelÃªncia",
                "Apresentando {$productName} â€” a escolha de quem nÃ£o aceita o ordinÃ¡rio",
            ],
            'casual' => [
                "JÃ¡ conhece o {$productName}? VocÃª vai se surpreender",
                "{$productName}: aquilo que faltava pra vocÃª dar o prÃ³ximo passo",
            ],
            default => [
                "O mÃ©todo por trÃ¡s de 2.847 resultados com {$productName}",
                "{$productName}: o que ninguÃ©m te contou sobre resultados reais",
            ],
        };

        return $headlines[array_rand($headlines)];
    }

    /**
     * Generate dynamic subheadline for fallback
     */
    private function generateFallbackSubheadline(string $productName, string $productType, string $tone): string
    {
        return match($tone) {
            'urgent' => 'A condiÃ§Ã£o especial Ã© por tempo limitado â€” veja por que agir agora faz diferenÃ§a',
            'luxury' => 'Uma experiÃªncia premium desenvolvida para profissionais que valorizam qualidade',
            'casual' => 'Sem enrolaÃ§Ã£o, sem promessas vazias â€” sÃ³ o que realmente funciona',
            default => 'Descubra o passo a passo que jÃ¡ transformou a realidade de milhares de pessoas como vocÃª',
        };
    }

    /**
     * Generate contextual CTA text based on tone
     */
    private function generateFallbackCTA(string $tone): string
    {
        return match($tone) {
            'urgent' => 'GARANTIR MINHA VAGA AGORA â†’',
            'luxury' => 'Solicitar meu acesso',
            'casual' => 'Quero comeÃ§ar agora! â†’',
            default => 'ComeÃ§ar agora â†’',
        };
    }

    /**
     * Enhance generated content with conversion elements
     */
    private function enhanceForConversion(array $structure, ?Product $product, ?Checkout $checkout): array
    {
        // Add checkout URL to all CTAs
        if ($checkout) {
            $checkoutUrl = url("/checkout/{$checkout->slug}");
            $structure['checkoutUrl'] = $checkoutUrl;
        }

        // Add conversion tracking
        $structure['tracking'] = [
            'events' => [
                'page_view' => true,
                'scroll_depth' => true,
                'cta_click' => true,
                'time_on_page' => true,
            ],
        ];

        // Add social proof elements
        $structure['socialProof'] = [
            'enabled' => true,
            'showRecentPurchases' => true,
            'showViewerCount' => true,
        ];

        return $structure;
    }

    /**
     * Edit landing page content via AI chat prompt
     */
    public function editWithAI(LandingPage $landingPage, string $userMessage, array $chatHistory = []): array
    {
        if (empty($this->openrouterApiKey)) {
            throw new \RuntimeException('OpenRouter API key not configured');
        }

        $currentContent = $landingPage->content;
        // Convert image URLs to [IMG:keywords] tokens so the AI sees the pattern
        $currentContent = $this->convertUrlsToImgTokens($currentContent);
        $currentJson = json_encode($currentContent, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $systemPrompt = <<<PROMPT
VocÃª Ã© um assistente especializado em ediÃ§Ã£o de landing pages de vendas de alta conversÃ£o.

O usuÃ¡rio vai pedir alteraÃ§Ãµes na landing page dele. VocÃª deve retornar o JSON COMPLETO da landing page com as alteraÃ§Ãµes aplicadas.

REGRAS:
1. SEMPRE retorne o JSON completo da landing page (todas as seÃ§Ãµes, tema, meta, etc.)
2. Aplique APENAS as alteraÃ§Ãµes que o usuÃ¡rio pediu
3. Mantenha todo o restante EXATAMENTE como estÃ¡
4. Se o usuÃ¡rio pedir para mudar cores, altere no "theme"
5. Se pedir para mudar textos, altere na seÃ§Ã£o correspondente
6. Se pedir para adicionar/remover seÃ§Ãµes, faÃ§a isso no array "sections"
7. Se pedir sugestÃµes, aplique as melhores prÃ¡ticas de copywriting
8. RESPONDA APENAS COM JSON PURO, SEM markdown, sem ```json, sem texto explicativo
9. Mantenha os mesmos IDs das seÃ§Ãµes existentes
10. NUNCA inclua HTML nos valores do JSON (sem <span>, <b>, <i>, etc.) - use APENAS texto puro
11. NÃƒO inclua imagens na pÃ¡gina. Remova ou deixe vazio campos como "image" no hero e "photo" nos depoimentos. O sistema nÃ£o suporta imagens no momento.

ESTRUTURA ATUAL DA LANDING PAGE:
{$currentJson}
PROMPT;

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
        ];

        // Add chat history
        foreach ($chatHistory as $msg) {
            $messages[] = [
                'role' => $msg['role'] ?? 'user',
                'content' => $msg['content'] ?? '',
            ];
        }

        // Add current user message
        $messages[] = ['role' => 'user', 'content' => $userMessage];

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->openrouterApiKey}",
                'Content-Type' => 'application/json',
                'HTTP-Referer' => config('app.url', 'http://localhost:8000'),
                'X-Title' => 'Vector Pagamentos Landing Page Editor',
            ])->withoutVerifying()->timeout(120)->post('https://openrouter.ai/api/v1/chat/completions', [
                'model' => $this->defaultModel,
                'messages' => $messages,
                'temperature' => 0.5,
                'max_tokens' => 10000,
            ]);

            if ($response->successful()) {
                $content = $response->json('choices.0.message.content');
                $parsed = $this->extractJson($content);

                if ($parsed !== null && isset($parsed['sections'])) {
                    Log::info('AI edited landing page successfully');
                    $sanitized = $this->sanitizeContent($parsed);
                    // Image generation disabled â€” strip image fields instead of processing tokens
                    $sanitized = $this->stripImageFields($sanitized);
                    return $sanitized;
                }

                Log::warning('AI edit response did not contain valid sections', [
                    'content_preview' => substr($content ?? '', 0, 500),
                ]);
                throw new \RuntimeException('A IA nÃ£o retornou um JSON vÃ¡lido. Tente novamente.');
            }

            throw new \RuntimeException('Erro na API da IA: ' . $response->status());

        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            throw new \RuntimeException('NÃ£o foi possÃ­vel conectar Ã  IA. Tente novamente.');
        }
    }

    /**
     * Regenerate a specific section
     */
    public function regenerateSection(
        LandingPage $landingPage,
        string $sectionId,
        string $prompt
    ): array {
        $sections = $landingPage->sections ?? [];
        $sectionIndex = array_search($sectionId, array_column($sections, 'id'));
        
        if ($sectionIndex === false) {
            throw new \InvalidArgumentException("Section not found: {$sectionId}");
        }

        $section = $sections[$sectionIndex];
        $sectionType = $section['type'];

        // Generate new content for this section
        $newContent = $this->generateSectionContent($sectionType, $prompt, [
            'product' => $landingPage->product?->toArray(),
        ]);

        $section['content'] = $newContent;
        $sections[$sectionIndex] = $section;

        return $sections;
    }

    /**
     * Generate content for a single section type
     */
    public function generateSectionContent(string $type, string $prompt, array $context = []): array
    {
        // For now, use fallback template content
        // In production, this would call AI for specific section
        
        $templates = [
            'hero' => [
                'headline' => 'Headline Impactante',
                'subheadline' => 'SubtÃ­tulo que complementa',
                'ctaText' => 'COMEÃ‡AR AGORA',
            ],
            'benefits' => [
                'title' => 'BenefÃ­cios',
                'items' => [],
            ],
            'testimonials' => [
                'title' => 'Depoimentos',
                'items' => [],
            ],
        ];

        return $templates[$type] ?? [];
    }

    /**
     * Generate an image using AI via Together.ai's image generation API.
     * Uses the free FLUX.1-schnell model.
     * Returns the local URL of the saved image, or null on failure.
     */
    private function generateAIImage(string $prompt, int $width = 1024, int $height = 768): ?string
    {
        if (empty($this->togetherApiKey)) {
            Log::info('Together API key not configured, skipping AI image generation');
            return null;
        }

        // Check cache to avoid regenerating the same image
        $cacheKey = 'ai_img_' . md5($prompt . $width . $height);
        $cached = Cache::get($cacheKey);
        if ($cached) {
            $storagePath = preg_replace('#^.*/storage/#', '', $cached);
            if (Storage::disk('public')->exists($storagePath)) {
                return $cached;
            }
        }

        try {
            Log::info('Generating AI image via Together.ai', [
                'prompt' => Str::limit($prompt, 100),
                'model' => $this->imageModel,
            ]);

            $response = Http::withHeaders([
                'Authorization' => "Bearer {$this->togetherApiKey}",
                'Content-Type' => 'application/json',
            ])->withoutVerifying()->timeout(90)->post('https://api.together.xyz/v1/images/generations', [
                'model' => $this->imageModel,
                'prompt' => $prompt,
                'width' => min($width, 1440),
                'height' => min($height, 1440),
                'steps' => 4,
                'n' => 1,
                'response_format' => 'b64_json',
            ]);

            if ($response->successful()) {
                $data = $response->json('data.0') ?? ($response->json('data')[0] ?? null);

                $imageData = null;

                // Try b64_json first
                if (!empty($data['b64_json'])) {
                    $imageData = base64_decode($data['b64_json']);
                }
                // Try URL fallback
                elseif (!empty($data['url'])) {
                    $imgUrl = $data['url'];
                    $parsedUrl = parse_url($imgUrl);
                    $scheme = $parsedUrl['scheme'] ?? '';
                    $host = $parsedUrl['host'] ?? '';
                    $ip = gethostbyname($host);
                    $isPrivateIp = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;

                    if (in_array($scheme, ['http', 'https']) && !$isPrivateIp) {
                        $imgResponse = Http::timeout(30)->get($imgUrl);
                        if ($imgResponse->successful()) {
                            $imageData = $imgResponse->body();
                        }
                    }
                }

                if ($imageData && strlen($imageData) > 1000) {
                    // Detect format from magic bytes
                    $ext = 'png';
                    if (substr($imageData, 0, 3) === "\xff\xd8\xff") {
                        $ext = 'jpg';
                    } elseif (substr($imageData, 0, 4) === 'RIFF') {
                        $ext = 'webp';
                    }

                    $filename = 'lp-images/' . Str::uuid() . '.' . $ext;
                    Storage::disk('public')->put($filename, $imageData);

                    $url = url('storage/' . $filename);
                    Cache::put($cacheKey, $url, now()->addDays(30));

                    Log::info('AI image generated successfully', [
                        'file' => $filename,
                        'size' => strlen($imageData),
                    ]);
                    return $url;
                }

                Log::warning('AI image response had no usable image data', [
                    'keys' => array_keys($data ?? []),
                    'response_preview' => Str::limit($response->body(), 300),
                ]);
            } else {
                Log::warning('Together.ai image generation failed', [
                    'status' => $response->status(),
                    'body' => Str::limit($response->body(), 300),
                ]);
            }

        } catch (\Exception $e) {
            Log::warning('AI image generation error: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Strip image fields from generated content (image generation disabled).
     * Removes hero images and testimonial photos so the page renders without broken images.
     */
    private function stripImageFields(array $content): array
    {
        if (!isset($content['sections']) || !is_array($content['sections'])) {
            return $content;
        }

        foreach ($content['sections'] as &$section) {
            if (!isset($section['content']) || !is_array($section['content'])) {
                continue;
            }

            // Remove hero image
            if (isset($section['content']['image'])) {
                $section['content']['image'] = '';
            }
            if (isset($section['content']['imagePrompt'])) {
                unset($section['content']['imagePrompt']);
            }

            // Remove testimonial/item photos
            if (isset($section['content']['items']) && is_array($section['content']['items'])) {
                foreach ($section['content']['items'] as &$item) {
                    if (isset($item['photo'])) {
                        $item['photo'] = '';
                    }
                }
            }
        }

        // Remove generated images metadata
        unset($content['_generatedImages']);

        return $content;
    }

    /**
     * Process [IMG:keywords] tokens in content by generating real images via AI.
     * Falls back to loremflickr if AI generation fails.
     */
    private function processImgTokens(array $content): array
    {
        if (!isset($content['sections']) || !is_array($content['sections'])) {
            return $content;
        }

        foreach ($content['sections'] as &$section) {
            if (!isset($section['content']) || !is_array($section['content'])) {
                continue;
            }

            // Process hero image
            if (isset($section['content']['image']) && is_string($section['content']['image'])) {
                $section['content']['image'] = $this->resolveImgToken($section['content']['image'], 1024, 768);
            }

            // Process testimonial/item photos
            if (isset($section['content']['items']) && is_array($section['content']['items'])) {
                foreach ($section['content']['items'] as &$item) {
                    if (isset($item['photo']) && is_string($item['photo'])) {
                        $item['photo'] = $this->resolveImgToken($item['photo'], 256, 256);
                    }
                }
            }
        }

        return $content;
    }

    /**
     * Resolve a single [IMG:keywords] token to a real image URL.
     */
    private function resolveImgToken(string $value, int $width = 1024, int $height = 768): string
    {
        $trimmed = trim($value);

        // Not a token â€” return as-is
        if (!preg_match('/^\[IMG:(.+)\]$/i', $trimmed, $matches)) {
            // But still fix broken URLs (pollinations, placeholder, etc.)
            return $this->fixImageUrl($value, $width, $height);
        }

        $keywords = trim($matches[1]);
        $prompt = $this->buildImagePrompt($keywords, $width, $height);

        // Try AI image generation
        $url = $this->generateAIImage($prompt, $width, $height);
        if ($url) {
            return $url;
        }

        // Fallback to loremflickr
        Log::info('Falling back to loremflickr for image', ['keywords' => $keywords]);
        return $this->buildImageUrlFromKeywords($keywords, $width, $height);
    }

    /**
     * Build a rich prompt for image generation from keywords
     */
    private function buildImagePrompt(string $keywords, int $width, int $height): string
    {
        $isAvatar = ($width <= 300 && $height <= 300);

        if ($isAvatar) {
            return "Professional portrait photo of a Brazilian person, {$keywords}, natural soft lighting, clean blurred background, high quality headshot for testimonial section, photorealistic";
        }

        $orientation = $width > $height ? 'landscape orientation' : ($height > $width ? 'portrait orientation' : 'square');
        return "Professional high-quality photograph: {$keywords}. {$orientation}, vivid colors, sharp focus, commercial quality, suitable for a modern sales landing page, photorealistic";
    }

    /**
     * Convert image URLs in content to [IMG:keywords] tokens for AI consumption.
     * This ensures the AI sees the [IMG:...] pattern and follows it.
     */
    private function convertUrlsToImgTokens(array $content): array
    {
        if (!isset($content['sections']) || !is_array($content['sections'])) {
            return $content;
        }

        foreach ($content['sections'] as &$section) {
            if (!isset($section['content']) || !is_array($section['content'])) {
                continue;
            }

            // Convert hero image URL to token
            if (isset($section['content']['image']) && is_string($section['content']['image'])) {
                $section['content']['image'] = $this->urlToImgToken($section['content']['image']);
            }

            // Convert testimonial/item photos to tokens
            if (isset($section['content']['items']) && is_array($section['content']['items'])) {
                foreach ($section['content']['items'] as &$item) {
                    if (isset($item['photo']) && is_string($item['photo'])) {
                        $item['photo'] = $this->urlToImgToken($item['photo']);
                    }
                }
            }
        }

        return $content;
    }

    /**
     * Convert a single image URL back to an [IMG:keywords] token
     */
    private function urlToImgToken(string $url): string
    {
        $trimmed = trim($url);

        // Already a token
        if (preg_match('/^\[IMG:/i', $trimmed)) {
            return $trimmed;
        }

        // Our own AI-generated images stored locally â€” keep as-is, they're good
        if (strpos($trimmed, '/storage/lp-images/') !== false) {
            return $trimmed;
        }

        // loremflickr.com â€” extract keywords from path  e.g. /800/600/hacker,cybersecurity
        if (strpos($trimmed, 'loremflickr.com') !== false) {
            $path = parse_url($trimmed, PHP_URL_PATH) ?? '';
            // Path like /800/600/keyword1,keyword2
            if (preg_match('/\/\d+\/\d+\/(.+)$/', $path, $m)) {
                $keywords = str_replace(',', ' ', urldecode($m[1]));
                return '[IMG:' . trim($keywords) . ']';
            }
        }

        // Pollinations.ai â€” extract prompt from path
        if (strpos($trimmed, 'image.pollinations.ai') !== false) {
            $path = parse_url($trimmed, PHP_URL_PATH) ?? '';
            $prompt = urldecode(trim($path, '/'));
            $prompt = preg_replace('/^prompt\//i', '', $prompt);
            $keywords = $this->extractImageKeywords($prompt);
            if (!empty($keywords)) {
                return '[IMG:' . implode(' ', $keywords) . ']';
            }
        }

        // unsplash.com â€” extract from URL
        if (strpos($trimmed, 'unsplash.com') !== false || strpos($trimmed, 'source.unsplash') !== false) {
            if (preg_match('/\?([^#]+)/', $trimmed, $m)) {
                parse_str($m[1], $params);
                if (!empty($params['sig'])) {
                    return '[IMG:product professional]';
                }
            }
            return '[IMG:professional product]';
        }

        // randomuser.me â€” it's an avatar
        if (strpos($trimmed, 'randomuser.me') !== false) {
            return '[IMG:person portrait headshot]';
        }

        // picsum.photos â€” random, no keywords recoverable
        if (strpos($trimmed, 'picsum.photos') !== false) {
            return '[IMG:product technology]';
        }

        // Any other URL â€” keep as generic
        if (filter_var($trimmed, FILTER_VALIDATE_URL)) {
            return '[IMG:product]';
        }

        // Not a URL â€” might already be a description, wrap it
        if (!empty($trimmed)) {
            return '[IMG:' . $trimmed . ']';
        }

        return $url;
    }

    /**
     * Fix old Pollinations.ai URLs that use unsupported query parameters (width, height, seed)
     */
    private function fixPollinationsUrls(array $content): array
    {
        if (isset($content['sections']) && is_array($content['sections'])) {
            foreach ($content['sections'] as &$section) {
                if (isset($section['content']) && is_array($section['content'])) {
                    // Fix hero image URL
                    if (isset($section['content']['image']) && is_string($section['content']['image'])) {
                        $section['content']['image'] = $this->fixImageUrl($section['content']['image'], 800, 600);
                    }
                    // Fix testimonial photo URLs
                    if (isset($section['content']['items']) && is_array($section['content']['items'])) {
                        foreach ($section['content']['items'] as &$item) {
                            if (isset($item['photo']) && is_string($item['photo'])) {
                                $item['photo'] = $this->fixImageUrl($item['photo'], 150, 150);
                            }
                        }
                    }
                }
            }
        }
        return $content;
    }

    /**
     * Fix any broken/unreliable image URL or convert [IMG:keywords] to real URLs
     */
    private function fixImageUrl(string $url, int $defaultWidth = 800, int $defaultHeight = 600): string
    {
        $trimmed = trim($url);

        // Handle [IMG:keywords] pattern from AI editor
        if (preg_match('/^\[IMG:(.+)\]$/i', $trimmed, $matches)) {
            return $this->buildImageUrlFromKeywords(trim($matches[1]), $defaultWidth, $defaultHeight);
        }

        // Replace Pollinations.ai URLs (service unreliable)
        if (strpos($url, 'image.pollinations.ai') !== false) {
            return $this->cleanPollinationsUrl($url);
        }
        // Replace via.placeholder.com with picsum
        if (strpos($url, 'via.placeholder.com') !== false) {
            $seed = abs(crc32($url)) % 10000;
            if (preg_match('/(\d+)x(\d+)/', $url, $m)) {
                return "https://picsum.photos/seed/{$seed}/{$m[1]}/{$m[2]}";
            }
            return "https://picsum.photos/seed/{$seed}/800/600";
        }
        return $url;
    }

    /**
     * Replace broken Pollinations URLs with keyword-based image URLs
     */
    private function cleanPollinationsUrl(string $url): string
    {
        if (strpos($url, 'image.pollinations.ai') === false) {
            return $url;
        }

        // Extract prompt text from Pollinations URL path
        $parsed = parse_url($url);
        $path = $parsed['path'] ?? '';
        $promptText = urldecode(trim($path, '/'));
        $promptText = preg_replace('/^prompt\//i', '', $promptText);

        // Detect dimensions from query params
        $width = 800;
        $height = 600;
        if (isset($parsed['query'])) {
            parse_str($parsed['query'], $queryParams);
            if (isset($queryParams['width'])) $width = (int)$queryParams['width'];
            if (isset($queryParams['height'])) $height = (int)$queryParams['height'];
        }

        return $this->buildImageUrlFromKeywords($promptText, $width, $height);
    }

    /**
     * Render landing page to HTML
     */
    public function renderToHtml(LandingPage $landingPage): string
    {
        $content = $this->sanitizeContent($landingPage->content);
        $content = $this->fixPollinationsUrls($content);
        $theme = $content['theme'] ?? [];
        $sections = $content['sections'] ?? [];
        $meta = $content['meta'] ?? [];

        // Always use the real checkout URL from the linked checkout
        $checkoutUrl = '#';
        if ($landingPage->checkout_id && $landingPage->checkout) {
            $checkoutUrl = url("/checkout/{$landingPage->checkout->slug}");
        } elseif (!empty($content['checkoutUrl']) && $content['checkoutUrl'] !== '#checkout') {
            $checkoutUrl = $content['checkoutUrl'];
        }

        // Build HTML
        $html = $this->buildHtmlTemplate($meta, $theme, $sections, $checkoutUrl, $landingPage);

        return $html;
    }

    /**
     * Build complete HTML template
     */
    private function buildHtmlTemplate(
        array $meta,
        array $theme,
        array $sections,
        string $checkoutUrl,
        LandingPage $landingPage
    ): string {
        $title = $meta['title'] ?? $landingPage->name;
        $description = $meta['description'] ?? '';
        $primaryColor = $theme['primaryColor'] ?? '#6366f1';
        $secondaryColor = $theme['secondaryColor'] ?? '#8b5cf6';
        $bgColor = $theme['backgroundColor'] ?? '#ffffff';
        $textColor = $theme['textColor'] ?? '#1f2937';
        $accentColor = $theme['accentColor'] ?? '#10b981';
        $templateStyle = $theme['style'] ?? $landingPage->template ?? 'modern';
        $fontFamily = $theme['fontFamily'] ?? $this->getFontForTemplate($templateStyle);
        $borderRadiusClass = $theme['borderRadius'] ?? $this->getBorderRadiusForTemplate($templateStyle);

        // Detect dark mode based on background color luminance
        $isDark = $this->isColorDark($bgColor);
        $cardBg = $isDark ? $this->lightenColor($bgColor, 10) : '#ffffff';
        $cardBgAlt = $isDark ? $this->lightenColor($bgColor, 15) : '#f9fafb';
        $mutedText = $isDark ? '#9ca3af' : '#6b7280';
        $subtleText = $isDark ? '#d1d5db' : '#4b5563';
        $borderColor = $isDark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.06)';
        $mobileCta = $isDark ? 'rgba(0,0,0,0.95)' : 'rgba(255,255,255,0.97)';
        $glowColor = $isDark ? "{$primaryColor}30" : 'transparent';

        $sectionsHtml = '';
        foreach ($sections as $index => $section) {
            $section['_index'] = $index;
            $section['_template'] = $templateStyle;
            $sectionsHtml .= $this->renderSection($section, $checkoutUrl, $theme);
        }

        $templateCSS = $this->getTemplateSpecificCSS($templateStyle, $theme);
        $googleFontsUrl = $this->getGoogleFontsUrl($fontFamily);

        return <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title}</title>
    <meta name="description" content="{$description}">
    <meta property="og:title" content="{$title}">
    <meta property="og:description" content="{$description}">
    <meta property="og:type" content="website">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="{$googleFontsUrl}" rel="stylesheet">
    <style>
        :root {
            --primary: {$primaryColor};
            --secondary: {$secondaryColor};
            --accent: {$accentColor};
            --bg: {$bgColor};
            --text: {$textColor};
            --card-bg: {$cardBg};
            --card-bg-alt: {$cardBgAlt};
            --muted: {$mutedText};
            --subtle: {$subtleText};
            --border: {$borderColor};
            --glow: {$glowColor};
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: '{$fontFamily}', system-ui, -apple-system, sans-serif;
            background-color: var(--bg);
            color: var(--text);
            overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }

        /* Scroll-triggered animations */
        .reveal {
            opacity: 0;
            transform: translateY(40px);
            transition: all 0.8s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .reveal.visible {
            opacity: 1;
            transform: translateY(0);
        }
        .reveal-left {
            opacity: 0;
            transform: translateX(-60px);
            transition: all 0.8s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .reveal-left.visible {
            opacity: 1;
            transform: translateX(0);
        }
        .reveal-right {
            opacity: 0;
            transform: translateX(60px);
            transition: all 0.8s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .reveal-right.visible {
            opacity: 1;
            transform: translateX(0);
        }
        .reveal-zoom {
            opacity: 0;
            transform: scale(0.9);
            transition: all 0.7s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .reveal-zoom.visible {
            opacity: 1;
            transform: scale(1);
        }
        .reveal-blur {
            opacity: 0;
            filter: blur(10px);
            transition: all 0.9s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .reveal-blur.visible {
            opacity: 1;
            filter: blur(0);
        }

        /* Staggered children animation */
        .stagger-children > * {
            opacity: 0;
            transform: translateY(30px);
            transition: all 0.6s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .stagger-children.visible > *:nth-child(1) { transition-delay: 0.05s; opacity: 1; transform: translateY(0); }
        .stagger-children.visible > *:nth-child(2) { transition-delay: 0.12s; opacity: 1; transform: translateY(0); }
        .stagger-children.visible > *:nth-child(3) { transition-delay: 0.19s; opacity: 1; transform: translateY(0); }
        .stagger-children.visible > *:nth-child(4) { transition-delay: 0.26s; opacity: 1; transform: translateY(0); }
        .stagger-children.visible > *:nth-child(5) { transition-delay: 0.33s; opacity: 1; transform: translateY(0); }
        .stagger-children.visible > *:nth-child(6) { transition-delay: 0.40s; opacity: 1; transform: translateY(0); }
        .stagger-children.visible > *:nth-child(n+7) { transition-delay: 0.47s; opacity: 1; transform: translateY(0); }

        /* Button styles */
        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
            box-shadow: 0 4px 20px {$primaryColor}40;
            position: relative;
            overflow: hidden;
        }
        .btn-primary::before {
            content: '';
            position: absolute;
            top: 0; left: -100%;
            width: 100%; height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.15), transparent);
            transition: left 0.6s ease;
        }
        .btn-primary:hover {
            transform: translateY(-3px) scale(1.02);
            box-shadow: 0 8px 30px {$primaryColor}50;
        }
        .btn-primary:hover::before {
            left: 100%;
        }
        .btn-primary:active {
            transform: translateY(-1px) scale(0.99);
        }

        /* Gradient text */
        .gradient-text {
            background: linear-gradient(135deg, var(--primary), var(--secondary));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }

        /* Theme utilities */
        .theme-card { background-color: var(--card-bg); }
        .theme-card-alt { background-color: var(--card-bg-alt); }
        .theme-muted { color: var(--muted); }
        .theme-subtle { color: var(--subtle); }
        .theme-border { border-color: var(--border); }

        /* Card hover effect */
        .card-hover {
            transition: all 0.4s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .card-hover:hover {
            transform: translateY(-6px);
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
        }

        /* Floating animation */
        @keyframes float {
            0%, 100% { transform: translateY(0px) rotate(0deg); }
            33% { transform: translateY(-8px) rotate(1deg); }
            66% { transform: translateY(-4px) rotate(-1deg); }
        }
        .animate-float { animation: float 6s ease-in-out infinite; }

        /* Pulse glow */
        @keyframes pulse-glow {
            0%, 100% { box-shadow: 0 0 20px {$primaryColor}30; }
            50% { box-shadow: 0 0 40px {$primaryColor}50; }
        }
        .animate-pulse-glow { animation: pulse-glow 2.5s ease-in-out infinite; }

        /* Gradient shift */
        @keyframes gradient-shift {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        .animate-gradient {
            background-size: 200% 200%;
            animation: gradient-shift 4s ease infinite;
        }

        /* Counter animation */
        .count-up {
            font-variant-numeric: tabular-nums;
        }

        /* Smooth scroll */
        html { scroll-behavior: smooth; }

        /* Selection color */
        ::selection {
            background: {$primaryColor}30;
            color: var(--text);
        }

        /* Custom scrollbar */
        ::-webkit-scrollbar { width: 8px; }
        ::-webkit-scrollbar-track { background: var(--bg); }
        ::-webkit-scrollbar-thumb {
            background: {$primaryColor}40;
            border-radius: 4px;
        }
        ::-webkit-scrollbar-thumb:hover {
            background: {$primaryColor}60;
        }

        /* FAQ accordion */
        .faq-answer {
            max-height: 0;
            overflow: hidden;
            transition: max-height 0.4s cubic-bezier(0.16, 1, 0.3, 1), padding 0.3s ease;
            padding: 0 1.5rem;
        }
        .faq-answer.open {
            max-height: 500px;
            padding: 0 1.5rem 1rem;
        }
        .faq-chevron {
            transition: transform 0.3s ease;
        }
        .faq-chevron.rotated {
            transform: rotate(180deg);
        }

        /* Image loading */
        img {
            opacity: 0;
            transition: opacity 0.5s ease;
        }
        img.loaded, img[src=""] {
            opacity: 1;
        }

        {$templateCSS}
    </style>
</head>
<body class="antialiased template-{$templateStyle}">
    {$sectionsHtml}
    
    <!-- Floating CTA (Desktop) -->
    <div class="fixed bottom-8 right-8 z-50 hidden lg:block" style="opacity:0;transform:translateY(20px);transition:all 0.5s ease" id="floating-cta">
        <a href="{$checkoutUrl}" class="btn-primary animate-pulse-glow inline-flex items-center px-7 py-3.5 rounded-full text-white font-semibold text-base shadow-2xl gap-2">
            <span>Comprar Agora</span>
            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6"></path>
            </svg>
        </a>
    </div>

    <!-- Mobile Fixed CTA -->
    <div class="fixed bottom-0 left-0 right-0 px-4 pb-4 pt-3 backdrop-blur-md border-t lg:hidden z-50" style="background:{$mobileCta};border-color:var(--border);transform:translateY(100%);transition:transform 0.4s ease" id="mobile-cta">
        <a href="{$checkoutUrl}" class="btn-primary block w-full py-4 rounded-2xl text-white font-bold text-center text-lg tracking-wide">
            GARANTIR MINHA VAGA â†’
        </a>
    </div>

    <script>
    (function() {
        'use strict';

        // Intersection Observer for scroll animations
        const observerOptions = { threshold: 0.12, rootMargin: '0px 0px -40px 0px' };
        const observer = new IntersectionObserver(function(entries) {
            entries.forEach(function(entry) {
                if (entry.isIntersecting) {
                    entry.target.classList.add('visible');
                    // Don't unobserve â€” keep for re-entry if needed
                }
            });
        }, observerOptions);

        // Observe all animated elements
        document.querySelectorAll('.reveal, .reveal-left, .reveal-right, .reveal-zoom, .reveal-blur, .stagger-children').forEach(function(el) {
            observer.observe(el);
        });

        // Image lazy loading with fade
        document.querySelectorAll('img').forEach(function(img) {
            if (img.complete) {
                img.classList.add('loaded');
            } else {
                img.addEventListener('load', function() { this.classList.add('loaded'); });
                img.addEventListener('error', function() { this.classList.add('loaded'); });
            }
        });

        // Show floating CTA after scroll
        var floatingCta = document.getElementById('floating-cta');
        var mobileCta = document.getElementById('mobile-cta');
        var scrollThreshold = 600;
        var lastScroll = 0;

        window.addEventListener('scroll', function() {
            var scrollY = window.pageYOffset || document.documentElement.scrollTop;
            if (scrollY > scrollThreshold) {
                if (floatingCta) { floatingCta.style.opacity = '1'; floatingCta.style.transform = 'translateY(0)'; }
                if (mobileCta) { mobileCta.style.transform = 'translateY(0)'; }
            } else {
                if (floatingCta) { floatingCta.style.opacity = '0'; floatingCta.style.transform = 'translateY(20px)'; }
                if (mobileCta) { mobileCta.style.transform = 'translateY(100%)'; }
            }
            lastScroll = scrollY;
        });

        // FAQ accordion
        document.querySelectorAll('.faq-toggle').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var answer = this.nextElementSibling;
                var chevron = this.querySelector('.faq-chevron');
                var isOpen = answer.classList.contains('open');

                // Close all others
                document.querySelectorAll('.faq-answer.open').forEach(function(el) {
                    el.classList.remove('open');
                });
                document.querySelectorAll('.faq-chevron.rotated').forEach(function(el) {
                    el.classList.remove('rotated');
                });

                // Toggle current
                if (!isOpen) {
                    answer.classList.add('open');
                    if (chevron) chevron.classList.add('rotated');
                }
            });
        });

        // Counter animation for stats
        document.querySelectorAll('.count-up').forEach(function(el) {
            var target = el.textContent;
            var numMatch = target.match(/[\d.,]+/);
            if (!numMatch) return;

            var num = parseFloat(numMatch[0].replace(/\./g, '').replace(',', '.'));
            var prefix = target.substring(0, target.indexOf(numMatch[0]));
            var suffix = target.substring(target.indexOf(numMatch[0]) + numMatch[0].length);
            var hasDecimal = numMatch[0].includes(',');
            var isLargeNum = num >= 1000;

            var counted = false;
            var counterObserver = new IntersectionObserver(function(entries) {
                if (entries[0].isIntersecting && !counted) {
                    counted = true;
                    var start = 0;
                    var duration = 1800;
                    var startTime = null;

                    function animate(time) {
                        if (!startTime) startTime = time;
                        var progress = Math.min((time - startTime) / duration, 1);
                        var eased = 1 - Math.pow(1 - progress, 3);
                        var current = Math.floor(eased * num);

                        var formatted = current.toString();
                        if (isLargeNum) {
                            formatted = current.toLocaleString('pt-BR');
                        }
                        el.textContent = prefix + formatted + suffix;

                        if (progress < 1) {
                            requestAnimationFrame(animate);
                        } else {
                            el.textContent = target;
                        }
                    }
                    requestAnimationFrame(animate);
                }
            }, { threshold: 0.5 });
            counterObserver.observe(el);
        });

        // Track page view
        console.log('Landing page view:', '{$landingPage->id}');

        // CTA click tracking
        document.querySelectorAll('a[href*="checkout"]').forEach(function(link) {
            link.addEventListener('click', function() {
                console.log('CTA clicked');
            });
        });

        // Parallax for hero (subtle)
        var heroSection = document.querySelector('[data-section-type="hero"]');
        if (heroSection) {
            window.addEventListener('scroll', function() {
                var scrollY = window.pageYOffset;
                if (scrollY < 800) {
                    var heroImg = heroSection.querySelector('img');
                    if (heroImg) {
                        heroImg.style.transform = 'translateY(' + (scrollY * 0.08) + 'px)';
                    }
                }
            }, { passive: true });
        }
    })();
    </script>
</body>
</html>
HTML;
    }

    /**
     * Get font family for template
     */
    private function getFontForTemplate(string $template): string
    {
        return match($template) {
            'modern' => 'Inter',
            'bold' => 'Plus Jakarta Sans',
            'minimal' => 'DM Sans',
            'dark' => 'Space Grotesk',
            'gradient' => 'Outfit',
            'video' => 'Plus Jakarta Sans',
            default => 'Inter',
        };
    }

    /**
     * Get border radius preset for template
     */
    private function getBorderRadiusForTemplate(string $template): string
    {
        return match($template) {
            'modern' => '2xl',
            'bold' => 'xl',
            'minimal' => 'lg',
            'dark' => '2xl',
            'gradient' => '3xl',
            'video' => 'xl',
            default => '2xl',
        };
    }

    /**
     * Get Google Fonts URL for a font family
     */
    private function getGoogleFontsUrl(string $fontFamily): string
    {
        $fonts = [
            'Inter' => 'Inter:wght@300;400;500;600;700;800;900',
            'Plus Jakarta Sans' => 'Plus+Jakarta+Sans:wght@400;500;600;700;800',
            'DM Sans' => 'DM+Sans:wght@300;400;500;600;700',
            'Space Grotesk' => 'Space+Grotesk:wght@300;400;500;600;700',
            'Outfit' => 'Outfit:wght@300;400;500;600;700;800',
        ];

        $fontParam = $fonts[$fontFamily] ?? $fonts['Inter'];
        return "https://fonts.googleapis.com/css2?family={$fontParam}&display=swap";
    }

    /**
     * Lighten a hex color by a percentage
     */
    private function lightenColor(string $hex, int $percent): string
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        if (strlen($hex) !== 6) return "#{$hex}";

        $r = hexdec(substr($hex, 0, 2));
        $g = hexdec(substr($hex, 2, 2));
        $b = hexdec(substr($hex, 4, 2));

        $r = min(255, $r + round((255 - $r) * $percent / 100));
        $g = min(255, $g + round((255 - $g) * $percent / 100));
        $b = min(255, $b + round((255 - $b) * $percent / 100));

        return sprintf('#%02x%02x%02x', $r, $g, $b);
    }

    /**
     * Get template-specific CSS rules
     */
    private function getTemplateSpecificCSS(string $template, array $theme): string
    {
        $primary = $theme['primaryColor'] ?? '#6366f1';
        $secondary = $theme['secondaryColor'] ?? '#8b5cf6';
        $accent = $theme['accentColor'] ?? '#10b981';

        return match($template) {
            'modern' => <<<CSS
/* Modern â€” clean lines, subtle shadows */
.template-modern section { position: relative; }
.template-modern .theme-card {
    border: 1px solid var(--border);
    box-shadow: 0 1px 3px rgba(0,0,0,0.04), 0 6px 16px rgba(0,0,0,0.04);
    border-radius: 1rem;
}
.template-modern .theme-card:hover {
    box-shadow: 0 4px 12px rgba(0,0,0,0.06), 0 16px 32px rgba(0,0,0,0.06);
}
.template-modern h1 { letter-spacing: -0.03em; line-height: 1.1; }
.template-modern h2 { letter-spacing: -0.02em; }
CSS,
            'bold' => <<<CSS
/* Bold â€” impactful, energetic */
.template-bold section { position: relative; }
.template-bold h1 {
    letter-spacing: -0.04em;
    line-height: 1.05;
    text-transform: uppercase;
}
.template-bold h2 { letter-spacing: -0.02em; text-transform: uppercase; }
.template-bold .btn-primary {
    font-size: 1.15em;
    letter-spacing: 0.05em;
    text-transform: uppercase;
    padding: 1.1rem 2.5rem;
}
.template-bold .theme-card {
    border: 2px solid var(--border);
    border-radius: 0.75rem;
}
@keyframes shake {
    0%, 100% { transform: translateX(0); }
    25% { transform: translateX(-3px); }
    75% { transform: translateX(3px); }
}
.template-bold .urgency-text { animation: shake 0.5s ease-in-out infinite; }
CSS,
            'minimal' => <<<CSS
/* Minimal â€” clean, spacious, zen */
.template-minimal section { position: relative; }
.template-minimal h1 {
    font-weight: 500;
    letter-spacing: -0.01em;
    line-height: 1.2;
}
.template-minimal h2 { font-weight: 500; }
.template-minimal .theme-card {
    border: 1px solid var(--border);
    border-radius: 0.5rem;
    box-shadow: none;
}
.template-minimal .btn-primary {
    border-radius: 0.375rem;
    font-weight: 500;
    box-shadow: none;
}
.template-minimal .btn-primary:hover {
    box-shadow: none;
    transform: translateY(-2px);
}
.template-minimal p { line-height: 1.8; }
CSS,
            'dark' => <<<CSS
/* Dark â€” glowing, techy, premium */
.template-dark section { position: relative; }
.template-dark .theme-card {
    background: rgba(255,255,255,0.03);
    border: 1px solid rgba(255,255,255,0.08);
    border-radius: 1rem;
    backdrop-filter: blur(12px);
    -webkit-backdrop-filter: blur(12px);
}
.template-dark .theme-card:hover {
    border-color: {$primary}50;
    box-shadow: 0 0 30px {$primary}15;
}
.template-dark .btn-primary {
    box-shadow: 0 0 25px {$primary}40, 0 4px 15px rgba(0,0,0,0.3);
}
.template-dark .btn-primary:hover {
    box-shadow: 0 0 40px {$primary}60, 0 8px 25px rgba(0,0,0,0.4);
}
.template-dark h1 {
    text-shadow: 0 0 40px {$primary}30;
    letter-spacing: -0.03em;
}
/* Glow orbs */
.template-dark .glow-orb {
    position: absolute;
    border-radius: 50%;
    filter: blur(80px);
    opacity: 0.15;
    pointer-events: none;
    z-index: 0;
}
.template-dark .glow-orb-1 {
    width: 400px; height: 400px;
    background: {$primary};
    top: -200px; right: -100px;
}
.template-dark .glow-orb-2 {
    width: 300px; height: 300px;
    background: {$accent};
    bottom: -150px; left: -50px;
}
CSS,
            'gradient' => <<<CSS
/* Gradient â€” vibrant, colorful, fluid */
.template-gradient section { position: relative; overflow: hidden; }
.template-gradient .gradient-text {
    background: linear-gradient(135deg, {$primary}, {$secondary}, {$accent});
    -webkit-background-clip: text;
    -webkit-text-fill-color: transparent;
    background-size: 200% 200%;
    animation: gradient-shift 5s ease infinite;
}
.template-gradient .theme-card {
    border: 1px solid transparent;
    background-clip: padding-box;
    border-radius: 1.5rem;
    position: relative;
}
.template-gradient .theme-card::before {
    content: '';
    position: absolute;
    inset: -1px;
    border-radius: inherit;
    background: linear-gradient(135deg, {$primary}40, {$secondary}40, {$accent}40);
    z-index: -1;
}
.template-gradient .btn-primary {
    background: linear-gradient(135deg, {$primary}, {$secondary}, {$accent});
    background-size: 200% 200%;
    animation: gradient-shift 3s ease infinite;
    border-radius: 9999px;
}
.template-gradient h1 { letter-spacing: -0.03em; }
/* Decorative blobs */
.template-gradient .blob {
    position: absolute;
    border-radius: 50%;
    filter: blur(60px);
    opacity: 0.12;
    pointer-events: none;
    animation: float 8s ease-in-out infinite;
}
CSS,
            'video' => <<<CSS
/* VSL â€” video-centric, focused */
.template-video section { position: relative; }
.template-video .video-container {
    aspect-ratio: 16/9;
    border-radius: 1rem;
    overflow: hidden;
    box-shadow: 0 25px 50px rgba(0,0,0,0.3);
    position: relative;
}
.template-video .video-container iframe {
    width: 100%; height: 100%;
    border: none;
}
.template-video h1 {
    letter-spacing: -0.03em;
    line-height: 1.1;
}
.template-video .btn-primary {
    font-size: 1.2em;
    padding: 1.1rem 3rem;
    letter-spacing: 0.02em;
    animation: pulse-glow 2s ease-in-out infinite;
}
CSS,
            default => '',
        };
    }

    /**
     * Render individual section
     */
    private function renderSection(array $section, string $checkoutUrl, array $theme): string
    {
        $type = $section['type'] ?? 'custom';
        $content = $section['content'] ?? [];
        $settings = $section['settings'] ?? [];
        $id = $section['id'] ?? Str::random(8);
        $template = $section['_template'] ?? 'modern';
        $index = $section['_index'] ?? 0;
        
        $bgColor = $settings['backgroundColor'] ?? 'transparent';
        $padding = $settings['padding'] ?? 'py-16 lg:py-24';
        $animation = $settings['animation'] ?? $this->getDefaultAnimation($type, $index);

        // Adapt section background to match theme (dark/light)
        $bgColor = $this->adaptSectionBg($bgColor, $theme);

        $method = 'render' . ucfirst($type) . 'Section';
        
        if (method_exists($this, $method)) {
            $innerHtml = $this->$method($content, $checkoutUrl, $theme, $template);
        } else {
            $innerHtml = '<div class="text-center theme-muted">Section: ' . $type . '</div>';
        }

        $bgStyle = $bgColor === 'gradient' 
            ? "background: linear-gradient(135deg, {$theme['primaryColor']}08, {$theme['secondaryColor']}10, {$theme['primaryColor']}05);"
            : "background-color: {$bgColor};";

        $animClass = $this->getAnimationClass($animation);
        $isDark = $this->isColorDark($theme['backgroundColor'] ?? '#ffffff');

        // Template-specific decorative elements
        $decorHtml = '';
        if ($template === 'dark' && $type === 'hero') {
            $primaryColor = $theme['primaryColor'] ?? '#8b5cf6';
            $accentColor = $theme['accentColor'] ?? '#22d3ee';
            $decorHtml = "<div class=\"glow-orb glow-orb-1\" style=\"background:{$primaryColor}\"></div><div class=\"glow-orb glow-orb-2\" style=\"background:{$accentColor}\"></div>";
        }
        if ($template === 'gradient' && ($type === 'hero' || $type === 'cta')) {
            $primaryColor = $theme['primaryColor'] ?? '#ec4899';
            $secondaryColor = $theme['secondaryColor'] ?? '#8b5cf6';
            $decorHtml = "<div class=\"blob\" style=\"width:300px;height:300px;background:{$primaryColor};top:-100px;right:-50px\"></div><div class=\"blob\" style=\"width:250px;height:250px;background:{$secondaryColor};bottom:-80px;left:-30px;animation-delay:2s\"></div>";
        }

        return <<<HTML
<section id="{$id}" data-section-type="{$type}" class="{$padding} relative overflow-hidden" style="{$bgStyle}">
    {$decorHtml}
    <div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 relative z-10 {$animClass}">
        {$innerHtml}
    </div>
</section>
HTML;
    }

    /**
     * Get default animation based on section type and position
     */
    private function getDefaultAnimation(string $type, int $index): string
    {
        if ($type === 'hero') return 'fade-up';
        if ($type === 'stats') return 'fade-up';
        
        $animations = ['fade-up', 'fade-up', 'fade-left', 'fade-right', 'zoom-in', 'fade-up'];
        return $animations[$index % count($animations)];
    }

    /**
     * Get CSS class for animation type
     */
    private function getAnimationClass(string $animation): string
    {
        return match($animation) {
            'fade-up' => 'reveal',
            'fade-left' => 'reveal-left',
            'fade-right' => 'reveal-right',
            'zoom-in' => 'reveal-zoom',
            'slide-up' => 'reveal',
            'blur-in' => 'reveal-blur',
            'none' => '',
            default => 'reveal',
        };
    }

    /**
     * Render hero section
     */
    private function renderHeroSection(array $content, string $checkoutUrl, array $theme, string $template = 'modern'): string
    {
        $badge = $content['badge'] ?? '';
        $headline = $content['headline'] ?? '';
        $subheadline = $content['subheadline'] ?? '';
        $description = $content['description'] ?? '';
        $ctaText = $content['ctaText'] ?? 'ComeÃ§ar Agora';
        $ctaSecondary = $content['ctaSecondary'] ?? '';
        $image = $content['image'] ?? null;
        $videoUrl = $content['videoUrl'] ?? null;

        $headlinePlain = strip_tags($headline);
        $headlineAttr = htmlspecialchars($headlinePlain, ENT_QUOTES, 'UTF-8');
        $imageAttr = $image ? htmlspecialchars($image, ENT_QUOTES, 'UTF-8') : '';

        $badgeHtml = $badge ? "<div class=\"inline-block px-5 py-2 mb-8 text-sm font-semibold rounded-full\" style=\"background:var(--primary);color:white;opacity:0.95\">{$badge}</div>" : '';
        $ctaSecondaryHtml = $ctaSecondary ? "<p class=\"mt-5 text-sm theme-muted\">{$ctaSecondary}</p>" : '';

        // VSL template with video
        if ($template === 'video' && $videoUrl) {
            $videoEmbedUrl = $this->getVideoEmbedUrl($videoUrl);
            return <<<HTML
<div class="text-center py-8 lg:py-16">
    {$badgeHtml}
    <h1 class="text-3xl md:text-4xl lg:text-5xl font-extrabold leading-tight mb-8">
        <span class="gradient-text">{$headlinePlain}</span>
    </h1>
    <p class="text-lg md:text-xl theme-muted mb-10 max-w-2xl mx-auto">{$subheadline}</p>
    <div class="video-container max-w-4xl mx-auto mb-10 animate-float">
        <iframe src="{$videoEmbedUrl}" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen loading="lazy"></iframe>
    </div>
    <div class="flex flex-col sm:flex-row gap-4 justify-center">
        <a href="{$checkoutUrl}" class="btn-primary px-10 py-5 rounded-2xl text-white font-bold text-xl">
            {$ctaText}
        </a>
    </div>
    {$ctaSecondaryHtml}
</div>
HTML;
        }

        // Split layout for templates with image
        if ($image && in_array($template, ['modern', 'bold', 'gradient'])) {
            $imgErrorFallback = "this.onerror=null;this.style.cssText='width:100%;aspect-ratio:4/3;border-radius:1.5rem;background:linear-gradient(135deg,var(--primary),var(--secondary));display:block';this.removeAttribute('src');this.classList.add('loaded')";
            return <<<HTML
<div class="flex flex-col lg:flex-row items-center gap-10 lg:gap-16 py-8 lg:py-16">
    <div class="flex-1 text-center lg:text-left">
        {$badgeHtml}
        <h1 class="text-4xl md:text-5xl lg:text-6xl font-extrabold leading-[1.08] mb-6">
            <span class="gradient-text">{$headlinePlain}</span>
        </h1>
        <p class="text-xl md:text-2xl theme-muted mb-3 leading-relaxed">{$subheadline}</p>
        <p class="text-lg theme-muted mb-10 leading-relaxed max-w-xl">{$description}</p>
        <div class="flex flex-col sm:flex-row gap-4 justify-center lg:justify-start">
            <a href="{$checkoutUrl}" class="btn-primary px-10 py-4 rounded-2xl text-white font-bold text-lg">
                {$ctaText}
            </a>
        </div>
        {$ctaSecondaryHtml}
    </div>
    <div class="flex-1 max-w-lg reveal-right">
        <img src="{$imageAttr}" alt="{$headlineAttr}" class="w-full rounded-2xl shadow-2xl animate-float" loading="lazy" onerror="{$imgErrorFallback}" />
    </div>
</div>
HTML;
        }

        // Dark template â€” centered with glow
        if ($template === 'dark') {
            return <<<HTML
<div class="text-center py-12 lg:py-24">
    {$badgeHtml}
    <h1 class="text-4xl md:text-5xl lg:text-7xl font-extrabold leading-[1.05] mb-6">
        <span class="gradient-text">{$headlinePlain}</span>
    </h1>
    <p class="text-xl md:text-2xl theme-muted mb-4 max-w-3xl mx-auto leading-relaxed">{$subheadline}</p>
    <p class="text-lg theme-muted mb-10 max-w-2xl mx-auto leading-relaxed">{$description}</p>
    <div class="flex flex-col sm:flex-row gap-4 justify-center">
        <a href="{$checkoutUrl}" class="btn-primary px-10 py-5 rounded-2xl text-white font-bold text-xl animate-pulse-glow">
            {$ctaText}
        </a>
    </div>
    {$ctaSecondaryHtml}
</div>
HTML;
        }

        // Minimal template â€” clean centered
        if ($template === 'minimal') {
            return <<<HTML
<div class="text-center py-16 lg:py-28">
    {$badgeHtml}
    <h1 class="text-3xl md:text-4xl lg:text-5xl font-medium leading-snug mb-8 max-w-4xl mx-auto tracking-tight">
        {$headlinePlain}
    </h1>
    <p class="text-lg md:text-xl theme-muted mb-4 max-w-2xl mx-auto leading-relaxed">{$subheadline}</p>
    <p class="text-base theme-muted mb-12 max-w-xl mx-auto leading-relaxed">{$description}</p>
    <a href="{$checkoutUrl}" class="inline-block px-8 py-3.5 rounded-lg text-white font-medium text-base" style="background:var(--primary)">
        {$ctaText}
    </a>
    {$ctaSecondaryHtml}
</div>
HTML;
        }

        // Default centered layout
        $imageHtml = '';
        if ($image) {
            $imgErrorFallback = "this.onerror=null;this.style.cssText='width:100%;aspect-ratio:16/9;border-radius:1.5rem;background:linear-gradient(135deg,var(--primary),var(--secondary));display:block';this.removeAttribute('src');this.classList.add('loaded')";
            $imageHtml = "<div class=\"mt-12 max-w-3xl mx-auto reveal-zoom\"><img src=\"{$imageAttr}\" alt=\"{$headlineAttr}\" class=\"w-full rounded-2xl shadow-2xl\" loading=\"lazy\" onerror=\"{$imgErrorFallback}\" /></div>";
        }

        return <<<HTML
<div class="text-center py-12 lg:py-20">
    {$badgeHtml}
    <h1 class="text-4xl md:text-5xl lg:text-6xl font-extrabold leading-[1.08] mb-6">
        <span class="gradient-text">{$headlinePlain}</span>
    </h1>
    <p class="text-xl md:text-2xl theme-muted mb-4 max-w-3xl mx-auto leading-relaxed">{$subheadline}</p>
    <p class="text-lg theme-muted mb-10 max-w-2xl mx-auto leading-relaxed">{$description}</p>
    <div class="flex flex-col sm:flex-row gap-4 justify-center">
        <a href="{$checkoutUrl}" class="btn-primary px-10 py-4 rounded-2xl text-white font-bold text-lg">
            {$ctaText}
        </a>
    </div>
    {$ctaSecondaryHtml}
    {$imageHtml}
</div>
HTML;
    }

    /**
     * Get video embed URL from various video platforms
     */
    private function getVideoEmbedUrl(string $url): string
    {
        // YouTube
        if (preg_match('/(?:youtube\.com\/watch\?v=|youtu\.be\/)([\w-]+)/', $url, $m)) {
            return "https://www.youtube.com/embed/{$m[1]}?rel=0&modestbranding=1";
        }
        // Vimeo
        if (preg_match('/vimeo\.com\/(\d+)/', $url, $m)) {
            return "https://player.vimeo.com/video/{$m[1]}";
        }
        return $url;
    }

    /**
     * Render stats section
     */
    private function renderStatsSection(array $content, string $checkoutUrl, array $theme, string $template = 'modern'): string
    {
        $items = $content['items'] ?? [];
        $isDark = $this->isColorDark($theme['backgroundColor'] ?? '#ffffff');

        $itemsHtml = '';
        foreach ($items as $item) {
            $value = $item['value'] ?? '0';
            $label = $item['label'] ?? '';
            
            $itemsHtml .= <<<HTML
<div class="text-center px-4">
    <div class="text-3xl md:text-4xl font-extrabold gradient-text count-up mb-2">{$value}</div>
    <div class="text-sm theme-muted font-medium">{$label}</div>
</div>
HTML;
        }

        $dividerStyle = $isDark ? 'border-color:rgba(255,255,255,0.1)' : 'border-color:rgba(0,0,0,0.08)';

        return <<<HTML
<div class="flex flex-wrap justify-center gap-8 md:gap-0 md:divide-x stagger-children" style="{$dividerStyle}">
    {$itemsHtml}
</div>
HTML;
    }

    /**
     * Render logos/social proof section
     */
    private function renderLogosSection(array $content, string $checkoutUrl, array $theme, string $template = 'modern'): string
    {
        $title = $content['title'] ?? '';
        $items = $content['items'] ?? [];

        $logosHtml = '';
        foreach ($items as $logo) {
            $logoText = is_string($logo) ? $logo : ($logo['name'] ?? '');
            $logosHtml .= "<div class=\"px-6 py-3 theme-muted font-semibold text-lg opacity-50 hover:opacity-80 transition-opacity\">{$logoText}</div>";
        }

        return <<<HTML
<div class="text-center">
    <p class="text-sm theme-muted font-medium uppercase tracking-widest mb-8">{$title}</p>
    <div class="flex flex-wrap items-center justify-center gap-4">
        {$logosHtml}
    </div>
</div>
HTML;
    }

    /**
     * Render benefits section
     */
    private function renderBenefitsSection(array $content, string $checkoutUrl, array $theme, string $template = 'modern'): string
    {
        $title = $content['title'] ?? 'BenefÃ­cios';
        $subtitle = $content['subtitle'] ?? '';
        $items = $content['items'] ?? [];
        $primaryColor = $theme['primaryColor'] ?? '#6366f1';
        $secondaryColor = $theme['secondaryColor'] ?? '#8b5cf6';

        $cols = count($items) <= 3 ? 'lg:grid-cols-3' : 'lg:grid-cols-4';
        if (count($items) === 2) $cols = 'lg:grid-cols-2';

        $itemsHtml = '';
        foreach ($items as $index => $item) {
            $icon = $item['icon'] ?? 'star';
            $itemTitle = $item['title'] ?? '';
            $itemDesc = $item['description'] ?? '';
            $iconSvg = $this->getIconSvg($icon);
            $delay = $index * 0.08;
            
            $itemsHtml .= <<<HTML
<div class="theme-card card-hover rounded-2xl p-7" style="border:1px solid var(--border);transition-delay:{$delay}s">
    <div class="w-14 h-14 rounded-xl flex items-center justify-center mb-5" style="background:linear-gradient(135deg, {$primaryColor}15, {$secondaryColor}15)">
        <div style="color:var(--primary)">{$iconSvg}</div>
    </div>
    <h3 class="text-lg font-bold mb-3">{$itemTitle}</h3>
    <p class="theme-muted leading-relaxed text-[0.95rem]">{$itemDesc}</p>
</div>
HTML;
        }

        return <<<HTML
<div class="text-center mb-14">
    <h2 class="text-3xl md:text-4xl font-bold mb-4 tracking-tight">{$title}</h2>
    <p class="text-xl theme-muted max-w-2xl mx-auto">{$subtitle}</p>
</div>
<div class="grid md:grid-cols-2 {$cols} gap-6 stagger-children">
    {$itemsHtml}
</div>
HTML;
    }

    /**
     * Get SVG icon by name
     */
    private function getIconSvg(string $name): string
    {
        $icons = [
            'rocket' => '<svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15.59 14.37a6 6 0 01-5.84 7.38v-4.8m5.84-2.58a14.98 14.98 0 006.16-12.12A14.98 14.98 0 009.63 8.41m5.96 5.96a14.926 14.926 0 01-5.841 2.58m-.119-8.54a6 6 0 00-7.381 5.84h4.8m2.581-5.84a14.927 14.927 0 00-2.58 5.84m2.699 2.7c-.103.021-.207.041-.311.06a15.09 15.09 0 01-2.448-2.448 14.9 14.9 0 01.06-.312m-2.24 2.39a4.493 4.493 0 00-1.757 4.306 4.493 4.493 0 004.306-1.758M16.5 9a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0z"></path></svg>',
            'shield-check' => '<svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"></path></svg>',
            'users' => '<svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z"></path></svg>',
            'star' => '<svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M11.48 3.499a.562.562 0 011.04 0l2.125 5.111a.563.563 0 00.475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 00-.182.557l1.285 5.385a.562.562 0 01-.84.61l-4.725-2.885a.563.563 0 00-.586 0L6.982 20.54a.562.562 0 01-.84-.61l1.285-5.386a.562.562 0 00-.182-.557l-4.204-3.602a.563.563 0 01.321-.988l5.518-.442a.563.563 0 00.475-.345L11.48 3.5z"></path></svg>',
            'lightning' => '<svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z"></path></svg>',
            'chart-bar' => '<svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z"></path></svg>',
            'clock' => '<svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>',
            'heart' => '<svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 8.25c0-2.485-2.099-4.5-4.688-4.5-1.935 0-3.597 1.126-4.312 2.733-.715-1.607-2.377-2.733-4.313-2.733C5.1 3.75 3 5.765 3 8.25c0 7.22 9 12 9 12s9-4.78 9-12z"></path></svg>',
            'fire' => '<svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15.362 5.214A8.252 8.252 0 0112 21 8.25 8.25 0 016.038 7.048 8.287 8.287 0 009 9.6a8.983 8.983 0 013.361-6.867 8.21 8.21 0 003 2.48z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 18a3.75 3.75 0 00.495-7.467 5.99 5.99 0 00-1.925 3.546 5.974 5.974 0 01-2.133-1A3.75 3.75 0 0012 18z"></path></svg>',
            'target' => '<svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="12" r="9" stroke-width="1.5"/><circle cx="12" cy="12" r="5" stroke-width="1.5"/><circle cx="12" cy="12" r="1" fill="currentColor"/></svg>',
            'trophy' => '<svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16.5 18.75h-9m9 0a3 3 0 013 3h-15a3 3 0 013-3m9 0v-3.375c0-.621-.503-1.125-1.125-1.125h-.871M7.5 18.75v-3.375c0-.621.504-1.125 1.125-1.125h.872m5.007 0H9.497m5.007 0a7.454 7.454 0 01-.982-3.172M9.497 14.25a7.454 7.454 0 00.981-3.172M5.25 4.236c-.982.143-1.954.317-2.916.52A6.003 6.003 0 007.73 9.728M5.25 4.236V4.5c0 2.108.966 3.99 2.48 5.228M5.25 4.236V2.721C7.456 2.41 9.71 2.25 12 2.25c2.291 0 4.545.16 6.75.47v1.516M18.75 4.236c.982.143 1.954.317 2.916.52A6.003 6.003 0 0116.27 9.728M18.75 4.236V4.5c0 2.108-.966 3.99-2.48 5.228m0 0a6.003 6.003 0 01-5.54 0"></path></svg>',
            'diamond' => '<svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 2L2 9l10 13L22 9 12 2zM2 9h20"></path></svg>',
            'zap' => '<svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z"></path></svg>',
            'crown' => '<svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 17l3-9 6 5 6-5 3 9H3z M3 17h18v2H3v-2z"></path></svg>',
            'gift' => '<svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 11.25v8.25a1.5 1.5 0 01-1.5 1.5H4.5a1.5 1.5 0 01-1.5-1.5v-8.25M12 4.875A2.625 2.625 0 109.375 7.5H12m0-2.625V7.5m0-2.625A2.625 2.625 0 1114.625 7.5H12m0 0V21m-8.625-9.75h18c.621 0 1.125-.504 1.125-1.125v-1.5c0-.621-.504-1.125-1.125-1.125h-18c-.621 0-1.125.504-1.125 1.125v1.5c0 .621.504 1.125 1.125 1.125z"></path></svg>',
            'lock' => '<svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"></path></svg>',
            'check-circle' => '<svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>',
            'sparkles' => '<svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09zM18.259 8.715L18 9.75l-.259-1.035a3.375 3.375 0 00-2.455-2.456L14.25 6l1.036-.259a3.375 3.375 0 002.455-2.456L18 2.25l.259 1.035a3.375 3.375 0 002.455 2.456L21.75 6l-1.036.259a3.375 3.375 0 00-2.455 2.456zM16.894 20.567L16.5 21.75l-.394-1.183a2.25 2.25 0 00-1.423-1.423L13.5 18.75l1.183-.394a2.25 2.25 0 001.423-1.423l.394-1.183.394 1.183a2.25 2.25 0 001.423 1.423l1.183.394-1.183.394a2.25 2.25 0 00-1.423 1.423z"></path></svg>',
            'globe' => '<svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 21a9.004 9.004 0 008.716-6.747M12 21a9.004 9.004 0 01-8.716-6.747M12 21c2.485 0 4.5-4.03 4.5-9S14.485 3 12 3m0 18c-2.485 0-4.5-4.03-4.5-9S9.515 3 12 3m0 0a8.997 8.997 0 017.843 4.582M12 3a8.997 8.997 0 00-7.843 4.582m15.686 0A11.953 11.953 0 0112 10.5c-2.998 0-5.74-1.1-7.843-2.918m15.686 0A8.959 8.959 0 0121 12c0 .778-.099 1.533-.284 2.253m0 0A17.919 17.919 0 0112 16.5c-3.162 0-6.133-.815-8.716-2.247m0 0A9.015 9.015 0 013 12c0-1.605.42-3.113 1.157-4.418"></path></svg>',
            'arrow-right' => '<svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"></path></svg>',
        ];

        return $icons[$name] ?? '<svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M5 13l4 4L19 7"></path></svg>';
    }

    /**
     * Render testimonials section
     */
    private function renderTestimonialsSection(array $content, string $checkoutUrl, array $theme, string $template = 'modern'): string
    {
        $title = $content['title'] ?? 'Depoimentos';
        $subtitle = $content['subtitle'] ?? '';
        $items = $content['items'] ?? [];

        $itemsHtml = '';
        foreach ($items as $index => $item) {
            $name = $item['name'] ?? 'Cliente';
            $role = $item['role'] ?? '';
            $text = $item['text'] ?? '';
            $rating = $item['rating'] ?? 5;
            $photo = $item['photo'] ?? null;
            
            $stars = str_repeat('â˜…', $rating);
            $nameAttr = htmlspecialchars(strip_tags($name), ENT_QUOTES, 'UTF-8');
            $photoAttr = $photo ? htmlspecialchars($photo, ENT_QUOTES, 'UTF-8') : '';
            $initial = mb_substr(strip_tags($name), 0, 1);
            $avatarFallback = "<div class=\"w-12 h-12 rounded-full flex items-center justify-center text-white font-bold text-lg\" style=\"background:linear-gradient(135deg, var(--primary), var(--secondary))\">{$initial}</div>";
            $avatarHtml = $photo 
                ? "<img src=\"{$photoAttr}\" alt=\"{$nameAttr}\" class=\"w-12 h-12 rounded-full object-cover\" loading=\"lazy\" onerror=\"this.onerror=null;this.outerHTML='<div class=\\\"w-12 h-12 rounded-full flex items-center justify-center text-white font-bold text-lg\\\" style=\\\"background:linear-gradient(135deg, var(--primary), var(--secondary))\\\">{$initial}</div>'\" />"
                : $avatarFallback;
            
            $delay = $index * 0.1;
            
            $itemsHtml .= <<<HTML
<div class="theme-card card-hover rounded-2xl p-7" style="border:1px solid var(--border);transition-delay:{$delay}s">
    <div class="text-yellow-400 text-lg mb-4 tracking-wider">{$stars}</div>
    <p class="theme-subtle mb-6 leading-relaxed text-[0.95rem]">"{$text}"</p>
    <div class="flex items-center gap-4 pt-4" style="border-top:1px solid var(--border)">
        {$avatarHtml}
        <div>
            <p class="font-semibold text-sm">{$name}</p>
            <p class="text-xs theme-muted">{$role}</p>
        </div>
    </div>
</div>
HTML;
        }

        $cols = count($items) >= 3 ? 'md:grid-cols-3' : 'md:grid-cols-2';

        return <<<HTML
<div class="text-center mb-14">
    <h2 class="text-3xl md:text-4xl font-bold mb-4 tracking-tight">{$title}</h2>
    <p class="text-xl theme-muted max-w-2xl mx-auto">{$subtitle}</p>
</div>
<div class="grid {$cols} gap-6 stagger-children">
    {$itemsHtml}
</div>
HTML;
    }

    /**
     * Render features section
     */
    private function renderFeaturesSection(array $content, string $checkoutUrl, array $theme, string $template = 'modern'): string
    {
        $title = $content['title'] ?? 'Recursos';
        $subtitle = $content['subtitle'] ?? '';
        $items = $content['items'] ?? [];

        $itemsHtml = '';
        foreach ($items as $index => $item) {
            $icon = $item['icon'] ?? 'check-circle';
            $itemTitle = $item['title'] ?? '';
            $itemDesc = $item['description'] ?? '';
            $iconSvg = $this->getIconSvg($icon);
            $delay = $index * 0.08;
            
            $itemsHtml .= <<<HTML
<div class="flex gap-5 items-start" style="transition-delay:{$delay}s">
    <div class="flex-shrink-0 w-12 h-12 rounded-xl flex items-center justify-center" style="background:linear-gradient(135deg, var(--primary), var(--secondary));color:white">
        {$iconSvg}
    </div>
    <div>
        <h3 class="font-bold text-base mb-1.5">{$itemTitle}</h3>
        <p class="theme-muted text-sm leading-relaxed">{$itemDesc}</p>
    </div>
</div>
HTML;
        }

        return <<<HTML
<div class="text-center mb-14">
    <h2 class="text-3xl md:text-4xl font-bold mb-4 tracking-tight">{$title}</h2>
    <p class="text-xl theme-muted max-w-2xl mx-auto">{$subtitle}</p>
</div>
<div class="grid md:grid-cols-2 gap-8 max-w-4xl mx-auto stagger-children">
    {$itemsHtml}
</div>
HTML;
    }

    /**
     * Render pricing section
     */
    private function renderPricingSection(array $content, string $checkoutUrl, array $theme, string $template = 'modern'): string
    {
        $title = $content['title'] ?? 'PreÃ§os';
        $subtitle = $content['subtitle'] ?? '';
        $originalPrice = $content['originalPrice'] ?? '';
        $currentPrice = $content['currentPrice'] ?? '';
        $discount = $content['discount'] ?? '';
        $installments = $content['installments'] ?? '';
        $features = $content['features'] ?? [];
        $ctaText = $content['ctaText'] ?? 'Comprar Agora';
        $urgency = $content['urgency'] ?? '';
        $primaryColor = $theme['primaryColor'] ?? '#6366f1';
        $secondaryColor = $theme['secondaryColor'] ?? '#8b5cf6';

        $featuresHtml = '';
        foreach ($features as $feature) {
            $featuresHtml .= <<<HTML
<li class="flex items-center py-2.5 gap-3">
    <svg class="w-5 h-5 flex-shrink-0" style="color:var(--accent)" fill="currentColor" viewBox="0 0 20 20">
        <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
    </svg>
    <span class="text-[0.95rem]">{$feature}</span>
</li>
HTML;
        }

        $urgencyHtml = $urgency ? "<p class=\"mt-5 text-sm font-semibold animate-pulse\" style=\"color:var(--primary)\">{$urgency}</p>" : '';
        $discountBadge = $discount ? "<span class=\"inline-block px-4 py-1.5 rounded-full text-sm font-bold mb-5\" style=\"background:var(--accent);color:white\">{$discount}</span>" : '';

        return <<<HTML
<div class="max-w-md mx-auto">
    <div class="text-center mb-10">
        <h2 class="text-3xl md:text-4xl font-bold mb-4 tracking-tight">{$title}</h2>
        <p class="text-xl theme-muted">{$subtitle}</p>
    </div>
    <div class="theme-card rounded-3xl shadow-2xl overflow-hidden card-hover" style="border:1px solid var(--border)">
        <div class="p-8 text-center text-white relative overflow-hidden" style="background:linear-gradient(135deg, {$primaryColor}, {$secondaryColor})">
            <div class="absolute inset-0 opacity-10" style="background:radial-gradient(circle at 30% 20%, white 0%, transparent 50%)"></div>
            <div class="relative z-10">
                {$discountBadge}
                <p class="text-lg line-through opacity-60">{$originalPrice}</p>
                <p class="text-5xl md:text-6xl font-extrabold my-3 tracking-tight">{$currentPrice}</p>
                <p class="text-sm opacity-80">{$installments}</p>
            </div>
        </div>
        <div class="p-8">
            <ul class="mb-8">
                {$featuresHtml}
            </ul>
            <a href="{$checkoutUrl}" class="btn-primary block w-full py-4 rounded-2xl text-white font-bold text-center text-lg">
                {$ctaText}
            </a>
            {$urgencyHtml}
        </div>
    </div>
</div>
HTML;
    }

    /**
     * Render guarantee section
     */
    private function renderGuaranteeSection(array $content, string $checkoutUrl, array $theme, string $template = 'modern'): string
    {
        $title = $content['title'] ?? 'Garantia';
        $description = $content['description'] ?? '';
        $isDark = $this->isColorDark($theme['backgroundColor'] ?? '#ffffff');
        $shieldBg = $isDark ? 'rgba(34, 197, 94, 0.1)' : '#f0fdf4';
        $shieldColor = $isDark ? '#4ade80' : '#16a34a';

        return <<<HTML
<div class="flex flex-col md:flex-row items-center gap-8 theme-card rounded-3xl p-8 md:p-10 card-hover" style="border:1px solid var(--border)">
    <div class="flex-shrink-0">
        <div class="w-24 h-24 rounded-2xl flex items-center justify-center animate-float" style="background:{$shieldBg}">
            <svg class="w-12 h-12" style="color:{$shieldColor}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z"></path>
            </svg>
        </div>
    </div>
    <div>
        <h3 class="text-2xl font-bold mb-3">{$title}</h3>
        <p class="theme-muted leading-relaxed text-[0.95rem]">{$description}</p>
    </div>
</div>
HTML;
    }

    /**
     * Render FAQ section
     */
    private function renderFaqSection(array $content, string $checkoutUrl, array $theme, string $template = 'modern'): string
    {
        $title = $content['title'] ?? 'FAQ';
        $items = $content['items'] ?? [];

        $itemsHtml = '';
        foreach ($items as $index => $item) {
            $question = $item['question'] ?? '';
            $answer = $item['answer'] ?? '';
            
            $itemsHtml .= <<<HTML
<div class="theme-card rounded-2xl overflow-hidden mb-3" style="border:1px solid var(--border)">
    <button class="faq-toggle w-full px-6 py-5 text-left font-semibold flex justify-between items-center gap-4 cursor-pointer" style="color:var(--text)">
        <span class="text-[0.95rem]">{$question}</span>
        <svg class="w-5 h-5 flex-shrink-0 faq-chevron theme-muted" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
        </svg>
    </button>
    <div class="faq-answer">
        <p class="theme-muted leading-relaxed text-[0.9rem]">{$answer}</p>
    </div>
</div>
HTML;
        }

        return <<<HTML
<div class="text-center mb-14">
    <h2 class="text-3xl md:text-4xl font-bold mb-4 tracking-tight">{$title}</h2>
</div>
<div class="max-w-3xl mx-auto stagger-children">
    {$itemsHtml}
</div>
HTML;
    }

    /**
     * Render countdown section
     */
    private function renderCountdownSection(array $content, string $checkoutUrl, array $theme, string $template = 'modern'): string
    {
        $title = $content['title'] ?? 'Oferta por tempo limitado';
        $urgencyText = $content['urgencyText'] ?? '';
        $endDate = $content['endDate'] ?? date('Y-m-d\TH:i:s', strtotime('+3 days'));

        $isDark = $this->isColorDark($theme['backgroundColor'] ?? '#ffffff');
        $digitBg = $isDark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.05)';

        return <<<HTML
<div class="text-center">
    <h2 class="text-2xl md:text-3xl font-bold mb-8">{$title}</h2>
    <div class="flex justify-center gap-4 mb-6" id="countdown-display">
        <div class="flex flex-col items-center">
            <div class="text-3xl md:text-5xl font-extrabold px-5 py-3 rounded-xl" style="background:{$digitBg}" id="cd-days">00</div>
            <span class="text-xs theme-muted mt-2 uppercase tracking-wider">Dias</span>
        </div>
        <div class="text-3xl md:text-5xl font-extrabold theme-muted pt-3">:</div>
        <div class="flex flex-col items-center">
            <div class="text-3xl md:text-5xl font-extrabold px-5 py-3 rounded-xl" style="background:{$digitBg}" id="cd-hours">00</div>
            <span class="text-xs theme-muted mt-2 uppercase tracking-wider">Horas</span>
        </div>
        <div class="text-3xl md:text-5xl font-extrabold theme-muted pt-3">:</div>
        <div class="flex flex-col items-center">
            <div class="text-3xl md:text-5xl font-extrabold px-5 py-3 rounded-xl" style="background:{$digitBg}" id="cd-mins">00</div>
            <span class="text-xs theme-muted mt-2 uppercase tracking-wider">Min</span>
        </div>
        <div class="text-3xl md:text-5xl font-extrabold theme-muted pt-3">:</div>
        <div class="flex flex-col items-center">
            <div class="text-3xl md:text-5xl font-extrabold px-5 py-3 rounded-xl" style="background:{$digitBg};color:var(--primary)" id="cd-secs">00</div>
            <span class="text-xs theme-muted mt-2 uppercase tracking-wider">Seg</span>
        </div>
    </div>
    <p class="text-sm font-semibold animate-pulse" style="color:var(--primary)">{$urgencyText}</p>
    <script>
    (function(){
        var end = new Date('{$endDate}').getTime();
        function updateCountdown() {
            var now = Date.now();
            var diff = Math.max(0, end - now);
            var d = Math.floor(diff / 86400000);
            var h = Math.floor((diff % 86400000) / 3600000);
            var m = Math.floor((diff % 3600000) / 60000);
            var s = Math.floor((diff % 60000) / 1000);
            var el = function(id) { return document.getElementById(id); };
            if (el('cd-days')) el('cd-days').textContent = String(d).padStart(2,'0');
            if (el('cd-hours')) el('cd-hours').textContent = String(h).padStart(2,'0');
            if (el('cd-mins')) el('cd-mins').textContent = String(m).padStart(2,'0');
            if (el('cd-secs')) el('cd-secs').textContent = String(s).padStart(2,'0');
        }
        updateCountdown();
        setInterval(updateCountdown, 1000);
    })();
    </script>
</div>
HTML;
    }

    /**
     * Render CTA section
     */
    private function renderCtaSection(array $content, string $checkoutUrl, array $theme, string $template = 'modern'): string
    {
        $title = $content['title'] ?? 'NÃ£o perca!';
        $description = $content['description'] ?? '';
        $buttonText = $content['buttonText'] ?? 'Comprar Agora';
        $buttonSecondary = $content['buttonSecondary'] ?? '';

        return <<<HTML
<div class="text-center text-white py-4">
    <h2 class="text-3xl md:text-4xl font-bold mb-5 tracking-tight">{$title}</h2>
    <p class="text-xl opacity-90 mb-10 max-w-2xl mx-auto leading-relaxed">{$description}</p>
    <a href="{$checkoutUrl}" class="inline-block px-12 py-5 rounded-2xl font-bold text-lg transition-all shadow-xl hover:shadow-2xl hover:scale-[1.03]" style="background:white;color:var(--primary)">
        {$buttonText}
    </a>
    <p class="mt-5 text-sm opacity-70">{$buttonSecondary}</p>
</div>
HTML;
    }

    /**
     * Render video section (standalone)
     */
    private function renderVideoSection(array $content, string $checkoutUrl, array $theme, string $template = 'modern'): string
    {
        $title = $content['title'] ?? '';
        $videoUrl = $content['videoUrl'] ?? $content['url'] ?? '';
        $description = $content['description'] ?? '';

        if (empty($videoUrl)) {
            return '<div class="text-center theme-muted py-8">VÃ­deo nÃ£o configurado</div>';
        }

        $embedUrl = $this->getVideoEmbedUrl($videoUrl);

        $titleHtml = $title ? "<h2 class=\"text-2xl md:text-3xl font-bold mb-6 text-center tracking-tight\">{$title}</h2>" : '';
        $descHtml = $description ? "<p class=\"text-center theme-muted mt-6 max-w-2xl mx-auto\">{$description}</p>" : '';

        return <<<HTML
{$titleHtml}
<div class="video-container max-w-4xl mx-auto rounded-2xl overflow-hidden shadow-2xl">
    <iframe src="{$embedUrl}" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen loading="lazy"></iframe>
</div>
{$descHtml}
HTML;
    }

    /**
     * Render about section
     */
    private function renderAboutSection(array $content, string $checkoutUrl, array $theme, string $template = 'modern'): string
    {
        $title = $content['title'] ?? 'Sobre';
        $description = $content['description'] ?? '';
        $image = $content['image'] ?? null;

        $imageHtml = '';
        if ($image) {
            $imageAttr = htmlspecialchars($image, ENT_QUOTES, 'UTF-8');
            $imageHtml = "<div class=\"flex-shrink-0\"><img src=\"{$imageAttr}\" alt=\"\" class=\"w-32 h-32 rounded-2xl object-cover shadow-lg\" loading=\"lazy\" /></div>";
        }

        return <<<HTML
<div class="flex flex-col md:flex-row items-center gap-8 max-w-4xl mx-auto">
    {$imageHtml}
    <div>
        <h2 class="text-2xl md:text-3xl font-bold mb-4">{$title}</h2>
        <p class="theme-muted leading-relaxed">{$description}</p>
    </div>
</div>
HTML;
    }
}
