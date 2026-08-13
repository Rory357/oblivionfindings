<?php

namespace App\Http\Controllers\ControlRoom;

use App\Http\Controllers\Concerns\RespondsToInertiaOrJson;
use App\Http\Controllers\Controller;
use App\Http\Controllers\ControlRoom\Concerns\AuthorizesControlRoomAlertAccess;
use App\Models\ControlRoom\AlertWatcher;
use App\Models\ControlRoomAlert;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ControlRoomWatcherController extends Controller
{
    use AuthorizesControlRoomAlertAccess;
    use RespondsToInertiaOrJson;

    /**
     * List watchers for an alert.
     */
    public function index(Request $request, ControlRoomAlert $alert)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);
        $this->assertCanAccessAlert($user, $alert);

        $watchers = AlertWatcher::where('alert_id', $alert->id)
            ->with('user:id,name,email')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (AlertWatcher $w) => [
                'id' => $w->id,
                'user_id' => $w->user_id,
                'user_name' => $w->user?->name,
                'user_email' => $w->user?->email,
                'added_by_user_id' => $w->added_by_user_id,
                'created_at' => $w->created_at?->toISOString(),
            ]);

        return response()->json(['watchers' => $watchers]);
    }

    /**
     * Add a watcher to an alert.
     */
    public function store(Request $request, ControlRoomAlert $alert)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);
        $this->assertCanAccessAlert($user, $alert);

        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);
        $this->assertCanUseWatcher($user, (int) $data['user_id']);

        // Check not already watching
        $exists = AlertWatcher::where('alert_id', $alert->id)
            ->where('user_id', $data['user_id'])
            ->exists();

        if ($exists) {
            if ($request->header('X-Inertia')) {
                return back()->withErrors(['alert' => 'User is already watching this alert.']);
            }

            return response()->json(['message' => 'User is already watching this alert.'], 422);
        }

        $watcher = AlertWatcher::create([
            'alert_id' => $alert->id,
            'user_id' => $data['user_id'],
            'added_by_user_id' => $user->id,
        ]);

        $alert->increment('watchers_count');

        AuditLogger::log('controlRoom.watcher.added', $alert, [
            'alert_id' => $alert->id,
            'watcher_user_id' => $data['user_id'],
        ]);

        if ($request->header('X-Inertia')) {
            return $this->inertiaOrJson($request, 'Watcher added.');
        }

        return response()->json(['watcher' => $watcher], 201);
    }

    /**
     * Toggle current user as watcher on an alert.
     */
    public function toggle(Request $request, ControlRoomAlert $alert)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);
        $this->assertCanAccessAlert($user, $alert);

        $existing = AlertWatcher::where('alert_id', $alert->id)
            ->where('user_id', $user->id)
            ->first();

        if ($existing) {
            $existing->delete();
            $alert->decrement('watchers_count');

            return $this->inertiaOrJson($request, 'Stopped watching this alert.', ['watching' => false]);
        }

        AlertWatcher::create([
            'alert_id' => $alert->id,
            'user_id' => $user->id,
            'added_by_user_id' => $user->id,
        ]);

        $alert->increment('watchers_count');

        return $this->inertiaOrJson($request, 'Watching this alert.', ['watching' => true]);
    }

    /**
     * Remove a watcher from an alert.
     */
    public function destroy(Request $request, ControlRoomAlert $alert, int $watcher)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);
        $watcherId = $watcher;
        $this->nestedAlertResources()->watcher($user, $alert, $watcherId);

        DB::transaction(function () use ($alert, $user, $watcherId): void {
            $locked = $this->nestedAlertResources()->watcher($user, $alert, $watcherId, true);
            $watcherUserId = $locked->user_id;

            $locked->delete();
            $locked->alert()->decrement('watchers_count');

            AuditLogger::log('controlRoom.watcher.removed', $alert, [
                'alert_id' => $alert->id,
                'watcher_user_id' => $watcherUserId,
            ]);
        }, 3);

        return $this->inertiaOrJson($request, 'Watcher removed.');
    }

    private function assertCanUseWatcher(User $user, int $watcherUserId): void
    {
        $this->assertCanAccessStaff(
            $user,
            $watcherUserId,
            'You are not authorized to add that staff member as a watcher.',
        );
    }
}
