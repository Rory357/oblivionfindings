<?php

namespace App\Domain\Clinical\Enums;

/**
 * Normalised clinical-risk band shared by the scored assessment tools
 * (FRAT, Braden, MUST). Each tool's native total maps onto this five-point
 * scale so the register, hero chips and Trends can compare like-for-like,
 * regardless of whether the underlying tool scores high-is-worse (FRAT/MUST)
 * or low-is-worse (Braden).
 *
 * Tones mirror the design's status palette: minimal/low → success,
 * medium → warning, high/very_high → critical.
 */
enum ClinicalRiskBand: string
{
    case Minimal = 'minimal';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case VeryHigh = 'very_high';

    public function label(): string
    {
        return match ($this) {
            self::Minimal => 'Minimal risk',
            self::Low => 'Low risk',
            self::Medium => 'Medium risk',
            self::High => 'High risk',
            self::VeryHigh => 'Very high risk',
        };
    }

    /** Semantic status tone for chips/badges. */
    public function tone(): string
    {
        return match ($this) {
            self::Minimal, self::Low => 'success',
            self::Medium => 'warning',
            self::High, self::VeryHigh => 'critical',
        };
    }

    /** Whether this band warrants a care-plan review / watch entry. */
    public function needsAction(): bool
    {
        return $this === self::High || $this === self::VeryHigh;
    }

    /** Sort weight (0 = lowest concern) for register ordering. */
    public function severity(): int
    {
        return match ($this) {
            self::Minimal => 0,
            self::Low => 1,
            self::Medium => 2,
            self::High => 3,
            self::VeryHigh => 4,
        };
    }
}
