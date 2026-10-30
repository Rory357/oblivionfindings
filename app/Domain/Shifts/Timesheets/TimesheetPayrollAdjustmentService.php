<?php

namespace App\Domain\Shifts\Timesheets;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Shift;
use App\Models\Site;
use App\Models\Timesheet;
use App\Models\TimesheetAmendment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\AuthorizationEvidenceLockService;
use App\Services\CurrentAuthorizationReads;
use App\Services\Operations\WorkforceMutationGuard;
use App\Services\UserSiteAccessService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Only records externally processed amendments; all payroll algorithms are unchanged. */
class TimesheetPayrollAdjustmentService
{
    private const DENIAL = 'You are not authorized to access timesheets for this site.';

    public function __construct(
        private readonly WorkforceMutationGuard $mutex,
        private readonly AuthorizationEvidenceLockService $authorization,
        private readonly UserSiteAccessService $sites,
    ) {}

    public function process(TimesheetAmendment $bound, User $actor): TimesheetPayrollAdjustmentResult
    {
        // Bind the displayed parent tuple before waiting, never follow a retarget.
        $hint = Timesheet::query()->findOrFail($bound->timesheet_id);
        try {
            return DB::transaction(function () use ($bound, $hint, $actor): TimesheetPayrollAdjustmentResult {
                $this->mutex->lock();
                $timesheet = Timesheet::query()->whereKey($hint->id)->lockForUpdate()->firstOrFail();
                $amendment = TimesheetAmendment::query()->whereKey($bound->id)->lockForUpdate()->firstOrFail();
                abort_unless((int) $amendment->timesheet_id === (int) $bound->timesheet_id, 409, 'This amendment changed. Refresh and review the current record.');
                foreach (['user_id', 'client_id', 'shift_id', 'site_id', 'shift_site_id'] as $field) {
                    abort_unless($this->sameId($timesheet->getAttribute($field), $hint->getAttribute($field)), 403, self::DENIAL);
                }
                $currentActor = $this->currentAuthority($timesheet, $actor);
                if ($amendment->status !== TimesheetAmendment::STATUS_APPROVED) {
                    return new TimesheetPayrollAdjustmentResult($amendment, (int) $currentActor->id, false, 'Only approved amendments can be marked as processed.');
                }
                if (! $amendment->payroll_adjustment_required) {
                    return new TimesheetPayrollAdjustmentResult($amendment, (int) $currentActor->id, false, 'This amendment does not require payroll adjustment.');
                }
                if ($amendment->applied_at !== null) {
                    return new TimesheetPayrollAdjustmentResult($amendment, (int) $currentActor->id, false);
                }
                $before = $amendment->getAttributes();
                $at = now()->utc()->startOfSecond();
                $amendment->forceFill(['applied_at' => $at]);
                abort_unless($amendment->save() === true, 409, 'The processing marker could not be saved. Refresh and review the current record.');
                $saved = TimesheetAmendment::query()->whereKey($amendment->id)->lockForUpdate()->firstOrFail();
                foreach ($before as $field => $value) {
                    if (in_array($field, ['applied_at', 'updated_at'], true)) {
                        continue;
                    }
                    abort_unless($saved->getRawOriginal($field) === $value, 409, 'The processing marker could not be saved. Refresh and review the current record.');
                }
                abort_unless($saved->applied_at?->getTimestamp() === $at->getTimestamp(), 409, 'The processing marker could not be saved. Refresh and review the current record.');
                $lastAudit = AuditLog::query()->where('action', 'timesheet.amendment.payroll_processed')
                    ->where('auditable_type', $timesheet->getMorphClass())->where('auditable_id', $timesheet->id)
                    ->orderByDesc('id')->lockForUpdate()->first();
                AuditLogger::logOrFail('timesheet.amendment.payroll_processed', $timesheet, [
                    'actor_id' => (int) $currentActor->id, 'amendment_id' => (int) $saved->id,
                    'processed_by' => (int) $currentActor->id, 'applied_at' => $at->toIso8601String(),
                    'processing_method' => 'external',
                ]);
                $audit = AuditLog::query()->where('action', 'timesheet.amendment.payroll_processed')
                    ->where('auditable_type', $timesheet->getMorphClass())->where('auditable_id', $timesheet->id)
                    ->where('id', '>', $lastAudit?->id ?? 0)->orderBy('id')->lockForUpdate()->get();
                abort_unless($audit->count() === 1 && (int) $audit->first()->user_id === (int) $currentActor->id
                    && (int) ($audit->first()->meta['actor_id'] ?? 0) === (int) $currentActor->id
                    && (int) ($audit->first()->meta['processed_by'] ?? 0) === (int) $currentActor->id
                    && (int) ($audit->first()->meta['amendment_id'] ?? 0) === (int) $saved->id
                    && ($audit->first()->meta['processing_method'] ?? null) === 'external'
                    && ($audit->first()->meta['applied_at'] ?? null) === $at->toIso8601String(), 409, 'The processing audit could not be saved. Refresh and review the current record.');

                return new TimesheetPayrollAdjustmentResult($saved, (int) $currentActor->id, true);
            });
        } catch (QueryException $exception) {
            if (($exception->errorInfo[1] ?? null) === 3572) {
                throw ValidationException::withMessages(['payroll_adjustment' => 'The current processing evidence is being updated. Refresh and try again.']);
            }
            throw $exception;
        }
    }

