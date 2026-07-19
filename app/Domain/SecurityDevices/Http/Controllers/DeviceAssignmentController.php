<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\SecurityDevices\Enums\AssignmentType;
use App\Domain\SecurityDevices\Http\Controllers\Concerns\MapsDevicesForList;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Services\DeviceAssignmentService;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\ClientConsent;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

class DeviceAssignmentController extends Controller
{
    use MapsDevicesForList;

    public function __construct(
        private readonly DeviceAssignmentService $service,
        private readonly SecurityDevicesAccessService $access,
    ) {}

    /**
     * Assign a device to an entity.
     */
    public function assign(Request $request, Device $device)
    {
        $user = $request->user();
        abort_unless($user->canDo('securityDevices.devices.assign'), 403);
        $this->access->assertCanViewDevice($user, $device);
        $this->access->assertCanManageActiveAssignment($user, $device);

        $validated = $request->validate([
            'assignable_type' => ['required', 'string', 'in:'.implode(',', DeviceAssignment::VALID_TARGETS)],
            'assignable_id' => ['required', 'integer', 'min:1'],
            'assignment_type' => ['nullable', 'string', 'in:permanent,temporary,loan,shared'],
            'expected_return_at' => ['nullable', 'date', 'after:today'],
            'consent_id' => ['nullable', 'integer', 'exists:client_consents,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $this->access->assertCanAssignTarget(
            $user,
            $device,
            $validated['assignable_type'],
            (int) $validated['assignable_id'],
        );

        try {
            $this->enforceConsentForClientTracker(
                $device,
                $validated['assignable_type'],
                (int) $validated['assignable_id'],
                isset($validated['consent_id']) ? (int) $validated['consent_id'] : null,
            );

            DB::transaction(function () use ($device, $user, $validated): void {
                $this->access->assertCanManageActiveAssignment($user, $device, true);
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
            });
        } catch (\InvalidArgumentException $e) {
            return back()->withErrors(['assignable_type' => $e->getMessage()]);
        }

        return back()->with('success', 'Device assigned successfully.');
    }

    /**
     * Reject client-tracker assignments that lack a valid ClientConsent.
     *
     * Applies when: assignable is a client AND the device is a tracking device
     * (domain='tracking'). A valid consent is one that belongs to this client,
     * status='given', and either has no expiry or hasn't expired yet.
     *
     * Enforced at controller level (not in the model) to avoid breaking unit
     * tests that build fixtures via DeviceAssignment::create() directly.
     */
    private function enforceConsentForClientTracker(
        Device $device,
        string $assignableType,
        int $assignableId,
        ?int $consentId,
    ): void {
        if ($assignableType !== DeviceAssignment::TARGET_CLIENT) {
            return;
        }

        if ($device->domain !== 'tracking') {
            return;
        }

        if (! $consentId) {
            throw new \InvalidArgumentException(
                'Assigning a tracking device to a client requires a valid consent. '
                .'Request consent via the family portal or record it in person before assigning.'
            );
        }

        $consent = ClientConsent::query()
            ->where('id', $consentId)
            ->where('client_id', $assignableId)
            ->where('status', 'given')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->first();

        if (! $consent) {
            throw new \InvalidArgumentException(
                'The chosen consent is not valid for this client (missing, not given, expired, '
                .'or belongs to a different client).'
            );
        }
    }

    /**
     * Release a device from its current assignment.
     */
    public function release(Request $request, Device $device)
    {
        $user = $request->user();
        abort_unless($user->canDo('securityDevices.devices.assign'), 403);
        $this->access->assertCanViewDevice($user, $device);
        $this->access->assertCanManageActiveAssignment($user, $device);

        $released = DB::transaction(function () use ($device, $user) {
            $this->access->assertCanManageActiveAssignment($user, $device, true);

            return $this->service->release($device, $user->id);
        });

        if (! $released) {
            return back()->with('info', 'Device has no active assignment to release.');
        }

        return back()->with('success', 'Device released to pool.');
    }

    /**
     * Return assignment history for a device (JSON for Inertia partial reload).
     */
    public function history(Request $request, Device $device)
    {
        $user = $request->user();
        abort_unless($user->canDo('securityDevices.devices.view'), 403);
        $this->access->assertCanViewDevice($user, $device);

        $visibleAssignments = $device->assignments()
            ->with(['assignedBy:id,name', 'releasedBy:id,name'])
            ->latest('assigned_at')
            ->get()
            ->filter(fn (DeviceAssignment $assignment): bool => $this->access->canAccessAssignmentTarget(
                $user,
                $device,
                $assignment->assignable_type,
                (int) $assignment->assignable_id,
            ))
            ->values();
        $page = max(1, $request->integer('page', 1));
        $assignments = new LengthAwarePaginator(
            $visibleAssignments->forPage($page, 20)->values(),
            $visibleAssignments->count(),
            20,
            $page,
            ['path' => $request->url(), 'query' => $request->query()],
        );

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
