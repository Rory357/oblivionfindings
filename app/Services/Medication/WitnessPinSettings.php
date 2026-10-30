<?php

namespace App\Services\Medication;

use App\Models\AppSetting;

/**
 * Organisation rules for the personal witness PIN (eMAR PIN-1).
 *
 * The defaults are the values Stephan chose on 29 Sep 2026: 5 wrong attempts
 * lock a PIN for 15 minutes, with no forced renewal. They ship as defaults
 * (shown as "Default — not yet reviewed" until someone saves them) so that
 * witnessing keeps working after the cutover from login passwords. Who may
 * reset another person's PIN is the "medications.witness_pin.reset"
 * permission, managed in Settings › Roles, not a value here.
 */
class WitnessPinSettings
{
    public const MAX_ATTEMPTS = 'medications.witness_pin.max_attempts';

    public const LOCKOUT_MINUTES = 'medications.witness_pin.lockout_minutes';

    public const RENEWAL_MONTHS = 'medications.witness_pin.renewal_months';

    /**
     * Whole numbers each rule accepts (P11 Q-F: number inputs). Renewal can
     * also be off. Every value the earlier fixed choices allowed still fits.
     */
    public const RANGES = [
        self::MAX_ATTEMPTS => [3, 10],
        self::LOCKOUT_MINUTES => [5, 60],
        self::RENEWAL_MONTHS => [1, 24],
    ];

    /** Renewal switched off: a PIN stays until its owner changes it. */
    public const RENEWAL_OFF = 'none';

    public const DEFAULTS = [
        self::MAX_ATTEMPTS => '5',
        self::LOCKOUT_MINUTES => '15',
        self::RENEWAL_MONTHS => 'none',
    ];

    /**
     * Forgotten-PIN fallback for ordinary medicines (PIN-2), Staff & PINs ›
     * Witness PINs › Forgotten PIN. Off until a manager switches it on
     * (Stephan, 9 Oct); switching it on loosens a check. It is never available for a
     * controlled or witness-required medicine; that floor is in
     * ForgottenWitnessPinService, not a setting.
     */
    public const FORGOTTEN_FALLBACK = 'medications.witness_pin.forgotten_fallback';

    /** Minutes the named colleague has to confirm before the house lead is told. */
    public const CONFIRM_MINUTES = 'medications.witness_pin.confirm_minutes';

    public const CONFIRM_MINUTES_DEFAULT = '30';

    public const CONFIRM_MINUTES_RANGE = [10, 240];

    public const FORGOTTEN_FALLBACK_DEFAULT = 'no';

    public function forgottenFallbackAllowed(): bool
    {
        $stored = AppSetting::query()->where('key', self::FORGOTTEN_FALLBACK)->value('value');

        return (in_array($stored, ['yes', 'no'], true) ? $stored : self::FORGOTTEN_FALLBACK_DEFAULT) === 'yes';
    }

    public function confirmWithinMinutes(): int
    {
        $stored = AppSetting::query()->where('key', self::CONFIRM_MINUTES)->value('value');
        $stored = is_int($stored) ? (string) $stored : $stored;
        [$min, $max] = self::CONFIRM_MINUTES_RANGE;

        return is_string($stored) && preg_match('/^\d{1,6}$/', $stored) === 1 && (int) $stored >= $min && (int) $stored <= $max
            ? (int) $stored
            : (int) self::CONFIRM_MINUTES_DEFAULT;
    }

    public function maxAttempts(): int
    {
        return (int) $this->value(self::MAX_ATTEMPTS);
    }

    public function lockoutMinutes(): int
    {
        return (int) $this->value(self::LOCKOUT_MINUTES);
    }

    /** Months after which a PIN must be renewed, or null for no renewal. */
    public function renewalMonths(): ?int
    {
        $value = $this->value(self::RENEWAL_MONTHS);

        return $value === self::RENEWAL_OFF ? null : (int) $value;
    }

    /** @return array<string, string> */
    public function all(): array
    {
        $stored = AppSetting::query()
            ->whereIn('key', array_keys(self::DEFAULTS))
            ->pluck('value', 'key');

        $values = [];
        foreach (self::DEFAULTS as $key => $default) {
            $values[$key] = $this->normalise($key, $stored[$key] ?? null);
        }

        return $values;
    }

    /**
     * Which rules someone has deliberately saved. A rule with no stored row
     * still carries its shipped default and shows "Default — not yet reviewed".
     *
     * @return array<string, bool>
     */
    public function reviewed(): array
    {
        $stored = AppSetting::query()
            ->whereIn('key', array_keys(self::DEFAULTS))
            ->pluck('key')
            ->all();

        $reviewed = [];
        foreach (array_keys(self::DEFAULTS) as $key) {
            $reviewed[$key] = in_array($key, $stored, true);
        }

        return $reviewed;
    }

    /** @param  array<string, string>  $values  Validated against RANGES by the caller. */
    public function save(array $values): void
    {
        foreach (self::DEFAULTS as $key => $default) {
            if (! array_key_exists($key, $values)) {
                continue;
            }

            AppSetting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => $this->normalise($key, $values[$key])],
            );
        }
    }

    private function value(string $key): string
    {
        return $this->normalise(
            $key,
            AppSetting::query()->where('key', $key)->value('value'),
        );
    }

    /** A stored value out of range, or not a whole number, reads as the default. */
    private function normalise(string $key, mixed $value): string
    {
        $value = is_int($value) ? (string) $value : $value;
        if ($key === self::RENEWAL_MONTHS && $value === self::RENEWAL_OFF) {
            return $value;
        }
        [$min, $max] = self::RANGES[$key];

        return is_string($value) && preg_match('/^\d{1,6}$/', $value) === 1 && (int) $value >= $min && (int) $value <= $max
            ? (string) (int) $value
            : self::DEFAULTS[$key];
    }
}
