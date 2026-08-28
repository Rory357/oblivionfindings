<?php

namespace App\Domain\Shifts\Timesheets;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrPayrollRun;
use App\Domain\Hr\Models\HrTimeEntry;
use App\Domain\Hr\Services\AlternativeHolidayService;
use App\Models\Client;
use App\Models\Shift;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\Operations\BillingService;
use App\Services\Operations\TimesheetHrSyncService;
use App\Services\Operations\TimesheetReconciliationService;
use App\Services\ShiftOperationalSnapshotService;
use App\Services\UserSiteAccessService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class TimesheetApprovalService
{
    public function __construct(
        private readonly TimesheetReconciliationService $reconciliation,
        private readonly ShiftOperationalSnapshotService $snapshots,
        private readonly TimesheetHrSyncService $hrSync,
        private readonly BillingService $billing,
        private readonly AlternativeHolidayService $alternativeHolidays,
        private readonly AuthorizationEvidenceLockService $authorizationEvidence,
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    public function submit(Timesheet $timesheet, User $actor): TimesheetWorkflowResult
    {
        try {
            return DB::transaction(function () use ($timesheet, $actor): TimesheetWorkflowResult {
                $this->lockApplicationPayrollMutex();
                $locked = $this->lock($timesheet);
                $actor = $this->lockCurrentWriterAuthority($actor, $locked, 'submit');
                $this->lockPayrollRunsForWorkDates([$locked->work_date], 'submitted');

                $this->assertSubmittable($locked, 'submitted');
                $this->reconciliation->assertWorkflowAllowed($locked, 'submitted');

                $locked->forceFill($this->submittedFields($actor))->save();

                return new TimesheetWorkflowResult(
                    $locked->fresh(['shift.client']) ?? $locked,
                    true,
                );
            });
        } catch (ValidationException $exception) {
            $this->persistReconciliationBlockAfterRollback($timesheet, $exception);

            throw $exception;
        }
    }

    /**
     * Persist editable fields without allowing a concurrent workflow
     * transition to be overwritten by a stale route-bound model.
     *
     * @param  array<string, mixed>  $updates
     */
    public function updateEditable(Timesheet $timesheet, User $actor, array $updates): TimesheetWorkflowResult
    {
        try {
            return DB::transaction(function () use ($timesheet, $actor, $updates): TimesheetWorkflowResult {
                $this->lockApplicationPayrollMutex();
                $locked = $this->lock($timesheet);
                $actor = $this->lockCurrentWriterAuthority($actor, $locked, 'update', $updates);
                $this->assertEditableSource($locked);
                $linkedEntry = $this->hrSync->lockCanonicalEntryForMutation($locked);

                $this->lockPayrollRunsForWorkDates([
                    $locked->work_date,
                    $updates['work_date'] ?? $locked->work_date,
                    $linkedEntry?->entry_date,
                ], 'updated');

                $this->assertSubmittable($locked, 'updated');
                $originalClientId = $this->clientId($locked);

                $locked->fill($updates);
                $locked->save();

                $this->invalidateChangedManualAllocations($locked, $originalClientId);
                $this->reconciliation->reconcile($locked->fresh() ?? $locked);

                return new TimesheetWorkflowResult(
                    $locked->fresh(['shift.client']) ?? $locked,
                    true,
                );
            });
        } catch (ValidationException $exception) {
            $this->persistReconciliationBlockAfterRollback($timesheet, $exception);

            throw $exception;
        }
    }

    /**
     * @param  array<string, mixed>  $updates
     */
    public function resubmit(Timesheet $timesheet, User $actor, array $updates): TimesheetWorkflowResult
    {
        try {
            return DB::transaction(function () use ($timesheet, $actor, $updates): TimesheetWorkflowResult {
                $this->lockApplicationPayrollMutex();
                $locked = $this->lock($timesheet);
                $actor = $this->lockCurrentWriterAuthority($actor, $locked, 'resubmit', $updates);
                $this->assertEditableSource($locked);
                $linkedEntry = $this->hrSync->lockCanonicalEntryForMutation($locked);

                $this->lockPayrollRunsForWorkDates([
                    $locked->work_date,
                    $updates['work_date'] ?? $locked->work_date,
                    $linkedEntry?->entry_date,
                ], 'resubmitted');

                $this->assertSubmittable($locked, 'resubmitted');
                $originalClientId = $this->clientId($locked);

                $locked->fill($updates);
                $locked->save();

                $this->invalidateChangedManualAllocations($locked, $originalClientId);

                $this->reconciliation->assertWorkflowAllowed($locked->fresh() ?? $locked, 'submitted');

                $locked->forceFill($this->submittedFields($actor));
                $locked->save();

                return new TimesheetWorkflowResult(
                    $locked->fresh(['shift.client']) ?? $locked,
                    true,
                );
            });
        } catch (ValidationException $exception) {
            $this->persistReconciliationBlockAfterRollback($timesheet, $exception);

            throw $exception;
        }
    }

    public function approve(Timesheet $timesheet, User $actor, ?string $decisionNotes = null): TimesheetWorkflowResult
    {
        try {
            return DB::transaction(function () use ($timesheet, $actor, $decisionNotes): TimesheetWorkflowResult {
                // Attendance correction and payroll publication both enter
                // through this application-wide gate. Approval must take the
                // same first lock so whichever command queues first owns the
                // Timesheet decision; the loser then rechecks the locked row.
                $this->lockApplicationPayrollMutex();
                $locked = $this->lock($timesheet);
                $actor = $this->lockCurrentReviewAuthority($actor, $locked);

                if ($locked->status === 'approved') {
                    return new TimesheetWorkflowResult(
                        $locked->fresh(['shift.client']) ?? $locked,
                        false,
                    );
                }

                if ($locked->status !== 'submitted') {
                    throw ValidationException::withMessages([
                        'timesheet' => 'Only submitted timesheets can be approved.',
                    ]);
                }

                $linkedEntry = $this->hrSync->lockCanonicalEntryForMutation($locked);
                $this->hrSync->assertNoWorkerOverlapForMutation($locked, $linkedEntry);
                $this->lockPayrollRunsForWorkDates([
                    $locked->work_date,
                    $linkedEntry?->entry_date,
                ], 'approved');
                $this->assertApprovalAllowed($locked, $actor);

                $locked->forceFill([
                    'status' => 'approved',
                    'approved_by' => $actor->id,
                    'approved_at' => now(),
                    'decision_notes' => $decisionNotes,
                ])->save();

                $this->syncApprovedTimesheet($locked, $linkedEntry);

                try {
                    $this->alternativeHolidays->accrueForTimesheet($locked->fresh() ?? $locked);
                } catch (\Throwable $exception) {
                    Log::warning('Alternative holiday accrual failed for approved timesheet', [
                        'timesheet_id' => $locked->id,
                        'error' => $exception->getMessage(),
                    ]);
                }

                return new TimesheetWorkflowResult(
                    $locked->fresh(['shift.client']) ?? $locked,
                    true,
                );
            });
        } catch (ValidationException $exception) {
            $this->persistReconciliationBlockAfterRollback($timesheet, $exception);

            throw $exception;
        }
    }

    public function returnForChanges(Timesheet $timesheet, User $actor, string $notes): TimesheetWorkflowResult
    {
        try {
            return DB::transaction(function () use ($timesheet, $actor, $notes): TimesheetWorkflowResult {
                $this->lockApplicationPayrollMutex();
                $locked = $this->lock($timesheet);
                $actor = $this->lockCurrentReviewAuthority($actor, $locked);

                if ($locked->status !== 'submitted') {
                    return new TimesheetWorkflowResult(
                        $locked->fresh(['shift.client']) ?? $locked,
                        false,
                    );
                }

                $this->lockPayrollRunsForWorkDates([$locked->work_date], 'returned');
                $this->assertNotPayrollLinked($locked, 'returned after export preparation');
                $this->reconciliation->assertWorkflowAllowed($locked, 'returned');

                $locked->forceFill([
                    'status' => 'returned',
                    'returned_by' => $actor->id,
                    'returned_at' => now(),
                    'returned_notes' => $notes,
                    'approved_by' => null,
                    'approved_at' => null,
                    'decision_notes' => null,
                ])->save();

                return new TimesheetWorkflowResult(
                    $locked->fresh(['shift.client']) ?? $locked,
                    true,
                );
            });
        } catch (ValidationException $exception) {
            $this->persistReconciliationBlockAfterRollback($timesheet, $exception);

            throw $exception;
        }
    }

    public function reject(Timesheet $timesheet, User $actor, string $notes): TimesheetWorkflowResult
    {
        try {
            return DB::transaction(function () use ($timesheet, $actor, $notes): TimesheetWorkflowResult {
                $this->lockApplicationPayrollMutex();
                $locked = $this->lock($timesheet);
                $actor = $this->lockCurrentReviewAuthority($actor, $locked);

                if ($locked->status !== 'submitted') {
                    return new TimesheetWorkflowResult(
                        $locked->fresh(['shift.client']) ?? $locked,
                        false,
                    );
                }

                $this->lockPayrollRunsForWorkDates([$locked->work_date], 'rejected');
                $this->assertNotPayrollLinked($locked, 'rejected after export preparation');
                $this->reconciliation->assertWorkflowAllowed($locked, 'rejected');

                $locked->forceFill([
                    'status' => 'rejected',
                    'approved_by' => $actor->id,
                    'approved_at' => now(),
                    'decision_notes' => $notes,
                ])->save();

                return new TimesheetWorkflowResult(
                    $locked->fresh(['shift.client']) ?? $locked,
                    true,
                );
            });
        } catch (ValidationException $exception) {
            $this->persistReconciliationBlockAfterRollback($timesheet, $exception);

            throw $exception;
        }
    }

    /**
     * @param  Collection<int, Timesheet>  $timesheets
     */
    public function bulkApprove(Collection $timesheets, User $actor, ?string $decisionNotes = null): BulkResult
    {
        $timesheets
            ->filter(fn (Timesheet $timesheet): bool => $timesheet->status !== 'approved')
            // Keep the batch preflight read-only. Current permission and Site
            // evidence is locked again by every transactional approve leaf;
            // an unauthorized native caller must not cause reconciliation
            // fields to be materialised before that authority decision.
            ->each(fn (Timesheet $timesheet) => $this->assertApprovalAllowed($timesheet, $actor, false));

        return BulkResult::fromResults(
            $timesheets->map(fn (Timesheet $timesheet) => $this->approve($timesheet, $actor, $decisionNotes))
        );
    }

    /**
     * @param  Collection<int, Timesheet>  $timesheets
     */
    public function bulkReturn(Collection $timesheets, User $actor, string $notes): BulkResult
    {
        return BulkResult::fromResults(
            $timesheets->map(fn (Timesheet $timesheet) => $this->returnForChanges($timesheet, $actor, $notes))
        );
    }

    /**
     * @param  Collection<int, Timesheet>  $timesheets
     */
    public function bulkReject(Collection $timesheets, User $actor, string $notes): BulkResult
    {
        return BulkResult::fromResults(
            $timesheets->map(fn (Timesheet $timesheet) => $this->reject($timesheet, $actor, $notes))
        );
    }

    protected function lock(Timesheet $timesheet): Timesheet
    {
        return Timesheet::query()
            ->lockForUpdate()
            ->findOrFail($timesheet->id);
    }

    protected function lockApplicationPayrollMutex(): void
    {
        $mutex = DB::table('hr_payroll_run_mutexes')
            ->where('key', 'application')
            ->lockForUpdate()
            ->first();

        if (! $mutex) {
            throw new \RuntimeException('The application payroll mutex is missing; migration repair is required.');
        }
    }

    /**
     * Re-read and lock the complete current authority decision after the
     * payroll/Timesheet aggregate has joined this transaction's lock set.
     * Route middleware and controller Site checks are request snapshots and
     * cannot authorize a write that waited behind either of those mutexes.
     */
    protected function lockCurrentReviewAuthority(User $actor, Timesheet $timesheet): User
    {
        $lockedActor = $this->authorizationEvidence->lockForUser($actor, [
            'timesheets.approve',
            'hr.time.approveTeam',
            'timesheets.manageAny',
            'hr.time.manage',
        ]);
        abort_unless(
            $lockedActor->canDo('timesheets.approve')
                || $lockedActor->canDo('timesheets.manageAny'),
            403,
        );

        $profile = HrEmployeeProfile::query()
            ->where('user_id', $lockedActor->id)
            ->lockForUpdate()
            ->first([
                'id',
                'user_id',
                'primary_site_id',
                'secondary_site_ids',
                'is_active',
                'start_date',
                'end_date',
            ]);
        abort_unless($profile && $this->isCurrentReviewProfile($profile), 403);

        $siteId = $this->siteAccess->timesheetSiteId($timesheet);
        $site = Site::query()
            ->active()
            ->notArchived()
            ->whereNull('archived_at')
            ->whereKey($siteId)
            ->lockForUpdate()
            ->first(['id']);
        abort_unless($site, 403, 'You are not authorized to access timesheets for this site.');

        $assignedSiteIds = collect([
            $profile->primary_site_id,
            ...($profile->secondary_site_ids ?? []),
        ])
            ->filter(fn (mixed $assignedSiteId): bool => is_numeric($assignedSiteId) && (int) $assignedSiteId > 0)
            ->map(fn (mixed $assignedSiteId): int => (int) $assignedSiteId)
            ->unique()
            ->values()
            ->all();
        abort_unless(
            in_array((int) $site->id, $assignedSiteIds, true),
            403,
            'You are not authorized to access timesheets for this site.',
        );

        $lockedActor->setRelation('hrEmployeeProfile', $profile);

        return $lockedActor;
    }

    /**
     * Rebuild the native draft-writer decision only after the application
     * payroll mutex and canonical Timesheet row are owned. Controller and
     * route checks are pre-wait snapshots; they cannot authorize a mutation.
     *
     * @param  array<string, mixed>  $updates
     */
    protected function lockCurrentWriterAuthority(
        User $actor,
        Timesheet $timesheet,
        string $command,
        array $updates = [],
    ): User {
        $siteIds = $this->lockCanonicalWriterProvenance($timesheet, $updates);
        $lockedActor = $this->authorizationEvidence->lockForUser($actor, [
            'timesheets.update',
            'timesheets.submit',
            'timesheets.manageAny',
            'hr.time.manage',
        ]);

        $hasRequiredPermission = match ($command) {
            'submit' => $lockedActor->canDo('timesheets.submit'),
            'update' => $lockedActor->canDo('timesheets.update'),
            'resubmit' => $lockedActor->canDo('timesheets.update')
                && $lockedActor->canDo('timesheets.submit'),
            default => false,
        };
        abort_unless($hasRequiredPermission, 403);
        abort_unless(
            (int) $timesheet->user_id === (int) $lockedActor->id
                || $lockedActor->canDo('timesheets.manageAny'),
            403,
        );

        $profile = HrEmployeeProfile::query()
            ->where('user_id', $lockedActor->id)
            ->lockForUpdate()
            ->first([
                'id',
                'user_id',
                'primary_site_id',
                'secondary_site_ids',
                'is_active',
                'start_date',
                'end_date',
            ]);
        abort_unless($profile && $this->isCurrentReviewProfile($profile), 403);

        $lockedSiteIds = Site::query()
            ->active()
            ->notArchived()
            ->whereNull('archived_at')
            ->whereIn('id', $siteIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id')
            ->map(fn (mixed $siteId): int => (int) $siteId)
            ->all();
        abort_unless($lockedSiteIds === $siteIds, 403, 'You are not authorized to access timesheets for this site.');

        $assignedSiteIds = collect([
            $profile->primary_site_id,
            ...($profile->secondary_site_ids ?? []),
        ])
            ->filter(fn (mixed $assignedSiteId): bool => is_numeric($assignedSiteId) && (int) $assignedSiteId > 0)
            ->map(fn (mixed $assignedSiteId): int => (int) $assignedSiteId)
            ->unique()
            ->values()
            ->all();
        abort_unless(
            collect($siteIds)->every(fn (int $siteId): bool => in_array($siteId, $assignedSiteIds, true)),
            403,
            'You are not authorized to access timesheets for this site.',
        );

        $lockedActor->setRelation('hrEmployeeProfile', $profile);

        return $lockedActor;
    }

    /**
     * Lock the old and proposed Client/Site provenance in the shared
     * Client -> Shift -> User/RBAC -> Profile -> Site order. Linked shifts
     * remain authoritative; manual rows must resolve to one Site before any
     * editable field or workflow evidence is written.
     *
     * @param  array<string, mixed>  $updates
     * @return list<int>
     */
    protected function lockCanonicalWriterProvenance(Timesheet $timesheet, array $updates): array
    {
        $requestedClientId = array_key_exists('client_id', $updates) && $updates['client_id'] !== null
            ? (int) $updates['client_id']
            : (array_key_exists('client_id', $updates) ? null : $this->clientId($timesheet));
        $shiftSnapshot = $timesheet->shift_id !== null
            ? Shift::query()->whereKey($timesheet->shift_id)->first(['id', 'client_id'])
            : null;

        $clientIds = collect([
            $this->clientId($timesheet),
            $requestedClientId,
            $shiftSnapshot?->client_id,
        ])
            ->filter(fn (mixed $clientId): bool => is_numeric($clientId) && (int) $clientId > 0)
            ->map(fn (mixed $clientId): int => (int) $clientId)
            ->unique()
            ->sort()
            ->values();
        $clients = Client::query()
            ->withTrashed()
            ->whereIn('id', $clientIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'site_id'])
            ->keyBy(fn (Client $client): int => (int) $client->id);
        abort_unless($clients->count() === $clientIds->count(), 403, 'You are not authorized to access timesheets for this site.');

        $lockedShift = null;
        if ($timesheet->shift_id !== null) {
            $lockedShift = Shift::query()
                ->whereKey($timesheet->shift_id)
                ->lockForUpdate()
                ->first(['id', 'client_id', 'site_id', 'user_id']);
            abort_unless(
                $lockedShift
                    && $clients->has((int) $lockedShift->client_id)
                    && (int) $lockedShift->client_id === (int) $timesheet->client_id
                    && (int) $lockedShift->client_id === (int) $requestedClientId
                    && (int) $lockedShift->user_id === (int) $timesheet->user_id,
                403,
                'You are not authorized to access timesheets for this site.',
            );
        }

        $currentSiteIds = collect([
            $timesheet->site_id,
            $timesheet->shift_site_id,
            $this->clientSiteId($clients, $this->clientId($timesheet)),
            $lockedShift?->site_id,
            $this->clientSiteId($clients, $lockedShift?->client_id),
        ])->filter(fn (mixed $siteId): bool => is_numeric($siteId) && (int) $siteId > 0)
            ->map(fn (mixed $siteId): int => (int) $siteId)
            ->unique()
            ->values();
        abort_unless($currentSiteIds->count() === 1, 403, 'You are not authorized to access timesheets for this site.');

        $proposedSiteIds = collect([
            array_key_exists('site_id', $updates) ? $updates['site_id'] : $timesheet->site_id,
            array_key_exists('shift_site_id', $updates) ? $updates['shift_site_id'] : $timesheet->shift_site_id,
            $this->clientSiteId($clients, $requestedClientId),
            $lockedShift?->site_id,
            $this->clientSiteId($clients, $lockedShift?->client_id),
        ])->filter(fn (mixed $siteId): bool => is_numeric($siteId) && (int) $siteId > 0)
            ->map(fn (mixed $siteId): int => (int) $siteId)
            ->unique()
            ->values();
        abort_unless($proposedSiteIds->count() === 1, 403, 'You are not authorized to access timesheets for this site.');

        return $currentSiteIds
            ->merge($proposedSiteIds)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /** @param Collection<int, Client> $clients */
    protected function clientSiteId(Collection $clients, mixed $clientId): ?int
    {
        if (! is_numeric($clientId) || (int) $clientId <= 0) {
            return null;
        }

        $siteId = $clients->get((int) $clientId)?->site_id;

        return is_numeric($siteId) && (int) $siteId > 0 ? (int) $siteId : null;
    }

    protected function isCurrentReviewProfile(HrEmployeeProfile $profile): bool
    {
        $today = now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString();

        return (bool) $profile->is_active
            && ($profile->start_date === null || $profile->start_date->toDateString() <= $today)
            && ($profile->end_date === null || $profile->end_date->toDateString() >= $today);
    }

    protected function clientId(Timesheet $timesheet): ?int
    {
        return $timesheet->client_id !== null
            ? (int) $timesheet->client_id
            : null;
    }

    protected function invalidateChangedManualAllocations(Timesheet $timesheet, ?int $originalClientId): void
    {
        $newClientId = $this->clientId($timesheet);

        // Materialised allocations belong to the previous primary client
        // selection. Invalidate them whenever a shiftless manual entry changes
        // or clears that client, inside the same transaction as the mutation.
        if ($timesheet->shift_id === null
            && ($newClientId === null || $originalClientId !== $newClientId)) {
            $timesheet->clientAllocations()->delete();
        }
    }

    protected function assertSubmittable(Timesheet $timesheet, string $action): void
    {
        if (! in_array($timesheet->status, ['draft', 'returned'], true)) {
            throw ValidationException::withMessages([
                'timesheet' => "Only draft or returned timesheets can be {$action}.",
            ]);
        }

        if ($timesheet->is_protected_from_changes) {
            throw ValidationException::withMessages([
                'timesheet' => 'Approved or payroll-linked timesheets require a controlled correction workflow.',
            ]);
        }

        if ($timesheet->linkedShiftIsCancelled()) {
            throw ValidationException::withMessages([
                'shift_id' => "Timesheets linked to cancelled shifts cannot be {$action}.",
            ]);
        }
    }

    protected function assertEditableSource(Timesheet $timesheet): void
    {
        if ($timesheet->attendance_session_id !== null) {
            throw ValidationException::withMessages([
                'timesheet' => 'Attendance-backed timesheets must be corrected through the governed attendance correction workflow.',
            ]);
        }
    }

    protected function assertApprovalAllowed(
        Timesheet $timesheet,
        User $actor,
        bool $persistReconciliation = true,
    ): void {
        if ((int) $timesheet->user_id === (int) $actor->id) {
            abort(403, 'You cannot approve your own timesheet.');
        }

        if ($timesheet->linkedShiftIsCancelled()) {
            abort(422, 'Timesheets linked to cancelled shifts cannot be approved.');
        }

        $this->reconciliation->assertWorkflowAllowed($timesheet, 'approved', $persistReconciliation);
    }

    /**
     * @param  array<int, mixed>  $workDates
     */
    protected function lockPayrollRunsForWorkDates(array $workDates, string $action): void
    {
        $dates = collect($workDates)
            ->filter(fn (mixed $date): bool => filled($date))
            ->map(fn (mixed $date): string => Carbon::parse($date)->toDateString())
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
                throw ValidationException::withMessages([
                    'timesheet' => "This timesheet is locked by a payroll run and cannot be {$action}.",
                ]);
            }
        }
    }

    protected function assertNotPayrollLinked(Timesheet $timesheet, string $action): void
    {
        if (! $timesheet->is_payroll_segment_complete && ! $timesheet->payroll_reference) {
            return;
        }

        throw ValidationException::withMessages([
            'timesheet' => "Payroll-linked timesheets cannot be {$action}.",
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function submittedFields(User $actor): array
    {
        return [
            'status' => 'submitted',
            'submitted_at' => now(),
            'submitted_by' => $actor->id,
            'approved_by' => null,
            'approved_at' => null,
            'decision_notes' => null,
            'returned_at' => null,
            'returned_by' => null,
            'returned_notes' => null,
        ];
    }

    protected function syncApprovedTimesheet(Timesheet $timesheet, ?HrTimeEntry $lockedEntry): void
    {
        // Use `load` (not `loadMissing`) for relations that may have been
        // partially eager-loaded by site access checks before approval.
        $timesheet->loadMissing([
            'user.hrEmployeeProfile',
        ]);
        $timesheet->load([
            'shift.site:id,name',
            'shift.client:id,first_name,last_name,site_id',
            'shift.serviceContext:id,name',
            'shift.staff:id,name',
            'client:id,first_name,last_name',
            'staff:id,name',
        ]);

        $snapshot = $this->snapshots->snapshotForTimesheet($timesheet);

        $timesheet->forceFill([
            'shift_site_id' => $snapshot['shift_site_id'] ?? $timesheet->shift_site_id,
            'shift_service_context_id' => $snapshot['shift_service_context_id'] ?? $timesheet->shift_service_context_id,
            'shift_site_name_snapshot' => $snapshot['shift_site_name_snapshot'] ?: $timesheet->shift_site_name_snapshot,
            'shift_location_snapshot' => $snapshot['shift_location_snapshot'] ?: $timesheet->shift_location_snapshot,
            'service_context_name_snapshot' => $snapshot['service_context_name_snapshot'] ?: $timesheet->service_context_name_snapshot,
            'client_name_snapshot' => $snapshot['client_name_snapshot'] ?: $timesheet->client_name_snapshot,
            'staff_name_snapshot' => $snapshot['staff_name_snapshot'] ?: $timesheet->staff_name_snapshot,
            'shift_type_snapshot' => $snapshot['shift_type_snapshot'] ?: $timesheet->shift_type_snapshot ?: 'standard',
            'coverage_roles_snapshot' => $snapshot['coverage_roles_snapshot'] ?? $timesheet->coverage_roles_snapshot ?? [],
        ])->saveQuietly();

        $freshTimesheet = $timesheet->fresh();
        $missingSnapshotFields = array_keys(array_filter([
            'client_name_snapshot' => blank($freshTimesheet?->client_name_snapshot),
            'staff_name_snapshot' => blank($freshTimesheet?->staff_name_snapshot),
            'shift_type_snapshot' => blank($freshTimesheet?->shift_type_snapshot),
        ]));

        if ($missingSnapshotFields !== []) {
            throw ValidationException::withMessages([
                'timesheet' => 'This timesheet is missing required snapshot data and cannot be approved safely: '.implode(', ', $missingSnapshotFields).'.',
            ]);
        }

        $this->hrSync->syncToHr($freshTimesheet, $lockedEntry, true);
        $this->billing->generateFromTimesheet($freshTimesheet);
    }

    protected function persistReconciliationBlockAfterRollback(Timesheet $timesheet, ValidationException $exception): void
    {
        $messages = collect($exception->errors())->flatten()->implode(' ');

        if (! str_contains($messages, 'reconciliation found blocking issues')) {
            return;
        }

        $freshTimesheet = $timesheet->fresh();

        if ($freshTimesheet) {
            $this->reconciliation->reconcile($freshTimesheet);
        }
    }
}
