<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Exceptions\AttendanceClockOutBlockedException;
use App\Domain\Hr\Models\HrAttendanceBreakEvent;
use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Shifts\Lifecycle\Data\CompleteShiftData;
use App\Domain\Shifts\Lifecycle\ShiftLifecycleService;
use App\Domain\Shifts\Lifecycle\ShiftLifecycleSource;
use App\Domain\Shifts\Timesheets\Drafts\DraftTimesheetService;
use App\Models\ClientIncident;
use App\Models\ClientMedication;
use App\Models\ClientMedicationAdministration;
use App\Models\Shift;
use App\Models\ShiftHandover;
use App\Models\ShiftTask;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\ShiftHandoverService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AttendanceService
{
    public const AUTO_MATCH_GRACE_HOURS = 4;

    /**
     * @throws \LogicException
     */
    public function clockIn(User $user, array $data = []): HrAttendanceSession
    {
        $openSession = HrAttendanceSession::query()
            ->where('user_id', $user->id)
            ->open()
            ->first();

        if ($openSession) {
            throw new \LogicException('You are already clocked in. Please clock out before starting another session.');
        }

        $clockInAt = isset($data['clock_in_at']) ? Carbon::parse($data['clock_in_at']) : now();
        $shift = $this->resolveShift($user, $data, $clockInAt);
        $siteId = $data['site_id'] ?? $shift?->site_id ?? $shift?->client?->site_id;

        $session = DB::transaction(function () use ($user, $data, $clockInAt, $shift, $siteId) {
            $session = HrAttendanceSession::create([
                'tenant_id' => $user->tenant_id ?? null,
                'user_id' => $user->id,
                'shift_id' => $shift?->id,
                'site_id' => $siteId,
                'clock_in_at' => $clockInAt,
                'status' => 'open',
                'source' => $data['source'] ?? 'manual',
                'location' => $data['location'] ?? null,
                'notes' => $data['notes'] ?? null,
                'meta' => $data['meta'] ?? null,
                'created_by' => $data['created_by'] ?? $user->id,
            ]);

            if ($shift && in_array($shift->status, ['draft', 'scheduled'], true)) {
                app(ShiftLifecycleService::class)->start(
                    $shift,
                    $user,
                    $clockInAt,
                    ShiftLifecycleSource::ClockIn,
                );
            }

            return $session->fresh(['shift.client', 'timesheet']);
        });

        return $session->fresh(['shift.client', 'timesheet']);
    }

    /**
     * @throws \LogicException
     */
    public function clockOut(User $user, ?HrAttendanceSession $session = null, array $data = []): HrAttendanceSession
    {
        $session = $session ?: HrAttendanceSession::query()
            ->where('user_id', $user->id)
            ->open()
            ->latest('clock_in_at')
            ->first();

        if (! $session) {
            throw new \LogicException('No open attendance session found to clock out.');
        }

        if ((int) $session->user_id !== (int) $user->id) {
            throw new \LogicException('You can only clock out your own attendance session.');
        }

        $force = (bool) ($data['force'] ?? false);
        $overrideReason = trim((string) ($data['override_reason'] ?? ''));

        $clockOutAt = isset($data['clock_out_at']) ? Carbon::parse($data['clock_out_at']) : now();

        $closedSession = DB::transaction(function () use ($session, $user, $clockOutAt, $data, $force, $overrideReason) {
            $session = HrAttendanceSession::query()
                ->with(['shift'])
                ->lockForUpdate()
                ->findOrFail($session->id);

            if ((int) $session->user_id !== (int) $user->id) {
                throw new \LogicException('You can only clock out your own attendance session.');
            }

            if ($session->status !== 'open' || $session->clock_out_at) {
                throw new \LogicException('This attendance session has already been closed.');
            }

            if ($clockOutAt->lessThanOrEqualTo($session->clock_in_at)) {
                throw new \LogicException('Clock-out time must be after clock-in time.');
            }

            $openBreakStartedAt = $session->break_started_at?->copy();
            $openBreakMinutes = $openBreakStartedAt
                ? max(0, (int) $openBreakStartedAt->diffInMinutes($clockOutAt))
                : 0;
            $breakMinutes = max(
                (int) ($data['break_minutes'] ?? $session->break_minutes ?? 0),
                (int) ($session->break_minutes ?? 0) + $openBreakMinutes,
            );

            if ($breakMinutes > 0) {
                $elapsedMinutes = (int) $session->clock_in_at->diffInMinutes($clockOutAt);
                if ($breakMinutes >= $elapsedMinutes) {
                    throw new \LogicException(sprintf(
                        'Break duration (%d min) must be less than the session duration (%d min).',
                        $breakMinutes,
                        $elapsedMinutes,
                    ));
                }
            }

            $this->applyTaskUpdates($session, $user, $data['task_updates'] ?? []);
            $this->saveHandoverFromClockOut($session, $user, $data['handover'] ?? null);

            $session = $session->fresh([
                'shift.tasks',
                'shift.outgoingHandovers',
            ]) ?? $session;

            $blockers = $this->getEndOfShiftBlockers($session);

            if ($blockers !== [] && ! $force) {
                throw new AttendanceClockOutBlockedException($blockers);
            }

            if ($blockers !== [] && $force && $overrideReason === '') {
                throw new \LogicException('An override reason is required to end a shift with outstanding checklist items.');
            }

            $session->update([
                'clock_out_at' => $clockOutAt,
                'break_minutes' => $breakMinutes,
                'break_started_at' => null,
                'status' => 'closed',
                'notes' => $data['notes'] ?? $session->notes,
                'closed_by' => $user->id,
            ]);

            $this->closeOpenBreakEvent($session, $clockOutAt, $openBreakStartedAt);

            if ($session->shift && in_array($session->shift->status, ['in_progress', 'active', 'clocked_in', 'started'], true)) {
                app(ShiftLifecycleService::class)->complete(
                    $session->shift,
                    $user,
                    CompleteShiftData::fromClockOutSession(
                        $session->fresh(['shift']) ?? $session,
                        $force,
                        $overrideReason,
                        $blockers !== [] && $force ? ['forced_clock_out' => true] : [],
                    ),
                );
            }

            app(DraftTimesheetService::class)->fromAttendanceSession($session->fresh(['shift']), $user->id, $data);

            if ($blockers !== [] && $force) {
                AuditLogger::log('attendance.clockOut.forced', $session, [
                    'attendance_session_id' => $session->id,
                    'shift_id' => $session->shift_id,
                    'override_reason' => $overrideReason,
                    'blockers' => $blockers,
                ]);
            }

            return $session->fresh(['shift.client', 'timesheet']);
        });

        return $closedSession;
    }

    public function eligibleShiftsForUser(User $user, ?Carbon $clockInAt = null): Collection
    {
        $clockInAt = ($clockInAt ?: now())->copy()->utc();

        return Shift::query()
            ->with('client:id,site_id')
            ->where('user_id', $user->id)
            ->visibleToFrontline($user->organization_id)
            ->whereIn('status', ['draft', 'scheduled', 'in_progress'])
            ->where('starts_at', '<=', $clockInAt->copy()->addHours(self::AUTO_MATCH_GRACE_HOURS))
            ->where('ends_at', '>=', $clockInAt->copy()->subHours(self::AUTO_MATCH_GRACE_HOURS))
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getEndOfShiftBlockers(HrAttendanceSession $session): array
    {
        $session->loadMissing([
            'shift.tasks',
            'shift.outgoingHandovers',
        ]);

        $shift = $session->shift;
        if (! $shift) {
            return [];
        }

        $blockers = [];

        $pendingTasks = $shift->tasks
            ->where('is_completed', false)
            ->count();

        if ($pendingTasks > 0) {
            $blockers[] = [
                'key' => 'tasks_pending',
                'label' => 'Finish shift tasks',
                'detail' => trans_choice('{1} 1 shift task is still open.|[2,*] :count shift tasks are still open.', $pendingTasks),
                'count' => $pendingTasks,
                'action_url' => '#shift-tasks',
                'blocking' => true,
            ];
        }

        $handoverSubmitted = $shift->outgoingHandovers->contains(
            fn (ShiftHandover $handover) => in_array($handover->status, [
                ShiftHandoverService::STATUS_SUBMITTED,
                ShiftHandoverService::STATUS_ACKNOWLEDGED,
            ], true),
        );

        if (! $handoverSubmitted && ! $shift->handover_waived_at) {
            $blockers[] = [
                'key' => 'handover_missing',
                'label' => 'Write handover',
                'detail' => 'The next shift still needs a handover.',
                'count' => 1,
                'action_url' => '#handover',
                'blocking' => true,
            ];
        }

        $draftIncidents = ClientIncident::query()
            ->where('shift_id', $shift->id)
            ->where('reported_by', $session->user_id)
            ->where(function ($query) {
                $query->where('status', 'draft')
                    ->orWhereNull('submitted_at');
            })
            ->count();

        if ($draftIncidents > 0) {
            $blockers[] = [
                'key' => 'incidents_draft',
                'label' => 'Submit draft incidents',
                'detail' => trans_choice('{1} 1 incident report is still a draft.|[2,*] :count incident reports are still drafts.', $draftIncidents),
                'count' => $draftIncidents,
                'action_url' => '/incidents?shift_id='.$shift->id,
                'blocking' => true,
            ];
        }

        $unsignedMeds = $this->countUnsignedMedicationDoses($shift);
        if ($unsignedMeds > 0) {
            $blockers[] = [
                'key' => 'meds_unsigned',
                'label' => 'Sign medication records',
                'detail' => trans_choice('{1} 1 scheduled medication still needs a MAR entry.|[2,*] :count scheduled medications still need MAR entries.', $unsignedMeds),
                'count' => $unsignedMeds,
                'action_url' => $shift->client_id ? '/meds/today?client_id='.$shift->client_id : '/meds/today',
                'blocking' => true,
            ];
        }

        return $blockers;
    }

    /**
     * @throws \LogicException
     */
    public function startBreak(User $user, ?HrAttendanceSession $session = null, array $data = []): HrAttendanceSession
    {
        $session = $this->resolveOpenSessionForUser($user, $session);

        if ($session->break_started_at) {
            throw new \LogicException('A break is already in progress.');
        }

        $startedAt = isset($data['started_at']) ? Carbon::parse($data['started_at']) : now();

        return DB::transaction(function () use ($session, $user, $startedAt) {
            $session->update([
                'break_started_at' => $startedAt,
                'break_count' => ((int) $session->break_count) + 1,
            ]);

            HrAttendanceBreakEvent::query()->create([
                'session_id' => $session->id,
                'started_at' => $startedAt,
                'created_by' => $user->id,
            ]);

            return $session->fresh(['shift.client', 'timesheet', 'breakEvents']);
        });
    }

    /**
     * @throws \LogicException
     */
    public function endBreak(User $user, ?HrAttendanceSession $session = null, array $data = []): HrAttendanceSession
    {
        $session = $this->resolveOpenSessionForUser($user, $session);

        if (! $session->break_started_at) {
            throw new \LogicException('No break is currently in progress.');
        }

        $endedAt = isset($data['ended_at']) ? Carbon::parse($data['ended_at']) : now();
        if ($endedAt->lessThan($session->break_started_at)) {
            throw new \LogicException('Break end time must be after break start time.');
        }

        $minutes = max(0, (int) $session->break_started_at->diffInMinutes($endedAt));

        return DB::transaction(function () use ($session, $endedAt, $minutes) {
            $event = HrAttendanceBreakEvent::query()
                ->where('session_id', $session->id)
                ->whereNull('ended_at')
                ->latest('started_at')
                ->first();

            if ($event) {
                $event->update([
                    'ended_at' => $endedAt,
                    'minutes' => $minutes,
                ]);
            }

            $session->update([
                'break_started_at' => null,
                'break_minutes' => ((int) $session->break_minutes) + $minutes,
            ]);

            return $session->fresh(['shift.client', 'timesheet', 'breakEvents']);
        });
    }

    protected function resolveShift(User $user, array $data, Carbon $clockInAt): ?Shift
    {
        if (! empty($data['shift_id'])) {
            $shift = Shift::query()->with('client:id,site_id')->findOrFail($data['shift_id']);
            if ((int) $shift->user_id !== (int) $user->id) {
                throw new \LogicException('You cannot clock into a shift assigned to another staff member.');
            }

            if (! in_array($shift->status, ['draft', 'scheduled', 'in_progress'], true)) {
                throw new \LogicException('This shift cannot be clocked into in its current status.');
            }

            return $shift;
        }

        $eligibleShifts = $this->eligibleShiftsForUser($user, $clockInAt);

        if ($eligibleShifts->count() > 1) {
            throw new \LogicException('Multiple assigned shifts match this clock-in time. Please choose the shift you are starting.');
        }

        return $eligibleShifts->first();
    }

    protected function resolveOpenSessionForUser(User $user, ?HrAttendanceSession $session = null): HrAttendanceSession
    {
        $session = $session ?: HrAttendanceSession::query()
            ->where('user_id', $user->id)
            ->open()
            ->latest('clock_in_at')
            ->first();

        if (! $session) {
            throw new \LogicException('No open attendance session found.');
        }

        if ((int) $session->user_id !== (int) $user->id) {
            throw new \LogicException('You can only update your own attendance session.');
        }

        return $session;
    }

    protected function closeOpenBreakEvent(HrAttendanceSession $session, Carbon $clockOutAt, ?Carbon $startedAt): void
    {
        HrAttendanceBreakEvent::query()
            ->where('session_id', $session->id)
            ->whereNull('ended_at')
            ->latest('started_at')
            ->first()
            ?->update([
                'ended_at' => $clockOutAt,
                'minutes' => $startedAt
                    ? max(0, (int) $startedAt->diffInMinutes($clockOutAt))
                    : null,
            ]);
    }

    /**
     * @param  array<int, array{id?: int, is_completed?: bool}>|mixed  $updates
     */
    protected function applyTaskUpdates(HrAttendanceSession $session, User $user, mixed $updates): void
    {
        if (! is_array($updates) || $updates === []) {
            return;
        }

        if (! $session->shift_id) {
            throw ValidationException::withMessages([
                'task_updates' => 'Task updates require a linked shift.',
            ]);
        }

        $normalized = collect($updates)
            ->map(fn (mixed $update) => is_array($update) ? [
                'id' => (int) ($update['id'] ?? 0),
                'is_completed' => (bool) ($update['is_completed'] ?? false),
            ] : null)
            ->filter(fn (?array $update) => $update !== null && $update['id'] > 0)
            ->values();

        if ($normalized->isEmpty()) {
            return;
        }

        $ids = $normalized->pluck('id')->unique()->values();

        if ($ids->count() !== $normalized->count()) {
            throw ValidationException::withMessages([
                'task_updates' => 'Task updates included the same task more than once.',
            ]);
        }

        $tasks = ShiftTask::query()
            ->where('shift_id', $session->shift_id)
            ->whereIn('id', $ids->all())
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        if ($tasks->count() !== $ids->count()) {
            throw ValidationException::withMessages([
                'task_updates' => 'One or more shift tasks could no longer be found. Refresh and try again.',
            ]);
        }

        foreach ($normalized as $update) {
            /** @var ShiftTask $task */
            $task = $tasks->get($update['id']);
            $completed = (bool) $update['is_completed'];

            $task->forceFill([
                'is_completed' => $completed,
                'completed_at' => $completed ? ($task->completed_at ?? now()) : null,
                'completed_by' => $completed ? ($task->completed_by ?? $user->id) : null,
            ])->save();
        }
    }

    /**
     * @param  array<string, mixed>|mixed  $handover
     */
    protected function saveHandoverFromClockOut(HrAttendanceSession $session, User $user, mixed $handover): void
    {
        if (! is_array($handover) || $handover === [] || ! $session->shift_id) {
            return;
        }

        $shift = $session->shift ?: Shift::query()->find($session->shift_id);
        if (! $shift) {
            return;
        }

        $alreadySubmitted = ShiftHandover::query()
            ->where('outgoing_shift_id', $shift->id)
            ->whereIn('status', [
                ShiftHandoverService::STATUS_SUBMITTED,
                ShiftHandoverService::STATUS_ACKNOWLEDGED,
            ])
            ->exists();

        if ($alreadySubmitted) {
            return;
        }

        $notes = trim((string) ($handover['handover_notes'] ?? ''));
        $medsCompleted = (bool) ($handover['meds_completed'] ?? true);
        $followUpNeeded = (bool) ($handover['follow_up_needed'] ?? false);

        if ($notes === '') {
            $notes = $medsCompleted
                ? 'No specific items to flag for the next shift.'
                : 'Medications were not fully completed - please review on arrival.';
        }

        app(ShiftHandoverService::class)->save($shift, $user, [
            'handover_notes' => $notes,
            'client_mood' => $handover['shift_rating'] ?? null,
            'medications_due' => $medsCompleted
                ? null
                : [[
                    'label' => 'Review outstanding medications from previous shift',
                    'severity' => 'high',
                ]],
            'follow_up_items' => $followUpNeeded
                ? [[
                    'label' => 'Follow-up flagged by outgoing worker',
                    'priority' => 'medium',
                ]]
                : null,
            'submit' => true,
        ]);
    }

    protected function countUnsignedMedicationDoses(Shift $shift): int
    {
        if (! $shift->client_id || ! $shift->starts_at || ! $shift->ends_at) {
            return 0;
        }

        $medications = ClientMedication::query()
            ->where('client_id', $shift->client_id)
            ->active()
            ->where('is_prn', false)
            ->whereNotNull('dose_times')
            ->get(['id', 'dose_times']);

        if ($medications->isEmpty()) {
            return 0;
        }

        $start = $shift->starts_at->copy();
        $end = $shift->ends_at->copy();

        $signedKeys = ClientMedicationAdministration::query()
            ->where('client_id', $shift->client_id)
            ->whereIn('status', ['given', 'refused', 'withheld', 'missed'])
            ->whereNotNull('scheduled_for')
            ->whereBetween('scheduled_for', [
                $start->copy()->utc(),
                $end->copy()->utc(),
            ])
            ->get(['client_medication_id', 'scheduled_for'])
            ->mapWithKeys(fn (ClientMedicationAdministration $administration) => [
                $administration->client_medication_id.'|'.$administration->scheduled_for?->copy()->utc()->format('Y-m-d H:i') => true,
            ]);

        $unsigned = 0;

        foreach ($medications as $medication) {
            $doseTimes = is_array($medication->dose_times) ? $medication->dose_times : [];
            if ($doseTimes === []) {
                continue;
            }

            $day = $start->copy()->startOfDay();
            $lastDay = $end->copy()->startOfDay();

            while ($day->lessThanOrEqualTo($lastDay)) {
                foreach ($doseTimes as $doseTime) {
                    $scheduled = $day->copy()->setTimeFromTimeString($doseTime);

                    if (! $scheduled->betweenIncluded($start, $end)) {
                        continue;
                    }

                    $key = $medication->id.'|'.$scheduled->copy()->utc()->format('Y-m-d H:i');
                    if (! $signedKeys->has($key)) {
                        $unsigned++;
                    }
                }

                $day->addDay();
            }
        }

        return $unsigned;
    }
}
