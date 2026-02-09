<?php

namespace App\Helpers;

use App\Models\AppSetting;

class SettingsHelper
{
    /**
     * Get a setting value by key.
     *
     * @param string $key The setting key
     * @param mixed $default Default value if not found
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $setting = AppSetting::query()->where('key', $key)->first();
        
        if ($setting === null) {
            return $default;
        }
        
        return $setting->value ?? $default;
    }
    
    /**
     * Set a setting value.
     *
     * @param string $key The setting key
     * @param mixed $value The value to store
     * @return void
     */
    public static function set(string $key, mixed $value): void
    {
        AppSetting::updateOrCreate(
            ['key' => $key],
            ['value' => $value]
        );
    }
    
    /**
     * Check if a setting exists.
     *
     * @param string $key The setting key
     * @return bool
     */
    public static function has(string $key): bool
    {
        return AppSetting::query()->where('key', $key)->exists();
    }
    
    /**
     * Delete a setting.
     *
     * @param string $key The setting key
     * @return bool
     */
    public static function delete(string $key): bool
    {
        return AppSetting::query()->where('key', $key)->delete() > 0;
    }
    
    /**
     * Get all settings with a given prefix.
     *
     * @param string $prefix The key prefix
     * @return array
     */
    public static function getByPrefix(string $prefix): array
    {
        return AppSetting::query()
            ->where('key', 'like', $prefix . '%')
            ->pluck('value', 'key')
            ->toArray();
    }
}
