<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\Hr\Models\HrOffboardingChecklist;
use App\Models\Shift;
use App\Models\ShiftOpenPosition;
use App\Models\ShiftReplacementRequest;
use App\Models\User;
use App\Models\WorkforceAvailabilityCoverageAction;
use App\Services\ShiftReplacementService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Projects approved HR unavailability into the native roster replacement
 * workflow. The source/action link is durable and idempotent, while the
 * replacement request and job-board position remain owned by rostering.
 */
class WorkforceAvailabilityCoverageService
{
    /** @var array<int, string> */
    private const ACTIONABLE_SHIFT_STATUSES = ['draft', 'scheduled', 'in_progress'];

    /** @var array<int, string> */
    private const ACTIVE_REPLACEMENT_STATUSES = [
        ShiftReplacementService::REQUESTED,
        ShiftReplacementService::CLAIMED,
    ];

    /** @return Collection<int, WorkforceAvailabilityCoverageAction> */
    public function syncApprovedLeave(HrLeaveRequest $request, User $actor, ?callable $persist = null): Collection
    {
        return DB::transaction(function () use ($request, $actor, $persist): Collection {
            $locked = HrLeaveRequest::query()
                ->lockForUpdate()
                ->findOrFail($request->getKey());

            if ($locked->status !== 'approved') {
                $this->cancelSourceActions(
                    WorkforceAvailabilityCoverageAction::SOURCE_LEAVE,
                    (int) $locked->id,
                    $actor,
                    $persist,
                );

                return collect();
            }

            return $this->syncSourceActions(
                WorkforceAvailabilityCoverageAction::SOURCE_LEAVE,
                (int) $locked->id,
                (int) $locked->user_id,
                Carbon::parse($locked->starts_at),
                Carbon::parse($locked->ends_at),
                $actor,
                $persist,
            );
        });
    }

    public function cancelLeave(HrLeaveRequest $request, User $actor): void
    {
        DB::transaction(function () use ($request, $actor): void {
            $locked = HrLeaveRequest::query()
                ->lockForUpdate()
                ->findOrFail($request->getKey());

            $this->cancelSourceActions(
                WorkforceAvailabilityCoverageAction::SOURCE_LEAVE,
                (int) $locked->id,
                $actor,
            );
        });
    }

    /** @return Collection<int, WorkforceAvailabilityCoverageAction> */
    public function syncOffboarding(HrOffboardingChecklist $checklist, User $actor): Collection
    {
        return DB::transaction(function () use ($checklist, $actor): Collection {
            $identity = HrOffboardingChecklist::query()
                ->select(['id', 'employee_profile_id'])
                ->findOrFail($checklist->getKey());
            $profile = HrEmployeeProfile::query()
                ->withTrashed()
                ->lockForUpdate()
                ->findOrFail($identity->employee_profile_id);
            $locked = HrOffboardingChecklist::query()
                ->where('employee_profile_id', $profile->id)
                ->lockForUpdate()
                ->findOrFail($identity->id);

            if (! in_array($locked->status, ['pending', 'in_progress'], true) || ! $locked->due_date) {
                $this->cancelSourceActions(
                    WorkforceAvailabilityCoverageAction::SOURCE_OFFBOARDING,
                    (int) $locked->id,
                    $actor,
                );

                return collect();
            }

            $timezone = (string) config('app.worker_timezone', config('app.timezone', 'UTC'));
            $unavailableFrom = Carbon::parse($locked->due_date->toDateString(), $timezone)
                ->endOfDay()
                ->utc();

            return $this->syncSourceActions(
                WorkforceAvailabilityCoverageAction::SOURCE_OFFBOARDING,
                (int) $locked->id,
                (int) $profile->user_id,
                $unavailableFrom,
                null,
                $actor,
            );
        });
    }

    public function cancelOffboarding(HrOffboardingChecklist $checklist, User $actor): void
    {
        DB::transaction(function () use ($checklist, $actor): void {
            $identity = HrOffboardingChecklist::query()
                ->select(['id', 'employee_profile_id'])
                ->findOrFail($checklist->getKey());
            $profile = HrEmployeeProfile::query()
                ->withTrashed()
                ->lockForUpdate()
                ->findOrFail($identity->employee_profile_id);
            $locked = HrOffboardingChecklist::query()
                ->where('employee_profile_id', $profile->id)
                ->lockForUpdate()
                ->findOrFail($identity->id);

            $this->cancelSourceActions(
                WorkforceAvailabilityCoverageAction::SOURCE_OFFBOARDING,
                (int) $locked->id,
                $actor,
            );
        });
    }

