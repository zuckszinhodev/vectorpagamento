<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Tymon\JWTAuth\Contracts\JWTSubject;

class Member extends Authenticatable implements JWTSubject
{
    use HasUuids, SoftDeletes, Notifiable;

    protected $fillable = [
        'id',
        'account_id',
        'user_id',
        'name',
        'email',
        'password',
        'avatar_url',
        'phone',
        'status',
        'email_verified_at',
        'last_login_at',
        'metadata',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function getJWTIdentifier()
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims()
    {
        return [
            'type' => 'member',
            'account_id' => $this->account_id,
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class, 'user_id', 'user_id');
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(LessonComment::class);
    }

    public function memberArea(): BelongsTo
    {
        return $this->belongsTo(MemberArea::class, 'account_id', 'account_id');
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function getActiveEnrollments()
    {
        return $this->enrollments()->active()->with('course')->get();
    }

    public function hasAccessToCourse(string $courseId): bool
    {
        return $this->enrollments()
            ->where('course_id', $courseId)
            ->active()
            ->exists();
    }

    public function getContinueWatching(int $limit = 5)
    {
        return LessonProgress::whereHas('enrollment', function ($q) {
                $q->where('user_id', $this->user_id)
                    ->where('status', 'active');
            })
            ->where('is_completed', false)
            ->where('progress_percent', '>', 0)
            ->orderBy('last_watched_at', 'desc')
            ->limit($limit)
            ->with(['lesson.module.course', 'enrollment'])
            ->get();
    }
}
