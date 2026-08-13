<?php

namespace App\Http\Controllers\ControlRoom;

use App\Http\Controllers\Concerns\RespondsToInertiaOrJson;
use App\Http\Controllers\Controller;
use App\Http\Controllers\ControlRoom\Concerns\AuthorizesControlRoomAlertAccess;
use App\Models\ControlRoom\TimeEntry;
use App\Models\ControlRoomAlert;
use App\Services\AuditLogger;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ControlRoomTimeEntryController extends Controller
{
    use AuthorizesControlRoomAlertAccess;
    use RespondsToInertiaOrJson;

    /**
     * List time entries for an alert, including any running entry for the current user.
     */
    public function index(Request $request, ControlRoomAlert $alert)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);
        $this->assertCanAccessAlert($user, $alert);

        $entries = TimeEntry::where('alert_id', $alert->id)
            ->with('user:id,name,email')
            ->orderByDesc('started_at')
            ->get()
            ->map(fn (TimeEntry $e) => [
                'id' => $e->id,
                'user_id' => $e->user_id,
                'user_name' => $e->user?->name,
                'task_id' => $e->task_id,
                'started_at' => $e->started_at?->toISOString(),
                'ended_at' => $e->ended_at?->toISOString(),
                'duration_minutes' => $e->duration_minutes,
                'description' => $e->description,
                'billable' => $e->billable,
                'is_running' => $e->isRunning(),
            ]);

        // Find the running entry for the current user on this alert
        $runningEntry = TimeEntry::where('alert_id', $alert->id)
            ->where('user_id', $user->id)
            ->whereNull('ended_at')
            ->first();

        return response()->json([
            'entries' => $entries,
            'running_entry' => $runningEntry ? [
                'id' => $runningEntry->id,
                'started_at' => $runningEntry->started_at?->toISOString(),
                'description' => $runningEntry->description,
            ] : null,
        ]);
    }

    /**
     * Start a timer for the current user on an alert.
     */
    public function start(Request $request, ControlRoomAlert $alert)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);
        $this->assertCanAccessAlert($user, $alert);

        // Check no running entry for this user on this alert
        $running = TimeEntry::where('alert_id', $alert->id)
            ->where('user_id', $user->id)
            ->whereNull('ended_at')
            ->exists();

        if ($running) {
            if ($request->header('X-Inertia')) {
                return back()->withErrors(['alert' => 'You already have a running timer on this alert.']);
            }

            return response()->json(['message' => 'You already have a running timer on this alert.'], 422);
        }

        $entry = TimeEntry::create([
            'alert_id' => $alert->id,
            'user_id' => $user->id,
            'started_at' => now(),
        ]);

        if ($request->header('X-Inertia')) {
            return $this->inertiaOrJson($request, 'Timer started.');
        }

        return response()->json([
            'entry' => [
                'id' => $entry->id,
                'started_at' => $entry->started_at->toISOString(),
            ],
        ], 201);
    }

    /**
     * Stop a running timer.
     */
    public function stop(Request $request, ControlRoomAlert $alert, int $entry)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);
        $entryId = $entry;
        $entry = $this->nestedAlertResources()->timeEntry($user, $alert, $entryId);

        if (! $entry->isRunning()) {
            if ($request->header('X-Inertia')) {
                return back()->withErrors(['alert' => 'This time entry is not running.']);
            }

            return response()->json(['message' => 'This time entry is not running.'], 422);
        }

        $data = $request->validate([
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $entry = DB::transaction(function () use ($alert, $data, $entryId, $user): TimeEntry {
            $locked = $this->nestedAlertResources()->timeEntry($user, $alert, $entryId, true);
            if (! $locked->isRunning()) {
                throw ValidationException::withMessages([
                    'alert' => 'This time entry is not running.',
                ]);
            }

            $endedAt = now();
            $durationMinutes = (int) round($locked->started_at->diffInMinutes($endedAt));
            $locked->update([
                'ended_at' => $endedAt,
                'duration_minutes' => max($durationMinutes, 1),
                'description' => $data['description'] ?? $locked->description,
            ]);

            $this->updateAlertTimeSpent($alert->id);
            AuditLogger::log('controlRoom.timeEntry.stopped', $alert, [
                'alert_id' => $alert->id,
                'entry_id' => $locked->id,
                'duration_minutes' => $locked->duration_minutes,
            ]);

            return $locked;
        }, 3);

        if ($request->header('X-Inertia')) {
            return $this->inertiaOrJson($request, "Timer stopped — {$entry->duration_minutes} min logged.");
        }

        return response()->json([
            'entry' => [
                'id' => $entry->id,
                'started_at' => $entry->started_at->toISOString(),
                'ended_at' => $entry->ended_at->toISOString(),
                'duration_minutes' => $entry->duration_minutes,
            ],
        ]);
    }

    /**
     * Create a manual time entry.
     */
    public function store(Request $request, ControlRoomAlert $alert)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);
        $this->assertCanAccessAlert($user, $alert);

        $data = $request->validate([
            'duration_minutes' => ['required', 'integer', 'min:1'],
            'description' => ['nullable', 'string', 'max:1000'],
            'task_id' => [
                'nullable',
                'integer',
                Rule::exists('control_room_alert_tasks', 'id')
                    ->where(fn ($query) => $query->where('alert_id', $alert->id)),
            ],
            'started_at' => ['nullable', 'date'],
        ]);

        $startedAt = isset($data['started_at'])
            ? Carbon::parse($data['started_at'])
            : now()->subMinutes($data['duration_minutes']);

        $endedAt = $startedAt->copy()->addMinutes($data['duration_minutes']);

        $entry = TimeEntry::create([
            'alert_id' => $alert->id,
            'user_id' => $user->id,
            'task_id' => $data['task_id'] ?? null,
            'started_at' => $startedAt,
            'ended_at' => $endedAt,
            'duration_minutes' => $data['duration_minutes'],
            'description' => $data['description'] ?? null,
        ]);

        // Update alert aggregate
        $this->updateAlertTimeSpent($alert->id);

        AuditLogger::log('controlRoom.timeEntry.created', $alert, [
            'alert_id' => $alert->id,
            'entry_id' => $entry->id,
            'duration_minutes' => $data['duration_minutes'],
        ]);

        if ($request->header('X-Inertia')) {
            return $this->inertiaOrJson($request, 'Time logged.');
        }

        return response()->json(['entry' => $entry], 201);
    }

    /**
     * Delete a time entry.
     */
    public function destroy(Request $request, ControlRoomAlert $alert, int $entry)
    {
        $user = $request->user();
        abort_unless($user && $user->canDo('controlRoom.alerts.manage'), 403);
        $entryId = $entry;
        $this->nestedAlertResources()->timeEntry($user, $alert, $entryId);

        DB::transaction(function () use ($alert, $entryId, $user): void {
            $locked = $this->nestedAlertResources()->timeEntry($user, $alert, $entryId, true);
            $locked->delete();
            $this->updateAlertTimeSpent($alert->id);

            AuditLogger::log('controlRoom.timeEntry.deleted', $alert, [
                'alert_id' => $alert->id,
                'entry_id' => $entryId,
            ]);
        }, 3);

        if ($request->header('X-Inertia')) {
            return $this->inertiaOrJson($request, 'Time entry deleted.');
        }

        return response()->json(['message' => 'Time entry deleted.']);
    }

    /* ------------------------------------------------------------------
     * Private helpers
     * ---------------------------------------------------------------- */

    /**
     * Recalculate and update the alert's total time_spent_minutes.
     */
    private function updateAlertTimeSpent(int $alertId): void
    {
        $total = TimeEntry::where('alert_id', $alertId)
            ->whereNotNull('ended_at')
            ->sum('duration_minutes');

        ControlRoomAlert::where('id', $alertId)
            ->update(['time_spent_minutes' => (int) $total]);
    }
}
