<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class KycSubmission extends Model
{
    use HasUuids;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'user_id',
        'status',
        'full_name',
        'birth_date',
        'document_number',
        'phone',
        'address_street',
        'address_number',
        'address_complement',
        'address_neighborhood',
        'address_city',
        'address_state',
        'address_zip_code',
        'company_name',
        'company_document',
        'account_type',
        'reviewed_by',
        'reviewed_at',
        'rejection_reason',
        'admin_notes',
    ];

    protected $casts = [
        'birth_date' => 'date',
        'reviewed_at' => 'datetime',
    ];

    protected $hidden = [
        'admin_notes',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function documents()
    {
        return $this->hasMany(KycDocument::class, 'user_id', 'user_id');
    }

    public function activityLogs()
    {
        return $this->hasMany(KycActivityLog::class, 'submission_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isUnderReview(): bool
    {
        return $this->status === 'under_review';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }
}
