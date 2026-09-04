<?php

namespace App\Support;

use App\Models\AppSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rules\Password;

class SecurityPolicy
{
    public const CACHE_KEY = 'security-policy:v1';

    private const DEFAULTS = [
        'password_min_length' => 12,
        'password_require_uppercase' => true,
        'password_require_numbers' => true,
        'password_require_symbols' => true,
        'session_timeout_minutes' => 0,
        'max_login_attempts' => 5,
        'lockout_duration_minutes' => 1,
        'force_2fa' => false,
    ];

    public static function passwordRule(): Password
    {
        $rule = Password::min(self::integer('password_min_length'));

        if (self::boolean('password_require_uppercase')) {
            $rule->mixedCase();
        }

        if (self::boolean('password_require_numbers')) {
            $rule->numbers();
        }

        if (self::boolean('password_require_symbols')) {
            $rule->symbols();
        }

        return $rule;
    }

    public static function forceTwoFactor(): bool
    {
        return self::boolean('force_2fa');
    }

    public static function sessionTimeoutMinutes(): int
    {
        return self::integer('session_timeout_minutes');
    }

    public static function maxLoginAttempts(): int
    {
        return max(1, self::integer('max_login_attempts'));
    }

    public static function lockoutDurationMinutes(): int
    {
        return max(1, self::integer('lockout_duration_minutes'));
    }

    private static function integer(string $key): int
    {
        return (int) self::value($key);
    }

    private static function boolean(string $key): bool
    {
        $value = self::value($key);

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * The setting keys this policy owns (AppSetting busts the cache when
     * one of them is written).
     *
     * @return string[]
     */
    public static function keys(): array
    {
        return array_keys(self::DEFAULTS);
    }

    public static function flushCache(): void
    {
        self::$settings = null;
        Cache::forget(self::CACHE_KEY);
    }

    /** @var array<string, mixed>|null */
    private static ?array $settings = null;

    private static function value(string $key): mixed
    {
        // Two global middleware (session timeout, forced 2FA) consult this
        // on every request — one cached read for all keys instead of one
        // uncached query per key per request.
        self::$settings ??= Cache::rememberForever(self::CACHE_KEY, fn (): array => AppSetting::query()
            ->whereIn('key', self::keys())
            ->pluck('value', 'key')
            ->all());

        return self::$settings[$key] ?? self::DEFAULTS[$key] ?? null;
    }
}
