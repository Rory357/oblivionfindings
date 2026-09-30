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

    public const OPTIONS = [
        self::MAX_ATTEMPTS => ['3', '5', '10'],
        self::LOCKOUT_MINUTES => ['5', '15', '30', '60'],
        self::RENEWAL_MONTHS => ['none', '6', '12'],
    ];

    public const DEFAULTS = [
        self::MAX_ATTEMPTS => '5',
        self::LOCKOUT_MINUTES => '15',
        self::RENEWAL_MONTHS => 'none',
    ];

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

        return $value === 'none' ? null : (int) $value;
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

    /** @param  array<string, string>  $values  Validated against OPTIONS by the caller. */
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

    private function normalise(string $key, mixed $value): string
    {
        return is_string($value) && in_array($value, self::OPTIONS[$key], true)
            ? $value
            : self::DEFAULTS[$key];
    }
}
