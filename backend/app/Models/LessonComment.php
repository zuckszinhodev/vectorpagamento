<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LessonComment extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'id',
        'lesson_id',
        'member_id',
        'parent_id',
        'content',
        'is_approved',
        'is_pinned',
    ];

    protected $casts = [
        'is_approved' => 'boolean',
        'is_pinned' => 'boolean',
    ];

    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(LessonComment::class, 'parent_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(LessonComment::class, 'parent_id')->orderBy('created_at');
    }

    public function scopeApproved($query)
    {
        return $query->where('is_approved', true);
    }

    public function scopePinned($query)
    {
        return $query->where('is_pinned', true);
    }

    public function scopeRootComments($query)
    {
        return $query->whereNull('parent_id');
    }
}
