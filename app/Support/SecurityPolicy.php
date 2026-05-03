<?php

namespace App\Support;

use App\Models\AppSetting;
use Illuminate\Validation\Rules\Password;

class SecurityPolicy
{
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

    private static function value(string $key): mixed
    {
        $setting = AppSetting::query()->where('key', $key)->first();

        return $setting?->value ?? self::DEFAULTS[$key] ?? null;
    }
}
