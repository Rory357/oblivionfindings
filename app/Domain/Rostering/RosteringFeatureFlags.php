<?php

namespace App\Domain\Rostering;

use App\Models\AppSetting;
use Throwable;

class RosteringFeatureFlags
{
    /**
     * @var array<string, bool>
     */
    private array $cache = [];

    public function publishEnabled(): bool
    {
        return $this->enabled('publish');
    }

    public function autoScheduleEnabled(): bool
    {
        return $this->enabled('auto_schedule');
    }

    private function enabled(string $feature): bool
    {
        $settingKey = "features.rostering.{$feature}";

        if (array_key_exists($settingKey, $this->cache)) {
            return $this->cache[$settingKey];
        }

        $default = (bool) config("features.rostering.{$feature}", false);

        try {
            $applicationValue = $this->setting($settingKey);

            return $this->cache[$settingKey] = $this->toBool($applicationValue ?? $default);
        } catch (Throwable) {
            return $this->cache[$settingKey] = $default;
        }
    }

    private function setting(string $key): mixed
    {
        return AppSetting::query()->where('key', $key)->first()?->value;
    }

    private function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
        }

        return (bool) $value;
    }
}
