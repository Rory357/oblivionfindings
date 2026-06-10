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
     * Holidays are seeded globally (tenant_id null); tenant-specific rows
     * additionally apply to that tenant.
     */
    public function holidayFor(CarbonInterface|string $date, ?int $tenantId = null, ?string $region = null): ?HrPublicHoliday
    {
        $dateString = $date instanceof CarbonInterface
            ? $date->toDateString()
            : Carbon::parse($date)->toDateString();

        $holidays = $this->holidaysOn($dateString, $tenantId);

        $normalisedRegion = $region !== null ? mb_strtolower(trim($region)) : null;

        return $holidays->first(function (HrPublicHoliday $holiday) use ($normalisedRegion) {
            if ($holiday->is_national || $holiday->region === null || $holiday->region === '') {
                return true;
            }

            return $normalisedRegion !== null
                && mb_strtolower(trim((string) $holiday->region)) === $normalisedRegion;
        });
    }

    public function isPublicHoliday(CarbonInterface|string $date, ?int $tenantId = null, ?string $region = null): bool
    {
        return $this->holidayFor($date, $tenantId, $region) !== null;
    }

    /**
     * @return Collection<int, HrPublicHoliday>
     */
    private function holidaysOn(string $dateString, ?int $tenantId): Collection
    {
        $key = $dateString.'|'.($tenantId ?? 'global');

        return $this->cache[$key] ??= HrPublicHoliday::query()
            ->whereDate('date', $dateString)
            ->where(function ($query) use ($tenantId) {
                $query->whereNull('tenant_id');
                if ($tenantId !== null) {
                    $query->orWhere('tenant_id', $tenantId);
                }
            })
            ->get();
    }
}
