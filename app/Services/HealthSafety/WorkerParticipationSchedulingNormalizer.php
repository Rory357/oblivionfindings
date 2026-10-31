<?php

namespace App\Services\HealthSafety;

use DateTimeImmutable;
use DateTimeZone;

/** Explicit participation wall-time input only; unmarked validated adapters retain their contract. */
final class WorkerParticipationSchedulingNormalizer
{
    public const TIMEZONE = 'Pacific/Auckland';

    /** @return array{value:mixed,errors:array<string,string>} */
    public static function normalize(mixed $scheduledAt, mixed $scheduledTimezone = null, mixed $scheduledOffset = null, string $configuredTimezone = self::TIMEZONE): array
    {
        if ($scheduledTimezone === null) {
            if ($scheduledOffset !== null && $scheduledOffset !== '') {
                return self::error('scheduled_offset', 'Choose the labelled scheduling timezone before its offset.');
            }

            return ['value' => $scheduledAt, 'errors' => []];
        }
        if ($scheduledTimezone !== $configuredTimezone) {
            return self::error('scheduled_at', 'Use the labelled scheduling timezone.');
        }
        if (! is_string($scheduledAt) || ! preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}$/D', $scheduledAt)) {
            return self::error('scheduled_at', 'Choose a real local date and time to the minute.');
        }
        $utc = new DateTimeZone('UTC');
        $wall = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $scheduledAt, $utc);
        if ($wall === false || $wall->format('Y-m-d\TH:i') !== $scheduledAt || (int) $wall->format('Y') < 1000) {
            return self::error('scheduled_at', 'Choose a real local date and time.');
        }
        if ($scheduledOffset === '') { $scheduledOffset = null; }
        if ($scheduledOffset !== null && (! is_string($scheduledOffset)
            || ! preg_match('/^[+-](?:0[0-9]|1[0-4]):[0-5][0-9]$/D', $scheduledOffset)
            || (substr($scheduledOffset, 1, 2) === '14' && substr($scheduledOffset, 4, 2) !== '00'))) {
            return self::error('scheduled_offset', 'Choose a valid UTC offset.');
        }
        try { $zone = new DateTimeZone($configuredTimezone); }
        catch (\Exception) { return self::error('scheduled_at', 'The labelled scheduling timezone is unavailable.'); }
        $transitions = $zone->getTransitions($wall->getTimestamp() - 86400, $wall->getTimestamp() + 86400);
        $offsets = $transitions === false ? [$zone->getOffset($wall)] : array_unique(array_column($transitions, 'offset'));
        $matches = [];
        foreach ($offsets as $offset) {
            $instant = $wall->setTimestamp($wall->getTimestamp() - $offset);
            if ($instant->setTimezone($zone)->format('Y-m-d\TH:i') === $scheduledAt) {
                $matches[$instant->getTimestamp()] = $instant;
            }
        }
        if ($matches === []) {
            return self::error('scheduled_at', 'This time is skipped by daylight saving. Choose a time outside that hour.');
        }
        if ($scheduledOffset !== null) {
            $selectedSeconds = ((int) substr($scheduledOffset, 1, 2) * 3600 + (int) substr($scheduledOffset, 4, 2) * 60) * ($scheduledOffset[0] === '-' ? -1 : 1);
            foreach ($matches as $instant) {
                // Historical zones can include offset seconds. A minute-only
                // choice must match the actual offset, not a truncated label.
                if ($zone->getOffset($instant) === $selectedSeconds) {
                    return self::storedInstant($instant);
                }
            }
            return self::error('scheduled_offset', 'That UTC offset does not apply to the selected local time.');
        }
        if (count($matches) !== 1) {
            return self::error('scheduled_offset', 'This time occurs twice at daylight saving. Choose its UTC offset.');
        }

        return self::storedInstant(reset($matches));
    }

    private static function storedInstant(DateTimeImmutable $instant): array
    {
        $year = (int) $instant->format('Y');
        if ($year < 1000 || $year > 9999) {
            return self::error('scheduled_at', 'Choose a date within the supported scheduling range.');
        }

        return ['value' => $instant->format('Y-m-d\TH:i:s\Z'), 'errors' => []];
    }

    private static function error(string $field, string $message): array
    {
        return ['value' => null, 'errors' => [$field => $message]];
    }
}
