<?php

namespace App\Services\Eligibility;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Splits UTC-backed work intervals by the configured worker-local calendar.
 * Durations always come from the underlying instants, so NZ DST days retain
 * their real 23/25-hour length while day and ISO-week keys stay local.
 */
final class LocalWorkTimeSegmenter
{
    /** @return array<string, float> Local Y-m-d => elapsed hours */
    public function byDay(CarbonInterface $startsAt, CarbonInterface $endsAt): array
    {
        $timezone = $this->timezone();
        $start = CarbonImmutable::instance($startsAt)->utc();
        $end = CarbonImmutable::instance($endsAt)->utc();
        if ($end->lessThanOrEqualTo($start)) {
            return [];
        }

        $segments = [];
        $localDay = $start->setTimezone($timezone)->startOfDay();
        while ($localDay->utc()->lessThan($end)) {
            $nextLocalDay = $localDay->addDay()->startOfDay();
            $boundaryStart = $localDay->utc();
            $boundaryEnd = $nextLocalDay->utc();
            $overlapStart = $start->greaterThan($boundaryStart) ? $start : $boundaryStart;
            $overlapEnd = $end->lessThan($boundaryEnd) ? $end : $boundaryEnd;

            if ($overlapEnd->greaterThan($overlapStart)) {
                $segments[$localDay->toDateString()] = ($overlapEnd->getTimestamp() - $overlapStart->getTimestamp()) / 3600;
            }

            $localDay = $nextLocalDay;
        }

        return $segments;
    }

    /** @return array<string, float> Local ISO-week Monday Y-m-d => elapsed hours */
    public function byWeek(CarbonInterface $startsAt, CarbonInterface $endsAt): array
    {
        $timezone = $this->timezone();
        $segments = [];

        foreach ($this->byDay($startsAt, $endsAt) as $localDate => $hours) {
            $week = CarbonImmutable::createFromFormat('!Y-m-d', $localDate, $timezone);
            if (! $week) {
                throw new InvalidArgumentException('A local work-date segment could not be parsed.');
            }

            $key = $week->startOfWeek(CarbonImmutable::MONDAY)->toDateString();
            $segments[$key] = ($segments[$key] ?? 0.0) + $hours;
        }

        return $segments;
    }

    public function timezone(): DateTimeZone
    {
        $name = (string) (config('app.worker_timezone') ?: 'Pacific/Auckland');

        try {
            return new DateTimeZone($name);
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException("The configured worker timezone '{$name}' is invalid.", 0, $exception);
        }
    }
}
