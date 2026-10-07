<?php

namespace App\Domain\Shifts\Timesheets;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\AttendanceTimeEntryProjector;
use App\Models\Client;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\User;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use App\Services\Operations\TimesheetReconciliationService;
use App\Services\UserSiteAccessService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Draft creation and optional submission are one governed command. */
class TimesheetCreationService
{
    public function __construct(
        private readonly AttendanceTimeEntryProjector $payrollBoundary,
        private readonly AuthorizationEvidenceLockService $authorization,
        private readonly UserSiteAccessService $sites,
        private readonly TimesheetApprovalService $approvals,
        private readonly TimesheetReconciliationService $reconciliation,
        private readonly TimesheetCommandEvidence $commandEvidence,
    ) {}

    /** @param array<string, mixed> $data Validated create-dialog fields. */
    public function create(User $actor, array $data): TimesheetWorkflowResult
    {
        try {
            return DB::transaction(function () use ($actor, $data): TimesheetWorkflowResult {
                $this->payrollBoundary->lockApplicationPayrollMutex();
                $shiftMode = $data['mode'] === 'shift';
                $submit = ! empty($data['submit']);
                $shiftId = $shiftMode ? (int) $data['shift_id'] : null;
                $shiftClientId = $shiftId ? Shift::query()->whereKey($shiftId)->value('client_id') : null;
                $clientId = $shiftMode ? $shiftClientId : ($data['client_id'] ?? null);
                $client = $clientId ? Client::query()->whereKey($clientId)->lockForUpdate()->first() : null;
                abort_unless(! $clientId || $client, 403);
                $shift = $shiftId ? Shift::query()->whereKey($shiftId)->lockForUpdate()->first() : null;
                abort_unless(! $shiftMode || ($shift && (int) $shift->client_id === (int) $clientId), 403);

                $ownerId = $shift?->user_id ?? $actor->id;
                $users = $this->authorization->lockForUsers([$actor->id, $ownerId], [
                    'timesheets.create', 'timesheets.submit', 'timesheets.manageAny', 'hr.time.manage',
                ]);
                $lockedActor = $users->get((int) $actor->id);
                $owner = $users->get((int) $ownerId);
                abort_unless($lockedActor->isApproved() && $lockedActor->canDo('timesheets.create'), 403);
                abort_unless(! $submit || $lockedActor->canDo('timesheets.submit'), 403);
                abort_unless(! $shift || $lockedActor->canDo('timesheets.manageAny')
                    || (int) $shift->user_id === (int) $lockedActor->id, 403);

                $profiles = HrEmployeeProfile::query()->whereIn('user_id', $users->keys())
                    ->orderBy('user_id')->lockForUpdate()->get()->keyBy('user_id');
                foreach ($users as $user) {
                    $user->setRelation('hrEmployeeProfile', $profiles->get((int) $user->id));
                }
                $siteIds = collect([$data['site_id'] ?? null, $client?->site_id, $shift?->site_id])
                    ->filter(fn ($id) => $id !== null)->map(fn ($id) => (int) $id)->unique()->sort()->values();
                abort_unless($siteIds->count() <= 1, 403);
                abort_unless(! $client || $client->site_id, 403);
                abort_unless(! $shift || $siteIds->count() === 1, 403);
                $lockedSites = Site::query()->active()->notArchived()->whereNull('archived_at')
                    ->whereIn('id', $siteIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                abort_unless($lockedSites->count() === $siteIds->count(), 403);
                CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($lockedActor, $siteIds, $shift): void {
                    if ($siteIds->isEmpty()) {
                        // Existing standalone manual drafts can omit a Site. The
                        // normal submit guard still requires canonical provenance.
                        return;
                    }
                    $allowed = $this->sites->accessibleSiteIds($lockedActor, [], $reads);
                    abort_unless($siteIds->every(fn (int $id): bool => in_array($id, $allowed, true)), 403);
                    if ($shift?->user_id !== null) {
                        // Keep the existing Shift access recipient contract, using
                        // current reads of its complete nested authority evidence.
                        $worker = $this->sites->applyFleetRecipientEligibility(User::query()->whereKey($shift->user_id), (int) $siteIds->first());
                        abort_unless($reads->query($worker)->exists(), 403);
                    }
                });
                if ($shift && Timesheet::query()->where('shift_id', $shift->id)->where('user_id', $ownerId)->lockForUpdate()->exists()) {
                    throw ValidationException::withMessages(['shift_id' => 'A timesheet already exists for this shift and staff member.']);
                }

                $site = $lockedSites->first();
                $contextId = $shift ? $shift->service_context_id : $client?->service_context_id;
                $contextName = $contextId ? CurrentAuthorizationReads::within(fn ($reads) => $reads->query(ServiceContext::query()->whereKey($contextId))->value('name')) : null;
                $timesheet = new Timesheet($this->commandEvidence->normalizeInput([
                    'user_id' => $ownerId,
                    'client_id' => $client?->id,
                    'shift_id' => $shift?->id,
                    'activity_type' => $shiftMode ? null : ($data['activity_type'] ?? null),
                    'activity_items' => $shiftMode ? null : ($data['activity_items'] ?? []),
                    'site_id' => $data['site_id'] ?? null,
                    'shift_site_id' => $site?->id,
                    'shift_service_context_id' => $contextId,
                    'work_date' => $data['work_date'],
                    'starts_at' => $data['starts_at'],
                    'ends_at' => $data['ends_at'],
                    'break_minutes' => (int) ($data['break_minutes'] ?? $shift?->expected_break_minutes ?? 0),
                    'mileage_km' => $data['mileage_km'] ?? null,
                    'sleepover' => $shift ? (bool) $shift->is_sleepover : (bool) ($data['sleepover'] ?? false),
                    'on_call' => $shift ? (bool) $shift->is_on_call : (bool) ($data['on_call'] ?? false),
                    'allowance_notes' => $data['allowance_notes'] ?? null,
                    'public_holiday' => (bool) ($data['public_holiday'] ?? false),
                    'notes' => $data['notes'] ?? null,
                    'is_residential_billable' => (bool) ($data['is_residential_billable'] ?? false),
                    'shift_site_name_snapshot' => $site?->name,
                    'shift_location_snapshot' => $shift ? $shift->location : ($client ? ($data['notes'] ?? null) : $site?->name),
                    'service_context_name_snapshot' => $client || $shift ? $contextName : ($data['activity_type'] ?? null),
                    'client_name_snapshot' => $client ? (trim($client->first_name.' '.$client->last_name) ?: $client->full_name) : null,
                    'staff_name_snapshot' => $owner->name,
                    'shift_type_snapshot' => $shift?->shift_type ?? ($client ? 'standard' : ($data['activity_type'] ?? 'manual')),
                    'coverage_roles_snapshot' => $shift ? array_values(array_filter((array) ($shift->coverage_roles ?? []))) : [],
                    'status' => 'draft',
                    'created_by' => $lockedActor->id,
                ]));
                $timesheet = $this->commandEvidence->save($timesheet);
                $timesheet->setRelation('shift', $shift)->setRelation('client', $client)->setRelation('staff', $owner);
                if ($shift) {
                    $this->reconciliation->reconcile($timesheet);
                }
                if ($submit) {
                    $timesheet = $this->approvals->submit($timesheet, $lockedActor)->timesheet;
                }

                return new TimesheetWorkflowResult($this->commandEvidence->persisted($timesheet, ['status', 'submitted_by', 'submitted_at', 'created_by']), true, (int) $lockedActor->id);
            }, attempts: 3);
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) === 3572) {
                throw ValidationException::withMessages(['timesheet' => 'Current timesheet evidence is busy. Refresh and try again.']);
            }
            throw $exception;
        }
    }
}