    /**
     * @return Collection<int, WorkforceAvailabilityCoverageAction>
     */
    private function syncSourceActions(
        string $sourceType,
        int $sourceId,
        int $subjectUserId,
        CarbonInterface $windowStartsAt,
        ?CarbonInterface $windowEndsAt,
        User $actor,
        ?callable $persist = null,
    ): Collection {
        $now = now();
        $targetShiftIds = $this->targetShiftQuery(
            $subjectUserId,
            $windowStartsAt,
            $windowEndsAt,
            $now,
        )
            ->pluck('shifts.id')
            ->map(fn ($id) => (int) $id);

        $existingShiftIds = WorkforceAvailabilityCoverageAction::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->pluck('shift_id')
            ->map(fn ($id) => (int) $id);

        Shift::query()
            ->whereIn('id', $targetShiftIds->merge($existingShiftIds)->unique()->sort()->values())
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $targetShifts = $this->targetShiftQuery(
            $subjectUserId,
            $windowStartsAt,
            $windowEndsAt,
            $now,
        )
            ->whereIn('shifts.id', $targetShiftIds)
            ->get();
        $targetShiftIds = $targetShifts
            ->pluck('id')
            ->map(fn ($id) => (int) $id);

        $existingActions = WorkforceAvailabilityCoverageAction::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $this->cancelActions(
            $existingActions->reject(
                fn (WorkforceAvailabilityCoverageAction $action) => $targetShiftIds->contains((int) $action->shift_id),
            ),
            $actor,
            $persist,
        );

        $actions = $targetShifts->map(function (Shift $shift) use (
            $actor,
            $existingActions,
            $sourceId,
            $sourceType,
            $subjectUserId,
            $windowEndsAt,
            $windowStartsAt,
            $persist,
        ): WorkforceAvailabilityCoverageAction {
            if ($persist) {
                $shift = $persist($shift, [], false);
            }
            $action = $existingActions->first(
                fn (WorkforceAvailabilityCoverageAction $candidate) => (int) $candidate->shift_id === (int) $shift->id,
            );

            [$replacement, $managesReplacement] = $this->ensureReplacement(
                $shift,
                $subjectUserId,
                $actor,
                $sourceType,
                $persist,
            );

            $attributes = [
                'replacement_request_id' => $replacement->id,
                'owner_user_id' => $action?->owner_user_id ?: $actor->id,
                'action_kind' => $shift->status === 'in_progress'
                    ? WorkforceAvailabilityCoverageAction::KIND_HANDOVER
                    : WorkforceAvailabilityCoverageAction::KIND_REPLACEMENT,
                'status' => WorkforceAvailabilityCoverageAction::STATUS_OPEN,
                'window_starts_at' => $windowStartsAt,
                'window_ends_at' => $windowEndsAt,
                'manages_replacement' => $managesReplacement || (
                    (int) ($action?->replacement_request_id) === (int) $replacement->id
                    && (bool) ($action?->manages_replacement)
                ),
                'cancelled_by' => null,
                'cancelled_at' => null,
            ];

            if ($action) {
                if ($persist) {
                    return $persist($action, $attributes);
                }
                $action->update($attributes);

                return $action->fresh();
            }

            $attributes = array_merge($attributes, [
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'shift_id' => $shift->id,
                'created_by' => $actor->id,
            ]);

            return $persist
                ? $persist(new WorkforceAvailabilityCoverageAction, $attributes)
                : WorkforceAvailabilityCoverageAction::query()->create($attributes);
        });

        return $actions->values();
    }

    /** @return Builder<Shift> */
    private function targetShiftQuery(
        int $subjectUserId,
        CarbonInterface $windowStartsAt,
        ?CarbonInterface $windowEndsAt,
        CarbonInterface $now,
    ): Builder {
        $query = Shift::query()
            ->where('user_id', $subjectUserId)
            ->whereIn('status', self::ACTIONABLE_SHIFT_STATUSES)
            ->where('ends_at', '>', $now)
            ->where('ends_at', '>', $windowStartsAt)
            ->when(
                $windowEndsAt,
                fn (Builder $shiftQuery, CarbonInterface $endsAt) => $shiftQuery
                    ->where('starts_at', '<', $endsAt),
            )
            ->orderBy('id');

        return $this->applyCoverageShiftIntegrityScope($query);
    }

