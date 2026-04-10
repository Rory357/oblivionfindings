<?php

namespace App\Enums;

/**
 * Canonical alert severity levels for the entire platform.
 *
 * This is the SINGLE definition of severity semantics.
 * All signal services, rules, and alert creation must use these values.
 *
 * USAGE GUIDELINES:
 *
 * CRITICAL — Immediate threat to life, safety, or regulatory compliance.
 *   Must be responded to within minutes.
 *   Examples: SOS/panic, missing resident, serious injury, controlled drug discrepancy.
 *   Expected volume: <1% of all alerts.
 *
 * HIGH — Significant operational disruption or safety risk.
 *   Must be responded to within the current shift.
 *   Examples: Overdue medication, lone worker overdue check-in (extended),
 *   staff no-show >60min, door forced, device tamper, expired medication.
 *   Expected volume: ~5-10% of alerts.
 *
 * MEDIUM — Operational issue requiring attention but not urgent.
 *   Should be addressed within 4-8 hours.
 *   Examples: Shift late start 30min, inspection overdue <7 days,
 *   device offline (non-safety), missed dose (routine medication).
 *   Expected volume: ~30-40% of alerts.
 *
 * LOW — Informational or minor issue. Acknowledged but not urgent.
 *   Can be addressed within 24-48 hours.
 *   Examples: Stock warning, geofence enter (non-breach), low battery.
 *   Expected volume: ~50% of alerts.
 *
 * Do NOT use critical for anything that can safely wait >15 minutes.
 * Do NOT use low for anything that requires same-day action.
 */
class AlertSeverity
{
    public const LOW = 'low';
    public const MEDIUM = 'medium';
    public const HIGH = 'high';
    public const CRITICAL = 'critical';

    /**
     * Ordered list of valid severities from lowest to highest.
     */
    public const ALL = [self::LOW, self::MEDIUM, self::HIGH, self::CRITICAL];

    /**
     * Numeric rank for comparison.
     */
    public const RANK = [
        self::LOW => 0,
        self::MEDIUM => 1,
        self::HIGH => 2,
        self::CRITICAL => 3,
    ];

    /**
     * Validate that a severity value is canonical.
     */
    public static function isValid(string $severity): bool
    {
        return in_array($severity, self::ALL, true);
    }

    /**
     * Normalise a severity value. Returns the canonical value or a safe default.
     */
    public static function normalise(?string $severity, string $default = self::MEDIUM): string
    {
        if ($severity === null || !self::isValid($severity)) {
            return $default;
        }

        return $severity;
    }

    /**
     * Return the higher of two severity values.
     */
    public static function higher(string $a, string $b): string
    {
        $rankA = self::RANK[$a] ?? 1;
        $rankB = self::RANK[$b] ?? 1;

        return $rankA >= $rankB ? $a : $b;
    }

    /**
     * Return the lower of two severity values.
     */
    public static function lower(string $a, string $b): string
    {
        $rankA = self::RANK[$a] ?? 1;
        $rankB = self::RANK[$b] ?? 1;

        return $rankA <= $rankB ? $a : $b;
    }

    /**
     * Check if severity A is at least as high as severity B.
     */
    public static function isAtLeast(string $severity, string $minimum): bool
    {
        return (self::RANK[$severity] ?? 0) >= (self::RANK[$minimum] ?? 0);
    }
}
