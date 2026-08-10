<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrPublicHoliday;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class PublicHolidayCalendar
{
    /** @var array<string, Collection<int, HrPublicHoliday>> */
    private array $cache = [];

    /**
     * The holiday observed on the given calendar date, if any.
     *
     * National holidays always match; regional anniversary days match only
     * when the workplace region is known and equal (case-insensitive).
     * Holidays are one application catalogue. National holidays always match;
     * regional anniversary days match the supplied workplace region.
     */
    public function holidayFor(CarbonInterface|string $date, ?string $region = null): ?HrPublicHoliday
    {
        $dateString = $date instanceof CarbonInterface
            ? $date->toDateString()
            : Carbon::parse($date)->toDateString();

        $holidays = $this->holidaysOn($dateString);

        $normalisedRegion = $region !== null ? mb_strtolower(trim($region)) : null;

        return $holidays->first(function (HrPublicHoliday $holiday) use ($normalisedRegion) {
            if ($holiday->is_national || $holiday->region === null || $holiday->region === '') {
                return true;
            }

            return $normalisedRegion !== null
                && mb_strtolower(trim((string) $holiday->region)) === $normalisedRegion;
        });
    }

    public function isPublicHoliday(CarbonInterface|string $date, ?string $region = null): bool
    {
        return $this->holidayFor($date, $region) !== null;
    }

    /**
     * @return Collection<int, HrPublicHoliday>
     */
    private function holidaysOn(string $dateString): Collection
    {
        $key = $dateString;

        return $this->cache[$key] ??= HrPublicHoliday::query()
            ->whereDate('date', $dateString)
            ->get();
    }
}
