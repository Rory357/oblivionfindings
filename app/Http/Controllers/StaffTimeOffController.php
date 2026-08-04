<?php

namespace App\Http\Controllers;

use App\Domain\Hr\Services\HrLeaveAccessService;
use App\Domain\Hr\Services\LeaveService;
use App\Models\StaffTimeOff;
use Illuminate\Http\Request;

class StaffTimeOffController extends Controller
{
    public function store(
        Request $request,
        LeaveService $leaveService,
        HrLeaveAccessService $leaveAccess,
    ) {
        $auth = $request->user();
        abort_unless($auth && ($auth->canDo('staff.availability.updateAny') || $auth->canDo('staff.availability.updateSelf')), 403);

        $data = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'type' => ['required', 'in:leave,unavailable,training'],
            'leave_type' => ['nullable', 'string', 'in:'.implode(',', LeaveService::LEAVE_TYPES)],
            'label' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
            'return_to' => ['nullable', 'string'],
        ]);

        $userId = $data['user_id'] ?? $auth->id;
        if ($userId !== $auth->id && ! $auth->canDo('staff.availability.updateAny')) {
            abort(403);
        }

        $target = $leaveAccess->currentSubject($auth, (int) $userId);

        $returnTo = $data['return_to'] ?? route('operations.rostering.index');

        // Roster-entered LEAVE routes through the leave engine so balances and ledger entries are
        // written and HR can see it. unavailable/training stay roster-only (no balance impact).
        if ($data['type'] === 'leave') {
            try {
                $leaveService->createRosterLeave($target, $data, $auth);
            } catch (\InvalidArgumentException $e) {
                return redirect($returnTo)->with('error', $e->getMessage());
            }

            return redirect($returnTo)->with('success', 'Leave recorded and synced to the staff member’s HR balance.');
        }

        StaffTimeOff::create([
            'user_id' => $target->id,
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'type' => $data['type'],
            'label' => $data['label'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $auth->id,
        ]);

        return redirect($returnTo)->with('success', 'Time off saved.');
    }

    public function destroy(
        Request $request,
        StaffTimeOff $staffTimeOff,
        HrLeaveAccessService $leaveAccess,
    ) {
        $auth = $request->user();
        abort_unless($auth && ($auth->canDo('staff.availability.updateAny') || $auth->canDo('staff.availability.updateSelf')), 403);

        if ($staffTimeOff->user_id !== $auth->id && ! $auth->canDo('staff.availability.updateAny')) {
            abort(403);
        }
        $leaveAccess->currentSubject($auth, (int) $staffTimeOff->user_id);

        $returnTo = $request->input('return_to') ?: route('operations.rostering.index');

        // Don't let a roster-side delete silently desync an approved leave request's balance.
        if ($staffTimeOff->hr_leave_request_id) {
            return redirect($returnTo)->with('error', 'This time off comes from an approved leave request — cancel it from the Leave module so the balance stays correct.');
        }

        $staffTimeOff->delete();

        return redirect($returnTo)->with('success', 'Time off deleted.');
    }
}
