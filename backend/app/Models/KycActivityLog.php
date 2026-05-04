<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class KycActivityLog extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    public const ACTION_SUBMISSION_CREATED = 'submission_created';
    public const ACTION_DOCUMENT_UPLOADED = 'document_uploaded';
    public const ACTION_DOCUMENT_DELETED = 'document_deleted';
    public const ACTION_SUBMITTED_FOR_REVIEW = 'submitted_for_review';
    public const ACTION_APPROVED = 'approved';
    public const ACTION_REJECTED = 'rejected';
    public const ACTION_RESUBMISSION_REQUESTED = 'resubmission_requested';

    protected $fillable = [
        'id',
        'user_id',
        'submission_id',
        'action',
        'metadata',
        'performed_by',
        'ip_address',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function submission()
    {
        return $this->belongsTo(KycSubmission::class, 'submission_id');
    }

    public function performer()
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    public static function log(
        string $userId,
        string $action,
        ?string $submissionId = null,
        ?array $metadata = null,
        ?string $performedBy = null,
        ?string $ipAddress = null
    ): self {
        return self::create([
            'user_id' => $userId,
            'submission_id' => $submissionId,
            'action' => $action,
            'metadata' => $metadata,
            'performed_by' => $performedBy,
            'ip_address' => $ipAddress,
        ]);
    }
}
