<?php

namespace Tests\Support;

use Carbon\Carbon;
use DateTimeInterface;

/**
 * Carbon 3 parses a datetime string that carries no timezone in the frozen
 * clock's timezone (Creator::rawCreateFromFormat). Eloquent reads every stored
 * datetime that way, so a test that freezes time in worker time reads UTC
 * database values 12-13 hours off and can pass or fail for the wrong reason.
 * Production never freezes the clock, so it always parses in the default
 * (UTC) timezone. Freezing the same instant in UTC avoids the skew, and
 * Carbon::now('Pacific/Auckland') still returns worker time.
 */
final class FrozenClockTimezone
{
    private const UTC_NAMES = ['UTC', 'Z', '+00:00', 'Etc/UTC'];

    /**
     * Explain why the frozen clock would skew Eloquent datetimes, or return
     * null when time is not frozen or is frozen in the default timezone.
     */
    public static function violation(): ?string
    {
        $testNow = Carbon::getTestNow();

        if (! $testNow instanceof DateTimeInterface) {
            return null;
        }

        $frozen = $testNow->getTimezone()->getName();
        $default = date_default_timezone_get();

        if ($frozen === $default || (in_array($frozen, self::UTC_NAMES, true) && in_array($default, self::UTC_NAMES, true))) {
            return null;
        }

        return sprintf(
            'Time is frozen in %s, but the default timezone is %s. Carbon parses stored datetimes in the frozen '
            .'clock\'s timezone, so Eloquent would read them hours off. Freeze the same instant in UTC instead, '
            .'for example Carbon::setTestNow(Carbon::parse(\'2026-05-21 10:00:00\', \'Pacific/Auckland\')->utc()).',
            $frozen,
            $default,
        );
    }
}