    /**
     * Coverage replay runs after leave/offboarding may already have changed the
     * worker's mutable employment state. Retain the canonical shift, Site,
     * client, worker and profile binding without re-applying the assignment-time
     * active/date/approval eligibility checks that the source itself invalidates.
     *
     * @return Builder<Shift>
     */
    private function applyCoverageShiftIntegrityScope(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();
        $row = "`{$table}`";
        $siteColumn = $query->qualifyColumn('site_id');
        $clientColumn = $query->qualifyColumn('client_id');
        $clientSite = "(SELECT `site_id` FROM `clients` AS `coverage_client_site` WHERE `coverage_client_site`.`id` = {$row}.`client_id` LIMIT 1)";
        $authoritativeSite = "COALESCE({$row}.`site_id`, {$clientSite})";

        return $query
            ->where(function (Builder $siteIntegrity) use ($siteColumn): void {
                $siteIntegrity->whereNull($siteColumn)
                    ->orWhereHas('site');
            })
            ->where(function (Builder $clientIntegrity) use ($clientColumn, $siteColumn): void {
                $clientIntegrity->whereNull($clientColumn)
                    ->orWhereHas('client', fn (Builder $clientQuery) => $clientQuery
                        ->whereNotNull($clientQuery->qualifyColumn('site_id'))
                        ->where(function (Builder $siteAgreement) use ($siteColumn): void {
                            $siteAgreement->whereNull($siteColumn)
                                ->orWhereColumn('clients.site_id', $siteColumn);
                        }));
            })
            ->where(function (Builder $siteProvenance) use ($siteColumn): void {
                $siteProvenance->whereNotNull($siteColumn)
                    ->orWhereHas('client', fn (Builder $clientQuery) => $clientQuery
                        ->whereNotNull($clientQuery->qualifyColumn('site_id')));
            })
            ->whereRaw("EXISTS (SELECT 1 FROM `users` AS `coverage_worker` JOIN `hr_employee_profiles` AS `coverage_profile` ON `coverage_profile`.`user_id` = `coverage_worker`.`id` AND `coverage_profile`.`deleted_at` IS NULL WHERE `coverage_worker`.`id` = {$row}.`user_id` AND `coverage_worker`.`role` NOT IN ('client', 'next_of_kin') AND NOT EXISTS (SELECT 1 FROM `role_user` JOIN `roles` ON `roles`.`id` = `role_user`.`role_id` WHERE `role_user`.`user_id` = `coverage_worker`.`id` AND `roles`.`name` IN ('client', 'next_of_kin')) AND (`coverage_profile`.`primary_site_id` = {$authoritativeSite} OR JSON_CONTAINS(COALESCE(`coverage_profile`.`secondary_site_ids`, JSON_ARRAY()), JSON_ARRAY({$authoritativeSite}))))");
    }

