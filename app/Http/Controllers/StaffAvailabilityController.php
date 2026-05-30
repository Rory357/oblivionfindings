<?php

namespace App\Http\Controllers;

use App\Models\StaffAvailability;
use App\Models\User;
use Illuminate\Http\Request;

class StaffAvailabilityController extends Controller
{
    public function index(Request $request, User $user)
    {
        $auth = $request->user();
        abort_unless($auth && ($auth->canDo('staff.availability.updateAny') || $auth->canDo('staff.availability.updateSelf')), 403);

        if ($user->id !== $auth->id && !$auth->canDo('staff.availability.updateAny')) {
            abort(403);
        }

        // Only staff users have availability
        abort_unless(User::staff()->whereKey($user->id)->exists(), 404);

        // Availability now lives inside Rostering as the single scheduler surface,
        // so schedulers/managers are sent to that consolidated tab (AvailabilityPane
        // + EditAvailabilityDialog post to this controller's store/destroy).
        //
        // Frontline staff (staff.availability.updateSelf but NOT rostering.viewAny)
        // would be bounced straight back to /my-day by RoleScope on that route, so
        // they keep this standalone self-service editor — their only way in.
        if ($auth->canDo('rostering.viewAny')) {
            return redirect()->route('operations.rostering.index', ['tab' => 'availability']);
        }

        $availability = StaffAvailability::query()
            ->where('user_id', $user->id)
            ->orderBy('day_of_week')
            ->orderBy('starts_at')
            ->get(['id', 'day_of_week', 'starts_at', 'ends_at']);

        $canManage = $user->id === $auth->id
            ? $auth->canDo('staff.availability.updateSelf')
            : $auth->canDo('staff.availability.updateAny');

        return inertia('staff/availability', [
            'user' => ['id' => $user->id, 'name' => $user->name, 'email' => $user->email],
            'availability' => $availability,
            'canManage' => $canManage,
        ]);
    }

    public function store(Request $request, User $user)
    {
        $auth = $request->user();
        abort_unless($auth && ($auth->canDo('staff.availability.updateAny') || $auth->canDo('staff.availability.updateSelf')), 403);

        if ($user->id !== $auth->id && !$auth->canDo('staff.availability.updateAny')) {
            abort(403);
        }

        abort_unless(User::staff()->whereKey($user->id)->exists(), 404);

        $data = $request->validate([
            'day_of_week' => ['required', 'integer', 'min:0', 'max:6'],
            'starts_at' => ['required', 'date_format:H:i'],
            'ends_at' => ['required', 'date_format:H:i', 'after:starts_at'],
        ]);

        StaffAvailability::create([
            'user_id' => $user->id,
            'day_of_week' => (int) $data['day_of_week'],
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
        ]);

        return back()->with('success', 'Availability added.');
    }

    public function destroy(Request $request, User $user, StaffAvailability $availability)
    {
        $auth = $request->user();
        abort_unless($auth && ($auth->canDo('staff.availability.updateAny') || $auth->canDo('staff.availability.updateSelf')), 403);

        if ($user->id !== $auth->id && !$auth->canDo('staff.availability.updateAny')) {
            abort(403);
        }

        abort_unless($availability->user_id === $user->id, 404);

        $availability->delete();
        return back()->with('success', 'Availability removed.');
    }
}
