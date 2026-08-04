<?php

namespace App\Console\Commands;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Services\CanonicalDeviceSiteResolver;
use App\Domain\Monitoring\Services\CentralSiteMonitoringReadinessService;
use App\Domain\Monitoring\Services\MonitoringRuntimeHealthService;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Asset;
use App\Models\Client;
use App\Models\Site;
use App\Models\SiteRoom;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

final class MonitoringCentralSiteReadiness extends Command
{
    protected $signature = 'monitoring:central-site-readiness
        {site? : One operational Site ID}
        {--all : Assess every operational Site}
        {--json : Emit one privacy-safe JSON report}';

    protected $description = 'Read-only proof that central monitoring is working for each Site without a collector';

    public function handle(
        CentralSiteMonitoringReadinessService $readiness,
        MonitoringRuntimeHealthService $runtimeHealth,
        CanonicalDeviceSiteResolver $siteResolver,
    ): int {
        $siteOption = $this->argument('site');
        $all = (bool) $this->option('all');
        if (($siteOption === null && ! $all) || ($siteOption !== null && $all)) {
            $this->error('Provide one Site ID or use --all, but not both.');

            return self::INVALID;
        }
        if ($siteOption !== null
            && (filter_var($siteOption, FILTER_VALIDATE_INT) === false || (int) $siteOption < 1)) {
            $this->error('The Site ID must be a positive integer.');

            return self::INVALID;
        }

        $sites = Site::query()
            ->where('is_active', true)
            ->where('archived', false)
            ->whereNull('archived_at')
            ->when($siteOption !== null, fn (Builder $query): Builder => $query->whereKey((int) $siteOption))
            ->orderBy('name')
            ->get(['id', 'name']);
        if ($sites->isEmpty()) {
            $this->error('No matching operational Site was found.');

            return self::FAILURE;
        }

        $siteIds = $sites->pluck('id')->map(fn (mixed $id): int => (int) $id)->values();
        $devices = $this->candidateDevices($siteIds)->get();
        $sitesByDevice = $devices->mapWithKeys(function (Device $device) use ($siteIds, $siteResolver): array {
            try {
                $siteId = $siteResolver->resolve((int) $device->id);
            } catch (Throwable) {
                return [];
            }

            return $siteIds->contains($siteId) ? [$device->id => $siteId] : [];
        });
        $devices = $devices->whereIn('id', $sitesByDevice->keys())->values();
        $monitors = $devices->isEmpty()
            ? collect()
            : Monitor::query()
                ->whereIn('device_id', $devices->pluck('id'))
                ->with('profile:id,is_active,stale_after_seconds')
                ->get();
        $reports = $readiness->assess(
            $sites,
            $devices,
            $monitors,
            $sitesByDevice,
            $runtimeHealth->workerStates(),
        );
        $verified = $reports->every(fn (array $report): bool => $report['proof_state'] === 'verified');

        if ($this->option('json')) {
            $this->line(json_encode([
                'observed_at' => now()->utc()->toIso8601String(),
                'all_sites_verified' => $verified,
                'sites' => $reports->all(),
            ], JSON_THROW_ON_ERROR));
        } else {
            $this->table(
                ['Site', 'State', 'Proof', 'Direct checks', 'Fresh', 'Workers', 'Topology', 'Discovery'],
                $reports->map(fn (array $report): array => [
                    $report['site']['name'],
                    $report['label'],
                    $report['proof_state'],
                    $report['direct_monitors'],
                    $report['fresh'],
                    "{$report['runtime']['available']}/{$report['runtime']['required']}",
                    $report['topology']['state'],
                    $report['discovery']['state'],
                ])->all(),
            );
            $this->line($verified
                ? 'Every selected Site has current central monitoring proof.'
                : 'One or more Sites do not yet have current central monitoring proof.');
        }

        return $verified ? self::SUCCESS : self::FAILURE;
    }

    /** @param Collection<int, int> $siteIds */
    private function candidateDevices(Collection $siteIds): Builder
    {
        $roomIds = SiteRoom::query()->whereIn('site_id', $siteIds)->pluck('id');
        $clientIds = Client::query()
            ->whereIn('site_id', $siteIds)
            ->where('status', 'active')
            ->pluck('id');
        $staffIds = HrEmployeeProfile::query()
            ->whereIn('primary_site_id', $siteIds)
            ->where('is_active', true)
            ->where(fn (Builder $query): Builder => $query->whereNull('start_date')->orWhereDate('start_date', '<=', today()))
            ->where(fn (Builder $query): Builder => $query->whereNull('end_date')->orWhereDate('end_date', '>=', today()))
            ->pluck('user_id');
        $vehicleIds = Asset::query()
            ->where('status', 'active')
            ->where(function (Builder $query) use ($siteIds, $clientIds): void {
                $query->whereIn('site_id', $siteIds)
                    ->orWhereIn('home_site_id', $siteIds);
                if ($clientIds->isNotEmpty()) {
                    $query->orWhereIn('client_id', $clientIds);
                }
            })
            ->pluck('id');

        return Device::query()
            ->whereIn('status', [
                DeviceStatus::Active->value,
                DeviceStatus::Degraded->value,
                DeviceStatus::Offline->value,
            ])
            ->whereHas('assignments', function (Builder $assignment) use (
                $siteIds,
                $roomIds,
                $clientIds,
                $staffIds,
                $vehicleIds,
            ): void {
                $assignment->active()
                    ->where('assigned_at', '<=', now())
                    ->where(function (Builder $target) use (
                        $siteIds,
                        $roomIds,
                        $clientIds,
                        $staffIds,
                        $vehicleIds,
                    ): void {
                        $target->where(fn (Builder $branch): Builder => $branch
                            ->where('assignable_type', DeviceAssignment::TARGET_SITE)
                            ->whereIn('assignable_id', $siteIds));
                        foreach ([
                            DeviceAssignment::TARGET_ROOM => $roomIds,
                            DeviceAssignment::TARGET_CLIENT => $clientIds,
                            DeviceAssignment::TARGET_STAFF => $staffIds,
                            DeviceAssignment::TARGET_VEHICLE => $vehicleIds,
                        ] as $type => $ids) {
                            if ($ids->isNotEmpty()) {
                                $target->orWhere(fn (Builder $branch): Builder => $branch
                                    ->where('assignable_type', $type)
                                    ->whereIn('assignable_id', $ids));
                            }
                        }
                    });
            });
    }
}
