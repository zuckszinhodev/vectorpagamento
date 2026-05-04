<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Webhook extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'id',
        'account_id',
        'url',
        'events',
        'secret',
        'is_active',
    ];

    protected $casts = [
        'events' => 'array',
        'is_active' => 'boolean',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Check if this webhook is subscribed to a given event
     */
    public function isSubscribedTo(string $event): bool
    {
        $events = $this->events ?? [];
        return in_array($event, $events) || in_array('*', $events);
    }
}
