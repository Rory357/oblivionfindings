<?php

namespace App\Services\Tracking;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

final class ClientZoneSchedule
{
    /** The window identity is its local START date, including overnight exceptions. End is exclusive. */
    public function window(array $schedule, CarbonInterface $time): ?string
    {
        $local = CarbonImmutable::instance($time)->setTimezone('Pacific/Auckland');
        foreach ([$local->startOfDay(), $local->subDay()->startOfDay()] as $day) {
            $date = $day->toDateString();
            if ($date < $schedule['first_date'] || $date > $schedule['last_date']
                || ! in_array($day->isoWeekday(), $schedule['weekdays'], true)
                || in_array($date, $schedule['exception_dates'], true)) {
                continue;
            }
            $start = $day->setTimeFromTimeString($schedule['start']);
            $end = ($schedule['following_day'] ? $day->addDay() : $day)->setTimeFromTimeString($schedule['end']);
            if ($local->greaterThanOrEqualTo($start) && $local->lessThan($end)) {
                return $date;
            }
        }

        return null;
    }
}
