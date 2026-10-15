<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BotConversation extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'account_id',
        'flow_id',
        'contact_phone',
        'contact_name',
        'current_node_id',
        'context',
        'status',
        'message_count',
        'last_message_at',
        'resume_node_id',
        'resume_at',
        'resume_token',
    ];

    protected $casts = [
        'context' => 'array',
        'message_count' => 'integer',
        'last_message_at' => 'datetime',
        'resume_at' => 'datetime',
    ];

    const STATUS_ACTIVE = 'active';
    const STATUS_HANDOFF = 'handoff';
    const STATUS_ENDED = 'ended';

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(BotFlow::class, 'flow_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(BotConversationMessage::class, 'conversation_id');
    }

    public function scopeForAccount($query, string $accountId)
    {
        return $query->where('account_id', $accountId);
    }

    /** Conversa entregue a um humano nao volta para o bot sozinha. */
    public function isBotHandling(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** Descarta a continuacao agendada; o job pendente vira no-op pelo token. */
    public function cancelScheduledResume(): void
    {
        $this->resume_node_id = null;
        $this->resume_at = null;
        $this->resume_token = null;
    }

    public function putContext(string $key, mixed $value): void
    {
        $context = $this->context ?? [];
        $context[$key] = $value;
        $this->context = $context;
    }

    /** Ultimas trocas em formato de chat, para dar memoria a IA. */
    public function recentHistory(int $limit = 10): array
    {
        return $this->messages()
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get()
            ->reverse()
            ->map(fn (BotConversationMessage $m) => [
                'role' => $m->direction === 'in' ? 'user' : 'assistant',
                'content' => $m->body,
            ])
            ->values()
            ->all();
    }
}