    /** Current explicit translation of the native canonical Timesheet/Site predicate. */
    private function currentAuthority(Timesheet $timesheet, User $actor): User
    {
        $client = $timesheet->client_id === null ? null : Client::query()->withTrashed()->whereKey($timesheet->client_id)->lockForUpdate()->first();
        $shift = $timesheet->shift_id === null ? null : Shift::query()->whereKey($timesheet->shift_id)->lockForUpdate()->first();
        abort_unless($timesheet->client_id === null || ($client && ! $client->trashed() && (int) $client->site_id > 0), 403, self::DENIAL);
        abort_unless($timesheet->shift_id === null || ($shift && $this->sameId($shift->user_id, $timesheet->user_id)
            && $this->sameId($shift->client_id, $timesheet->client_id)), 403, self::DENIAL);
        if ($shift) {
            abort_unless($shift->site_id !== null || $client, 403, self::DENIAL);
            abort_unless(! $client || $shift->site_id === null || (int) $shift->site_id === (int) $client->site_id, 403, self::DENIAL);
        }
        $ids = collect([$timesheet->site_id, $timesheet->shift_site_id, $client?->site_id, $shift?->site_id])
            ->filter(fn ($id) => is_numeric($id) && (int) $id > 0)->map(fn ($id) => (int) $id)->unique()->values();
        abort_unless($ids->count() === 1, 403, self::DENIAL);
        $siteId = (int) $ids->first();
        $ids = collect([(int) $actor->id, (int) $timesheet->user_id])->unique()->sort()->values()->all();
        $users = CurrentAuthorizationReads::within(function ($reads) use ($ids) {
            abort_unless($reads->query(User::query()->whereIn('id', $ids)->orderBy('id'))->get()->count() === count($ids), 403, self::DENIAL);

            return collect($ids)->mapWithKeys(fn ($id) => [$id => $this->authorization->lockForUserWithoutWaiting($id, ['timesheets.approve', 'hr.time.approveTeam', 'timesheets.manageAny', 'hr.time.manage'])]);
        });
        $profiles = CurrentAuthorizationReads::within(fn ($reads) => $reads->query(HrEmployeeProfile::query()->whereIn('user_id', $ids)->orderBy('user_id'))->get()->keyBy('user_id'));
        foreach ($users as $user) {
            $user->setRelation('hrEmployeeProfile', $profiles->get($user->id));
        }
        $currentActor = $users->get((int) $actor->id);
        abort_unless($currentActor?->isApproved() && ($currentActor->canDo('timesheets.approve') || $currentActor->canDo('timesheets.manageAny')), 403);
        CurrentAuthorizationReads::within(function (CurrentAuthorizationReads $reads) use ($currentActor, $timesheet, $shift, $siteId): void {
            abort_unless($reads->query(Site::query()->whereKey($siteId))->exists(), 403, self::DENIAL);
            abort_unless(in_array($siteId, $this->sites->accessibleSiteIds($currentActor, [], $reads), true), 403, self::DENIAL);
            if ($shift) {
                $worker = $this->sites->applyFleetRecipientEligibility(User::query()->whereKey($timesheet->user_id), $siteId);
                abort_unless($reads->query($worker)->exists(), 403, self::DENIAL);
            }
        });

        return $currentActor;
    }

    private function sameId(mixed $left, mixed $right): bool
    {
        return $left === null ? $right === null : ($right !== null && (int) $left === (int) $right);
    }
}
