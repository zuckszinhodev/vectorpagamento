<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LessonProgress extends Model
{
    use HasUuids;

    protected $table = 'lesson_progress';

    protected $fillable = [
        'id',
        'enrollment_id',
        'lesson_id',
        'watched_seconds',
        'progress_percent',
        'is_completed',
        'completed_at',
        'last_watched_at',
        'notes',
    ];

    protected $casts = [
        'watched_seconds' => 'integer',
        'progress_percent' => 'integer',
        'is_completed' => 'boolean',
        'completed_at' => 'datetime',
        'last_watched_at' => 'datetime',
        'notes' => 'array',
    ];

    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function updateProgress(int $watchedSeconds): void
    {
        $lesson = $this->lesson;
        $duration = $lesson->duration_seconds ?: 1;
        
        $progressPercent = min(100, (int) round(($watchedSeconds / $duration) * 100));
        $isCompleted = $progressPercent >= 90; // Consider completed at 90%

        $updates = [
            'watched_seconds' => max($this->watched_seconds, $watchedSeconds),
            'progress_percent' => max($this->progress_percent, $progressPercent),
            'last_watched_at' => now(),
        ];

        if ($isCompleted && !$this->is_completed) {
            $updates['is_completed'] = true;
            $updates['completed_at'] = now();
        }

        $this->update($updates);

        // Update enrollment progress
        $this->enrollment->updateProgress();
    }

    public function markAsCompleted(): void
    {
        if (!$this->is_completed) {
            $this->update([
                'is_completed' => true,
                'completed_at' => now(),
                'progress_percent' => 100,
            ]);

            $this->enrollment->updateProgress();
        }
    }

    public function addNote(string $note, ?int $timestamp = null): void
    {
        $notes = $this->notes ?? [];
        $notes[] = [
            'content' => $note,
            'timestamp' => $timestamp,
            'created_at' => now()->toISOString(),
        ];
        $this->update(['notes' => $notes]);
    }
}
