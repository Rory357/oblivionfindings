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
        return $this->resolve($monitor, $at)['window'];
    }

    /** Current configuration evaluated at the supplied occurrence instant; not an assignment-history claim.
     * @return array{state: string, window: ?MonitoringMaintenanceWindow, specificity: ?string, matching: list<MonitoringMaintenanceWindow>, overridden: list<MonitoringMaintenanceWindow>}
     */
    public function resolve(Monitor $monitor, DateTimeInterface $at): array
    {
        $at = CarbonImmutable::instance($at)->utc();
        $siteId = app(CanonicalDeviceSiteResolver::class)->resolve((int) $monitor->device_id);
        $windows = MonitoringMaintenanceWindow::query()
            ->useWritePdo()
            ->where('site_id', $siteId)
            ->where('status', 'active')
            ->where('starts_at', '<=', $at)
            ->where(fn ($query) => $query
                ->whereNull('recurrence_until')
                ->orWhere('recurrence_until', '>', $at))
            ->where(fn ($query) => $query
                ->where('monitor_id', $monitor->id)
                ->orWhere('device_id', $monitor->device_id)
                ->orWhere(fn ($scope) => $scope->whereNull('monitor_id')->whereNull('device_id')))
            ->orderBy('id')
            ->get()
            ->filter(fn (MonitoringMaintenanceWindow $window): bool => $this->containsOccurrence($window, $at))
            ->all();
        if ($windows === []) {
            return ['state' => 'none', 'window' => null, 'specificity' => null, 'matching' => [], 'overridden' => []];
        }
        $rank = fn (MonitoringMaintenanceWindow $window): int => $window->monitor_id !== null ? 2 : ($window->device_id !== null ? 1 : 0);
        $priority = max(array_map($rank, $windows));
        $matching = array_values(array_filter($windows, fn ($window): bool => $rank($window) === $priority));
        $overridden = array_values(array_filter($windows, fn ($window): bool => $rank($window) < $priority));
        $state = count($matching) > 1 ? 'conflict' : ($matching[0]->policy === 'suppress_notifications_and_ticketing' ? 'applied' : 'unsupported');

        return [
            'state' => $state,
            'window' => $state === 'applied' ? $matching[0] : null,
            'specificity' => ['site', 'device', 'monitor'][$priority],
            'matching' => $matching,
            'overridden' => $overridden,
        ];
    }

    public function containsOccurrence(MonitoringMaintenanceWindow $window, DateTimeInterface $at): bool
    {
        return (new MaintenanceSchedule)->contains((new MaintenanceOverlap)->values($window), $at);
    }
}
