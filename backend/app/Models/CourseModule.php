<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CourseModule extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'id',
        'course_id',
        'name',
        'description',
        'thumbnail_url',
        'sort_order',
        'is_published',
        'is_free',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_published' => 'boolean',
        'is_free' => 'boolean',
    ];

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function lessons(): HasMany
    {
        return $this->hasMany(Lesson::class, 'module_id')->orderBy('sort_order');
    }

    public function getTotalLessonsAttribute(): int
    {
        return $this->lessons()->count();
    }

    public function getDurationMinutesAttribute(): int
    {
        $totalSeconds = $this->lessons()->sum('duration_seconds');
        return (int) ceil($totalSeconds / 60);
    }

    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }
}
