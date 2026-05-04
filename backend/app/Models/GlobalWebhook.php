<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class GlobalWebhook extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'url',
        'events',
        'secret',
        'active',
        'description',
        'last_triggered_at',
        'success_count',
        'failure_count',
    ];

    protected $casts = [
        'events' => 'array',
        'active' => 'boolean',
        'last_triggered_at' => 'datetime',
    ];

    protected $hidden = [
        'secret',
    ];

    /**
     * Available webhook events
     */
    public static function getAvailableEvents(): array
    {
        return [
            'payment.created' => 'Pagamento criado',
            'payment.approved' => 'Pagamento aprovado',
            'payment.rejected' => 'Pagamento rejeitado',
            'payment.refunded' => 'Pagamento estornado',
            'payment.cancelled' => 'Pagamento cancelado',
            'charge.created' => 'Cobrança criada',
            'charge.paid' => 'Cobrança paga',
            'charge.expired' => 'Cobrança expirada',
            'withdrawal.requested' => 'Saque solicitado',
            'withdrawal.approved' => 'Saque aprovado',
            'withdrawal.completed' => 'Saque completado',
            'withdrawal.rejected' => 'Saque rejeitado',
            'kyc.submitted' => 'KYC enviado',
            'kyc.approved' => 'KYC aprovado',
            'kyc.rejected' => 'KYC rejeitado',
            'customer.created' => 'Cliente criado',
            'customer.updated' => 'Cliente atualizado',
        ];
    }

    /**
     * Increment success count
     */
    public function markSuccess(): void
    {
        $this->increment('success_count');
        $this->update(['last_triggered_at' => now()]);
    }

    /**
     * Increment failure count
     */
    public function markFailure(): void
    {
        $this->increment('failure_count');
        $this->update(['last_triggered_at' => now()]);
    }
}
