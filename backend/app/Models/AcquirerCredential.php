<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One encrypted credential field (api_key, secret_key, webhook_secret, ...).
 *
 * cipher_text and fingerprint are hidden from every serialisation path so a
 * forgotten ->toArray() in a controller cannot leak them. The only value ever
 * exposed is mask_hint.
 */
class AcquirerCredential extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'acquirer_config_id',
        'acquirer_key',
        'field_key',
        'cipher_text',
        'cipher_alg',
        'key_version',
        'mask_hint',
        'value_length',
        'fingerprint',
        'rotated_at',
    ];

    protected $hidden = [
        'cipher_text',
        'fingerprint',
    ];

    protected $casts = [
        'key_version' => 'integer',
        'value_length' => 'integer',
        'rotated_at' => 'datetime',
    ];

    public function config(): BelongsTo
    {
        return $this->belongsTo(AcquirerConfig::class, 'acquirer_config_id');
    }

    /**
     * Representation safe to send to the admin panel.
     *
     * @return array<string, mixed>
     */
    public function toMaskedArray(): array
    {
        return [
            'field_key' => $this->field_key,
            'masked' => $this->mask_hint,
            'length' => $this->value_length,
            'key_version' => $this->key_version,
            'configured' => $this->value_length > 0,
            'rotated_at' => $this->rotated_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
