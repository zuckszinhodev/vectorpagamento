<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class LandingPage extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'account_id',
        'product_id',
        'checkout_id',
        'name',
        'slug',
        'description',
        'prompt',
        'ai_model',
        'content',
        'sections',
        'styles',
        'seo',
        'template',
        'color_scheme',
        'custom_colors',
        'custom_fonts',
        'status',
        'published_at',
        'views_count',
        'clicks_count',
        'conversions_count',
        'custom_domain',
        'subdomain',
        'version',
        'version_history',
    ];

    protected $casts = [
        'content' => 'array',
        'sections' => 'array',
        'styles' => 'array',
        'seo' => 'array',
        'custom_colors' => 'array',
        'custom_fonts' => 'array',
        'version_history' => 'array',
        'published_at' => 'datetime',
        'views_count' => 'integer',
        'clicks_count' => 'integer',
        'conversions_count' => 'integer',
        'version' => 'integer',
    ];

    protected $attributes = [
        'content' => '{}',
        'status' => 'draft',
        'template' => 'modern',
        'color_scheme' => 'auto',
        'views_count' => 0,
        'clicks_count' => 0,
        'conversions_count' => 0,
        'version' => 1,
    ];

    /**
     * Boot the model
     */
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->slug)) {
                $model->slug = static::generateUniqueSlug($model->name);
            }
        });
    }

    /**
     * Generate unique slug
     */
    public static function generateUniqueSlug(string $name): string
    {
        $slug = Str::slug($name);
        
        if (empty($slug)) {
            $slug = 'pagina';
        }

        // Check against ALL records including soft-deleted to avoid DB unique constraint conflicts
        $exists = static::withTrashed()->where('slug', $slug)->exists();

        if ($exists) {
            // Add a short random code to make it unique
            $code = strtolower(Str::random(5));
            $slug = $slug . '-' . $code;

            // In the unlikely event it still collides, keep trying
            while (static::withTrashed()->where('slug', $slug)->exists()) {
                $code = strtolower(Str::random(5));
                $slug = Str::slug($name) . '-' . $code;
            }
        }

        return $slug;
    }

    /**
     * Relationships
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function checkout(): BelongsTo
    {
        return $this->belongsTo(Checkout::class);
    }

    public function variants(): HasMany
    {
        return $this->hasMany(LandingPageVariant::class);
    }

    /**
     * Scopes
     */
    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    public function scopeDraft($query)
    {
        return $query->where('status', 'draft');
    }

    public function scopeForAccount($query, string $accountId)
    {
        return $query->where('account_id', $accountId);
    }

    /**
     * Helpers
     */
    public function isPublished(): bool
    {
        return $this->status === 'published';
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }

    public function publish(): void
    {
        $this->update([
            'status' => 'published',
            'published_at' => now(),
        ]);
    }

    public function unpublish(): void
    {
        $this->update([
            'status' => 'draft',
        ]);
    }

    public function incrementViews(): void
    {
        $this->increment('views_count');
    }

    public function incrementClicks(): void
    {
        $this->increment('clicks_count');
    }

    public function incrementConversions(): void
    {
        $this->increment('conversions_count');
    }

    public function getConversionRate(): float
    {
        if ($this->views_count === 0) {
            return 0;
        }

        return round(($this->conversions_count / $this->views_count) * 100, 2);
    }

    public function getUrl(): string
    {
        if ($this->custom_domain) {
            return "https://{$this->custom_domain}";
        }

        if ($this->subdomain) {
            return "https://{$this->subdomain}.wazerpay.com";
        }

        return url("/lp/{$this->slug}");
    }

    /**
     * Save a new version before updating content
     */
    public function saveVersion(): void
    {
        $history = $this->version_history ?? [];
        
        $history[] = [
            'version' => $this->version,
            'content' => $this->content,
            'sections' => $this->sections,
            'updated_at' => now()->toIso8601String(),
        ];

        // Keep only last 10 versions
        if (count($history) > 10) {
            $history = array_slice($history, -10);
        }

        $this->version_history = $history;
        $this->version = $this->version + 1;
    }

    /**
     * Available templates
     */
    public static function getTemplates(): array
    {
        return [
            'modern' => [
                'name' => 'Moderno',
                'description' => 'Design limpo e moderno com foco em conversão',
                'preview' => '/templates/modern-preview.png',
            ],
            'bold' => [
                'name' => 'Impactante',
                'description' => 'Design ousado com cores vibrantes',
                'preview' => '/templates/bold-preview.png',
            ],
            'minimal' => [
                'name' => 'Minimalista',
                'description' => 'Design simples e elegante',
                'preview' => '/templates/minimal-preview.png',
            ],
            'dark' => [
                'name' => 'Dark Mode',
                'description' => 'Design escuro profissional',
                'preview' => '/templates/dark-preview.png',
            ],
            'gradient' => [
                'name' => 'Gradiente',
                'description' => 'Design com gradientes modernos',
                'preview' => '/templates/gradient-preview.png',
            ],
            'video' => [
                'name' => 'Video Sales Letter',
                'description' => 'Otimizado para VSL com vídeo hero',
                'preview' => '/templates/video-preview.png',
            ],
        ];
    }

    /**
     * Default section types for landing pages
     */
    public static function getSectionTypes(): array
    {
        return [
            'hero' => [
                'name' => 'Hero Section',
                'description' => 'Seção principal com título e CTA',
                'icon' => 'rectangle-stack',
            ],
            'benefits' => [
                'name' => 'Benefícios',
                'description' => 'Lista de benefícios do produto',
                'icon' => 'check-badge',
            ],
            'features' => [
                'name' => 'Funcionalidades',
                'description' => 'Grid de funcionalidades',
                'icon' => 'squares-2x2',
            ],
            'testimonials' => [
                'name' => 'Depoimentos',
                'description' => 'Provas sociais e reviews',
                'icon' => 'chat-bubble-left-right',
            ],
            'pricing' => [
                'name' => 'Preços',
                'description' => 'Tabela de preços ou oferta',
                'icon' => 'currency-dollar',
            ],
            'faq' => [
                'name' => 'FAQ',
                'description' => 'Perguntas frequentes',
                'icon' => 'question-mark-circle',
            ],
            'video' => [
                'name' => 'Vídeo',
                'description' => 'Seção de vídeo/VSL',
                'icon' => 'play-circle',
            ],
            'guarantee' => [
                'name' => 'Garantia',
                'description' => 'Seção de garantia',
                'icon' => 'shield-check',
            ],
            'about' => [
                'name' => 'Sobre',
                'description' => 'Sobre o autor/empresa',
                'icon' => 'user-circle',
            ],
            'countdown' => [
                'name' => 'Contador',
                'description' => 'Timer de urgência',
                'icon' => 'clock',
            ],
            'cta' => [
                'name' => 'Call to Action',
                'description' => 'Botão de ação',
                'icon' => 'cursor-arrow-rays',
            ],
            'custom' => [
                'name' => 'Personalizado',
                'description' => 'HTML/conteúdo customizado',
                'icon' => 'code-bracket',
            ],
        ];
    }
}
