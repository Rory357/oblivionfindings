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

    public function publishEnabled(?int $organizationId = null): bool
    {
        return $this->enabled('publish', $organizationId);
    }

    public function autoScheduleEnabled(?int $organizationId = null): bool
    {
        return $this->enabled('auto_schedule', $organizationId);
    }

    private function enabled(string $feature, ?int $organizationId = null): bool
    {
        $cacheKey = $feature.':'.($organizationId ?? 'global');

        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        $default = (bool) config("features.rostering.{$feature}", false);

        try {
            $orgValue = $organizationId
                ? $this->setting("features.rostering.{$feature}.organization.{$organizationId}")
                : null;
            $globalValue = $this->setting("features.rostering.{$feature}");

            return $this->cache[$cacheKey] = $this->toBool($orgValue ?? $globalValue ?? $default);
        } catch (Throwable) {
            return $this->cache[$cacheKey] = $default;
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
