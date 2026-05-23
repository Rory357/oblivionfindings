<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ControlRoomAlert;
use App\Models\Shift;
use App\Models\ShiftTask;
use App\Models\Timesheet;
use App\Models\TimesheetClientAllocation;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Legacy My Day helpers — now trimmed to the two safe, still-used actions:
 * completing a shift task and submitting a timesheet draft.
 *
 * The old shortcut `clockIn`/`clockOut` methods were removed in PR 4.5 so
 * the frontline clock flow has a single trusted path through
 * {@see \App\Http\Controllers\AttendanceController} + {@see \App\Domain\Hr\Services\AttendanceService}.
 * Do not re-add quick-clock endpoints here.
 */
class MyDayActionsController extends Controller
{
    public function completeShiftTask(Request $request, ShiftTask $task)
    {
        abort_unless($request->user(), 403);
        // Verify the task belongs to a shift assigned to this user
        abort_unless($task->shift && $task->shift->user_id === $request->user()->id, 403);

        if ($task->is_completed) {
            $task->update([
                'is_completed' => false,
                'completed_at' => null,
                'completed_by' => null,
            ]);
        } else {
            $task->update([
                'is_completed' => true,
                'completed_at' => now(),
                'completed_by' => $request->user()->id,
            ]);
        }

        return back()->with('success', $task->is_completed ? 'Task completed.' : 'Task reopened.');
    }

    /**
     * Find-or-create today's draft timesheet for the worker so the /my-day
     * "Today's timesheet" button can open the review popup immediately, even
     * before the worker has clocked out.
     *
     * Historically a Timesheet row was only written by AttendanceService on
     * clock-out, which meant clicking "Today's timesheet" mid-shift had
     * nothing to show. The popup-driven UX needs an existing draft, so this
     * endpoint:
     *
     *   1. Locates the worker's active (in-progress) shift for today.
     *   2. Looks up the (shift_id, user_id) timesheet — Timesheet enforces a
     *      unique pair, so AttendanceService's eventual clock-out call will
     *      update the SAME row instead of conflicting.
     *   3. Creates the row with the shift's planned start/end/break if it
     *      doesn't exist yet.
     *   4. Flashes `open_timesheet_id` so the front-end knows which draft to
     *      open in the popup once props refresh.
     */
    public function ensureTodayTimesheet(Request $request)
    {
        $user = $request->user();
        abort_unless($user, 403);
        // Mirror the timesheet-create permission already required by the
        // canonical `POST /operations/timesheets` endpoint. Without this gate
        // a worker who has lost timesheets.create can still mint today's
        // draft via the /my-day popup path.
        abort_unless($user->canDo('timesheets.create'), 403);

        $tz = config('app.worker_timezone', 'Pacific/Auckland');
        $now = Carbon::now($tz);
        $today = $now->copy()->startOfDay()->utc();
        $end = $now->copy()->endOfDay()->utc();

        $shift = Shift::query()
            ->where('user_id', $user->id)
            ->whereBetween('starts_at', [$today, $end])
            ->whereNotIn('status', ['cancelled'])
            ->orderBy('starts_at')
            ->first();

        if (! $shift) {
            return back()->withErrors([
                'timesheet' => 'No shift today to write a timesheet against.',
            ]);
        }

        $timesheet = Timesheet::query()
            ->where('shift_id', $shift->id)
            ->where('user_id', $user->id)
            ->first();

        if (! $timesheet) {
            $client = $shift->client;
            $timesheet = Timesheet::query()->create([
                'user_id' => $user->id,
                'client_id' => $shift->client_id,
                'shift_id' => $shift->id,
                'shift_site_id' => $shift->site_id,
                'shift_service_context_id' => $shift->service_context_id,
                'work_date' => $shift->starts_at?->copy()->timezone($tz)->toDateString()
                    ?? $now->toDateString(),
                'starts_at' => $shift->starts_at,
                'ends_at' => $shift->ends_at,
                'break_minutes' => (int) ($shift->expected_break_minutes ?? 30),
                'status' => 'draft',
                'is_residential_billable' => false,
                'created_by' => $user->id,
                'shift_site_name_snapshot' => $shift->site?->name,
                'shift_location_snapshot' => $shift->location,
                'service_context_name_snapshot' => $shift->serviceContext?->name,
                'client_name_snapshot' => $client
                    ? trim($client->first_name.' '.$client->last_name)
                    : null,
                'staff_name_snapshot' => $user->name,
                'shift_type_snapshot' => $shift->shift_type ?? 'standard',
            ]);

            AuditLogger::log('timesheet.draft.ensure', $timesheet, [
                'timesheet_id' => $timesheet->id,
                'shift_id' => $shift->id,
                'created' => true,
            ]);
        }

        // The front-end watches for this flash key and opens the popup with
        // the matching timesheet from `props.timesheets` once it refreshes.
        return back()->with('open_timesheet_id', $timesheet->id);
    }

