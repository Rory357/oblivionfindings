<?php

namespace App\Domain\Clinical\Enums;

/**
 * NEWS2 aggregate clinical-risk band, driving the deterioration watch + the
 * observation-wizard advice. Maps to the design's four tones:
 * Low → success, Low-medium → primary, Medium → warning, High → critical.
 */
enum News2Band: string
{
    case Low = 'low';
    case LowMedium = 'low_medium';
    case Medium = 'medium';
    case High = 'high';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Low',
            self::LowMedium => 'Low-medium',
            self::Medium => 'Medium',
            self::High => 'High',
        };
    }

    /** Recommended clinical response (RCP NEWS2 monitoring/escalation guidance). */
    public function advice(): string
    {
        return match ($this) {
            self::Low => 'Routine monitoring.',
            self::LowMedium => 'A single parameter scored 3 — urgent review by a registered nurse.',
            self::Medium => 'Urgent review by a clinician — consider escalation.',
            self::High => 'Emergency response — escalate to senior clinical decision-maker now.',
        };
    }

    /** Whether this band warrants a deterioration alert + watchlist entry. */
    public function isOnWatch(): bool
    {
        return $this === self::Medium || $this === self::High;
    }

    /** The bands counted by the deterioration watch (latest band ≥ Medium). */
    public static function onWatchValues(): array
    {
        return [self::Medium->value, self::High->value];
    }
}
