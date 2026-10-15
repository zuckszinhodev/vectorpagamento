<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class N8nIntegration extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'name',
        'description',
        'account_id',
        'webhook_url',
        'events',
        'secret_key',
        'is_active',
        'retry_count',
        'retry_delay',
        'timeout',
        'headers',
        'metadata',
        'last_triggered_at',
        'last_status',
        'last_response',
        'success_count',
        'failure_count',
    ];

    protected $casts = [
        'events' => 'array',
        'headers' => 'array',
        'metadata' => 'array',
        'is_active' => 'boolean',
        'last_triggered_at' => 'datetime',
        'retry_count' => 'integer',
        'retry_delay' => 'integer',
        'timeout' => 'integer',
        'success_count' => 'integer',
        'failure_count' => 'integer',
    ];

    protected $hidden = [
        'secret_key',
    ];

    protected $attributes = [
        'retry_count' => 3,
        'retry_delay' => 60,
        'timeout' => 30,
        'success_count' => 0,
        'failure_count' => 0,
    ];

    /**
     * Relationship with Account
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Available n8n webhook events
     */
    public static function getAvailableEvents(): array
    {
        return [
            // Payment Events
            'payment.created' => 'Pagamento criado',
            'payment.processing' => 'Pagamento em processamento',
            'payment.approved' => 'Pagamento aprovado',
            'payment.rejected' => 'Pagamento rejeitado',
            'payment.refunded' => 'Pagamento estornado',
            'payment.cancelled' => 'Pagamento cancelado',
            'payment.expired' => 'Pagamento expirado',
            
            // Charge Events
            'charge.created' => 'Cobrança criada',
            'charge.updated' => 'Cobrança atualizada',
            'charge.paid' => 'Cobrança paga',
            'charge.expired' => 'Cobrança expirada',
            'charge.cancelled' => 'Cobrança cancelada',
            
            // PIX Events
            'pix.generated' => 'PIX gerado',
            'pix.paid' => 'PIX pago',
            'pix.expired' => 'PIX expirado',
            
            // Credit Card Events
            'card.authorized' => 'Cartão autorizado',
            'card.captured' => 'Cartão capturado',
            'card.declined' => 'Cartão recusado',
            
            // Boleto Events
            'boleto.generated' => 'Boleto gerado',
            'boleto.paid' => 'Boleto pago',
            'boleto.expired' => 'Boleto expirado',
            
            // Withdrawal Events
            'withdrawal.requested' => 'Saque solicitado',
            'withdrawal.approved' => 'Saque aprovado',
            'withdrawal.completed' => 'Saque completado',
            'withdrawal.rejected' => 'Saque rejeitado',
            
            // Customer Events
            'customer.created' => 'Cliente criado',
            'customer.updated' => 'Cliente atualizado',
            
            // Product Events
            'product.created' => 'Produto criado',
            'product.updated' => 'Produto atualizado',
            'product.deleted' => 'Produto deletado',
            
            // Checkout Events
            'checkout.created' => 'Checkout criado',
            'checkout.visited' => 'Checkout visitado',
            'checkout.abandoned' => 'Checkout abandonado',
            'checkout.completed' => 'Checkout completado',
            
            // Affiliate Events
            'affiliate.created' => 'Afiliado criado',
            'affiliate.sale' => 'Venda de afiliado',
            'affiliate.commission' => 'Comissão de afiliado',
            
            // KYC Events
            'kyc.submitted' => 'KYC enviado',
            'kyc.approved' => 'KYC aprovado',
            'kyc.rejected' => 'KYC rejeitado',
        ];
    }

    /**
     * Event categories for better organization
     */
    public static function getEventCategories(): array
    {
        return [
            'payment' => [
                'label' => 'Pagamentos',
                'events' => ['payment.created', 'payment.processing', 'payment.approved', 'payment.rejected', 'payment.refunded', 'payment.cancelled', 'payment.expired'],
            ],
            'charge' => [
                'label' => 'Cobranças',
                'events' => ['charge.created', 'charge.updated', 'charge.paid', 'charge.expired', 'charge.cancelled'],
            ],
            'pix' => [
                'label' => 'PIX',
                'events' => ['pix.generated', 'pix.paid', 'pix.expired'],
            ],
            'card' => [
                'label' => 'Cartão de Crédito',
                'events' => ['card.authorized', 'card.captured', 'card.declined'],
            ],
            'boleto' => [
                'label' => 'Boleto',
                'events' => ['boleto.generated', 'boleto.paid', 'boleto.expired'],
            ],
            'withdrawal' => [
                'label' => 'Saques',
                'events' => ['withdrawal.requested', 'withdrawal.approved', 'withdrawal.completed', 'withdrawal.rejected'],
            ],
            'customer' => [
                'label' => 'Clientes',
                'events' => ['customer.created', 'customer.updated'],
            ],
            'product' => [
                'label' => 'Produtos',
                'events' => ['product.created', 'product.updated', 'product.deleted'],
            ],
            'checkout' => [
                'label' => 'Checkouts',
                'events' => ['checkout.created', 'checkout.visited', 'checkout.abandoned', 'checkout.completed'],
            ],
            'affiliate' => [
                'label' => 'Afiliados',
                'events' => ['affiliate.created', 'affiliate.sale', 'affiliate.commission'],
            ],
            'kyc' => [
                'label' => 'KYC',
                'events' => ['kyc.submitted', 'kyc.approved', 'kyc.rejected'],
            ],
        ];
    }

    /**
     * Check if integration listens to a specific event
     */
    public function listensTo(string $event): bool
    {
        $events = $this->events ?? [];
        
        // Check for wildcard
        if (in_array('*', $events)) {
            return true;
        }
        
        // Check for category wildcard (e.g., 'payment.*')
        $category = explode('.', $event)[0] ?? '';
        if (in_array($category . '.*', $events)) {
            return true;
        }
        
        return in_array($event, $events);
    }

    /**
     * Mark success delivery
     */
    public function markSuccess(?string $response = null): void
    {
        $this->increment('success_count');
        $this->update([
            'last_triggered_at' => now(),
            'last_status' => 'success',
            'last_response' => $response,
        ]);
    }

    /**
     * Mark failed delivery
     */
    public function markFailure(?string $response = null): void
    {
        $this->increment('failure_count');
        $this->update([
            'last_triggered_at' => now(),
            'last_status' => 'failed',
            'last_response' => $response,
        ]);
    }

    /**
     * Generate a new secret key
     */
    public static function generateSecretKey(): string
    {
        return 'n8n_' . bin2hex(random_bytes(32));
    }

    /**
     * Check health status
     */
    public function getHealthStatus(): string
    {
        if (!$this->is_active) {
            return 'inactive';
        }

        $total = $this->success_count + $this->failure_count;
        
        if ($total === 0) {
            return 'pending';
        }

        $successRate = $this->success_count / $total;
        
        if ($successRate >= 0.95) {
            return 'healthy';
        } elseif ($successRate >= 0.80) {
            return 'warning';
        } else {
            return 'critical';
        }
    }

    /**
     * Scope for active integrations
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope for integrations listening to an event
     */
    public function scopeForEvent($query, string $event)
    {
        return $query->where(function ($q) use ($event) {
            $category = explode('.', $event)[0];
            $q->whereJsonContains('events', $event)
              ->orWhereJsonContains('events', '*')
              ->orWhereJsonContains('events', $category . '.*');
        });
    }
}
