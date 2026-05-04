<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Certificate extends Model
{
    use HasUuids;

    protected $fillable = [
        'id',
        'enrollment_id',
        'course_id',
        'member_id',
        'code',
        'name',
        'issued_at',
        'pdf_url',
        'metadata',
    ];

    protected $casts = [
        'issued_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public static function generateCode(): string
    {
        do {
            $code = strtoupper(bin2hex(random_bytes(6)));
        } while (self::where('code', $code)->exists());

        return $code;
    }

    public function getVerificationUrlAttribute(): string
    {
        return config('app.url') . '/certificates/verify/' . $this->code;
    }
}