    /**
     * Submit a worker's timesheet for approval.
     *
     * Accepts an optional `client_allocations` array that lets the worker
     * attribute the timesheet's total hours across multiple clients (for
     * residential houses, group activity shifts, weighted shared support,
     * etc.). The validation enforces:
     *  - every client_id is one of the shift's allowed clients
     *  - sum of `hours` equals the timesheet's total hours within 0.01h
     *  - allocation_method is one of TimesheetClientAllocation::METHODS
     *  - time_segmented rows carry both starts_at and ends_at
     *
     * Allocation rows are saved transactionally with the status flip so a
     * mid-flight failure can't leave the timesheet submitted-but-unallocated.
     */
    public function submitTimesheet(Request $request, Timesheet $timesheet)
    {
        $user = $request->user();
        abort_unless($user, 403);
        // Match the canonical operations.timesheets.submit gate. A worker
        // whose timesheets.submit was revoked must not be able to submit via
        // the /my-day popup path either.
        abort_unless($user->canDo('timesheets.submit'), 403);
        abort_unless($timesheet->user_id === $user->id, 403);
        abort_unless(in_array($timesheet->status, ['draft', 'returned']), 422);

        $allocations = $this->validateAllocations($request, $timesheet);

        DB::transaction(function () use ($user, $timesheet, $allocations): void {
            if ($allocations !== null) {
                $this->persistAllocations($timesheet, $allocations);
            }

            // Mirror TimesheetApprovalService::submittedFields(): clear the
            // returned/approval metadata so a returned → submitted cycle
            // leaves a clean state for the manager re-reviewing it.
            $timesheet->update([
                'status' => 'submitted',
                'submitted_at' => now(),
                'submitted_by' => $user->id,
                'approved_by' => null,
                'approved_at' => null,
                'decision_notes' => null,
                'returned_at' => null,
                'returned_by' => null,
                'returned_notes' => null,
            ]);
        });

        AuditLogger::log('timesheet.submit', $timesheet, [
            'timesheet_id' => $timesheet->id,
            'allocation_count' => $allocations !== null ? count($allocations) : null,
            'allocation_method' => $allocations[0]['allocation_method'] ?? null,
        ]);

        return back()->with('success', 'Timesheet submitted for approval.');
    }

