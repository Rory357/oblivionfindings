<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Enums\HealthStatus;
use App\Domain\SecurityDevices\Http\Controllers\Concerns\ResolvesDeviceTenant;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceMaintenanceRecord;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Inertia\Inertia;

class MaintenanceHealthController extends Controller
{
    use ResolvesDeviceTenant;

    /**
     * Maintenance & Health dashboard page.
     */
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user->canDo('securityDevices.maintenance.view'), 403);
        $tenantId = $this->resolveDeviceTenantId($user);

        // ── Stats ─────────────────────────────────────────────────

        $stats = [
            'overdue' => DeviceMaintenanceRecord::query()->forTenant($tenantId)->overdue()->count(),
            'upcoming' => DeviceMaintenanceRecord::query()->forTenant($tenantId)->upcoming(14)->count(),
            'offline' => Device::query()->forTenant($tenantId)->where('status', DeviceStatus::Offline->value)->count(),
            'degraded' => Device::query()->forTenant($tenantId)->where('status', DeviceStatus::Degraded->value)->count(),
            'lowBattery' => Device::query()->forTenant($tenantId)->lowBattery()->count(),
            'critical' => Device::query()->forTenant($tenantId)->where('health_status', HealthStatus::Critical->value)->count(),
        ];

        // ── Maintenance records (filterable) ──────────────────────

        $mQuery = DeviceMaintenanceRecord::query()
            ->forTenant($tenantId)
            ->with(['device:id,name,device_uid,domain,category', 'performedBy:id,name']);

        if ($request->filled('status') && $request->input('status') !== 'all') {
            $mQuery->where('status', $request->input('status'));
        }

        if ($request->filled('type') && $request->input('type') !== 'all') {
            $mQuery->where('type', $request->input('type'));
        }

        if ($request->filled('search')) {
            $search = $request->input('search');
            $mQuery->where(function ($q) use ($search) {
                $q->where('description', 'like', "%{$search}%")
                    ->orWhere('vendor_reference', 'like', "%{$search}%")
                    ->orWhereHas('device', function ($dq) use ($search) {
                        $dq->where('name', 'like', "%{$search}%")
                            ->orWhere('device_uid', 'like', "%{$search}%");
                    });
            });
        }

        // Default: show overdue and scheduled first, then recent completed.
        $sort = $request->input('sort', 'scheduled_for');
        $direction = $request->input('direction', 'asc');
        $allowedSorts = ['scheduled_for', 'completed_at', 'type', 'status', 'created_at'];
        if (! in_array($sort, $allowedSorts)) {
            $sort = 'scheduled_for';
        }
        if (! in_array($direction, ['asc', 'desc'])) {
            $direction = 'asc';
        }
        $mQuery->orderBy($sort, $direction);

        $records = $mQuery->paginate(30)->withQueryString();

        // ── Devices needing health attention ──────────────────────

        $attentionDevices = Device::query()
            ->forTenant($tenantId)
            ->needingAttention()
            ->with(['assignments' => fn ($q) => $q->active()])
            ->orderByRaw("FIELD(health_status, 'critical', 'warning', 'unknown', 'healthy')")
            ->limit(20)
            ->get()
            ->map(fn (Device $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'device_uid' => $d->device_uid,
                'domain' => $d->domain,
                'category' => $d->category,
                'status' => $d->status?->value,
                'health_status' => $d->health_status?->value,
                'battery_level' => $d->battery_level,
                'last_seen_at' => $d->last_seen_at?->toISOString(),
            ]);

        // ── Low battery devices ───────────────────────────────────

        $lowBatteryDevices = Device::query()
            ->forTenant($tenantId)
            ->lowBattery()
            ->operational()
            ->orderBy('battery_level')
            ->limit(15)
            ->get()
            ->map(fn (Device $d) => [
                'id' => $d->id,
                'name' => $d->name,
                'device_uid' => $d->device_uid,
                'battery_level' => $d->battery_level,
                'battery_updated_at' => $d->battery_updated_at?->toISOString(),
            ]);

        return Inertia::render('security-devices/maintenance-health', [
            'pageMeta' => $request->routeIs('security-devices.maintenance')
                ? [
                    'title' => 'Maintenance',
                    'description' => 'Device servicing, calibration, repair, and health-related work across the estate.',
                    'href' => '/security-devices/maintenance',
                ]
                : [
                    'title' => 'Maintenance & Health',
                    'description' => 'Device maintenance scheduling, health monitoring, and operational attention tracking.',
                    'href' => '/security-devices/maintenance-health',
                ],
            'stats' => $stats,
            'records' => [
                'data' => $records->getCollection()->map(fn (DeviceMaintenanceRecord $r) => [
                    'id' => $r->id,
                    'device_id' => $r->device_id,
                    'device_name' => $r->device?->name,
                    'device_uid' => $r->device?->device_uid,
                    'type' => $r->type,
                    'status' => $r->status,
                    'description' => $r->description,
                    'scheduled_for' => $r->scheduled_for?->toDateString(),
                    'completed_at' => $r->completed_at?->toISOString(),
                    'performed_by' => $r->performedBy?->name,
                    'vendor_reference' => $r->vendor_reference,
                    'cost' => $r->cost,
                    'notes' => $r->notes,
                    'is_overdue' => $r->status === 'scheduled' && $r->scheduled_for && $r->scheduled_for->isPast(),
                ]),
                'links' => $records->linkCollection()->toArray(),
                'meta' => [
                    'current_page' => $records->currentPage(),
                    'last_page' => $records->lastPage(),
                    'total' => $records->total(),
                ],
            ],
            'attentionDevices' => $attentionDevices,
            'lowBatteryDevices' => $lowBatteryDevices,
            'filters' => $request->only(['status', 'type', 'search', 'sort', 'direction']),
            'can' => [
                'manage' => $user->canDo('securityDevices.maintenance.manage'),
            ],
        ]);
    }

    /**
     * Create a maintenance record for a device.
     */
    public function store(Request $request, Device $device)
    {
        $user = $request->user();
        abort_unless($user->canDo('securityDevices.maintenance.manage'), 403);
        abort_unless((int) $device->tenant_id === $this->resolveDeviceTenantId($user), 404);

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:scheduled_service,repair,firmware_update,inspection,replacement,calibration,connectivity_check,battery_replacement'],
            'status' => ['nullable', 'string', 'in:scheduled,in_progress,completed,cancelled'],
            'description' => ['required', 'string', 'max:2000'],
            'scheduled_for' => ['nullable', 'date'],
            'completed_at' => ['nullable', 'date'],
            'vendor_reference' => ['nullable', 'string', 'max:255'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $validated['device_id'] = $device->id;
        $validated['status'] = $validated['status'] ?? 'scheduled';

        if (($validated['status'] ?? '') === 'completed' && empty($validated['completed_at'])) {
            $validated['completed_at'] = now();
        }
        if (($validated['status'] ?? '') === 'completed') {
            $validated['performed_by_user_id'] = $user->id;
        }

        DeviceMaintenanceRecord::create($validated);

        return back()->with('success', 'Maintenance record created.');
    }

    /**
     * Update a maintenance record.
     */
    public function update(Request $request, DeviceMaintenanceRecord $record)
    {
        $user = $request->user();
        abort_unless($user->canDo('securityDevices.maintenance.manage'), 403);
        $record->loadMissing('device:id,tenant_id');
        abort_unless($record->device && (int) $record->device->tenant_id === $this->resolveDeviceTenantId($user), 404);

        $validated = $request->validate([
            'type' => ['sometimes', 'string', 'in:scheduled_service,repair,firmware_update,inspection,replacement,calibration,connectivity_check,battery_replacement'],
            'status' => ['sometimes', 'string', 'in:scheduled,in_progress,completed,cancelled'],
            'description' => ['sometimes', 'string', 'max:2000'],
            'scheduled_for' => ['nullable', 'date'],
            'completed_at' => ['nullable', 'date'],
            'vendor_reference' => ['nullable', 'string', 'max:255'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        if (($validated['status'] ?? '') === 'completed' && empty($validated['completed_at'])) {
            $validated['completed_at'] = now();
        }
        if (($validated['status'] ?? '') === 'completed' && ! $record->performed_by_user_id) {
            $validated['performed_by_user_id'] = $user->id;
        }

        $record->update($validated);

        return back()->with('success', 'Maintenance record updated.');
    }

    /**
     * Mark a maintenance record as completed.
     */
    public function complete(Request $request, DeviceMaintenanceRecord $record)
    {
        $user = $request->user();
        abort_unless($user->canDo('securityDevices.maintenance.manage'), 403);
        $record->loadMissing('device:id,tenant_id');
        abort_unless($record->device && (int) $record->device->tenant_id === $this->resolveDeviceTenantId($user), 404);

        $record->update([
            'status' => 'completed',
            'completed_at' => now(),
            'performed_by_user_id' => $user->id,
        ]);

        return back()->with('success', 'Maintenance marked as complete.');
    }
}
