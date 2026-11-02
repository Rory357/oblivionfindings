<?php

namespace App\Domain\Monitoring\Discovery\Services;

use App\Domain\Monitoring\Discovery\Models\DiscoveryRun;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\SecurityDevices\Setup\SecurityDevicesSettings;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

/**
 * S&D Phase B: starts each hub scan area's daily network scan once its time has come.
 *
 * It uses the same DiscoveryRunner path as "Run now", so the hub picks the run up as normal
 * work. A scan area only scans daily when someone has set its daily time; empty means off.
 * Setup's "Scan every day" switch (on by default) pauses every daily scan while it is off.
 */
final class DailyDiscoveryScheduler
{
    public const string TRIGGER = 'schedule:daily';

    public function __construct(
        private readonly DiscoveryRunner $runner,
        private readonly SecurityDevicesSettings $settings,
    ) {}

    /** Start today's scan for every scan area that is due. Returns how many runs were started. */
    public function startDue(?CarbonImmutable $now = null): int
    {
        if (! $this->settings->dailyScans()) {
            return 0;
        }

        $zone = (string) config('app.worker_timezone', 'Pacific/Auckland');
        $localNow = ($now ?? CarbonImmutable::now('UTC'))->setTimezone($zone);
        $started = 0;

        DiscoveryScope::query()
            ->with('collector')
            ->where('status', 'active')
            ->whereNotNull('daily_scan_at')
            ->whereNotNull('collector_id')
            ->orderBy('id')
            ->each(function (DiscoveryScope $scope) use ($localNow, &$started): void {
                if ($this->startIfDue($scope, $localNow)) {
                    $started++;
                }
            });

        return $started;
    }

    private function startIfDue(DiscoveryScope $scope, CarbonImmutable $localNow): bool
    {
        $dueAt = $this->dueAt($scope->daily_scan_at, $localNow);
        if ($dueAt === null || $localNow->lt($dueAt)) {
            return false;
        }

        // Work goes only to a hub an admin has approved and nobody has removed.
        if ($scope->collector === null || ! $scope->collector->isApprovedForWork()) {
            return false;
        }

        // Switched on (or changed) after today's time: the first scan is tomorrow, not straight away.
        $dueAtUtc = $dueAt->setTimezone('UTC');
        if ($scope->updated_at !== null && $scope->updated_at->gt($dueAtUtc)) {
            return false;
        }

        // Once a day, never on top of another run: skip if a run is still going or one
        // (by hand or scheduled) has started since today's time.
        $busyOrDone = DiscoveryRun::query()
            ->where('discovery_scope_id', $scope->id)
            ->where(fn ($query) => $query
                ->whereIn('status', ['queued', 'running'])
                ->orWhere('created_at', '>=', $dueAtUtc))
            ->exists();
        if ($busyOrDone) {
            return false;
        }

        try {
            return DB::transaction(function () use ($scope): bool {
                $run = $this->runner->start($scope, self::TRIGGER);
                if (! $run->wasRecentlyCreated) {
                    return false;
                }
                AuditLogger::logOrFail('monitoring.discovery.scope.daily_scan_started', $scope, [
                    'site_id' => (int) $scope->site_id,
                    'collector_id' => (int) $scope->collector_id,
                    'run_id' => (int) $run->id,
                    'run_uuid' => $run->run_uuid,
                    'planned_targets' => (int) $run->planned_targets,
                    'daily_scan_at' => $scope->daily_scan_at,
                ], systemActor: true);

                return true;
            }, 3);
        } catch (UnexpectedValueException) {
            // The scan area no longer passes its checks (Site closed, hub moved). Skip it today.
            return false;
        }
    }

    private function dueAt(mixed $time, CarbonImmutable $localNow): ?CarbonImmutable
    {
        if (! is_string($time) || preg_match('/\A([01]\d|2[0-3]):([0-5]\d)\z/', $time, $parts) !== 1) {
            return null;
        }

        return $localNow->setTime((int) $parts[1], (int) $parts[2]);
    }
}
