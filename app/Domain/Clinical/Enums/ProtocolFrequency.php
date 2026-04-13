<?php

namespace App\Domain\Clinical\Enums;

enum ProtocolFrequency: string
{
    case EveryShift = 'every_shift';
    case Daily = 'daily';
    case TwiceDaily = 'twice_daily';
    case Weekly = 'weekly';
    case Fortnightly = 'fortnightly';
    case Monthly = 'monthly';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::EveryShift => 'Every Shift',
            self::Daily => 'Daily',
            self::TwiceDaily => 'Twice Daily',
            self::Weekly => 'Weekly',
            self::Fortnightly => 'Fortnightly',
            self::Monthly => 'Monthly',
            self::Custom => 'Custom Interval',
        };
    }

    /**
     * Default interval in hours for schedule generation.
     * Returns null for EveryShift (shift-driven) and Custom (uses custom_frequency_hours).
     */
    public function defaultIntervalHours(): ?int
    {
        return match ($this) {
            self::Daily => 24,
            self::TwiceDaily => 12,
            self::Weekly => 168,
            self::Fortnightly => 336,
            self::Monthly => 720,
            self::EveryShift, self::Custom => null,
        };
    }
}
