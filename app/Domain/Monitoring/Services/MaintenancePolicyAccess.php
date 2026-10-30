<?php

namespace App\Domain\Monitoring\Services;

use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringMaintenanceWindow;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\Site;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use Illuminate\Support\Facades\DB;

/** Canonical resource mutex followed by callback-bounded current authority. */
final class MaintenancePolicyAccess
{
    public function __construct(private readonly SecurityDevicesAccessService $access) {}

    public function within(User $claimed, int $expectedActor, ?int $windowId, int $siteId, bool $write, callable $action): mixed
    {
        return DB::transaction(fn () => CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($claimed, $expectedActor, $windowId, $siteId, $write, $action): mixed {
            $window = null;
            if ($windowId !== null) {
                $query = MonitoringMaintenanceWindow::query()->whereKey($windowId);
                $window = ($write ? $query->lockForUpdate() : $reads->query($query))->firstOrFail();
            } else {
                $query = Site::query()->whereKey($siteId);
                ($write ? $query->lockForUpdate() : $reads->query($query))->firstOrFail();
            }
            $actor = app(AuthorizationEvidenceLockService::class)->lockForUserWithoutWaiting((int) $claimed->id, ['*']);
            abort_unless($actor->id === $expectedActor && $actor->approved_at && $actor->email_verified_at && ! $actor->external_clinical_account, 404, 'unavailable');
            foreach (['client', 'next_of_kin', 'external_clinician'] as $role) {
                abort_if($actor->role === $role || $actor->hasRole($role), 404, 'unavailable');
            }
            abort_unless($actor->canDo('securityDevices.monitoring.manage'), 404, 'unavailable');
            if ($window) {
                $this->scope($actor, $window->only(['site_id', 'device_id', 'monitor_id']), $reads);
            } else {
                $this->scope($actor, ['site_id' => $siteId], $reads);
            }

            return $action($actor, $reads, $window);
        }));
    }

    /** Return the current pinned canonical binding; never consult legacy partitions. */
    public function scope(User $actor, array $values, CurrentAuthorizationReads $reads): array
    {
        $reads->assertActive();
        $site = (int) ($values['site_id'] ?? 0);
        $monitorId = isset($values['monitor_id']) ? (int) $values['monitor_id'] : null;
        $explicitDevice = isset($values['device_id']) ? (int) $values['device_id'] : null;
        abort_if($monitorId !== null && $explicitDevice !== null, 404, 'unavailable');
        $deviceId = $explicitDevice ?? 0;
        if ($monitorId !== null) {
            $monitor = $reads->query(Monitor::query())->whereKey($monitorId)->first();
            abort_unless($monitor, 404, 'unavailable');
            $deviceId = (int) $monitor->device_id;
        }
        $access = $this->access->forCurrentEvidence($reads, $deviceId);
        $access->assertCanViewSite($actor, $site);
        if ($deviceId > 0) {
            $device = $reads->query(Device::query())->whereKey($deviceId)->first();
            abort_unless($device, 404, 'unavailable');
            $access->assertCanViewDevice($actor, $device);
            abort_unless(app(CanonicalDeviceSiteResolver::class)->forCurrentEvidence($reads)->resolve($deviceId) === $site, 404, 'unavailable');
        }
        $binding = ['site_id' => $site, 'device_id' => $explicitDevice, 'monitor_id' => $monitorId, 'monitor_device_id' => $monitorId !== null ? $deviceId : null];
        if (array_key_exists('monitor_device_id', $values)) {
            abort_unless($values['monitor_device_id'] === $binding['monitor_device_id'], 404, 'unavailable');
        }

        return $binding;
    }
}
