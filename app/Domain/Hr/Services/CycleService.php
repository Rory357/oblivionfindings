<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrGoal;
use App\Domain\Hr\Models\HrGoalCycle;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Resolves and manages the one application-wide OKR cycle catalogue.
 */
class CycleService
{
    public function __construct(private readonly HrGoalAccessService $goalAccess) {}

    /** All application cycles, newest window first. Seeds defaults if empty. */
    public function cycles(): Collection
    {
        $cycles = HrGoalCycle::query()->orderByDesc('starts_at')->get();

        if ($cycles->isEmpty()) {
            $this->seedDefaults();
            $cycles = HrGoalCycle::query()->orderByDesc('starts_at')->get();
        }

        return $cycles;
    }

    /** The cycle whose window contains today, else the most recent active one. */
    public function currentCycle(): ?HrGoalCycle
    {
        $cycles = $this->cycles()
            ->where('type', '!=', 'year');

        $today = Carbon::today();

        return $cycles->first(fn (HrGoalCycle $cycle) => $cycle->contains($today))
            ?? $cycles->firstWhere('status', 'active')
            ?? $cycles->first()
            ?? HrGoalCycle::query()->orderByDesc('starts_at')->first();
    }

    /** Seed FY plus four calendar quarters for the year covering today. */
    public function seedDefaults(): void
    {
        $year = (int) Carbon::today()->year;

        $fy = HrGoalCycle::query()->firstOrCreate(
            ['name' => "FY{$year}"],
            [
                'type' => 'year',
                'starts_at' => Carbon::create($year, 1, 1),
                'ends_at' => Carbon::create($year, 12, 31),
                'status' => 'active',
            ],
        );

        foreach ([1, 2, 3, 4] as $quarter) {
            $startMonth = ($quarter - 1) * 3 + 1;
            $starts = Carbon::create($year, $startMonth, 1);
            $ends = (clone $starts)->addMonths(3)->subDay();

            HrGoalCycle::query()->firstOrCreate(
                ['name' => "FY{$year} Q{$quarter}"],
                [
                    'type' => 'quarter',
                    'starts_at' => $starts,
                    'ends_at' => $ends,
                    'parent_cycle_id' => $fy->id,
                    'status' => $this->statusForWindow($starts, $ends),
                ],
            );
        }
    }

    /** Assign a cycle to any objective without one, using its date window. */
    public function backfillGoals(): void
    {
        $cycles = $this->cycles();
        $quarters = $cycles->where('type', 'quarter');
        $fy = $cycles->firstWhere('type', 'year');

        HrGoal::query()->whereNull('cycle_id')->chunkById(200, function ($goals) use ($quarters, $fy): void {
            foreach ($goals as $goal) {
                $anchor = $goal->due_date ?? $goal->start_date;
                $cycle = null;

                if ($anchor) {
                    $cycle = $quarters->first(fn (HrGoalCycle $candidate) => $candidate->contains($anchor));
                }

                $cycle = $cycle ?? $fy ?? $quarters->first();

                if ($cycle) {
                    $goal->forceFill(['cycle_id' => $cycle->id])->saveQuietly();
                }
            }
        });
    }

    /**
     * Clone an already-authorised objective selection into a target cycle.
     * Authorization is rechecked under lock so Site or employment changes
     * between selection and dispatch fail closed.
     *
     * @param  Collection<int, HrGoal>  $goals
     */
    public function rollover(
        User $viewer,
        HrGoalCycle $target,
        Collection $goals,
        bool $withKeyResults = true,
        ?HrGoalCycle $source = null,
    ): int {
        $goalIds = $goals
            ->map(fn (HrGoal $goal) => (int) $goal->getKey())
            ->values()
            ->all();
        abort_if($goalIds === [] || count($goalIds) !== count(array_unique($goalIds)), 404);

        return DB::transaction(function () use ($viewer, $target, $goalIds, $withKeyResults, $source): int {
            $lockedTarget = HrGoalCycle::query()
                ->lockForUpdate()
                ->findOrFail($target->getKey());
            $lockedGoals = $this->goalAccess
                ->applyCurrentGoalScope(HrGoal::query(), $viewer)
                ->whereKey($goalIds)
                ->when($source, fn ($query) => $query->where('cycle_id', $source->id))
                ->lockForUpdate()
                ->get();

            abort_unless($lockedGoals->count() === count($goalIds), 404);

            foreach ($lockedGoals as $goal) {
                $keyResults = $withKeyResults
                    ? $goal->keyResults()->lockForUpdate()->get()
                    : collect();
                $clone = $goal->replicateForApplication([
                    'progress_percentage',
                    'completed_at',
                    'last_checkin_at',
                ]);
                $clone->cycle_id = $lockedTarget->id;
                $clone->status = 'draft';
                $clone->progress_percentage = 0;
                $clone->confidence = 'on_track';
                $clone->completed_at = null;
                $clone->last_checkin_at = null;
                $clone->start_date = $lockedTarget->starts_at;
                $clone->due_date = $lockedTarget->ends_at;
                $clone->save();

                foreach ($keyResults as $keyResult) {
                    $keyResultClone = $keyResult->replicateForApplication([
                        'current_value',
                        'progress_percentage',
                        'status',
                    ]);
                    $keyResultClone->goal_id = $clone->id;
                    $keyResultClone->current_value = $keyResult->start_value;
                    $keyResultClone->progress_percentage = 0;
                    $keyResultClone->status = 'not_started';
                    $keyResultClone->confidence = 'on_track';
                    $keyResultClone->save();
                }
            }

            return $lockedGoals->count();
        }, attempts: 1);
    }

    private function statusForWindow(Carbon $starts, Carbon $ends): string
    {
        $today = Carbon::today();

        if ($today->lt($starts)) {
            return 'upcoming';
        }
        if ($today->gt($ends)) {
            return 'closed';
        }

        return 'active';
    }
}
