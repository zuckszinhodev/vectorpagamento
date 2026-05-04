<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Enrollment extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'id',
        'user_id',
        'course_id',
        'charge_id',
        'status',
        'access_type',
        'enrolled_at',
        'expires_at',
        'last_accessed_at',
        'progress_percent',
        'completed_at',
        'metadata',
    ];

    protected $casts = [
        'enrolled_at' => 'datetime',
        'expires_at' => 'datetime',
        'last_accessed_at' => 'datetime',
        'completed_at' => 'datetime',
        'progress_percent' => 'integer',
        'metadata' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function charge(): BelongsTo
    {
        return $this->belongsTo(Charge::class);
    }

    public function progress(): HasMany
    {
        return $this->hasMany(LessonProgress::class);
    }

    public function certificate(): HasOne
    {
        return $this->hasOne(Certificate::class);
    }

    public function isActive(): bool
    {
        if ($this->status !== 'active') {
            return false;
        }

        if ($this->access_type === 'lifetime') {
            return true;
        }

        if ($this->expires_at && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    public function calculateProgress(): int
    {
        $totalLessons = $this->course->lessons()->where('is_published', true)->count();
        
        if ($totalLessons === 0) {
            return 0;
        }

        $completedLessons = $this->progress()->where('is_completed', true)->count();
        
        return (int) round(($completedLessons / $totalLessons) * 100);
    }

    public function updateProgress(): void
    {
        $progress = $this->calculateProgress();
        $updates = ['progress_percent' => $progress];

        if ($progress >= 100 && !$this->completed_at) {
            $updates['completed_at'] = now();
        }

        $this->update($updates);
    }

    public function getLastWatchedLesson(): ?LessonProgress
    {
        return $this->progress()
            ->whereNotNull('last_watched_at')
            ->orderBy('last_watched_at', 'desc')
            ->first();
    }

    public function getNextLesson(): ?Lesson
    {
        $completedLessonIds = $this->progress()
            ->where('is_completed', true)
            ->pluck('lesson_id');

        return $this->course->lessons()
            ->where('is_published', true)
            ->whereNotIn('id', $completedLessonIds)
            ->orderBy('course_modules.sort_order')
            ->orderBy('lessons.sort_order')
            ->first();
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active')
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }
}
