<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RiskScore extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'charge_id',
        'total_score',
        'score_breakdown',
        'risk_level',
        'recommendation',
        'triggered_rules',
        'device_fingerprint',
        'ip_address',
        'geolocation',
    ];

    protected $casts = [
        'total_score' => 'integer',
        'score_breakdown' => 'array',
        'triggered_rules' => 'array',
        'device_fingerprint' => 'array',
        'geolocation' => 'array',
    ];

    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class);
    }

    public function scopeHighRisk($query)
    {
        return $query->whereIn('risk_level', ['high', 'critical']);
    }

    public function scopeForPeriod($query, string $from, string $to)
    {
        return $query->whereBetween('created_at', [$from, $to]);
    }
}
