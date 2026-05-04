<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class AdminActionApproval extends Model
{
    use HasUuids;

    protected $table = 'admin_action_approvals';
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'account_id',
        'requester_user_id',
        'reviewer_user_id',
        'action_key',
        'resource_type',
        'resource_id',
        'status',
        'payload',
        'request_reason',
        'review_reason',
        'requested_at',
        'reviewed_at',
        'expires_at',
        'consumed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'requested_at' => 'datetime',
        'reviewed_at' => 'datetime',
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];
}
