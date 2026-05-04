<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Security Threat Model
 * 
 * Tracks detected security threats like fraud attempts, brute force, etc.
 */
class SecurityThreat extends Model
{
    use HasFactory, HasUuids;

    protected $fillable = [
        'type',
        'severity',
        'ip_address',
        'user_agent',
        'email',
        'description',
        'blocked',
        'metadata',
    ];

    protected $casts = [
        'blocked' => 'boolean',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Threat types
     */
    public const TYPE_FRAUD_ATTEMPT = 'fraud_attempt';
    public const TYPE_BRUTE_FORCE = 'brute_force';
    public const TYPE_SUSPICIOUS_ACTIVITY = 'suspicious_activity';
    public const TYPE_BOT_DETECTED = 'bot_detected';
    public const TYPE_CARD_TESTING = 'card_testing';

    /**
     * Severity levels
     */
    public const SEVERITY_LOW = 'low';
    public const SEVERITY_MEDIUM = 'medium';
    public const SEVERITY_HIGH = 'high';
    public const SEVERITY_CRITICAL = 'critical';

    /**
     * Create a new threat record
     */
    public static function record(
        string $type,
        string $severity,
        string $ipAddress,
        string $description,
        ?string $email = null,
        ?string $userAgent = null,
        ?array $metadata = null
    ): self {
        return self::create([
            'type' => $type,
            'severity' => $severity,
            'ip_address' => $ipAddress,
            'description' => $description,
            'email' => $email,
            'user_agent' => $userAgent,
            'metadata' => $metadata,
            'blocked' => false,
        ]);
    }
}
