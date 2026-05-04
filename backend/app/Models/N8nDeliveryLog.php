<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class N8nDeliveryLog extends Model
{
    use HasUuids;

    protected $table = 'n8n_delivery_logs';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'integration_id',
        'event',
        // Legacy schema fields
        'payload',
        'status_code',
        'response',
        'duration_ms',
        'status',
        // Current schema fields
        'http_status',
        'attempt',
        'max_attempts',
        'request_payload',
        'request_headers',
        'response_body',
        'response_time_ms',
        'error_message',
        'next_retry_at',
        'delivered_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'request_payload' => 'array',
        'request_headers' => 'array',
        'status_code' => 'integer',
        'http_status' => 'integer',
        'attempt' => 'integer',
        'max_attempts' => 'integer',
        'duration_ms' => 'integer',
        'response_time_ms' => 'integer',
        'next_retry_at' => 'datetime',
        'delivered_at' => 'datetime',
    ];

    private static array $columnExists = [];

    private function hasColumn(string $column): bool
    {
        $key = $this->getTable() . ':' . $column;
        if (!array_key_exists($key, self::$columnExists)) {
            self::$columnExists[$key] = Schema::hasColumn($this->getTable(), $column);
        }

        return self::$columnExists[$key];
    }

    const STATUS_PENDING = 'pending';
    const STATUS_SUCCESS = 'success';
    const STATUS_FAILED = 'failed';
    const STATUS_RETRYING = 'retrying';

    public function integration()
    {
        return $this->belongsTo(N8nIntegration::class, 'integration_id');
    }

    public function scopeSuccessful($query)
    {
        return $query->where('status', self::STATUS_SUCCESS);
    }

    public function scopeFailed($query)
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    public function scopeForIntegration($query, string $integrationId)
    {
        return $query->where('integration_id', $integrationId);
    }

    public function markSuccess(int $httpStatus, ?string $responseBody, int $responseTimeMs): void
    {
        $update = [
            'status' => self::STATUS_SUCCESS,
            'delivered_at' => now(),
        ];

        if ($this->hasColumn('http_status')) {
            $update['http_status'] = $httpStatus;
        }
        if ($this->hasColumn('status_code')) {
            $update['status_code'] = $httpStatus;
        }

        $truncatedBody = $responseBody ? substr($responseBody, 0, 5000) : null;
        if ($this->hasColumn('response_body')) {
            $update['response_body'] = $truncatedBody;
        }
        if ($this->hasColumn('response')) {
            $update['response'] = $truncatedBody;
        }

        if ($this->hasColumn('response_time_ms')) {
            $update['response_time_ms'] = $responseTimeMs;
        }
        if ($this->hasColumn('duration_ms')) {
            $update['duration_ms'] = $responseTimeMs;
        }

        $this->update($update);
    }

    public function markFailed(string $error, ?int $httpStatus = null, ?string $responseBody = null): void
    {
        $canRetry = $this->attempt < $this->max_attempts;

        $update = [
            'status' => $canRetry ? self::STATUS_RETRYING : self::STATUS_FAILED,
            'error_message' => $error,
            'next_retry_at' => $canRetry ? now()->addSeconds(30 * pow(2, $this->attempt)) : null,
        ];

        if ($this->hasColumn('http_status')) {
            $update['http_status'] = $httpStatus;
        }
        if ($this->hasColumn('status_code')) {
            $update['status_code'] = $httpStatus;
        }

        $truncatedBody = $responseBody ? substr($responseBody, 0, 5000) : null;
        if ($this->hasColumn('response_body')) {
            $update['response_body'] = $truncatedBody;
        }
        if ($this->hasColumn('response')) {
            $update['response'] = $truncatedBody;
        }

        $this->update($update);
    }
}
