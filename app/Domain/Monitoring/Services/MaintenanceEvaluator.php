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
            ->first(fn (MonitoringMaintenanceWindow $window): bool => $this->contains($window, $at));
    }

    private function contains(MonitoringMaintenanceWindow $window, CarbonImmutable $at): bool
    {
        $start = CarbonImmutable::instance($window->starts_at)->utc();
        $end = CarbonImmutable::instance($window->ends_at)->utc();

        if ($window->recurrence === null) {
            return $at >= $start && $at < $end;
        }

        if ($at < $start || ($window->recurrence_until !== null && $at > $window->recurrence_until)) {
            return false;
        }

        $duration = $start->diffInSeconds($end);
        $period = $window->recurrence === 'daily' ? 86400 : 604800;
        $elapsed = $start->diffInSeconds($at, false);
        $offset = $elapsed % $period;

        return $offset >= 0 && $offset < $duration;
    }
}
