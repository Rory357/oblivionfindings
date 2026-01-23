<?php

namespace App\Http\Controllers;

use App\Models\StaffAvailability;
use App\Services\NotificationService;
use App\Models\User;
use Illuminate\Http\Request;

class StaffAvailabilityController extends Controller
{
    protected function canManage(Request $request, User $user): bool
    {
        $auth = $request->user();
        if (!$auth) {
            return false;
        }

        if ($auth->canDo('staff.availability.updateAny') || $auth->canDo('staff.update')) {
            return true;
        }

        return $auth->id === $user->id && $auth->canDo('staff.availability.updateSelf');
    }

    public function index(Request $request, User $user)
    {
        $auth = $request->user();
        abort_unless($auth, 403);

        // Staff can view their own; others require staff.viewAny
        if ($auth->id !== $user->id) {
            abort_unless($auth->canDo('staff.viewAny'), 403);
        }

        $rows = StaffAvailability::query()
            ->where('user_id', $user->id)
            ->orderBy('day_of_week')
            ->orderBy('starts_at')
            ->get();

        return inertia('staff/availability', [
            'user' => $user->only(['id', 'name', 'email']),
            'availability' => $rows,
            'canManage' => $this->canManage($request, $user),
        ]);
    }

    public function store(Request $request, User $user)
    {
        abort_unless($this->canManage($request, $user), 403);

        $data = $request->validate([
            'day_of_week' => ['required', 'integer', 'min:0', 'max:6'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i', 'after:starts_at'],
        ]);

        $availability = StaffAvailability::create(array_merge($data, ['user_id' => $user->id]));

        app(NotificationService::class)->notifyCrud($request->user(), 'created', 'staff availability', $availability, null, [
            'title' => 'Availability added',
            'url' => url("/staff/{$user->id}/availability"),
            'target_user_ids' => [$user->id],
        ]);

        return back()->with('success', 'Availability added.');
    }

    public function destroy(Request $request, User $user, StaffAvailability $availability)
    {
        abort_unless($this->canManage($request, $user), 403);
        abort_unless($availability->user_id === $user->id, 404);

        $availability->delete();

        app(NotificationService::class)->notifyCrud($request->user(), 'deleted', 'staff availability', $availability, null, [
            'title' => 'Availability removed',
            'url' => url("/staff/{$user->id}/availability"),
            'target_user_ids' => [$user->id],
        ]);

        return back()->with('success', 'Availability removed.');
    }
}