    /**
     * Validate the worker's submitted allocation payload against the
     * timesheet's total hours and the shift's eligible client roster.
     *
     * Returns the normalised allocation array (ready to upsert) or `null`
     * when the request didn't include `client_allocations` at all — that
     * way an old client / mobile app calling without allocations still
     * works exactly like before.
     *
     * @return array<int, array<string, mixed>>|null
     */
    private function validateAllocations(Request $request, Timesheet $timesheet): ?array
    {
        if (! $request->has('client_allocations')) {
            return null;
        }

        $data = $request->validate([
            'client_allocations' => ['array', 'min:1'],
            'client_allocations.*.client_id' => ['required', 'integer', 'exists:clients,id'],
            'client_allocations.*.hours' => ['required', 'numeric', 'min:0', 'max:24'],
            'client_allocations.*.allocation_method' => ['required', 'string', 'in:'.implode(',', TimesheetClientAllocation::METHODS)],
            'client_allocations.*.starts_at' => ['nullable', 'date'],
            'client_allocations.*.ends_at' => ['nullable', 'date', 'after_or_equal:client_allocations.*.starts_at'],
            'client_allocations.*.notes' => ['nullable', 'string', 'max:2000'],
            'client_allocations.*.sort_order' => ['nullable', 'integer'],
        ]);

        $allocations = collect($data['client_allocations'])->values();
        if ($allocations->isEmpty()) {
            throw ValidationException::withMessages([
                'client_allocations' => 'At least one client allocation is required.',
            ]);
        }

        // No duplicate client_id allocations on the same timesheet.
        $clientIds = $allocations->pluck('client_id')->map(fn ($id) => (int) $id);
        if ($clientIds->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages([
                'client_allocations' => 'Each client can only appear once in the allocation breakdown.',
            ]);
        }

        // Every client must be on the shift's eligible roster: the shift's
        // primary client, the site's residents, and any explicit shift_clients
        // pivot rows. Workers can't attribute time to clients they're not
        // rostered with.
        $allowedClientIds = $this->allowedClientIdsForTimesheet($timesheet);
        $stray = $clientIds->diff($allowedClientIds);
        if ($stray->isNotEmpty()) {
            throw ValidationException::withMessages([
                'client_allocations' => 'You can only attribute time to clients on this shift.',
            ]);
        }

        // Sum of hours must match the timesheet total within rounding tolerance.
        // Residential houses divide the same total across residents (equal
        // split), so the sum still equals total_hours — no bypass needed.
        $totalAllocated = (float) $allocations->sum(fn ($a) => (float) $a['hours']);
        $timesheetHours = (float) $timesheet->total_hours;

        if (abs($totalAllocated - $timesheetHours) > 0.02) {
            throw ValidationException::withMessages([
                'client_allocations' => sprintf(
                    'Allocations total %.2fh but the timesheet is %.2fh.',
                    $totalAllocated,
                    $timesheetHours,
                ),
            ]);
        }

        // time_segmented rows need both ends populated; the other methods
        // can leave them null.
        foreach ($allocations as $i => $row) {
            if (($row['allocation_method'] ?? null) === TimesheetClientAllocation::METHOD_TIME_SEGMENTED) {
                if (empty($row['starts_at']) || empty($row['ends_at'])) {
                    throw ValidationException::withMessages([
                        "client_allocations.{$i}.starts_at" => 'Start and end times are required for time-segmented allocations.',
                    ]);
                }
            }
        }

        return $allocations->values()->map(fn ($row, $i) => [
            'client_id' => (int) $row['client_id'],
            'hours' => round((float) $row['hours'], 2),
            'allocation_method' => (string) $row['allocation_method'],
            'starts_at' => isset($row['starts_at']) ? Carbon::parse($row['starts_at']) : null,
            'ends_at' => isset($row['ends_at']) ? Carbon::parse($row['ends_at']) : null,
            'notes' => $row['notes'] ?? null,
            'sort_order' => (int) ($row['sort_order'] ?? $i),
        ])->all();
    }

    /**
     * Returns the set of client_ids this worker may attribute time to on the
     * given timesheet. Combines:
     *   - the shift's primary client
     *   - all residents at the shift's site (residential setting)
     *   - any explicit shift_clients pivot rows (group shift schema)
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function allowedClientIdsForTimesheet(Timesheet $timesheet): \Illuminate\Support\Collection
    {
        $ids = collect();
        if ($timesheet->client_id) {
            $ids->push((int) $timesheet->client_id);
        }

        $timesheet->loadMissing('shift.client', 'shift.site.clients');
        $shift = $timesheet->shift;
        if ($shift) {
            if ($shift->client_id) {
                $ids->push((int) $shift->client_id);
            }
            // Residents at the same site: residential houses share a roster.
            $siteClients = $shift->site?->clients ?? collect();
            foreach ($siteClients as $sc) {
                $ids->push((int) $sc->id);
            }
            // Dormant group-shift schema (kept compatible — see migration
            // 2026_03_23_006400_add_multi_client_and_tags_to_shifts.php).
            if (\Illuminate\Support\Facades\Schema::hasTable('shift_clients')) {
                $groupIds = DB::table('shift_clients')
                    ->where('shift_id', $shift->id)
                    ->pluck('client_id');
                foreach ($groupIds as $gid) {
                    $ids->push((int) $gid);
                }
            }
        }

        return $ids->unique()->values();
    }

    /**
     * Upsert the worker's allocation breakdown for a timesheet. Deletes any
     * rows the worker removed; updates / inserts the rest. Runs inside a
     * transaction so a half-written breakdown is impossible.
     *
     * @param  array<int, array<string, mixed>>  $allocations
     */
    private function persistAllocations(Timesheet $timesheet, array $allocations): void
    {
        $keepIds = [];

        foreach ($allocations as $row) {
            $existing = TimesheetClientAllocation::query()
                ->where('timesheet_id', $timesheet->id)
                ->where('client_id', $row['client_id'])
                ->first();

            if ($existing) {
                $existing->update([
                    'hours' => $row['hours'],
                    'allocation_method' => $row['allocation_method'],
                    'starts_at' => $row['starts_at'],
                    'ends_at' => $row['ends_at'],
                    'notes' => $row['notes'],
                    'sort_order' => $row['sort_order'],
                ]);
                $keepIds[] = $existing->id;
            } else {
                $created = TimesheetClientAllocation::create([
                    'timesheet_id' => $timesheet->id,
                    'client_id' => $row['client_id'],
                    'hours' => $row['hours'],
                    'allocation_method' => $row['allocation_method'],
                    'starts_at' => $row['starts_at'],
                    'ends_at' => $row['ends_at'],
                    'notes' => $row['notes'],
                    'sort_order' => $row['sort_order'],
                ]);
                $keepIds[] = $created->id;
            }
        }

        // Anything not in `keepIds` was removed by the worker — wipe it.
        TimesheetClientAllocation::query()
            ->where('timesheet_id', $timesheet->id)
            ->when(! empty($keepIds), fn ($q) => $q->whereNotIn('id', $keepIds))
            ->delete();
    }

