<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\SecurityDevices\Enums\AssignmentType;
use App\Domain\SecurityDevices\Http\Controllers\Concerns\MapsDevicesForList;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Services\DeviceAssignmentService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class DeviceAssignmentController extends Controller
{
    use MapsDevicesForList;

    public function __construct(
        private readonly DeviceAssignmentService $service,
    ) {}

    /**
     * Assign a device to an entity.
     */
    public function assign(Request $request, Device $device)
    {
        $user = $request->user();
        abort_unless($user->canDo('securityDevices.devices.assign'), 403);

        $validated = $request->validate([
            'assignable_type' => ['required', 'string', 'in:' . implode(',', DeviceAssignment::VALID_TARGETS)],
            'assignable_id' => ['required', 'integer', 'min:1'],
            'assignment_type' => ['nullable', 'string', 'in:permanent,temporary,loan,shared'],
            'expected_return_at' => ['nullable', 'date', 'after:today'],
            'consent_id' => ['nullable', 'integer', 'exists:client_consents,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->service->assign(
                device: $device,
                assignableType: $validated['assignable_type'],
                assignableId: $validated['assignable_id'],
                assignedByUserId: $user->id,
                assignmentType: AssignmentType::tryFrom($validated['assignment_type'] ?? 'permanent') ?? AssignmentType::Permanent,
                expectedReturnAt: isset($validated['expected_return_at']) ? new \DateTime($validated['expected_return_at']) : null,
                consentId: $validated['consent_id'] ?? null,
                notes: $validated['notes'] ?? null,
            );
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['assignable_type' => $e->getMessage()]);
        }

        return back()->with('success', "Device assigned successfully.");
    }

    /**
     * Release a device from its current assignment.
     */
    public function release(Request $request, Device $device)
    {
        $user = $request->user();
        abort_unless($user->canDo('securityDevices.devices.assign'), 403);

        $released = $this->service->release($device, $user->id);

        if (!$released) {
            return back()->with('info', 'Device has no active assignment to release.');
        }

        return back()->with('success', "Device released to pool.");
    }

    /**
     * Return assignment history for a device (JSON for Inertia partial reload).
     */
    public function history(Request $request, Device $device)
    {
        $user = $request->user();
        abort_unless($user->canDo('securityDevices.devices.view'), 403);

        $assignments = $device->assignments()
            ->with(['assignedBy:id,name', 'releasedBy:id,name'])
            ->latest('assigned_at')
            ->paginate(20)
            ->withQueryString();

        return response()->json([
            'data' => $assignments->getCollection()->map(fn (DeviceAssignment $a) => [
                'id' => $a->id,
                'assignable_type' => $a->assignable_type,
                'assignable_id' => $a->assignable_id,
                'assignable_name' => $this->resolveAssignableName($a),
                'assignment_type' => $a->assignment_type?->value,
                'assigned_at' => $a->assigned_at?->toISOString(),
                'expected_return_at' => $a->expected_return_at?->toISOString(),
                'released_at' => $a->released_at?->toISOString(),
                'assigned_by' => $a->assignedBy ? ['id' => $a->assignedBy->id, 'name' => $a->assignedBy->name] : null,
                'released_by' => $a->releasedBy ? ['id' => $a->releasedBy->id, 'name' => $a->releasedBy->name] : null,
                'consent_id' => $a->consent_id,
                'notes' => $a->notes,
                'is_active' => $a->isActive(),
                'is_overdue' => $a->isOverdue(),
            ]),
            'meta' => [
                'current_page' => $assignments->currentPage(),
                'last_page' => $assignments->lastPage(),
                'total' => $assignments->total(),
            ],
        ]);
    }
}
