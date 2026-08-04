<?php

namespace App\Domain\Privacy\Services;

use App\Domain\Hr\Services\PublicHolidayCalendar;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Privacy Act 2020 statutory clock.
 *
 * IPP 6 requires an agency to respond to an access request "as soon as
 * reasonably practicable, and no later than 20 working days" after the day the
 * request is received. This computes that deadline, skipping weekends and NZ
 * public holidays (national + regional anniversary days) via the shared
 * {@see PublicHolidayCalendar}.
 *
 * The server is authoritative; the wizard mirrors this client-side for display.
 */
class StatutoryDueDate
{
    /** Privacy Act 2020 IPP 6 statutory response window. */
    public const WORKING_DAYS = 20;

    public function __construct(private readonly PublicHolidayCalendar $holidays) {}

    /**
     * The statutory due date: {received} + 20 working days.
     */
    public function dueFrom(CarbonInterface|string $receivedAt, ?string $region = null): Carbon
    {
        return $this->addWorkingDays($receivedAt, self::WORKING_DAYS, $region);
    }

    /**
     * Add N working days to a date, skipping weekends and NZ public holidays.
     */
    public function addWorkingDays(CarbonInterface|string $from, int $workingDays, ?string $region = null): Carbon
    {
        $date = Carbon::parse($from instanceof CarbonInterface ? $from->toDateString() : $from)->startOfDay();

        $added = 0;
        while ($added < $workingDays) {
            $date->addDay();

            if ($date->isWeekend()) {
                continue;
            }

            if ($this->holidays->isPublicHoliday($date, $region)) {
                continue;
            }

            $added++;
        }

        return $date;
    }
}
