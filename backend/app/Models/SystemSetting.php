<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class SystemSetting extends Model
{
    protected $fillable = [
        'key',
        'value',
        'type',
        'group',
        'description',
    ];

    /**
     * Get a setting value by key
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = Cache::remember("system_setting_{$key}", 3600, function () use ($key) {
            return static::where('key', $key)->first();
        });

        if (!$setting) {
            return $default;
        }

        return static::castValue($setting->value, $setting->type);
    }

    /**
     * Set a setting value
     */
    public static function set(string $key, mixed $value, ?string $type = null): bool
    {
        $setting = static::where('key', $key)->first();

        if (!$setting) {
            return false;
        }

        // Convert value to string for storage
        $stringValue = static::prepareValue($value, $type ?? $setting->type);

        $setting->update(['value' => $stringValue]);
        
        Cache::forget("system_setting_{$key}");

        return true;
    }

    /**
     * Get all settings by group
     */
    public static function getByGroup(string $group): array
    {
        $settings = static::where('group', $group)->get();
        
        $result = [];
        foreach ($settings as $setting) {
            $result[$setting->key] = static::castValue($setting->value, $setting->type);
        }

        return $result;
    }

    /**
     * Update multiple settings at once
     */
    public static function setMany(array $settings): void
    {
        foreach ($settings as $key => $value) {
            static::set($key, $value);
        }
    }

    /**
     * Cast value based on type
     */
    protected static function castValue(?string $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'integer' => (int) $value,
            'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode($value, true),
            default => $value,
        };
    }

    /**
     * Prepare value for storage
     */
    protected static function prepareValue(mixed $value, string $type): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'boolean' => $value ? 'true' : 'false',
            'json' => json_encode($value),
            default => (string) $value,
        };
    }
}
