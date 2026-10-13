<?php

namespace App\Support;

use App\Models\AppSetting;

/**
 * The organisation name people see in emails and on sign-in pages. A custom
 * Settings › Branding name wins; the stock install reads "Oblivion Care"
 * (mirrors resolveWordmarkName() in event-horizon-wordmark.tsx).
 */
final class BrandName
{
    private const STOCK = ['oblivion findings', 'oblivionfindings', 'laravel', ''];

    public static function display(): string
    {
        $custom = self::custom();

        return $custom ?? 'Oblivion Care';
    }

    /** The organisation's own name, or null when the stock product name applies. */
    public static function custom(): ?string
    {
        $name = AppSetting::query()->where('key', 'branding.name')->value('value');
        $name = is_string($name) ? trim($name) : trim((string) config('app.name'));

        return in_array(strtolower($name), self::STOCK, true) ? null : $name;
    }
}
