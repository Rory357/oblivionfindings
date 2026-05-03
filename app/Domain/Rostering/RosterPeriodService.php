<?php

namespace App\Domain\Rostering;

use App\Models\RosterPeriod;
use App\Models\Shift;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class RosterPeriodService
{
    public function weekStart(CarbonInterface|string|null $week): Carbon
    {
        $timezone = (string) config('app.worker_timezone', 'Pacific/Auckland');
        $date = $week instanceof CarbonInterface
            ? Carbon::instance($week)
            : Carbon::parse($week ?: now($timezone), $timezone);

        return $date->copy()->timezone($timezone)->startOfWeek(Carbon::MONDAY)->startOfDay();
    }

    public function weekEnd(CarbonInterface|string|null $week): Carbon
    {
        return $this->weekStart($week)->addDays(7);
    }

    public function findOrCreate(?int $organizationId, int $siteId, CarbonInterface|string $week): RosterPeriod
    {
        $weekStart = $this->weekStart($week);

        return RosterPeriod::query()->firstOrCreate(
            [
                'organization_id' => $organizationId,
                'site_id' => $siteId,
                'week_start' => $weekStart->toDateString(),
                'version' => $this->nextVersionFor($organizationId, $siteId, $weekStart->toDateString()),
            ],
            [
                'week_end' => $weekStart->copy()->addDays(7)->toDateString(),
                'status' => RosterPeriod::STATUS_DRAFT,
                'created_by' => auth()->id(),
            ],
        );
    }

    public function activeFor(?int $organizationId, int $siteId, CarbonInterface|string $week): ?RosterPeriod
    {
        $weekStart = $this->weekStart($week)->toDateString();

        return RosterPeriod::query()
            ->where('organization_id', $organizationId)
            ->where('site_id', $siteId)
            ->whereDate('week_start', $weekStart)
            ->where('status', '!=', RosterPeriod::STATUS_ARCHIVED)
            ->orderByDesc('version')
            ->first();
    }

    public function shiftsQuery(RosterPeriod $period): Builder
    {
        $timezone = (string) config('app.worker_timezone', 'Pacific/Auckland');
        $weekStartDate = $period->week_start instanceof CarbonInterface
            ? $period->week_start->toDateString()
            : (string) $period->week_start;
        $localWeekStart = Carbon::parse($weekStartDate, $timezone)->startOfDay();
        $weekStart = $localWeekStart->copy()->utc();
        $weekEnd = $localWeekStart->copy()->addDays(7)->utc();

        return Shift::query()
            ->where(function (Builder $query) use ($period, $weekStart, $weekEnd) {
                $query->where('roster_period_id', $period->id)
                    ->orWhere(function (Builder $fallback) use ($period, $weekStart, $weekEnd) {
                        $fallback->whereNull('roster_period_id')
                            ->where('site_id', $period->site_id)
                            ->where('organization_id', $period->organization_id)
                            ->where('starts_at', '<', $weekEnd)
                            ->where('ends_at', '>', $weekStart);
                    });
            });
    }

    private function nextVersionFor(?int $organizationId, int $siteId, string $weekStart): int
    {
        $active = RosterPeriod::query()
            ->where('organization_id', $organizationId)
            ->where('site_id', $siteId)
            ->whereDate('week_start', $weekStart)
            ->where('status', '!=', RosterPeriod::STATUS_ARCHIVED)
            ->orderByDesc('version')
            ->first();

        if ($active) {
            return $active->version;
        }

        $latestVersion = RosterPeriod::query()
            ->where('organization_id', $organizationId)
            ->where('site_id', $siteId)
            ->whereDate('week_start', $weekStart)
            ->max('version');

        return $latestVersion ? ((int) $latestVersion + 1) : 1;
    }
}
