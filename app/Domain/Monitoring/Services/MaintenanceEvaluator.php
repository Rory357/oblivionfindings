<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringMaintenanceWindow;
use Carbon\CarbonImmutable;
use DateTimeInterface;

final class MaintenanceEvaluator
{
    public function activeWindow(Monitor $monitor, DateTimeInterface $at): ?MonitoringMaintenanceWindow
    {
        $at = CarbonImmutable::instance($at)->utc();
        $siteId = app(CanonicalDeviceSiteResolver::class)->resolve((int) $monitor->device_id);

        return MonitoringMaintenanceWindow::query()
            ->where('site_id', $siteId)
            ->where('status', 'active')
            ->where('starts_at', '<=', $at)
            ->where(fn ($query) => $query
                ->whereNull('recurrence_until')
                ->orWhere('recurrence_until', '>=', $at))
            ->where(fn ($query) => $query
                ->where('monitor_id', $monitor->id)
                ->orWhere('device_id', $monitor->device_id)
                ->orWhere(fn ($scope) => $scope->whereNull('monitor_id')->whereNull('device_id')))
            ->orderByDesc('monitor_id')
            ->orderByDesc('device_id')
            ->orderBy('id')
            ->get()
            ->first(fn (MonitoringMaintenanceWindow $window): bool => $this->containsOccurrence($window, $at));
    }

    public function containsOccurrence(
        MonitoringMaintenanceWindow $window,
        DateTimeInterface $at,
    ): bool {
        $at = CarbonImmutable::instance($at)->utc();
        $start = CarbonImmutable::instance($window->starts_at)->utc();
        $end = CarbonImmutable::instance($window->ends_at)->utc();

        if ($window->recurrence === null) {
            return $at >= $start && $at < $end;
        }

        if ($at < $start || ($window->recurrence_until !== null && $at > $window->recurrence_until)) {
            return false;
        }

        $timezone = $window->timezone ?: 'UTC';
        $startLocal = $start->setTimezone($timezone);
        $endLocal = $end->setTimezone($timezone);
        $atLocal = $at->setTimezone($timezone);
        $candidates = $window->recurrence === 'daily'
            ? $this->dailyCandidates($startLocal, $atLocal)
            : $this->weeklyCandidates($startLocal, $atLocal);

        foreach ($candidates as $candidateStart) {
            if ($candidateStart < $startLocal) {
                continue;
            }
            $candidateEnd = $this->recurringEnd($candidateStart, $startLocal, $endLocal);
            if ($atLocal >= $candidateStart && $atLocal < $candidateEnd) {
                return true;
            }
        }

        return false;
    }

    /** @return list<CarbonImmutable> */
    private function dailyCandidates(CarbonImmutable $start, CarbonImmutable $at): array
    {
        $candidate = $at->setTime(
            $start->hour,
            $start->minute,
            $start->second,
            $start->micro,
        );

        return [$candidate, $candidate->subDay()];
    }

    /** @return list<CarbonImmutable> */
    private function weeklyCandidates(CarbonImmutable $start, CarbonImmutable $at): array
    {
        $elapsedDays = $start->startOfDay()->diffInDays($at->startOfDay(), false);
        if ($elapsedDays < 0) {
            return [];
        }
        $candidate = $start->addWeeks(intdiv((int) $elapsedDays, 7));

        return [$candidate, $candidate->subWeek()];
    }

    private function recurringEnd(
        CarbonImmutable $candidateStart,
        CarbonImmutable $templateStart,
        CarbonImmutable $templateEnd,
    ): CarbonImmutable {
        $dayOffset = $templateStart->startOfDay()->diffInDays($templateEnd->startOfDay(), false);

        return $candidateStart
            ->startOfDay()
            ->addDays((int) $dayOffset)
            ->setTime($templateEnd->hour, $templateEnd->minute, $templateEnd->second, $templateEnd->micro);
    }
}
