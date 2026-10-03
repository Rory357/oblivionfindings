<?php

namespace App\Services\Medication\Settings;

/**
 * A wall-clock time in Pacific/Auckland (P11 v5 quiet hours): "21:00", or
 * "off" when it isn't set. Shown as "9:00 pm". Never a loosening: quiet hours
 * only hold email and push, never the bell or Follow up alerts.
 */
final class TimeCodec implements MedicationSettingCodec
{
    public const OFF = 'off';

    /** `$prefix` comes before a time in the words ("From 9:00 pm"). */
    public function __construct(
        private readonly string $offLabel = 'Off',
        private readonly string $prefix = '',
    ) {}

    public function kind(): string
    {
        return 'time';
    }

    public function accepts(string $value): bool
    {
        return $value === self::OFF || preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1;
    }

    public function normalise(mixed $value, string $default): string
    {
        return is_string($value) && $this->accepts($value) ? $value : $default;
    }

    public function format(string $value): string
    {
        return $value === self::OFF || ! $this->accepts($value)
            ? $this->offLabel
            : trim($this->prefix.' '.self::clock($value));
    }

    public function loosens(string $from, string $to): bool
    {
        return false;
    }

    public function invalidMessage(string $label): string
    {
        return 'Choose a time for “'.$label.'”.';
    }

    public function toClient(): array
    {
        return ['off_label' => $this->offLabel, 'time_prefix' => $this->prefix];
    }

    /** "21:00" → "9:00 pm". */
    public static function clock(string $time): string
    {
        [$h, $m] = array_map('intval', explode(':', $time));

        return (($h + 11) % 12 + 1).':'.str_pad((string) $m, 2, '0', STR_PAD_LEFT).' '.($h < 12 ? 'am' : 'pm');
    }
}
