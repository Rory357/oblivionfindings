<?php

namespace App\Domain\SecurityDevices\Http\Controllers;

use App\Domain\SecurityDevices\Enums\AssignmentType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Services\DeviceAssignmentService;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Models\ClientConsent;
use App\Services\ConsentValidationService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class DeviceAssignmentController extends Controller
{
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
            'consent_id' => ['nullable', 'integer', 'min:1'],
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

            $this->service->assign(
                device: $device,
                assignableType: $validated['assignable_type'],
                assignableId: $validated['assignable_id'],
                assignedByUserId: $user->id,
                assignmentType: AssignmentType::tryFrom($validated['assignment_type'] ?? 'permanent') ?? AssignmentType::Permanent,
                expectedReturnAt: isset($validated['expected_return_at']) ? new \DateTime($validated['expected_return_at']) : null,
                consentId: $validated['consent_id'] ?? null,
                notes: $validated['notes'] ?? null,
                authorizeLockedDevice: function (Device $lockedDevice) use ($user, $validated): void {
                    $this->access->assertCanViewDevice($user, $lockedDevice);
                    $this->access->assertCanManageActiveAssignment($user, $lockedDevice, true);
                    $this->access->assertCanAssignTarget(
                        $user,
                        $lockedDevice,
                        $validated['assignable_type'],
                        (int) $validated['assignable_id'],
                    );
                },
            );
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
            ->with('consentType')
            ->where('id', $consentId)
            ->where('client_id', $assignableId)
            ->first();

        if (! $consent || ! ConsentValidationService::isValidTrackingConsent($consent)) {
            throw new \InvalidArgumentException(
                'The chosen consent is not an active location-tracking consent for this client '
                .'(missing, withdrawn, superseded, expired, or recorded for another purpose).'
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

        $released = $this->service->release(
            $device,
            $user->id,
            function (Device $lockedDevice) use ($user): void {
                $this->access->assertCanViewDevice($user, $lockedDevice);
                $this->access->assertCanManageActiveAssignment($user, $lockedDevice, true);
            },
        );

        if (! $released) {
            return back()->with('info', 'Device has no active assignment to release.');
        }

        return back()->with('success', 'Device released to pool.');
    }
}
