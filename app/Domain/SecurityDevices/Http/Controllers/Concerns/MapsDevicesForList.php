<?php

namespace App\Domain\SecurityDevices\Http\Controllers\Concerns;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

trait MapsDevicesForList
{
    protected function mapDeviceForList(Device $d): array
    {
        $activeAssignment = $d->assignments->first(fn ($a) => $a->released_at === null);
        $hasMonitoringCounts = array_key_exists('enabled_monitors_count', $d->getAttributes());
        $monitorCount = $hasMonitoringCounts ? (int) $d->enabled_monitors_count : null;
        $monitoringState = match (true) {
            ! $hasMonitoringCounts => null,
            $monitorCount === 0 => 'unmonitored',
            (int) $d->failing_monitors_count > 0 => 'attention',
            (int) $d->uncertain_monitors_count > 0 => 'unknown',
            default => 'healthy',
        };

        return [
            'id' => $d->id,
            'device_uid' => $d->device_uid,
            'name' => $d->name,
            'domain' => $d->domain,
            'category' => $d->category,
            'subcategory' => $d->subcategory,
            'manufacturer' => $d->manufacturer,
            'model' => $d->model,
            'status' => $d->status?->value,
            'health_status' => $d->health_status?->value,
            'provider' => $d->provider,
            'last_seen_at' => $d->last_seen_at?->toISOString(),
            'last_changed_at' => $d->updated_at?->toISOString(),
            'battery_level' => $d->battery_level,
            'assigned_to' => $activeAssignment
                ? $this->resolveAssignableName($activeAssignment)
                : null,
            'assignment_type' => $activeAssignment?->assignable_type,
            'monitor_count' => $monitorCount,
            'monitoring_state' => $monitoringState,
        ];
    }

    protected function resolveAssignableName(DeviceAssignment $assignment): string
    {
        $entity = $assignment->assignable();

        return match ($assignment->assignable_type) {
            'site' => $entity?->name ?? "Site #{$assignment->assignable_id}",
            'room' => $entity?->name ?? "Room #{$assignment->assignable_id}",
            'vehicle' => $entity?->name ?? "Vehicle #{$assignment->assignable_id}",
            'staff' => $entity?->name ?? "Staff #{$assignment->assignable_id}",
            'client' => $entity?->preferred_name
                ?: $entity?->first_name
                ?: "Client #{$assignment->assignable_id}",
            default => "{$assignment->assignable_type} #{$assignment->assignable_id}",
        };
    }

    /**
     * Apply common filters (status, health, provider, assigned, search) to a Device query.
     */
    protected function applyCommonFilters(Request $request, Builder $query): Builder
    {
        if ($request->filled('status') && $request->input('status') !== 'all') {
            $query->byStatus($request->input('status'));
        }

        if ($request->filled('health') && $request->input('health') !== 'all') {
            $query->byHealth($request->input('health'));
        }

        if ($request->filled('provider') && $request->input('provider') !== 'all') {
            $query->byProvider($request->input('provider'));
        }

        if ($request->filled('assigned') && $request->input('assigned') !== 'all') {
            if ($request->input('assigned') === 'yes') {
                $query->whereHas('assignments', fn ($q) => $q->active());
            } else {
                $query->whereDoesntHave('assignments', fn ($q) => $q->active());
            }
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('device_uid', 'like', "%{$search}%")
                    ->orWhere('serial_number', 'like', "%{$search}%")
                    ->orWhere('mac_address', 'like', "%{$search}%")
                    ->orWhere('imei', 'like', "%{$search}%")
                    ->orWhere('asset_tag', 'like', "%{$search}%")
                    ->orWhere('ip_address', 'like', "%{$search}%");
            });
        }

        return $query;
    }

    /**
     * Apply common sorting to a Device query.
     */
    protected function applyCommonSort(Request $request, Builder $query): Builder
    {
        $allowedSorts = ['name', 'device_uid', 'domain', 'category', 'status', 'health_status', 'last_seen_at'];
        $sort = $request->input('sort', 'name');
        $direction = $request->input('direction', 'asc');

        if (! in_array($sort, $allowedSorts)) {
            $sort = 'name';
        }
        if (! in_array($direction, ['asc', 'desc'])) {
            $direction = 'asc';
        }

        return $query->orderBy($sort, $direction);
    }
}
