<?php

use App\Helpers\SettingsHelper;

if (!function_exists('settings')) {
    /**
     * Get or set application settings.
     *
     * Usage:
     *   settings('key')              // Get value
     *   settings('key', $default)    // Get with default
     *   settings(['key' => $value])  // Set value
     *
     * @param string|array $key
     * @param mixed $default
     * @return mixed
     */
    function settings(string|array $key, mixed $default = null): mixed
    {
        if (is_array($key)) {
            foreach ($key as $k => $v) {
                SettingsHelper::set($k, $v);
            }
            return true;
        }
        
        return SettingsHelper::get($key, $default);
    }
}
