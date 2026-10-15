<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class BuyerSupportTicket extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'protocol',
        'name',
        'email',
        'subject',
        'category',
        'message',
        'order_reference',
        'attachments',
        'status',
        'admin_reply',
        'replied_by',
        'replied_at',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'replied_at' => 'datetime',
        'attachments' => 'array',
    ];

    const STATUS_OPEN = 'open';
    const STATUS_PENDING = 'pending';
    const STATUS_RESOLVED = 'resolved';
    const STATUS_CLOSED = 'closed';

    public const CATEGORIES = [
        'pagamento' => 'Cobrança e pagamento',
        'entrega' => 'Produto e entrega',
        'reembolso' => 'Reembolso',
        'seguranca' => 'Segurança / não reconheço a compra',
        'outro' => 'Outro assunto',
    ];

    /**
     * Protocolo legivel por telefone, sem caracteres ambiguos (O/0, I/1).
     * Formato: AJ-XXXXXX.
     */
    public static function generateProtocol(): string
    {
        do {
            $code = 'AJ-' . strtoupper(Str::password(6, false, true, false, false));
            $code = str_replace(['O', 'I', '0', '1'], ['A', 'B', 'C', 'D'], $code);
        } while (self::where('protocol', $code)->exists());

        return $code;
    }

    public function scopeStatus($query, ?string $status)
    {
        return $status ? $query->where('status', $status) : $query;
    }
}