    /** @return array{0: ShiftReplacementRequest, 1: bool} */
    private function ensureReplacement(
        Shift $shift,
        int $subjectUserId,
        User $owner,
        string $sourceType,
        ?callable $persist = null,
    ): array {
        $activeReplacement = ShiftReplacementRequest::query()
            ->where('shift_id', $shift->id)
            ->whereIn('status', self::ACTIVE_REPLACEMENT_STATUSES)
            ->latest('requested_at')
            ->lockForUpdate()
            ->first();

        if ($activeReplacement && (int) $activeReplacement->current_staff_id !== $subjectUserId) {
            throw ValidationException::withMessages([
                'coverage' => 'This shift changed while availability coverage was being reconciled.',
            ]);
        }

        if ($activeReplacement && $persist) {
            $activeReplacement = $persist($activeReplacement, [], false);
        }
        $managesReplacement = false;
        if (! $activeReplacement) {
            $attributes = [
                'shift_id' => $shift->id,
                'requested_by' => $owner->id,
                'current_staff_id' => $subjectUserId,
                'status' => ShiftReplacementService::REQUESTED,
                'reason' => $sourceType === WorkforceAvailabilityCoverageAction::SOURCE_LEAVE
                    ? 'Staff unavailable'
                    : 'Employment availability changed',
                'notes' => 'Coverage action created from an approved workforce availability change.',
                'required_skills' => [],
                'requested_at' => now(),
            ];
            $activeReplacement = $persist
                ? $persist(new ShiftReplacementRequest, $attributes)
                : ShiftReplacementRequest::query()->create($attributes);
            $managesReplacement = true;
        }

        $openPosition = ShiftOpenPosition::query()
            ->where('shift_id', $shift->id)
            ->whereIn('status', ['open', 'claimed'])
            ->orderBy('id')
            ->lockForUpdate()
            ->first();

        if ($openPosition && $persist) {
            $openPosition = $persist($openPosition, [], false);
        }

        // A standalone open position predates this source. Link it into the
        // replacement workflow, but do not let a later HR cancellation close
        // work that rostering already owned independently.
        if ($openPosition && $managesReplacement) {
            $managesReplacement = false;
        }

        if ($openPosition && $openPosition->replacement_request_id
            && (int) $openPosition->replacement_request_id !== (int) $activeReplacement->id
        ) {
            throw ValidationException::withMessages([
                'coverage' => 'This shift changed while availability coverage was being reconciled.',
            ]);
        }

        if ($openPosition) {
            if (! $openPosition->replacement_request_id) {
                if ($persist) {
                    $persist($openPosition, ['replacement_request_id' => $activeReplacement->id]);
                } else {
                    $openPosition->update(['replacement_request_id' => $activeReplacement->id]);
                }
            }
        } else {
            $attributes = [
                'shift_id' => $shift->id,
                'replacement_request_id' => $activeReplacement->id,
                'status' => 'open',
                'required_skills' => $activeReplacement->required_skills ?? [],
                'coverage_roles' => $shift->coverage_roles ?? [],
                'notes' => 'Replacement needed because staff availability changed.',
            ];
            if ($persist) {
                $persist(new ShiftOpenPosition, $attributes);
            } else {
                ShiftOpenPosition::query()->create($attributes);
            }
        }

        return [$activeReplacement, $managesReplacement];
    }

    private function cancelSourceActions(string $sourceType, int $sourceId, User $actor, ?callable $persist = null): void
    {
        $shiftIds = WorkforceAvailabilityCoverageAction::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->pluck('shift_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->sort()
            ->values();

        Shift::query()
            ->whereIn('id', $shiftIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $actions = WorkforceAvailabilityCoverageAction::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $this->cancelActions($actions, $actor, $persist);
    }

    /** @param Collection<int, WorkforceAvailabilityCoverageAction> $actions */
    private function cancelActions(Collection $actions, User $actor, ?callable $persist = null): void
    {
        $replacementIds = collect();

        foreach ($actions as $action) {
            if ($action->status !== WorkforceAvailabilityCoverageAction::STATUS_CANCELLED) {
                $attributes = [
                    'status' => WorkforceAvailabilityCoverageAction::STATUS_CANCELLED,
                    'cancelled_by' => $actor->id,
                    'cancelled_at' => now(),
                ];
                if ($persist) {
                    $persist($action, $attributes);
                } else {
                    $action->update($attributes);
                }
            }

            if ($action->replacement_request_id) {
                $replacementIds->push((int) $action->replacement_request_id);
            }
        }

        foreach ($replacementIds->unique()->sort()->values() as $replacementId) {
            $replacementActions = WorkforceAvailabilityCoverageAction::query()
                ->where('replacement_request_id', $replacementId)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id', 'status', 'manages_replacement']);
            if ($replacementActions->contains(
                fn (WorkforceAvailabilityCoverageAction $action): bool => $action->status
                    === WorkforceAvailabilityCoverageAction::STATUS_OPEN,
            )) {
                continue;
            }

            if (! $replacementActions->contains(
                fn (WorkforceAvailabilityCoverageAction $action): bool => $action->manages_replacement,
            )) {
                continue;
            }

            $replacement = ShiftReplacementRequest::query()
                ->lockForUpdate()
                ->find($replacementId);
            if (! $replacement || ! in_array($replacement->status, self::ACTIVE_REPLACEMENT_STATUSES, true)) {
                continue;
            }

            $attributes = [
                'status' => ShiftReplacementService::CANCELLED,
                'cancelled_by' => $actor->id,
                'cancelled_at' => now(),
            ];
            if ($persist) {
                $persist($replacement, $attributes);
            } else {
                $replacement->update($attributes);
            }

            ShiftOpenPosition::query()
                ->where('replacement_request_id', $replacement->id)
                ->whereIn('status', ['open', 'claimed'])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->each(fn (ShiftOpenPosition $position) => $persist
                    ? $persist($position, ['status' => 'cancelled'])
                    : $position->update(['status' => 'cancelled']));
        }
    }
}
