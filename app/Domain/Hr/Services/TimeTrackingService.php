<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrPayrollRun;
use App\Domain\Hr\Models\HrTimeEntry;
use App\Domain\Hr\Models\HrTimeEntryAmendment;
use App\Domain\Hr\Notifications\TimeEntryChangedNotification;
use App\Domain\Shifts\Timesheets\Drafts\DraftTimesheetService;
use App\Models\Client;
use App\Models\Shift;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\UserSiteAccessService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TimeTrackingService
{
    /** @var array<int, string> */
    private const COMMAND_AUTHORIZATION_EVIDENCE = [
        'timesheets.manageAny',
        'hr.time.manage',
        'timesheets.approve',
        'hr.time.approveTeam',
    ];

    public function __construct(
        private readonly AttendanceService $attendanceService,
        private readonly AttendanceTimeEntryProjector $attendanceTimeEntries,
        private readonly DraftTimesheetService $draftTimesheets,
        private readonly HrCurrentStaffService $currentStaff,
        private readonly UserSiteAccessService $siteAccess,
        private readonly AuthorizationEvidenceLockService $authorizationEvidence,
    ) {}

    /**
     * Clock in a user via the attendance system, creating both an
     * HrAttendanceSession and a corresponding HrTimeEntry.
     */
    public function clockIn(User $user, ?string $notes = null, ?string $projectCode = null, ?int $shiftId = null): HrTimeEntry
    {
        $this->assertCurrentStaff($user);

        $session = $this->attendanceService->clockIn($user, [
            'shift_id' => $shiftId,
            'notes' => $notes,
            'project_code' => $projectCode,
            'source' => 'hr_module',
        ]);

        $entry = HrTimeEntry::query()
            ->where('attendance_session_id', $session->id)
            ->first();

        if (! $entry) {
            throw new \LogicException('Could not record the time entry for this staff profile.');
        }

        return $entry;
    }

    /**
     * Compatibility entrypoint for historical/backfill callers. Live clock
     * commands project inside AttendanceService's governing transaction; this
     * delegate passes only the identity so the service re-reads and locks the
     * canonical aggregate instead of trusting the caller's model snapshot.
     */
    public function syncEntryFromSession(HrAttendanceSession $session, User $actor, array $extra = []): ?HrTimeEntry
    {
        return $this->attendanceService->projectTimeEntryForSession($actor, (int) $session->id, $extra);
    }

    /**
     * Close every still-open entry for a user through the one shared close path.
     * Used by the self-service clock-out to self-heal orphaned actives without
     * re-implementing the NZ break formula. Returns the number closed.
     */
    public function closeOpenEntries(User $user, Carbon $clockOut, int $breakMinutes, ?float $mileageKm = null, ?string $notes = null): int
    {
        return DB::transaction(function () use ($user, $clockOut, $breakMinutes, $mileageKm, $notes): int {
            $this->attendanceTimeEntries->lockApplicationPayrollMutex();

            $snapshots = HrTimeEntry::query()
                ->where('user_id', $user->id)
                ->whereNull('attendance_session_id')
                ->whereNull('clock_out')
                ->where(function ($status): void {
                    $status->whereNull('status')->orWhere('status', '!=', 'voided');
                })
                ->orderBy('id')
                ->get(['id']);

            if ($snapshots->isEmpty()) {
                return 0;
            }
            if ($snapshots->count() !== 1) {
                throw new \LogicException('Clock-out recovery requires exactly one orphan time entry.');
            }

            if (HrAttendanceSession::query()
                ->where('user_id', $user->id)
                ->open()
                ->lockForUpdate()
                ->exists()) {
                throw new \LogicException('An attendance session is still open; orphan time-entry recovery is not permitted.');
            }

            $snapshot = HrTimeEntry::query()->findOrFail($snapshots->first()->id);
            [$lockedEntry] = $this->lockPayAffectingEntry(
                $snapshot,
                $user,
                'clocked out',
                allowSelf: true,
            );
            if ($lockedEntry->attendance_session_id !== null || $lockedEntry->clock_out !== null) {
                throw new \LogicException('The orphan time entry changed before clock-out recovery completed.');
            }

            abort_unless($lockedEntry->clock_in instanceof \DateTimeInterface, 404);
            $clockIn = Carbon::instance($lockedEntry->clock_in);
            $this->validatedWorkedMinutes($clockIn, $clockOut, $breakMinutes);
            $this->attendanceTimeEntries->assertNoWorkerTimeOverlap(
                (int) $lockedEntry->user_id,
                $clockIn,
                $clockOut,
                (int) $lockedEntry->id,
            );

            $fresh = $this->applyClockOut($lockedEntry, $clockOut, $breakMinutes, $mileageKm, $notes);
            $this->draftTimesheets->fromManualEntry($fresh, $user->id);

            return 1;
        });
    }

    /** Apply a clock-out to an entry: hours, NZ break compliance, status. */
    private function applyClockOut(HrTimeEntry $entry, Carbon $clockOut, int $breakMinutes, ?float $mileageKm, ?string $notes): HrTimeEntry
    {
        return $this->attendanceTimeEntries->applyClockOut($entry, $clockOut, $breakMinutes, $mileageKm, $notes);
    }

    /**
     * Clock out a user via the attendance system, updating the
     * HrTimeEntry with calculated hours and break compliance.
     */
    public function clockOut(User $user, int $breakMinutes = 0, ?string $notes = null, ?float $mileageKm = null): HrTimeEntry
    {
        $this->assertCurrentStaff($user);

        $entry = HrTimeEntry::query()
            ->forUser($user->id)
            ->active()
            ->first();

        if (! $entry) {
            throw new \LogicException('No active clock-in found.');
        }

        try {
            // Clock out via attendance service (creates Operations Timesheet too).
            $session = $this->attendanceService->clockOut($user, null, [
                'break_minutes' => $breakMinutes,
                'notes' => $notes,
                'mileage_km' => $mileageKm,
            ]);
        } catch (\LogicException $exception) {
            // Preserve explicit attendance projection/provenance failures. Only
            // recover a legacy active row after confirming no open attendance
            // exists; closeOpenEntries repeats that decision under the User lock.
            if (HrAttendanceSession::query()->where('user_id', $user->id)->open()->exists()) {
                throw $exception;
            }

            $closed = $this->closeOpenEntries($user, now(), $breakMinutes, $mileageKm, $notes);
            if ($closed === 0) {
                throw $exception;
            }

            return $entry->fresh();
        }

        $synced = HrTimeEntry::query()
            ->where('attendance_session_id', $session->id)
            ->first();
        if (! $synced) {
            throw new \LogicException('Attendance was closed, but its time entry needs payroll follow-up.');
        }

        return $synced;
    }

    /**
     * Create a manual time entry.
     */
    public function createManualEntry(User $user, array $data): HrTimeEntry
    {
        $this->assertCurrentStaff($user);
        abort_unless(
            $user->canDo('timesheets.manageAny') || $user->canDo('timesheets.approve'),
            403,
        );
        $targetUserId = (int) ($data['user_id'] ?? $user->id);

        $clockInLocal = $this->parseWorkerLocalDateTime($data['clock_in']);
        $clockOutLocal = $this->parseWorkerLocalDateTime($data['clock_out']);
        $clockIn = $clockInLocal->copy()->utc();
        $clockOut = $clockOutLocal->copy()->utc();
        $breakMinutes = (int) ($data['break_minutes'] ?? 0);
        $totalMinutes = $this->validatedWorkedMinutes($clockIn, $clockOut, $breakMinutes);
        $totalHours = round($totalMinutes / 60, 2);

        return DB::transaction(function () use ($targetUserId, $data, $user, $clockInLocal, $clockIn, $clockOut, $breakMinutes, $totalHours) {
            $this->attendanceTimeEntries->lockApplicationPayrollMutex();
            $scope = $this->resolveManualEntryCommandScope($user, $targetUserId, $data);
            $lockedTimesheet = $this->lockCreationTimesheet(
                $targetUserId,
                $scope['shift_id'],
                $scope['site_id'],
                $scope['client_id'],
                $clockInLocal->toDateString(),
            );
            $this->assertNoConflictingCreationTimeEntry(
                $targetUserId,
                $scope['shift_id'],
                false,
            );
            $this->attendanceTimeEntries->assertNoWorkerTimeOverlap(
                $targetUserId,
                $clockIn,
                $clockOut,
            );
            $this->lockPayrollRunsForWorkDates(
                array_filter([
                    $clockInLocal->toDateString(),
                    $lockedTimesheet?->work_date?->toDateString(),
                ]),
                'created',
            );
            $this->assertTimesheetMutable($lockedTimesheet, 'created');

            $entry = HrTimeEntry::create([
                'user_id' => $targetUserId,
                'shift_id' => $scope['shift_id'],
                'site_id' => $scope['site_id'],
                'client_id' => $scope['client_id'],
                'entry_date' => $clockInLocal->toDateString(),
                'clock_in' => $clockIn,
                'clock_out' => $clockOut,
                'break_minutes' => $breakMinutes,
                'total_hours' => $totalHours,
                'entry_type' => 'manual',
                'status' => 'submitted',
                'notes' => $data['notes'] ?? null,
                'project_code' => $data['project_code'] ?? null,
                'cost_centre' => $data['cost_centre'] ?? null,
                'pay_type' => $data['pay_type'] ?? 'standard',
                'is_sleepover' => (bool) ($data['is_sleepover'] ?? false),
                'is_on_call' => (bool) ($data['is_on_call'] ?? false),
                'is_public_holiday' => (bool) ($data['is_public_holiday'] ?? false),
                'sleepover_disturbances' => $data['sleepover_disturbances'] ?? null,
                'mileage_km' => $data['mileage_km'] ?? null,
                'created_by' => $user->id,
            ]);

            $this->draftTimesheets->fromManualEntry($entry->fresh(), $user->id);

            return $entry->fresh();
        });
    }

    /**
     * Get a weekly summary of hours for a user.
     */
    public function getWeeklySummary(int $userId, ?string $weekStart = null): array
    {
        $start = $weekStart ? Carbon::parse($weekStart)->startOfWeek() : now()->startOfWeek();
        $end = $start->copy()->endOfWeek();

        $entries = HrTimeEntry::query()
            ->forUser($userId)
            ->forDateRange($start->toDateString(), $end->toDateString())
            ->whereNotNull('clock_out')
            ->get();

        $dailyHours = [];
        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $dateStr = $day->toDateString();
            $dailyHours[$dateStr] = $entries
                ->where('entry_date', $dateStr)
                ->sum('total_hours');
        }

        return [
            'week_start' => $start->toDateString(),
            'week_end' => $end->toDateString(),
            'daily_hours' => $dailyHours,
            'total_hours' => round($entries->sum('total_hours'), 2),
            'total_entries' => $entries->count(),
        ];
    }

    /* ------------------------------------------------------------------ */
    /*  Team helpers */
    /* ------------------------------------------------------------------ */

    public function getTeamUserIds(User $manager): array
    {
        return HrEmployeeProfile::query()
            ->where('manager_user_id', $manager->id)
            ->whereIn('user_id', $this->currentStaff->currentUsersQuery()->select('users.id'))
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Resolve every caller-supplied identity under one concealed command
     * boundary. The Client is the canonical Site owner; a Shift must belong to
     * that Client and target worker. Caller-supplied Client/Site values may
     * confirm the canonical relationship but can never override it.
     *
     * The application payroll mutex is already held by both callers. Canonical
     * Client -> Shift locks precede one complete sorted actor/target RBAC batch,
     * current Profiles, and the active Site row, followed by the downstream
     * Timesheet and payroll locks.
     *
     * @return array{shift_id: int|null, client_id: int, site_id: int}
     */
    private function resolveManualEntryCommandScope(User $actor, int $targetUserId, array $data): array
    {
        abort_unless(DB::transactionLevel() > 0, 404);
        abort_unless($this->positiveId($targetUserId) !== null, 404);

        $shiftId = $this->commandIdentityId($data, 'shift_id');
        $suppliedClientId = $this->commandIdentityId($data, 'client_id');
        $suppliedSiteId = $this->commandIdentityId($data, 'site_id');

        if ($shiftId !== null) {
            $shiftSnapshot = Shift::query()->whereKey($shiftId)->first(['id', 'client_id']);
            $canonicalClientId = $this->positiveId($shiftSnapshot?->client_id);
            abort_unless($canonicalClientId !== null, 404);
        } else {
            abort_unless($suppliedClientId !== null, 404);
            $canonicalClientId = $suppliedClientId;
        }

        $clientSnapshot = Client::query()->whereKey($canonicalClientId)->first(['id', 'site_id']);
        $canonicalSiteId = $this->positiveId($clientSnapshot?->site_id);
        abort_unless($canonicalSiteId !== null, 404);

        $client = Client::query()
            ->whereKey($canonicalClientId)
            ->where('site_id', $canonicalSiteId)
            ->lockForUpdate()
            ->first(['id', 'site_id']);
        abort_unless($client, 404);

        if ($shiftId !== null) {
            $shift = Shift::query()
                ->whereKey($shiftId)
                ->where('client_id', $client->id)
                ->where('user_id', $targetUserId)
                ->where(function ($siteScope) use ($client): void {
                    $siteScope->whereNull('site_id')->orWhere('site_id', $client->site_id);
                })
                ->lockForUpdate()
                ->first(['id', 'client_id', 'site_id', 'user_id']);
            abort_unless($shift, 404);
        }

        $canonicalClientId = (int) $client->id;
        $canonicalSiteId = (int) $client->site_id;
        abort_unless($suppliedClientId === null || $suppliedClientId === $canonicalClientId, 404);
        abort_unless($suppliedSiteId === null || $suppliedSiteId === $canonicalSiteId, 404);

        [$lockedActor, $target, $profile, $accessibleSiteIds] = $this->lockCommandUserScope(
            $actor,
            $targetUserId,
        );
        $site = Site::query()
            ->active()
            ->notArchived()
            ->whereNull('archived_at')
            ->whereKey($canonicalSiteId)
            ->lockForUpdate()
            ->first(['id']);
        abort_unless($site, 404);
        abort_unless(in_array($canonicalSiteId, $accessibleSiteIds, true), 404);

        $targetSiteIds = collect([
            $profile->primary_site_id,
            ...($profile->secondary_site_ids ?? []),
        ])
            ->map(fn ($siteId) => $this->positiveId($siteId))
            ->filter()
            ->unique()
            ->values()
            ->all();
        abort_unless(in_array($canonicalSiteId, $targetSiteIds, true), 404);

        $canManage = $lockedActor->canDo('timesheets.manageAny');
        $canApprove = $lockedActor->canDo('timesheets.approve');
        abort_unless($canManage || $canApprove, 404);

        if (! $canManage) {
            abort_unless(
                (int) $target->id === (int) $lockedActor->id
                    || (int) ($profile->manager_user_id ?? 0) === (int) $lockedActor->id,
                404,
            );
        }

        return [
            'shift_id' => $shiftId,
            'client_id' => $canonicalClientId,
            'site_id' => $canonicalSiteId,
        ];
    }

    /**
     * Lock actor and target as one complete sorted authorization set after the
     * canonical Client/Shift mutexes. Profiles follow in ascending primary-key
     * order, then the caller locks the active Site row before any mutation.
     *
     * @return array{0: User, 1: User, 2: HrEmployeeProfile, 3: array<int, int>}
     */
    private function lockCommandUserScope(User $actor, int $targetUserId): array
    {
        $lockedUsers = $this->authorizationEvidence->lockForUsers(
            [(int) $actor->id, $targetUserId],
            self::COMMAND_AUTHORIZATION_EVIDENCE,
        );
        $lockedActor = $lockedUsers->get((int) $actor->id);
        $target = $lockedUsers->get($targetUserId);
        abort_unless($lockedActor instanceof User && $target instanceof User, 404);
        abort_unless(collect([$lockedActor, $target])->every(fn (User $user): bool => $user->approved_at !== null
                && ! in_array($user->role, ['client', 'next_of_kin'], true)
                && ! $user->hasRole('client', 'next_of_kin')
        ), 404);

        $userIds = collect([(int) $lockedActor->id, (int) $target->id])
            ->unique()
            ->sort()
            ->values();
        $profiles = HrEmployeeProfile::withTrashed()
            ->whereIn('user_id', $userIds->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (HrEmployeeProfile $profile): int => (int) $profile->user_id);
        abort_unless($profiles->count() === $userIds->count(), 404);
        abort_unless($userIds->every(fn (int $userId): bool => $profiles->get($userId) instanceof HrEmployeeProfile
                && ! $profiles->get($userId)->trashed()
                && $this->isCurrentCommandProfile($profiles->get($userId))
        ), 404);
        $lockedUsers->each(function (User $user) use ($profiles): void {
            $user->setRelation('hrEmployeeProfile', $profiles->get((int) $user->id));
        });

        $profile = $profiles->get((int) $lockedActor->id);
        $targetProfile = $profiles->get((int) $target->id);
        abort_unless(
            $profile instanceof HrEmployeeProfile
                && $targetProfile instanceof HrEmployeeProfile,
            404,
        );

        $assignedSiteIds = collect([
            $profile->primary_site_id,
            ...($profile->secondary_site_ids ?? []),
        ])
            ->map(fn ($siteId) => $this->positiveId($siteId))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return [$lockedActor, $target, $targetProfile, $assignedSiteIds];
    }

    private function isCurrentCommandProfile(HrEmployeeProfile $profile): bool
    {
        $today = now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString();

        return $profile->is_active
            && ($profile->start_date === null || $profile->start_date->toDateString() <= $today)
            && ($profile->end_date === null || $profile->end_date->toDateString() >= $today);
    }

    private function commandIdentityId(array $data, string $key): ?int
    {
        if (! array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }

        $id = $this->positiveId($data[$key]);
        abort_unless($id !== null, 404);

        return $id;
    }

    /* ------------------------------------------------------------------ */
    /*  Edit / Amend time entry */
    /* ------------------------------------------------------------------ */

    /**
     * Lock the complete pay-affecting evidence set selected by a route-bound
     * time-entry identity. The application mutex serialises payroll export and
     * every cooperating writer; downstream locks are always Timesheet first,
     * then the canonical HrTimeEntry, then all affected payroll periods.
     *
     * @param  list<string>  $additionalWorkDates
     * @return array{0: HrTimeEntry, 1: Timesheet|null}
     */
    private function lockPayAffectingEntry(
        HrTimeEntry $snapshot,
        User $actor,
        string $action,
        array $additionalWorkDates = [],
        bool $manageOnly = false,
        bool $allowAttendance = false,
        bool $allowSelf = false,
        bool $requirePayMutable = true,
    ): array {
        $this->attendanceTimeEntries->lockApplicationPayrollMutex();

        $siteId = $this->positiveId($snapshot->site_id);
        $clientId = $this->positiveId($snapshot->client_id);
        abort_unless($siteId !== null, 404);

        $client = $clientId !== null
            ? Client::query()
                ->whereKey($clientId)
                ->where('site_id', $siteId)
                ->lockForUpdate()
                ->first(['id', 'site_id'])
            : null;
        abort_unless($clientId === null || $client, 404);

        $shiftId = $this->positiveId($snapshot->shift_id);
        $shift = null;
        if ($shiftId !== null) {
            abort_unless($client, 404);
            $shift = Shift::query()
                ->whereKey($shiftId)
                ->where('user_id', $snapshot->user_id)
                ->where('client_id', $client->id)
                ->where(function ($siteScope) use ($siteId): void {
                    $siteScope->whereNull('site_id')->orWhere('site_id', $siteId);
                })
                ->lockForUpdate()
                ->first(['id', 'user_id', 'client_id', 'site_id']);
            abort_unless($shift, 404);
        }

        [$lockedActor, $target, $targetProfile, $accessibleSiteIds] = $this->lockCommandUserScope(
            $actor,
            (int) $snapshot->user_id,
        );
        $site = Site::query()
            ->active()
            ->notArchived()
            ->whereNull('archived_at')
            ->whereKey($siteId)
            ->lockForUpdate()
            ->first(['id']);
        abort_unless($site, 404);
        abort_unless(in_array($siteId, $accessibleSiteIds, true), 404);

        $targetSiteIds = collect([
            $targetProfile->primary_site_id,
            ...($targetProfile->secondary_site_ids ?? []),
        ])
            ->map(fn ($assignedSiteId) => $this->positiveId($assignedSiteId))
            ->filter()
            ->unique()
            ->values()
            ->all();
        abort_unless(in_array($siteId, $targetSiteIds, true), 404);

        $sessionId = $this->positiveId($snapshot->attendance_session_id);
        $session = null;
        if ($sessionId !== null) {
            $session = HrAttendanceSession::query()
                ->whereKey($sessionId)
                ->where('user_id', $target->id)
                ->where('site_id', $siteId)
                ->where(function ($sessionShift) use ($shiftId): void {
                    if ($shiftId === null) {
                        $sessionShift->whereNull('shift_id');
                    } else {
                        $sessionShift->where('shift_id', $shiftId);
                    }
                })
                ->lockForUpdate()
                ->first(['id', 'user_id', 'shift_id', 'site_id']);
            abort_unless($session, 404);
        }

        $canManage = $lockedActor->canDo('timesheets.manageAny');
        $canApprove = $lockedActor->canDo('timesheets.approve');
        $canApproveTarget = ! $manageOnly
            && $canApprove
            && ((int) $target->id === (int) $lockedActor->id
                || (int) ($targetProfile->manager_user_id ?? 0) === (int) $lockedActor->id);
        $canSelfServe = $allowSelf && (int) $target->id === (int) $lockedActor->id;
        abort_unless($canManage || $canApproveTarget || $canSelfServe, 404);

        $timesheets = Timesheet::query()
            ->where(function ($query) use ($snapshot, $shiftId): void {
                $query->where('hr_time_entry_id', $snapshot->getKey());
                if ($shiftId !== null) {
                    $query->orWhere(function ($fallback) use ($snapshot, $shiftId): void {
                        $fallback
                            ->where('shift_id', $shiftId)
                            ->where('user_id', $snapshot->user_id);
                    });
                }
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        if ($timesheets->count() > 1) {
            throw new \LogicException('Time entry is linked to conflicting Timesheets.');
        }
        $timesheet = $timesheets->first();

        $entry = HrTimeEntry::withTrashed()
            ->whereKey($snapshot->getKey())
            ->lockForUpdate()
            ->first();
        if (! $entry) {
            abort(404);
        }
        if ($entry->trashed() || $entry->status === 'voided') {
            throw new \LogicException("A voided time entry cannot be {$action}.");
        }
        if ((int) $entry->user_id !== (int) $snapshot->user_id
            || (int) ($entry->shift_id ?? 0) !== (int) ($snapshot->shift_id ?? 0)
            || (int) ($entry->attendance_session_id ?? 0) !== (int) ($snapshot->attendance_session_id ?? 0)
            || (int) ($entry->site_id ?? 0) !== $siteId
            || (int) ($entry->client_id ?? 0) !== (int) ($clientId ?? 0)) {
            abort(404);
        }

        $isAttendanceBacked = $sessionId !== null || $entry->source_type === 'attendance';
        abort_unless($allowAttendance || ! $isAttendanceBacked, 404);
        if ($sessionId !== null) {
            abort_unless($session, 404);
            abort_unless(
                $entry->source_id === null || (int) $entry->source_id === $sessionId,
                404,
            );
        } elseif ($entry->source_type === 'attendance' || $entry->source_id !== null) {
            abort(404);
        }

        if ($timesheet) {
            if ($timesheet->hr_time_entry_id !== null
                && (int) $timesheet->hr_time_entry_id !== (int) $entry->id) {
                abort(404);
            }
            if ((int) $timesheet->user_id !== (int) $entry->user_id
                || (int) ($timesheet->shift_id ?? 0) !== (int) ($entry->shift_id ?? 0)) {
                abort(404);
            }

            abort_unless(
                ($clientId === null && $timesheet->client_id === null)
                    || ($clientId !== null
                        && ($timesheet->client_id === null || (int) $timesheet->client_id === $clientId)),
                404,
            );
            foreach ([$timesheet->site_id, $timesheet->shift_site_id] as $timesheetSiteId) {
                abort_unless($timesheetSiteId === null || (int) $timesheetSiteId === $siteId, 404);
            }
            abort_unless(
                $timesheet->attendance_session_id === null
                    || ($sessionId !== null && (int) $timesheet->attendance_session_id === $sessionId),
                404,
            );
        }
        $entry->setRelation('timesheet', $timesheet);

        $workDates = $additionalWorkDates;
        abort_unless($entry->clock_in && $entry->entry_date, 404);
        $entryDate = $entry->entry_date->toDateString();
        $clockInDate = $entry->clock_in->copy()
            ->setTimezone(config('app.worker_timezone', config('app.timezone', 'UTC')))
            ->toDateString();
        abort_unless($entryDate === $clockInDate, 404);
        $workDates[] = $entryDate;

        if ($timesheet?->work_date) {
            $timesheetDate = $timesheet->work_date->toDateString();
            abort_unless($timesheetDate === $entryDate, 404);
            $workDates[] = $timesheetDate;
        }

        if ($requirePayMutable) {
            $this->lockPayrollRunsForWorkDates($workDates, $action);
            $this->assertEntryNotPayrollLocked($entry, $action, $timesheet);
        }

        return [$entry, $timesheet];
    }

    private function lockCreationTimesheet(
        int $userId,
        ?int $shiftId,
        int $siteId,
        int $clientId,
        string $workDate,
    ): ?Timesheet {
        $shiftId = $this->positiveId($shiftId);
        if ($shiftId === null) {
            return null;
        }

        $timesheets = Timesheet::query()
            ->where('shift_id', $shiftId)
            ->where('user_id', $userId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        if ($timesheets->count() > 1) {
            throw new \LogicException('Shift is linked to conflicting Timesheets.');
        }

        $timesheet = $timesheets->first();
        if ($timesheet?->hr_time_entry_id !== null) {
            throw new \LogicException('This Shift already has a canonical time entry.');
        }
        if ($timesheet) {
            abort_unless(
                $timesheet->client_id === null || (int) $timesheet->client_id === $clientId,
                404,
            );
            foreach ([$timesheet->site_id, $timesheet->shift_site_id] as $timesheetSiteId) {
                abort_unless($timesheetSiteId === null || (int) $timesheetSiteId === $siteId, 404);
            }
            abort_unless($timesheet->attendance_session_id === null, 404);
            abort_unless(
                $timesheet->work_date === null || $timesheet->work_date->toDateString() === $workDate,
                404,
            );
        }

        return $timesheet;
    }

    /**
     * Creation has no new canonical row to lock yet. Lock any existing row
     * that would own the same Shift, plus every active clock when the new row
     * would also be active, after Timesheet and before payroll-run locks.
     */
    private function assertNoConflictingCreationTimeEntry(
        int $userId,
        ?int $shiftId,
        bool $creatingActiveClock,
    ): void {
        $shiftId = $this->positiveId($shiftId);
        if ($shiftId === null && ! $creatingActiveClock) {
            return;
        }

        $entries = HrTimeEntry::query()
            ->where('user_id', $userId)
            ->where(function ($query) use ($shiftId, $creatingActiveClock): void {
                if ($shiftId !== null) {
                    $query->where('shift_id', $shiftId);
                }
                if ($creatingActiveClock) {
                    if ($shiftId === null) {
                        $query->whereNull('clock_out');
                    } else {
                        $query->orWhereNull('clock_out');
                    }
                }
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'shift_id', 'clock_out']);

        if ($entries->isNotEmpty()) {
            throw new \LogicException('A canonical time entry already exists for this Shift or active worker clock.');
        }
    }

    /**
     * @param  list<string>  $workDates
     */
    private function lockPayrollRunsForWorkDates(array $workDates, string $action): void
    {
        $dates = collect($workDates)
            ->filter(fn (mixed $date): bool => filled($date))
            ->map(fn (mixed $date): string => Carbon::parse((string) $date)->toDateString())
            ->unique()
            ->sort()
            ->values();

        foreach ($dates as $workDate) {
            $runs = HrPayrollRun::query()
                ->where('period_start', '<=', $workDate)
                ->where('period_end', '>=', $workDate)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'status']);
            if ($runs->contains(fn (HrPayrollRun $run): bool => in_array($run->status, ['locked', 'exported'], true))) {
                throw new \LogicException("This entry falls in a locked payroll period and cannot be {$action}.");
            }
        }
    }

    /**
     * Time entries feed pay — block mutations once the entry's date falls in
     * a locked/exported payroll run or its timesheet is payroll-linked
     * (mirrors TimesheetApprovalService::assertNotLockedByPayroll at the
     * entry level; without this an entry could be amended AFTER payroll
     * consumed it, silently desyncing pay from the record).
     */
    protected function assertEntryNotPayrollLocked(
        HrTimeEntry $entry,
        string $action,
        ?Timesheet $timesheet,
    ): void {
        if ($entry->status === 'approved') {
            throw new \LogicException("An approved time entry cannot be {$action}.");
        }

        $this->assertTimesheetMutable($timesheet, $action);
    }

    private function assertTimesheetMutable(?Timesheet $timesheet, string $action): void
    {
        if (! $timesheet) {
            return;
        }

        if (
            $timesheet->is_payroll_segment_complete
            || filled($timesheet->payroll_reference)
            || $timesheet->exported_to_payroll_at !== null
        ) {
            throw new \LogicException("This entry's timesheet is payroll-linked and cannot be {$action}.");
        }

        if (! in_array($timesheet->status, ['draft', 'returned'], true)) {
            throw new \LogicException("This entry's timesheet is not editable and cannot be {$action}.");
        }
    }

    /**
     * Tell the entry's owner someone else touched their time record
     * (best-effort; skipped when they acted on their own entry).
     */
    protected function notifyEntryOwner(HrTimeEntry $entry, User $actor, string $action, ?string $reason = null): void
    {
        if ($entry->user_id === $actor->id) {
            return;
        }

        $owner = User::find($entry->user_id);
        if (! $owner) {
            return;
        }

        try {
            $owner->notify(new TimeEntryChangedNotification($entry, $actor, $action, $reason));
        } catch (\Throwable $exception) {
            Log::warning('Failed to send time-entry changed notification', [
                'entry_id' => $entry->id,
                'action' => $action,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function editTimeEntry(HrTimeEntry $entry, User $editor, array $data, string $reason): HrTimeEntry
    {
        $editableFields = ['clock_in', 'clock_out', 'break_minutes', 'pay_type', 'notes', 'is_sleepover', 'is_on_call', 'is_public_holiday', 'mileage_km', 'cost_centre', 'project_code'];
        $data = array_intersect_key(
            $data,
            array_flip([...$editableFields, 'sleepover_disturbances']),
        );

        $targetWorkDates = [];
        if (array_key_exists('clock_in', $data) && $data['clock_in'] !== null) {
            $clockInLocal = $this->parseWorkerLocalDateTime($data['clock_in']);
            $data['clock_in'] = $clockInLocal->copy()->utc();
            $data['entry_date'] = $clockInLocal->toDateString();
            $targetWorkDates[] = $clockInLocal->toDateString();
        }
        if (array_key_exists('clock_out', $data) && $data['clock_out'] !== null) {
            $data['clock_out'] = $this->parseWorkerLocalDateTime($data['clock_out'])->utc();
        }

        $result = DB::transaction(function () use ($entry, $editor, $data, $reason, $editableFields, $targetWorkDates) {
            [$lockedEntry] = $this->lockPayAffectingEntry(
                $entry,
                $editor,
                'edited',
                $targetWorkDates,
            );

            if (array_key_exists('clock_out', $data)
                && $data['clock_out'] === null
                && $lockedEntry->clock_out !== null) {
                throw new \LogicException('A closed time entry cannot be reopened through a generic amendment.');
            }

            $clockIn = array_key_exists('clock_in', $data)
                ? $data['clock_in']
                : $lockedEntry->clock_in;
            $clockOut = array_key_exists('clock_out', $data)
                ? $data['clock_out']
                : $lockedEntry->clock_out;
            abort_unless($clockIn instanceof \DateTimeInterface, 404);

            $clockIn = Carbon::instance($clockIn);
            $clockOut = $clockOut instanceof \DateTimeInterface
                ? Carbon::instance($clockOut)
                : null;
            $breakMinutes = (int) ($data['break_minutes'] ?? $lockedEntry->break_minutes ?? 0);

            if ($clockOut !== null) {
                $totalMinutes = $this->validatedWorkedMinutes($clockIn, $clockOut, $breakMinutes);
                $data['total_hours'] = round($totalMinutes / 60, 2);
                $workedHours = $totalMinutes / 60;
                $requiredBreak = $workedHours >= 4 ? 30 : ($workedHours >= 2 ? 10 : 0);
                $data['break_compliance_met'] = $breakMinutes >= $requiredBreak;
                if ($lockedEntry->clock_out === null) {
                    $data['status'] = 'submitted';
                }
            } else {
                if ($breakMinutes < 0) {
                    throw new \LogicException('Break duration cannot be negative.');
                }
                $data['total_hours'] = null;
                $data['break_compliance_met'] = null;
                $data['status'] = 'active';
            }

            $this->attendanceTimeEntries->assertNoWorkerTimeOverlap(
                (int) $lockedEntry->user_id,
                $clockIn,
                $clockOut,
                (int) $lockedEntry->id,
            );

            $originalValues = [];
            foreach ($editableFields as $field) {
                if (! array_key_exists($field, $data)) {
                    continue;
                }

                $oldValue = $lockedEntry->getAttribute($field);
                $newValue = $data[$field];
                $oldSerialised = $this->serialiseAmendmentValue($oldValue);
                $newSerialised = $this->serialiseAmendmentValue($newValue);
                if ($oldSerialised === $newSerialised) {
                    continue;
                }

                $originalValues[$field] = $oldValue;
                HrTimeEntryAmendment::query()->create([
                    'hr_time_entry_id' => $lockedEntry->id,
                    'amended_by' => $editor->id,
                    'field_name' => $field,
                    'old_value' => $oldSerialised,
                    'new_value' => $newSerialised,
                    'reason' => $reason,
                ]);
            }

            $disturbancesProvided = array_key_exists('sleepover_disturbances', $data);
            $derivedValuesChanged = collect(['total_hours', 'break_compliance_met', 'status'])
                ->contains(function (string $field) use ($data, $lockedEntry): bool {
                    return array_key_exists($field, $data)
                        && $this->serialiseAmendmentValue($lockedEntry->getAttribute($field))
                            !== $this->serialiseAmendmentValue($data[$field]);
                });
            if ($originalValues === [] && ! $disturbancesProvided && ! $derivedValuesChanged) {
                return ['entry' => $lockedEntry, 'changed' => false];
            }

            $data['amended_by'] = $editor->id;
            $data['amended_at'] = now();
            $data['amendment_reason'] = $reason;
            $data['original_values'] = array_merge($lockedEntry->original_values ?? [], $originalValues);
            $lockedEntry->update($data);

            $freshEntry = $lockedEntry->fresh();
            if ($freshEntry->clock_out !== null) {
                $this->draftTimesheets->fromManualEntry($freshEntry, $editor->id);
            }

            return ['entry' => $freshEntry, 'changed' => true];
        });

        if ($result['changed']) {
            $this->notifyEntryOwner($result['entry'], $editor, 'amended', $reason);
        }

        return $result['entry'];
    }

    /* ------------------------------------------------------------------ */
    /*  Clock on behalf */
    /* ------------------------------------------------------------------ */

    public function clockOnBehalf(User $manager, int $targetUserId, array $data): HrTimeEntry
    {
        $this->assertCurrentStaff($manager);
        abort_unless(
            $manager->canDo('timesheets.manageAny') || $manager->canDo('timesheets.approve'),
            403,
        );

        $clockInLocal = $this->parseWorkerLocalDateTime($data['clock_in']);
        $clockOutLocal = isset($data['clock_out']) ? $this->parseWorkerLocalDateTime($data['clock_out']) : null;
        $clockIn = $clockInLocal->copy()->utc();
        $clockOut = $clockOutLocal?->copy()->utc();
        $breakMinutes = (int) ($data['break_minutes'] ?? 0);

        $totalHours = null;
        $breakCompliant = null;
        if ($clockOut) {
            $totalMinutes = $this->validatedWorkedMinutes($clockIn, $clockOut, $breakMinutes);
            $totalHours = round($totalMinutes / 60, 2);
            $workedHours = $totalMinutes / 60;
            $requiredBreak = $workedHours >= 4 ? 30 : ($workedHours >= 2 ? 10 : 0);
            $breakCompliant = $breakMinutes >= $requiredBreak;
        } elseif ($breakMinutes < 0) {
            throw new \LogicException('Break duration cannot be negative.');
        }

        $reason = isset($data['reason']) ? trim((string) $data['reason']) : '';

        $result = DB::transaction(function () use ($targetUserId, $data, $clockInLocal, $clockIn, $clockOut, $breakMinutes, $totalHours, $breakCompliant, $manager, $reason) {
            $this->attendanceTimeEntries->lockApplicationPayrollMutex();
            $scope = $this->resolveManualEntryCommandScope($manager, $targetUserId, $data);
            $lockedTimesheet = $this->lockCreationTimesheet(
                $targetUserId,
                $scope['shift_id'],
                $scope['site_id'],
                $scope['client_id'],
                $clockInLocal->toDateString(),
            );
            $this->assertNoConflictingCreationTimeEntry(
                $targetUserId,
                $scope['shift_id'],
                $clockOut === null,
            );
            $this->attendanceTimeEntries->assertNoWorkerTimeOverlap(
                $targetUserId,
                $clockIn,
                $clockOut,
            );
            $this->lockPayrollRunsForWorkDates(
                array_filter([
                    $clockInLocal->toDateString(),
                    $lockedTimesheet?->work_date?->toDateString(),
                ]),
                'created',
            );
            $this->assertTimesheetMutable($lockedTimesheet, 'created');

            $entry = HrTimeEntry::create([
                'user_id' => $targetUserId,
                'shift_id' => $scope['shift_id'],
                'site_id' => $scope['site_id'],
                'client_id' => $scope['client_id'],
                'entry_date' => $clockInLocal->toDateString(),
                'clock_in' => $clockIn,
                'clock_out' => $clockOut,
                'break_minutes' => $breakMinutes,
                'total_hours' => $totalHours,
                'entry_type' => 'admin_clock',
                'status' => $clockOut ? 'submitted' : 'active',
                'pay_type' => $data['pay_type'] ?? 'standard',
                'is_sleepover' => (bool) ($data['is_sleepover'] ?? false),
                'is_on_call' => (bool) ($data['is_on_call'] ?? false),
                'is_public_holiday' => (bool) ($data['is_public_holiday'] ?? false),
                'sleepover_disturbances' => $data['sleepover_disturbances'] ?? null,
                'mileage_km' => $data['mileage_km'] ?? null,
                'break_compliance_met' => $breakCompliant,
                'notes' => $data['notes'] ?? null,
                'amendment_reason' => $reason !== '' ? $reason : null,
                'created_by' => $manager->id,
            ]);

            // Persist the (required) on-behalf reason as an audit row so the
            // amendment history drawer explains why a manager created the entry.
            if ($reason !== '') {
                HrTimeEntryAmendment::create([
                    'hr_time_entry_id' => $entry->id,
                    'amended_by' => $manager->id,
                    'field_name' => 'created_on_behalf',
                    'old_value' => null,
                    'new_value' => $manager->name,
                    'reason' => $reason,
                ]);
            }

            if ($clockOut) {
                $this->draftTimesheets->fromManualEntry($entry->fresh(), $manager->id);
            }

            return $entry->fresh();
        });

        $this->notifyEntryOwner($result, $manager, 'created', $reason !== '' ? $reason : null);

        return $result;
    }

    /* ------------------------------------------------------------------ */
    /*  Void (soft-delete) an entry */
    /* ------------------------------------------------------------------ */

    /**
     * Soft-delete a time entry with a mandatory reason recorded to the
     * amendment trail. Approved entries are locked (payroll integrity).
     */
    public function voidEntry(HrTimeEntry $entry, User $actor, string $reason): void
    {
        $reason = trim($reason);
        if ($reason === '' || mb_strlen($reason) > 255) {
            throw new \LogicException('A void reason of no more than 255 characters is required.');
        }

        $voidedEntry = DB::transaction(function () use ($entry, $actor, $reason): HrTimeEntry {
            [$lockedEntry, $lockedTimesheet] = $this->lockPayAffectingEntry(
                $entry,
                $actor,
                'voided',
                manageOnly: true,
            );

            HrTimeEntryAmendment::create([
                'hr_time_entry_id' => $lockedEntry->id,
                'amended_by' => $actor->id,
                'field_name' => 'voided',
                'old_value' => $lockedEntry->status,
                'new_value' => 'voided',
                'reason' => $reason,
            ]);

            $lockedEntry->update([
                'status' => 'voided',
                'amended_by' => $actor->id,
                'amended_at' => now(),
                'amendment_reason' => $reason,
            ]);

            if ($lockedTimesheet) {
                $lockedTimesheet->forceFill([
                    'status' => 'voided',
                    'archived_at' => now(),
                    'archived_reason' => $reason,
                ])->save();
            }

            $lockedEntry->delete(); // soft-delete (deleted_at)

            return $lockedEntry;
        });

        $this->notifyEntryOwner($voidedEntry, $actor, 'voided', $reason);
    }

    /* ------------------------------------------------------------------ */
    /*  Add note */
    /* ------------------------------------------------------------------ */

    /**
     * Append a team-visible, timestamped note to an entry. Recorded on the
     * amendment trail (field "note") so it surfaces in the history drawer
     * timeline without a separate notes table.
     */
    public function addNote(HrTimeEntry $entry, User $actor, string $note): HrTimeEntryAmendment
    {
        return DB::transaction(function () use ($entry, $actor, $note): HrTimeEntryAmendment {
            [$lockedEntry] = $this->lockPayAffectingEntry(
                $entry,
                $actor,
                'annotated',
                allowAttendance: true,
                requirePayMutable: false,
            );

            return HrTimeEntryAmendment::query()->create([
                'hr_time_entry_id' => $lockedEntry->id,
                'amended_by' => $actor->id,
                'field_name' => 'note',
                'old_value' => null,
                'new_value' => null,
                'reason' => $note,
            ]);
        });
    }

    /* ------------------------------------------------------------------ */
    /*  Correct / close a missed clock-out */
    /* ------------------------------------------------------------------ */

    /**
     * Force-close a still-open entry: set the clock-out, recompute hours +
     * NZ break compliance, and (when the entry is attendance-backed) route the
     * close through AttendanceService::correctSession so the linked Operations
     * timesheet is returned to draft with an audit trail.
     */
    public function correctMissedClockOut(HrTimeEntry $entry, User $actor, string $clockOut, int $breakMinutes, string $reason): HrTimeEntry
    {
        $clockOutLocal = $this->parseWorkerLocalDateTime($clockOut);
        $clockOutUtc = $clockOutLocal->copy()->utc();

        if ($entry->attendance_session_id) {
            $session = HrAttendanceSession::find($entry->attendance_session_id);
            if (! $session) {
                throw new \LogicException('The attendance session for this time entry is no longer available.');
            }

            // AttendanceService repeats these checks against its locked session.
            // Keeping the service-level guard here also rejects malformed direct
            // callers before the governed attendance command begins.
            $this->validatedWorkedMinutes($entry->clock_in, $clockOutUtc, $breakMinutes);
            $this->attendanceService->correctSession($actor, $session, $clockOutUtc, $breakMinutes, $reason);
            $result = $entry->fresh();
            if (! $result) {
                throw new \LogicException('The attendance-backed time entry is no longer available.');
            }

            $this->notifyEntryOwner($result, $actor, 'corrected', $reason);

            return $result;
        }

        $result = DB::transaction(function () use ($entry, $actor, $clockOutUtc, $breakMinutes, $reason) {
            [$lockedEntry] = $this->lockPayAffectingEntry($entry, $actor, 'corrected');
            if ($lockedEntry->clock_out) {
                throw new \LogicException('This entry is already clocked out.');
            }
            if ($lockedEntry->attendance_session_id) {
                throw new \LogicException('Time-entry attendance provenance changed while it was being locked.');
            }

            $totalMinutes = $this->validatedWorkedMinutes(
                $lockedEntry->clock_in,
                $clockOutUtc,
                $breakMinutes,
            );
            $totalHours = round($totalMinutes / 60, 2);
            $workedHours = $totalMinutes / 60;
            $requiredBreak = $workedHours >= 4 ? 30 : ($workedHours >= 2 ? 10 : 0);

            $this->attendanceTimeEntries->assertNoWorkerTimeOverlap(
                (int) $lockedEntry->user_id,
                $lockedEntry->clock_in,
                $clockOutUtc,
                (int) $lockedEntry->id,
            );

            HrTimeEntryAmendment::create([
                'hr_time_entry_id' => $lockedEntry->id,
                'amended_by' => $actor->id,
                'field_name' => 'clock_out',
                'old_value' => null,
                'new_value' => $clockOutUtc->toDateTimeString(),
                'reason' => $reason,
            ]);

            $lockedEntry->update([
                'clock_out' => $clockOutUtc,
                'break_minutes' => $breakMinutes,
                'total_hours' => $totalHours,
                'break_compliance_met' => $breakMinutes >= $requiredBreak,
                'status' => in_array($lockedEntry->status, ['active', null], true)
                    ? 'submitted'
                    : $lockedEntry->status,
                'amended_by' => $actor->id,
                'amended_at' => now(),
                'amendment_reason' => $reason,
            ]);

            $fresh = $lockedEntry->fresh();
            if ($fresh->clock_out && ! $fresh->attendance_session_id) {
                $this->draftTimesheets->fromManualEntry($fresh, $actor->id);
            }

            return $fresh;
        });

        $this->notifyEntryOwner($result, $actor, 'corrected', $reason);

        return $result;
    }

    private function validatedWorkedMinutes(Carbon $clockIn, Carbon $clockOut, int $breakMinutes): int
    {
        if ($breakMinutes < 0) {
            throw new \LogicException('Break duration cannot be negative.');
        }
        if ($clockOut->lessThanOrEqualTo($clockIn)) {
            throw new \LogicException('Time-entry clock-out must be after its canonical clock-in.');
        }

        $elapsedMinutes = (int) $clockIn->diffInMinutes($clockOut);
        if ($breakMinutes >= $elapsedMinutes) {
            throw new \LogicException(sprintf(
                'Break duration (%d min) must be less than the session duration (%d min).',
                $breakMinutes,
                $elapsedMinutes,
            ));
        }

        return $elapsedMinutes - $breakMinutes;
    }

    private function positiveId(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0
            ? (int) $value
            : null;
    }

    private function parseWorkerLocalDateTime(mixed $value): Carbon
    {
        $timezone = (string) config('app.worker_timezone', config('app.timezone', 'UTC'));

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->setTimezone($timezone);
        }

        return Carbon::parse((string) $value, $timezone)->setTimezone($timezone);
    }

    private function serialiseAmendmentValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->utc()->toDateTimeString();
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return is_array($value)
            ? json_encode($value, JSON_THROW_ON_ERROR)
            : (string) $value;
    }

    private function assertCurrentStaff(User $user): void
    {
        if (! $this->currentStaff->isCurrent($user)) {
            throw new \LogicException('Time tracking is available only to current approved staff.');
        }
    }
}
