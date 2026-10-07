<?php

namespace App\Domain\Rostering\AutoSchedule;

use App\Domain\Hr\Services\AttendanceTimeEntryProjector;
use App\Domain\Shifts\Lifecycle\ShiftLifecycleService;
use App\Domain\Shifts\Lifecycle\ShiftLifecycleSource;
use App\Models\RosterSuggestion;
use App\Models\RosterSuggestionRun;
use App\Models\Shift;
use App\Models\User;
use App\Services\Eligibility\PreparedShiftWorkload;
use App\Services\Eligibility\ProposedShiftWorkloadService;
use App\Services\ShiftStaffEligibilityService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class RosterSuggestionApplier
{
    public function __construct(
        private readonly ShiftStaffEligibilityService $eligibility,
        private readonly ShiftLifecycleService $lifecycle,
    ) {}

    public function applyOne(RosterSuggestion $suggestion, User $actor): RosterSuggestion
    {
        return DB::transaction(function () use ($suggestion, $actor) {
            app(AttendanceTimeEntryProjector::class)->lockApplicationPayrollMutex();
            $locked = RosterSuggestion::query()->lockForUpdate()->findOrFail($suggestion->id);
            $run = RosterSuggestionRun::query()->lockForUpdate()->findOrFail($locked->roster_suggestion_run_id);
            $locked->setRelation('run', $run);
            $shiftHint = Shift::query()->find($locked->shift_id);
            if (! $shiftHint) {
                throw ValidationException::withMessages(['suggestion' => 'The shift or staff member for this suggestion no longer exists.']);
            }
            $candidateHint = (new User)->setRawAttributes(['id' => (int) $locked->candidate_user_id]);
            $inputs = $this->lifecycle->lockAssignmentBatch(collect([$shiftHint]), $actor, collect([$shiftHint->id => $candidateHint]));
            $actor = $inputs['actor'];
            abort_unless($actor->isApproved() && $actor->canDo('rostering.autoSchedule'), 403);
            $locked->setRelation('shift', $inputs['shifts']->get($locked->shift_id))
                ->setRelation('candidate', $inputs['users']->get($locked->candidate_user_id));
            $this->assertApplyable($locked);
            $this->applyLocked($locked, $actor);

            return $locked->fresh(['shift.staff', 'candidate']) ?? $locked;
        });
    }

    public function applyAccepted(RosterSuggestionRun $run, User $actor): array
    {
        $results = ['applied' => 0, 'stale' => 0, 'failed' => 0];

        DB::transaction(function () use ($run, $actor, &$results): void {
            app(AttendanceTimeEntryProjector::class)->lockApplicationPayrollMutex();
            $currentRun = RosterSuggestionRun::query()->lockForUpdate()->findOrFail($run->id);
            abort_unless((int) $currentRun->site_id === (int) $run->getRawOriginal('site_id'), 409,
                'This suggestion run changed Site. Reload before applying it.');
            $suggestions = RosterSuggestion::query()
                ->where('roster_suggestion_run_id', $currentRun->id)
                ->where('status', RosterSuggestion::STATUS_ACCEPTED)
                ->orderBy('shift_id')->orderBy('rank')->orderBy('id')->lockForUpdate()->get();
            if ($suggestions->isEmpty()) {
                return;
            }
            $suggestions->each(fn (RosterSuggestion $row) => $row->setRelation('run', $currentRun));
            $duplicates = $suggestions->count() - $suggestions->unique('shift_id')->count();
            if ($duplicates > 0) {
                $results['stale'] = $duplicates;

                return;
            }
            // Identity hints only; full domain locks follow Context -> Client ->
            // Shift -> sorted User/RBAC/Profile -> Site before any decision.
            $shiftHints = $this->snapshotShiftsFor($suggestions);
            if ($shiftHints->count() !== $suggestions->count()) {
                $results['stale'] = $suggestions->count();

                return;
            }
            $assigneeHints = $suggestions->mapWithKeys(fn (RosterSuggestion $row) => [
                $row->shift_id => (new User)->setRawAttributes(['id' => (int) $row->candidate_user_id]),
            ]);
            $inputs = $this->lifecycle->lockAssignmentBatch($shiftHints, $actor, $assigneeHints);
            $actor = $inputs['actor'];
            abort_unless($actor->isApproved() && $actor->canDo('rostering.autoSchedule'), 403);
            $this->attachLockedShifts($suggestions, $inputs['shifts']);
            foreach ($suggestions as $suggestion) {
                $suggestion->setRelation('candidate', $inputs['users']->get($suggestion->candidate_user_id));
            }
            $proposed = $suggestions->map(function (RosterSuggestion $row): Shift {
                $candidate = clone $row->shift;
                $candidate->user_id = $row->candidate_user_id;

                return $candidate;
            });
            try {
                $workloads = app(ProposedShiftWorkloadService::class)->prepareCurrent($proposed, $proposed->pluck('id')->all(), $actor);
                if ($proposed->pluck('user_id')->unique()->contains(fn ($id) => ! $workloads->has($id))) {
                    throw new \LogicException('The accepted assignment workload was not prepared.');
                }
            } catch (\Throwable $exception) {
                try {
                    Log::error('Accepted assignment workload unavailable', ['run_id' => $currentRun->id,
                        'exception_class' => $exception::class]);
                } catch (\Throwable) {
                    // Keep the established unavailable batch result shape.
                }
                $results = ['applied' => 0, 'stale' => 0, 'failed' => $suggestions->count()];

                return;
            }
            $results = $this->preflightAcceptedSuggestions($suggestions, $workloads);
            if ($results['stale'] > 0 || $results['failed'] > 0) {
                return;
            }
            foreach ($suggestions as $suggestion) {
                $this->assertApplyable($suggestion);
                $this->applyLocked($suggestion, $actor, $workloads->get($suggestion->candidate_user_id));
                $results['applied']++;
            }
        });

        return $results;
    }

    private function assertEligibilityStillValid(RosterSuggestion $suggestion, ?PreparedShiftWorkload $workload = null): void
    {
        $eligibility = $workload ? $this->eligibility->evaluate($suggestion->shift, $suggestion->candidate, workload: $workload)
            : $this->eligibility->evaluate($suggestion->shift, $suggestion->candidate);
        if (! $eligibility->hasBlocks()) {
            return;
        }

        throw ValidationException::withMessages([
            'suggestion' => 'This suggestion is stale: '.implode(' ', $eligibility->blocking_reasons),
        ]);
    }

    private function applyLocked(RosterSuggestion $suggestion, User $actor, ?PreparedShiftWorkload $workload = null): void
    {
        $this->lifecycle->assign(
            $suggestion->shift,
            $actor,
            $suggestion->candidate,
            [
                'override_acknowledged' => true,
                'override_reason' => 'Applied roster suggestion after current eligibility recheck.',
            ],
            source: ShiftLifecycleSource::Bulk,
            workload: $workload,
        );
        $before = $suggestion->getRawOriginal();
        if ($suggestion->forceFill([
            'status' => RosterSuggestion::STATUS_APPLIED,
            'applied_by' => $actor->id,
            'applied_at' => now(),
        ])->save() !== true) {
            throw ValidationException::withMessages(['suggestion' => 'This suggestion could not be saved. No assignments were applied.']);
        }
        $stored = RosterSuggestion::query()->whereKey($suggestion->id)->lockForUpdate()->firstOrFail();
        if ($stored->status !== RosterSuggestion::STATUS_APPLIED || (int) $stored->applied_by !== (int) $actor->id
            || ! $stored->applied_at || ! $stored->applied_at->equalTo($suggestion->applied_at)) {
            throw ValidationException::withMessages(['suggestion' => 'The saved suggestion did not match this command. No assignments were applied.']);
        }
        foreach (array_diff($suggestion->getFillable(), ['status', 'applied_by', 'applied_at']) as $field) {
            if ($stored->getRawOriginal($field) !== ($before[$field] ?? null)) {
                throw ValidationException::withMessages(['suggestion' => 'The suggestion source changed while saving. No assignments were applied.']);
            }
        }
    }

    /**
     * @param  Collection<int, RosterSuggestion>  $suggestions
     * @return Collection<int, Shift>
     */
    private function snapshotShiftsFor(Collection $suggestions): Collection
    {
        return Shift::query()
            ->whereKey($suggestions->pluck('shift_id')->unique()->values()->all())
            ->orderBy('id')
            ->get()
            ->keyBy('id');
    }

    /**
     * @param  Collection<int, RosterSuggestion>  $suggestions
     * @param  Collection<int, Shift>  $lockedShifts
     */
    private function attachLockedShifts(Collection $suggestions, Collection $lockedShifts): void
    {
        foreach ($suggestions as $suggestion) {
            if ($shift = $lockedShifts->get($suggestion->shift_id)) {
                $suggestion->setRelation('shift', $shift);
            } else {
                $suggestion->unsetRelation('shift');
            }
        }
    }

    /**
     * @param  Collection<int, RosterSuggestion>  $suggestions
     * @return array{applied: int, stale: int, failed: int}
     */
    private function preflightAcceptedSuggestions(Collection $suggestions, ?Collection $workloads = null): array
    {
        $results = [
            'applied' => 0,
            'stale' => 0,
            'failed' => 0,
        ];
        $seenShiftIds = [];
        $candidateWindows = [];

        foreach ($suggestions as $suggestion) {
            try {
                if (isset($seenShiftIds[$suggestion->shift_id])) {
                    $results['stale']++;

                    continue;
                }

                $seenShiftIds[$suggestion->shift_id] = true;

                if ($this->overlapsAcceptedWindow($suggestion, $candidateWindows[$suggestion->candidate_user_id] ?? [])) {
                    $results['stale']++;

                    continue;
                }

                $candidateWindows[$suggestion->candidate_user_id][] = [
                    'starts_at' => $suggestion->shift?->starts_at,
                    'ends_at' => $suggestion->shift?->ends_at,
                ];

                $this->assertApplyable($suggestion);
                $this->assertEligibilityStillValid($suggestion, $workloads?->get($suggestion->candidate_user_id));
            } catch (ValidationException) {
                $results['stale']++;
            } catch (\Throwable) {
                $results['failed']++;
            }
        }

        return $results;
    }

    /**
     * @param  array<int, array{starts_at: mixed, ends_at: mixed}>  $windows
     */
    private function overlapsAcceptedWindow(RosterSuggestion $suggestion, array $windows): bool
    {
        $startsAt = $suggestion->shift?->starts_at;
        $endsAt = $suggestion->shift?->ends_at;

        if (! $startsAt || ! $endsAt) {
            return false;
        }

        foreach ($windows as $window) {
            if (! $window['starts_at'] || ! $window['ends_at']) {
                continue;
            }

            if ($startsAt->lt($window['ends_at']) && $endsAt->gt($window['starts_at'])) {
                return true;
            }
        }

        return false;
    }

    private function assertApplyable(RosterSuggestion $suggestion): void
    {
        if (! in_array($suggestion->status, [
            RosterSuggestion::STATUS_SUGGESTED,
            RosterSuggestion::STATUS_ACCEPTED,
        ], true)) {
            throw ValidationException::withMessages([
                'suggestion' => 'Only suggested or accepted assignments can be applied.',
            ]);
        }

        if ($suggestion->run?->isExpired()) {
            throw ValidationException::withMessages([
                'suggestion' => 'This suggestion run has expired. Generate a fresh run before applying it.',
            ]);
        }

        if (! $suggestion->shift || ! $suggestion->candidate) {
            throw ValidationException::withMessages([
                'suggestion' => 'The shift or staff member for this suggestion no longer exists.',
            ]);
        }

        if (! $suggestion->run?->site_id || (int) $suggestion->shift->site_id !== (int) $suggestion->run->site_id) {
            throw ValidationException::withMessages([
                'suggestion' => 'The suggested shift no longer belongs to this Site run.',
            ]);
        }

        if ($suggestion->shift->user_id) {
            throw ValidationException::withMessages([
                'suggestion' => 'This shift has already been assigned.',
            ]);
        }
    }
}
