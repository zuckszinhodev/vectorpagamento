<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MessageTemplate extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'account_id',
        'provider_id',
        'channel',
        'name',
        'trigger_event',
        'message_body',
        'is_active',
        'times_sent',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'times_sent' => 'integer',
    ];

    // ---- trigger events ----

    const TRIGGER_PAYMENT_APPROVED = 'payment.approved';
    const TRIGGER_PAYMENT_REFUSED = 'payment.refused';
    const TRIGGER_PAYMENT_EXPIRED = 'payment.expired';
    const TRIGGER_CHARGE_CREATED = 'charge.created';
    const TRIGGER_PIX_GENERATED = 'pix.generated';
    const TRIGGER_BOLETO_GENERATED = 'boleto.generated';
    const TRIGGER_REFUND_COMPLETED = 'refund.completed';
    const TRIGGER_CHARGEBACK_CREATED = 'chargeback.created';
    const TRIGGER_CHECKOUT_ABANDONED = 'checkout.abandoned';
    const TRIGGER_PIX_EXPIRED = 'pix.expired';
    const TRIGGER_BOLETO_EXPIRED = 'boleto.expired';

    const TRIGGERS = [
        self::TRIGGER_PAYMENT_APPROVED,
        self::TRIGGER_PAYMENT_REFUSED,
        self::TRIGGER_PAYMENT_EXPIRED,
        self::TRIGGER_CHARGE_CREATED,
        self::TRIGGER_PIX_GENERATED,
        self::TRIGGER_BOLETO_GENERATED,
        self::TRIGGER_REFUND_COMPLETED,
        self::TRIGGER_CHARGEBACK_CREATED,
        self::TRIGGER_CHECKOUT_ABANDONED,
        self::TRIGGER_PIX_EXPIRED,
        self::TRIGGER_BOLETO_EXPIRED,
    ];

    // ---- available variables ----

    const VARIABLES = [
        '{{nome_cliente}}' => 'Nome do cliente',
        '{{email_cliente}}' => 'E-mail do cliente',
        '{{telefone_cliente}}' => 'Telefone do cliente',
        '{{valor}}' => 'Valor da cobrança (R$)',
        '{{produto}}' => 'Nome do produto',
        '{{status}}' => 'Status do pagamento',
        '{{link_pagamento}}' => 'Link de pagamento',
        '{{codigo_pix}}' => 'Código PIX copia e cola',
        '{{data_vencimento}}' => 'Data de vencimento',
        '{{id_cobranca}}' => 'ID da cobrança',
    ];

    // ---- relationships ----

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(MessagingProvider::class, 'provider_id');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(MessageLog::class, 'template_id');
    }

    // ---- scopes ----

    public function scopeActive($query)
    {
        return $query->whereRaw('"is_active" = true');
    }

    public function scopeForAccount($query, string $accountId)
    {
        return $query->where('account_id', $accountId);
    }

    public function scopeForEvent($query, string $event)
    {
        return $query->where('trigger_event', $event);
    }

    // ---- helpers ----

    public function renderMessage(array $variables): string
    {
        $body = $this->message_body;
        foreach ($variables as $key => $value) {
            $body = str_replace($key, (string) $value, $body);
        }
        return $body;
    }

    public function incrementSent(): void
    {
        $this->increment('times_sent');
    }
}
