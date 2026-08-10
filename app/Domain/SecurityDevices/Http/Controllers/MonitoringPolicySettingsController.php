<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\Monitoring\Exceptions\MonitoringPolicyVersionConflict;
use App\Domain\Monitoring\Models\Monitor;
use App\Domain\Monitoring\Models\MonitoringCoverageExpectation;
use App\Domain\Monitoring\Models\MonitoringMaintenanceWindow;
use App\Domain\Monitoring\Models\MonitoringRetentionPolicy;
use App\Domain\Monitoring\Services\MonitoringPolicyAuthoringService;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

abstract class MonitoringPolicySettingsController extends Controller
{
    public function __construct(
        protected readonly MonitoringPolicyAuthoringService $authoring,
        protected readonly SecurityDevicesAccessService $access,
    ) {}

    protected function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->canDo('securityDevices.monitoring.manage'), 403);

        return $actor;
    }

    protected function assertApplicationAccess(User $actor): void
    {
        abort_unless($this->access->canViewAllSites($actor), 403);
    }

    protected function assertCoverageScope(User $actor, MonitoringCoverageExpectation $expectation): void
    {
        if ($expectation->site_id === null) {
            $this->assertApplicationAccess($actor);

            return;
        }

        $this->access->assertCanViewSite($actor, (int) $expectation->site_id);
    }

    protected function assertMaintenanceScope(User $actor, MonitoringMaintenanceWindow $window): void
    {
        $this->access->assertCanViewSite($actor, (int) $window->site_id);
        if ($window->device_id !== null) {
            $this->accessibleDevice($actor, (int) $window->device_id);
        }
        if ($window->monitor_id !== null) {
            $this->accessibleMonitor($actor, (int) $window->monitor_id);
        }
    }

    protected function assertRetentionScope(User $actor, MonitoringRetentionPolicy $policy): void
    {
        $this->assertRetentionAttributes($actor, $policy->only([
            'scope_kind', 'site_id', 'device_id', 'data_class', 'privacy_class',
        ]));
    }

    /** @param array<string, mixed> $attributes */
    protected function assertCoverageAttributes(User $actor, array $attributes): void
    {
        if (! array_key_exists('site_id', $attributes) || $attributes['site_id'] === null) {
            $this->assertApplicationAccess($actor);

            return;
        }

        $this->access->assertCanViewSite($actor, (int) $attributes['site_id']);
    }

    /** @param array<string, mixed> $attributes */
    protected function assertMaintenanceAttributes(User $actor, array $attributes): void
    {
        $this->access->assertCanViewSite($actor, (int) $attributes['site_id']);
        if (($attributes['device_id'] ?? null) !== null) {
            $this->accessibleDevice($actor, (int) $attributes['device_id']);
        }
        if (($attributes['monitor_id'] ?? null) !== null) {
            $this->accessibleMonitor($actor, (int) $attributes['monitor_id']);
        }
    }

    /** @param array<string, mixed> $attributes */
    protected function assertRetentionAttributes(User $actor, array $attributes): void
    {
        $scope = (string) ($attributes['scope_kind'] ?? '');
        if ($scope === 'site') {
            $this->access->assertCanViewSite($actor, (int) $attributes['site_id']);

            return;
        }
        if ($scope === 'device') {
            $this->accessibleDevice($actor, (int) $attributes['device_id']);

            return;
        }

        $this->assertApplicationAccess($actor);
    }

    protected function accessibleDevice(User $actor, int $deviceId): Device
    {
        return $this->access->visibleDevices($actor)->whereKey($deviceId)->firstOrFail();
    }

    protected function accessibleMonitor(User $actor, int $monitorId): Monitor
    {
        return Monitor::query()
            ->whereKey($monitorId)
            ->whereIn('device_id', $this->access->visibleDevices($actor)->select('devices.id'))
            ->firstOrFail();
    }

    /** @template TValue
     * @param  callable(): TValue  $callback
     * @return TValue
     */
    protected function governed(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (MonitoringPolicyVersionConflict) {
            abort(409, 'This monitoring policy changed after you opened it. Refresh and review the latest version.');
        }
    }

    /** @return array<string, int|string|bool|null> */
    protected function state(Model $record): array
    {
        $status = $record->getAttribute('status');

        return [
            'id' => (int) $record->id,
            'version' => (int) $record->version,
            'state' => $status !== null
                ? (string) $status
                : ((bool) $record->is_active ? 'active' : 'inactive'),
        ];
    }
}
