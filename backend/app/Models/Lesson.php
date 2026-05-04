<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lesson extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'id',
        'module_id',
        'name',
        'description',
        'thumbnail_url',
        'content_type',
        'video_url',
        'video_provider',
        'video_id',
        'duration_seconds',
        'content',
        'attachments',
        'resources',
        'sort_order',
        'is_published',
        'is_free',
        'is_downloadable',
    ];

    protected $casts = [
        'duration_seconds' => 'integer',
        'attachments' => 'array',
        'resources' => 'array',
        'sort_order' => 'integer',
        'is_published' => 'boolean',
        'is_free' => 'boolean',
        'is_downloadable' => 'boolean',
    ];

    public function module(): BelongsTo
    {
        return $this->belongsTo(CourseModule::class, 'module_id');
    }

    /**
     * Get the course through the module relationship.
     * Note: This is not a direct BelongsTo - Lesson → Module → Course.
     * Use $lesson->course (accessor) or eager load via module.course.
     */
    public function getCourseAttribute()
    {
        return $this->module?->course;
    }

    public function progress(): HasMany
    {
        return $this->hasMany(LessonProgress::class);
    }

    public function comments(): HasMany
    {
        return $this->hasMany(LessonComment::class)->whereNull('parent_id')->orderBy('created_at', 'desc');
    }

    public function allComments(): HasMany
    {
        return $this->hasMany(LessonComment::class)->orderBy('created_at', 'desc');
    }

    public function getDurationFormattedAttribute(): string
    {
        $minutes = floor($this->duration_seconds / 60);
        $seconds = $this->duration_seconds % 60;
        return sprintf('%d:%02d', $minutes, $seconds);
    }

    public function getEmbedUrlAttribute(): ?string
    {
        if (!$this->video_id || !$this->video_provider) {
            return $this->video_url;
        }

        return match ($this->video_provider) {
            'youtube' => "https://www.youtube.com/embed/{$this->video_id}?rel=0&modestbranding=1",
            'vimeo' => "https://player.vimeo.com/video/{$this->video_id}",
            'bunny' => $this->video_url,
            default => $this->video_url,
        };
    }

    public function scopePublished($query)
    {
        return $query->where('is_published', true);
    }

    public function scopeFree($query)
    {
        return $query->where('is_free', true);
    }

    /**
     * Extract video ID from URL based on provider
     */
    public static function extractVideoId(string $url, string $provider): ?string
    {
        return match ($provider) {
            'youtube' => self::extractYouTubeId($url),
            'vimeo' => self::extractVimeoId($url),
            default => null,
        };
    }

    private static function extractYouTubeId(string $url): ?string
    {
        $patterns = [
            '/youtube\.com\/watch\?v=([^&]+)/',
            '/youtube\.com\/embed\/([^?]+)/',
            '/youtu\.be\/([^?]+)/',
            '/youtube\.com\/v\/([^?]+)/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $matches)) {
                return $matches[1];
            }
        }

        return null;
    }

    private static function extractVimeoId(string $url): ?string
    {
        if (preg_match('/vimeo\.com\/(\d+)/', $url, $matches)) {
            return $matches[1];
        }
        return null;
    }
}
