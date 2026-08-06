<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Affiliate extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'account_id',
        'user_id',
        'product_id',
        'code',
        'token',
        'name',
        'email',
        'phone',
        'document',
        'commission_type',
        'commission_percent',
        'commission_value',
        'commission_fixed',
        'status',
        'total_sales',
        'total_commission',
        'total_orders',
        'metadata',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($affiliate) {
            if (empty($affiliate->token)) {
                $affiliate->token = Str::random(64);
            }
        });
    }

    protected $casts = [
        'commission_percent' => 'decimal:2',
        'commission_value' => 'decimal:2',
        'commission_fixed' => 'integer',
        'total_sales' => 'integer',
        'total_commission' => 'integer',
        'total_orders' => 'integer',
        'metadata' => 'array',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function charges(): HasMany
    {
        return $this->hasMany(Charge::class);
    }

    /**
     * Conta do próprio afiliado — para onde vai a comissão dele.
     *
     * Cuidado: `account_id` é a conta do PRODUTOR (dono do programa de
     * afiliados), não a do afiliado. Creditar nela devolveria o dinheiro para
     * quem vendeu. A conta do afiliado vem do metadata (gravado no convite) ou,
     * na falta dele, do usuário vinculado.
     */
    public function payoutAccountId(): ?string
    {
        $fromMetadata = $this->metadata['affiliate_account_id'] ?? null;
        if (is_string($fromMetadata) && $fromMetadata !== '') {
            return $fromMetadata;
        }

        return $this->user_id
            ? User::whereKey($this->user_id)->value('account_id')
            : null;
    }
}
