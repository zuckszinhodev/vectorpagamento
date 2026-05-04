<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

class Course extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'id',
        'account_id',
        'product_id',
        'name',
        'slug',
        'custom_domain',
        'description',
        'short_description',
        'thumbnail_url',
        'cover_url',
        'trailer_url',
        'instructor_name',
        'instructor_avatar',
        'instructor_bio',
        'duration_minutes',
        'level',
        'tags',
        'requirements',
        'objectives',
        'sort_order',
        'is_featured',
        'is_published',
        'published_at',
    ];

    protected $casts = [
        'duration_minutes' => 'integer',
        'tags' => 'array',
        'requirements' => 'array',
        'objectives' => 'array',
        'sort_order' => 'integer',
        'is_featured' => 'boolean',
        'is_published' => 'boolean',
        'published_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function modules(): HasMany
    {
        return $this->hasMany(CourseModule::class)->orderBy('sort_order');
    }

    public function lessons(): HasManyThrough
    {
        return $this->hasManyThrough(Lesson::class, CourseModule::class, 'course_id', 'module_id');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }

    /**
     * Get the member area associated with this course's account.
     * Uses shared account_id rather than a direct FK.
     */
    public function memberArea()
    {
        return $this->hasOne(MemberArea::class, 'account_id', 'account_id');
    }

    public function getTotalLessonsAttribute(): int
    {
        return $this->lessons()->count();
    }

    public function getPublishedLessonsAttribute(): int
    {
        return $this->lessons()->where('is_published', true)->count();
    }

    public function calculateDuration(): int
    {
        $totalSeconds = $this->lessons()->sum('duration_seconds');
        return (int) ceil($totalSeconds / 60);
    }

    public function updateDuration(): void
    {
        $this->update(['duration_minutes' => $this->calculateDuration()]);
    }

    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    public function scopeFeatured($query)
    {
        return $query->where('is_featured', true);
    }
}