    /**
     * Frontline acknowledge — lets the assigned worker mark a control-room
     * alert as seen from /my-day.
     *
     * Distinct from {@see \App\Http\Controllers\ControlRoom\ControlRoomAlertController::acknowledge}
     * which is gated to CR operators with `controlRoom.alerts.manage`. Here we
     * gate strictly on the alert's assignee so a frontline worker can clear
     * their own item without inheriting operator permissions. Transitions
     * open → ack via the same lifecycle check; a no-op otherwise so repeated
     * taps stay safe.
     */
    public function acknowledgeAlert(Request $request, ControlRoomAlert $alert)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($alert->assigned_to_user_id === $user->id, 403);

        if ($alert->isTerminal()) {
            return back();
        }

        if (! $alert->canTransitionTo(ControlRoomAlert::STATUS_ACK)) {
            // Already ack'd / triaging — treat as a successful no-op so the
            // frontline button stays idempotent.
            return back()->with('success', 'Alert already acknowledged.');
        }

        $alert->update([
            'status' => ControlRoomAlert::STATUS_ACK,
            'acknowledged_at' => now(),
            'acknowledged_by_user_id' => $user->id,
            'snoozed_until' => null,
            'snoozed_by_user_id' => null,
        ]);

        $alert->sla?->recordAcknowledge();

        AuditLogger::log('controlRoom.alert.acknowledge', $alert, [
            'alert_id' => $alert->id,
            'acknowledged_by' => $user->id,
            'via' => 'my-day',
        ]);

        return back()->with('success', 'Alert acknowledged.');
    }

    /**
     * Frontline snooze — hides the alert from the assignee's /my-day open
     * items until the window elapses. The alert stays open (CR status and
     * SLA untouched) so nothing is silenced for operators.
     *
     * Accepts one of three preset windows; invalid values fall through to the
     * shortest window. Critical alerts can't be snoozed — they must be
     * opened or acknowledged.
     */
    public function snoozeAlert(Request $request, ControlRoomAlert $alert)
    {
        $user = $request->user();
        abort_unless($user, 403);
        abort_unless($alert->assigned_to_user_id === $user->id, 403);

        if ($alert->isTerminal()) {
            return back();
        }

        if (strtolower((string) $alert->severity) === 'critical') {
            return back()->withErrors([
                'alert' => 'Critical alerts can\'t be snoozed. Open or acknowledge it.',
            ]);
        }

        $window = $request->input('window', '15m');
        $until = match ($window) {
            '1h' => now()->addHour(),
            'shift' => $this->endOfShiftFor($user),
            default => now()->addMinutes(15),
        };

        $alert->update([
            'snoozed_until' => $until,
            'snoozed_by_user_id' => $user->id,
        ]);

        AuditLogger::log('controlRoom.alert.snooze', $alert, [
            'alert_id' => $alert->id,
            'snoozed_by' => $user->id,
            'snoozed_until' => $until->toIso8601String(),
            'window' => $window,
        ]);

        return back()->with('success', 'Snoozed.');
    }

    /**
     * Best-effort "end of shift" resolution for snooze windows.
     *
     * Uses the user's open attendance session or next eligible shift if
     * either is available; otherwise falls back to end-of-day so the snooze
     * always has a finite window and can't be abused to hide work forever.
     */
    private function endOfShiftFor($user): Carbon
    {
        try {
            $openShift = \App\Domain\Hr\Models\HrAttendanceSession::query()
                ->where('user_id', $user->id)
                ->open()
                ->with('shift:id,ends_at')
                ->latest('clock_in_at')
                ->first();

            if ($openShift?->shift?->ends_at) {
                $end = Carbon::parse($openShift->shift->ends_at);
                if ($end->isFuture()) {
                    return $end;
                }
            }
        } catch (\Throwable) {
            // fall through to default
        }

        return now()->endOfDay();
    }
}
