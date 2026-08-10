<?php

namespace App\Domain\Rostering\AutoSchedule;

use App\Domain\Shifts\Lifecycle\ShiftLifecycleService;
use App\Models\RosterSuggestion;
use App\Models\RosterSuggestionRun;
use App\Models\Shift;
use App\Models\User;
use App\Services\ShiftStaffEligibilityService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RosterSuggestionApplier
{
    public function __construct(
        private readonly ShiftStaffEligibilityService $eligibility,
        private readonly ShiftLifecycleService $lifecycle,
    ) {
    }

    public function applyOne(RosterSuggestion $suggestion, User $actor): RosterSuggestion
    {
        $validationException = null;

        $result = DB::transaction(function () use ($suggestion, $actor, &$validationException) {
            $locked = RosterSuggestion::query()
                ->with(['run', 'shift', 'candidate'])
                ->lockForUpdate()
                ->findOrFail($suggestion->id);

            $this->lockAndAttachShift($locked);

            try {
                $this->assertApplyable($locked);
                $this->assertEligibilityStillValid($locked);
                $this->applyLocked($locked, $actor);
            } catch (ValidationException $exception) {
                $validationException = $exception;
            }

            return $locked->fresh(['shift.staff', 'candidate']) ?? $locked;
        });

        if ($validationException) {
            throw $validationException;
        }

        return $result;
    }

    public function applyAccepted(RosterSuggestionRun $run, User $actor): array
    {
        $results = [
            'applied' => 0,
            'stale' => 0,
            'failed' => 0,
        ];

        DB::transaction(function () use ($run, $actor, &$results): void {
            $suggestions = RosterSuggestion::query()
                ->with(['run', 'shift', 'candidate'])
                ->where('roster_suggestion_run_id', $run->id)
                ->where('status', RosterSuggestion::STATUS_ACCEPTED)
                ->orderBy('shift_id')
                ->orderBy('rank')
                ->lockForUpdate()
                ->get();

            if ($suggestions->isEmpty()) {
                return;
            }

            $lockedShifts = $this->lockShiftsFor($suggestions);
            $this->attachLockedShifts($suggestions, $lockedShifts);
            $results = $this->preflightAcceptedSuggestions($suggestions);

            if ($results['stale'] > 0 || $results['failed'] > 0) {
                return;
            }

            foreach ($suggestions as $suggestion) {
                $this->attachLockedShifts(collect([$suggestion]), $lockedShifts);
                $this->assertApplyable($suggestion);
                $this->assertEligibilityStillValid($suggestion);
                $this->applyLocked($suggestion, $actor);
                $results['applied']++;
            }
        });

        return $results;
    }

    private function assertEligibilityStillValid(RosterSuggestion $suggestion): void
    {
        $eligibility = $this->eligibility->evaluate($suggestion->shift, $suggestion->candidate);
        if (! $eligibility->hasBlocks()) {
            return;
        }

        $suggestion->forceFill(['status' => RosterSuggestion::STATUS_STALE])->save();

        throw ValidationException::withMessages([
            'suggestion' => 'This suggestion is stale: '.implode(' ', $eligibility->blocking_reasons),
        ]);
    }

    private function applyLocked(RosterSuggestion $suggestion, User $actor): void
    {
        $this->lifecycle->assign($suggestion->shift, $actor, $suggestion->candidate);

        $suggestion->forceFill([
            'status' => RosterSuggestion::STATUS_APPLIED,
            'applied_by' => $actor->id,
            'applied_at' => now(),
        ])->save();
    }

    /**
     * @param  Collection<int, RosterSuggestion>  $suggestions
     * @return Collection<int, Shift>
     */
    private function lockShiftsFor(Collection $suggestions): Collection
    {
        return Shift::query()
            ->whereKey($suggestions->pluck('shift_id')->unique()->values()->all())
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    private function lockAndAttachShift(RosterSuggestion $suggestion): void
    {
        $shift = Shift::query()
            ->whereKey($suggestion->shift_id)
            ->lockForUpdate()
            ->first();

        if ($shift) {
            $suggestion->setRelation('shift', $shift);
        } else {
            $suggestion->unsetRelation('shift');
        }
    }

    /**
     * @param  Collection<int, RosterSuggestion>  $suggestions
     * @param  Collection<int, Shift>  $lockedShifts
     */
    private function attachLockedShifts(Collection $suggestions, Collection $lockedShifts): void
    {
        foreach ($suggestions as $suggestion) {
            if ($shift = $lockedShifts->get($suggestion->shift_id)) {
                $suggestion->setRelation('shift', $shift->fresh() ?? $shift);
            } else {
                $suggestion->unsetRelation('shift');
            }
        }
    }

    /**
     * @param  Collection<int, RosterSuggestion>  $suggestions
     * @return array{applied: int, stale: int, failed: int}
     */
    private function preflightAcceptedSuggestions(Collection $suggestions): array
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
                    $suggestion->forceFill(['status' => RosterSuggestion::STATUS_CONFLICTED])->save();
                    $results['stale']++;

                    continue;
                }

                $seenShiftIds[$suggestion->shift_id] = true;

                if ($this->overlapsAcceptedWindow($suggestion, $candidateWindows[$suggestion->candidate_user_id] ?? [])) {
                    $suggestion->forceFill(['status' => RosterSuggestion::STATUS_CONFLICTED])->save();
                    $results['stale']++;

                    continue;
                }

                $candidateWindows[$suggestion->candidate_user_id][] = [
                    'starts_at' => $suggestion->shift?->starts_at,
                    'ends_at' => $suggestion->shift?->ends_at,
                ];

                $this->assertApplyable($suggestion);
                $this->assertEligibilityStillValid($suggestion);
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
            $suggestion->forceFill(['status' => RosterSuggestion::STATUS_STALE])->save();

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
            $suggestion->forceFill(['status' => RosterSuggestion::STATUS_CONFLICTED])->save();

            throw ValidationException::withMessages([
                'suggestion' => 'This shift has already been assigned.',
            ]);
        }
    }
}
