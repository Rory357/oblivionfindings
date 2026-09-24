<?php

namespace App\Services\Fleet;

/**
 * One reading of tracker driving and power events, shared by the daily
 * driving metrics and the per-trip behaviour analysis so both count the
 * same things.
 *
 * Queclink @Track trackers (GV500CG) report harsh driving as a single
 * `harsh_behaviour` event (GTHBM) and speed alarms as `speed_alarm`
 * (GTSPD). The kind of harsh event and the alarm phase travel in the
 * report's "Report ID / Report Type" field, kept as `report_id_type` on the
 * raw payload: its second character is the report type. For GTHBM
 * 0 = braking, 1 = acceleration, 2 = cornering; for GTSPD 0 = speed outside
 * the tracker's configured range (alarm), 1 = back inside it. Anything else
 * stays unclassified rather than guessed.
 */
final class FleetDrivingEventClassifier
{
    public const BRAKING = 'braking';

    public const ACCELERATION = 'acceleration';

    public const CORNERING = 'cornering';

    public const UNCLASSIFIED = 'unclassified';

    /** Earlier integrations name the direction in the event type itself. */
    private const BRAKING_TYPES = ['harsh_braking', 'harsh_brake', 'brake_hard'];

    private const ACCELERATION_TYPES = ['harsh_acceleration', 'rapid_acceleration', 'accel_hard'];

    private const CORNERING_TYPES = ['harsh_cornering', 'harsh_turn'];

    /** Tracker power problems that belong with vehicle faults. */
    public const FAULT_TYPES = ['external_power', 'power_off', 'battery_low'];

    /** Event types whose raw report type is needed to classify them. */
    public const REPORT_TYPED_EVENTS = ['harsh_behaviour', 'speed_alarm'];

    /** @return list<string> Every event type that can be a harsh driving event. */
    public static function harshEventTypes(): array
    {
        return [...self::BRAKING_TYPES, ...self::ACCELERATION_TYPES, ...self::CORNERING_TYPES, 'harsh_behaviour'];
    }

    /** The kind of harsh driving event, or null when the event is not one. */
    public static function harshKind(?string $eventType, ?string $reportType): ?string
    {
        $eventType = strtolower((string) $eventType);

        return match (true) {
            in_array($eventType, self::BRAKING_TYPES, true) => self::BRAKING,
            in_array($eventType, self::ACCELERATION_TYPES, true) => self::ACCELERATION,
            in_array($eventType, self::CORNERING_TYPES, true) => self::CORNERING,
            $eventType === 'harsh_behaviour' => match (self::reportTypeDigit($reportType)) {
                '0' => self::BRAKING,
                '1' => self::ACCELERATION,
                '2' => self::CORNERING,
                default => self::UNCLASSIFIED,
            },
            default => null,
        };
    }

    /**
     * Phase of a tracker speed alarm: 'start' when the tracker reported the
     * speed leaving its configured range, 'end' when it came back inside,
     * 'unknown' when the report type was not recorded; null for other events.
     */
    public static function speedAlarmPhase(?string $eventType, ?string $reportType): ?string
    {
        if (strtolower((string) $eventType) !== 'speed_alarm') {
            return null;
        }

        return match (self::reportTypeDigit($reportType)) {
            '0' => 'start',
            '1' => 'end',
            default => 'unknown',
        };
    }

    public static function isFault(?string $eventType): bool
    {
        return in_array(strtolower((string) $eventType), self::FAULT_TYPES, true);
    }

    /** @param  array<string,mixed>|string|null  $rawPayload */
    public static function reportType(array|string|null $rawPayload): ?string
    {
        if (is_string($rawPayload)) {
            $rawPayload = json_decode($rawPayload, true);
        }
        $value = is_array($rawPayload) ? ($rawPayload['report_id_type'] ?? null) : null;

        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : null;
    }

    private static function reportTypeDigit(?string $reportType): ?string
    {
        $reportType = trim((string) $reportType);

        return preg_match('/^[0-9A-Fa-f]{2}$/', $reportType) === 1 ? $reportType[1] : null;
    }
}
