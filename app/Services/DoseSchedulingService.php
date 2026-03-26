<?php

namespace App\Services;

class DoseSchedulingService
{
    // Default facility administration times (NZ care home standard)
    const MORNING = '08:00';
    const MIDDAY = '12:00';
    const AFTERNOON = '14:00';
    const EVENING = '18:00';
    const NIGHT = '22:00';
    const EARLY_MORNING = '06:00';

    /**
     * Calculate dose times from a medication frequency string.
     *
     * Accepts common NZ care home frequency codes (OD, BD, TDS, QDS, etc.)
     * as well as plain-English frequencies. Returns an array of HH:MM strings
     * representing the scheduled administration times for one day.
     *
     * @param  string  $frequency  The frequency string (e.g. "Twice daily", "BD", "Every 8 hours")
     * @return string[]  Array of "HH:MM" time strings, sorted chronologically
     */
    public static function calculateDoseTimes(string $frequency): array
    {
        $normalised = strtolower(str_replace([' ', '-', '_'], '', trim($frequency)));

        return match ($normalised) {
            'oncedaily', 'daily', 'od'
                => [self::MORNING],

            'twicedaily', 'bd', 'bid'
                => ['08:00', '20:00'],

            'threetimesdaily', 'tds', 'tid'
                => ['08:00', '14:00', '20:00'],

            'fourtimesdaily', 'qds', 'qid'
                => ['08:00', '12:00', '18:00', '22:00'],

            'every4hours', 'q4h'
                => ['06:00', '10:00', '14:00', '18:00', '22:00'],

            'every6hours', 'q6h'
                => ['06:00', '12:00', '18:00', '00:00'],

            'every8hours', 'q8h'
                => ['06:00', '14:00', '22:00'],

            'every12hours', 'q12h'
                => ['08:00', '20:00'],

            'everymorning', 'mane'
                => [self::MORNING],

            'everynight', 'nocte'
                => [self::NIGHT],

            'weekly'
                => [self::MORNING],

            'fortnightly'
                => [self::MORNING],

            'monthly'
                => [self::MORNING],

            'prn', 'asneeded', 'whenrequired'
                => [], // No scheduled times for PRN

            'stat'
                => [], // One-off administration

            default
                => [self::MORNING], // Default to once daily morning
        };
    }

    /**
     * Return a human-readable label for a set of dose times.
     *
     * @param  string[]  $doseTimes
     * @return string  e.g. "08:00, 14:00, 20:00"
     */
    public static function formatDoseTimes(array $doseTimes): string
    {
        return implode(', ', $doseTimes);
    }
}
