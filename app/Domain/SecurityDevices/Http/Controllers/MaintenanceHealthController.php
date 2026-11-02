<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceMaintenanceRecord;
use App\Domain\SecurityDevices\Presenters\MaintenanceOperationsPresenter;
use App\Domain\SecurityDevices\Services\DeviceMaintenanceLifecycleService;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Recording device maintenance. S&D rebuild C6 (Merge): the maintenance page is gone (Devices ›
 * Upkeep); the old device profile (?section=maintenance) still records work through these.
 */
class MaintenanceHealthController extends Controller
{
    public function __construct(
        private readonly SecurityDevicesAccessService $access,
        private readonly DeviceMaintenanceLifecycleService $lifecycle,
    ) {}

    /**
     * Create a maintenance record for a device.
     */
    public function store(Request $request, Device $device)
    {
        $user = $request->user();
        abort_unless($user->canDo('securityDevices.maintenance.manage'), 403);
        $this->access->assertCanViewDevice($user, $device);

        $validated = $request->validate([
            'type' => ['required', 'string', 'in:'.implode(',', MaintenanceOperationsPresenter::TYPES)],
            'status' => ['nullable', 'string', 'in:scheduled,in_progress,completed,cancelled'],
            'description' => ['required', 'string', 'max:2000'],
            'scheduled_for' => ['nullable', 'date'],
            'completed_at' => ['nullable', 'date', 'before_or_equal:now'],
            'vendor_reference' => ['nullable', 'string', 'max:255'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->lifecycle->create($user, $device, $validated);

        return back()->with('success', 'Maintenance record created.');
    }

    /**
     * Update a maintenance record.
     */
    public function update(Request $request, DeviceMaintenanceRecord $record)
    {
        $user = $request->user();
        abort_unless($user->canDo('securityDevices.maintenance.manage'), 403);
        $record->loadMissing('device:id');
        abort_unless($record->device, 404);
        $this->access->assertCanViewDevice($user, $record->device);

        $validated = $request->validate([
            'type' => ['sometimes', 'string', 'in:'.implode(',', MaintenanceOperationsPresenter::TYPES)],
            'status' => ['sometimes', 'string', 'in:scheduled,in_progress,completed,cancelled'],
            'description' => ['sometimes', 'string', 'max:2000'],
            'scheduled_for' => ['nullable', 'date'],
            'completed_at' => ['nullable', 'date', 'before_or_equal:now'],
            'vendor_reference' => ['nullable', 'string', 'max:255'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->lifecycle->update($user, $record, $validated);

        return back()->with('success', 'Maintenance record updated.');
    }

    /**
     * Mark a maintenance record as completed.
     */
    public function complete(Request $request, DeviceMaintenanceRecord $record)
    {
        $user = $request->user();
        abort_unless($user->canDo('securityDevices.maintenance.manage'), 403);
        $record->loadMissing('device:id');
        abort_unless($record->device, 404);
        $this->access->assertCanViewDevice($user, $record->device);

        $this->lifecycle->complete($user, $record);

        return back()->with('success', 'Maintenance marked as complete.');
    }
}
