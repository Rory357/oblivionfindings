<?php

namespace App\Services\Medication\Settings;

use App\Models\AppSetting;

/**
 * Whole-number Medication Settings read from `app_settings` (eMAR P11): the
 * class lists each key's range (RANGES) and its shipped default in config
 * (CONFIG, [config key, fallback]). A value nobody saved, or one outside its
 * range, reads as the default.
 *
 * Read once per request: the class is #[Scoped] and reads all its keys in one
 * query. AppSetting's saved/deleted events call settingChanged(), so a save of
 * one of its keys makes the request's instance re-read.
 */
trait ReadsWholeNumberSettings
{
    /** @var array<string, mixed>|null every value as stored, once read */
    private ?array $stored = null;

    /** The shipped default for a value, as stored. */
    public static function default(string $key): string
    {
        [$configKey, $fallback] = static::CONFIG[$key];

        return (string) (int) config($configKey, $fallback);
    }

    /** Forget the values read, so the next read sees the latest saved ones. */
    public function forget(): void
    {
        $this->stored = null;
    }

    /** An app_settings row changed: one of these keys makes this request's reader re-read. */
    public static function settingChanged(?string $key): void
    {
        if ($key !== null && in_array($key, static::storedKeys(), true)) {
            app(static::class)->forget();
        }
    }

    /**
     * Every key this reader reads in its one query: the whole numbers, plus
     * any other values the class lists.
     *
     * @return list<string>
     */
    protected static function storedKeys(): array
    {
        return array_keys(static::RANGES);
    }

    /** A key's value as stored, or null when nobody saved it. */
    private function stored(string $key): mixed
    {
        $this->stored ??= AppSetting::query()
            ->whereIn('key', static::storedKeys())
            ->pluck('value', 'key')
            ->all();

        return $this->stored[$key] ?? null;
    }

    /** A stored value out of range, or not a whole number, reads as the default. */
    private function value(string $key): int
    {
        $stored = $this->stored($key);
        [$min, $max] = static::RANGES[$key];
        $stored = is_int($stored) ? (string) $stored : $stored;

        return is_string($stored) && preg_match('/^\d{1,6}$/', $stored) === 1 && (int) $stored >= $min && (int) $stored <= $max
            ? (int) $stored
            : (int) static::default($key);
    }
}
