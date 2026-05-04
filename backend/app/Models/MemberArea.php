<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MemberArea extends Model
{
    use HasUuids, SoftDeletes;

    protected $fillable = [
        'id',
        'account_id',
        'name',
        'description',
        'logo_url',
        'favicon_url',
        'primary_color',
        'secondary_color',
        'accent_color',
        'background_color',
        'text_color',
        'font_family',
        'theme_mode',
        'layout_style',
        'banner_url',
        'welcome_message',
        'welcome_video_url',
        'subdomain',
        'custom_domain',
        'domain_verified',
        'ssl_enabled',
        'featured_courses',
        'home_sections',
        'custom_css',
        'settings',
        'is_active',
    ];

    protected $casts = [
        'domain_verified' => 'boolean',
        'ssl_enabled' => 'boolean',
        'featured_courses' => 'array',
        'home_sections' => 'array',
        'custom_css' => 'array',
        'settings' => 'array',
        'is_active' => 'boolean',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function courses(): HasMany
    {
        return $this->hasMany(Course::class, 'account_id', 'account_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(Member::class, 'account_id', 'account_id');
    }

    public function getFullDomainAttribute(): string
    {
        if ($this->custom_domain && $this->domain_verified) {
            return ($this->ssl_enabled ? 'https://' : 'http://') . $this->custom_domain;
        }
        
        if ($this->subdomain) {
            return 'https://' . $this->subdomain . '.' . config('app.member_area_domain', 'members.wazerpay.com');
        }
        
        return '';
    }
}
