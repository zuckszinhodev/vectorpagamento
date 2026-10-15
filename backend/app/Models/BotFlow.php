<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class BotFlow extends Model
{
    use HasUuids, SoftDeletes;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'account_id',
        'name',
        'mode',
        'is_active',
        'flow_data',
        'ai_config',
        'trigger_keywords',
        'only_business_hours',
        'business_hours',
        'conversations_count',
        'messages_count',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'only_business_hours' => 'boolean',
        'flow_data' => 'array',
        'ai_config' => 'array',
        'trigger_keywords' => 'array',
        'business_hours' => 'array',
        'conversations_count' => 'integer',
        'messages_count' => 'integer',
    ];

    const MODE_FLOW = 'flow';
    const MODE_AI = 'ai';

    /** Acoes que a IA so executa se o dono ligar explicitamente. */
    const AI_ACTIONS = [
        'lookup_order',
        'resend_pix',
        'resend_access',
        'create_payment_link',
    ];

    /** Acoes liberadas por padrao ao criar um bot de IA (somente leitura). */
    const AI_DEFAULT_ACTIONS = ['lookup_order'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(BotConversation::class, 'flow_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForAccount($query, string $accountId)
    {
        return $query->where('account_id', $accountId);
    }

    public function isAi(): bool
    {
        return $this->mode === self::MODE_AI;
    }

    /**
     * Uma acao da IA so vale se estiver na allowlist salva na configuracao.
     * Default deliberadamente restrito: sem config, so leitura.
     */
    public function aiActionAllowed(string $action): bool
    {
        $allowed = $this->ai_config['allowed_actions'] ?? self::AI_DEFAULT_ACTIONS;

        return in_array($action, is_array($allowed) ? $allowed : [], true);
    }

    /** Nos do fluxo indexados por id, para navegacao O(1) no motor. */
    public function nodesById(): array
    {
        $nodes = $this->flow_data['nodes'] ?? [];
        $map = [];
        foreach ($nodes as $node) {
            if (!empty($node['id'])) {
                $map[$node['id']] = $node;
            }
        }

        return $map;
    }

    public function startNodeId(): ?string
    {
        $nodes = $this->flow_data['nodes'] ?? [];

        return $this->flow_data['start_node_id']
            ?? ($nodes[0]['id'] ?? null);
    }

    /**
     * O bot assume a conversa se nao houver palavra-chave configurada
     * (atende tudo) ou se a mensagem contiver alguma delas.
     */
    public function matchesTrigger(string $message): bool
    {
        $keywords = array_filter((array) ($this->trigger_keywords ?? []));
        if (empty($keywords)) {
            return true;
        }

        $haystack = mb_strtolower($message);
        foreach ($keywords as $keyword) {
            $needle = mb_strtolower(trim((string) $keyword));
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    public function withinBusinessHours(?\DateTimeInterface $now = null): bool
    {
        if (!$this->only_business_hours) {
            return true;
        }

        $now = $now ? \Carbon\Carbon::instance(\Carbon\Carbon::parse($now)) : now();
        $hours = $this->business_hours ?? [];
        $today = $hours[strtolower($now->format('D'))] ?? null;

        if (!$today || empty($today['enabled'])) {
            return false;
        }

        $start = $today['start'] ?? '00:00';
        $end = $today['end'] ?? '23:59';
        $current = $now->format('H:i');

        return $current >= $start && $current <= $end;
    }
}
