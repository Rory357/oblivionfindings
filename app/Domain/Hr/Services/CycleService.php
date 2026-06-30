<?php

namespace App\Domain\Hr\Services;

use App\Domain\Hr\Models\HrGoal;
use App\Domain\Hr\Models\HrGoalCycle;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Resolves and manages OKR cycles — the period spine the hero cycle selector
 * and every cycle-scoped stat hang off.
 */
class CycleService
{
    /** All cycles for a tenant, newest window first. Seeds defaults if empty. */
    public function cyclesForTenant(?int $tenantId): Collection
    {
        $tenantId = $tenantId ?? 1;

        $cycles = HrGoalCycle::forTenant($tenantId)->orderByDesc('starts_at')->get();

        if ($cycles->isEmpty()) {
            $this->seedDefaults($tenantId);
            $cycles = HrGoalCycle::forTenant($tenantId)->orderByDesc('starts_at')->get();
        }

        return $cycles;
    }

    /** The cycle whose window contains today, else the most recent active one. */
    public function currentCycle(?int $tenantId): ?HrGoalCycle
    {
        $tenantId = $tenantId ?? 1;
        $cycles = $this->cyclesForTenant($tenantId)
            ->where('type', '!=', 'year'); // prefer a quarter/half as the default lens

        $today = Carbon::today();

        $containing = $cycles->first(fn (HrGoalCycle $c) => $c->contains($today));
        if ($containing) {
            return $containing;
        }

        return $cycles->firstWhere('status', 'active')
            ?? $cycles->first()
            ?? HrGoalCycle::forTenant($tenantId)->orderByDesc('starts_at')->first();
    }

    /** Seed FY + four calendar quarters for the year covering today. */
    public function seedDefaults(?int $tenantId): void
    {
        $tenantId = $tenantId ?? 1;
        $year = (int) Carbon::today()->year;

        $fy = HrGoalCycle::firstOrCreate(
            ['tenant_id' => $tenantId, 'name' => "FY{$year}"],
            [
                'type' => 'year',
                'starts_at' => Carbon::create($year, 1, 1),
                'ends_at' => Carbon::create($year, 12, 31),
                'status' => 'active',
            ],
        );

        foreach ([1, 2, 3, 4] as $q) {
            $startMonth = ($q - 1) * 3 + 1;
            $starts = Carbon::create($year, $startMonth, 1);
            $ends = (clone $starts)->addMonths(3)->subDay();

            HrGoalCycle::firstOrCreate(
                ['tenant_id' => $tenantId, 'name' => "FY{$year} Q{$q}"],
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

    /** Assign a cycle to any goal that doesn't yet have one, by its window. */
    public function backfillGoals(?int $tenantId): void
    {
        $tenantId = $tenantId ?? 1;
        $cycles = $this->cyclesForTenant($tenantId);
        $quarters = $cycles->where('type', 'quarter');
        $fy = $cycles->firstWhere('type', 'year');

        HrGoal::forTenant($tenantId)->whereNull('cycle_id')->chunkById(200, function ($goals) use ($quarters, $fy) {
            foreach ($goals as $goal) {
                $anchor = $goal->due_date ?? $goal->start_date;
                $cycle = null;

                if ($anchor) {
                    $cycle = $quarters->first(fn (HrGoalCycle $c) => $c->contains($anchor));
                }

                $cycle = $cycle ?? $fy ?? $quarters->first();

                if ($cycle) {
                    $goal->forceFill(['cycle_id' => $cycle->id])->saveQuietly();
                }
            }
        });
    }

    /** Clone selected objectives into a target cycle (optionally with KRs). */
    public function rollover(HrGoalCycle $target, array $goalIds, bool $withKeyResults = true): int
    {
        $count = 0;

        DB::transaction(function () use ($target, $goalIds, $withKeyResults, &$count) {
            $goals = HrGoal::with('keyResults')->whereIn('id', $goalIds)->get();

            foreach ($goals as $goal) {
                $clone = $goal->replicate(['progress_percentage', 'completed_at', 'last_checkin_at']);
                $clone->cycle_id = $target->id;
                $clone->status = 'draft';
                $clone->progress_percentage = 0;
                $clone->confidence = 'on_track';
                $clone->completed_at = null;
                $clone->last_checkin_at = null;
                $clone->start_date = $target->starts_at;
                $clone->due_date = $target->ends_at;
                $clone->save();

                if ($withKeyResults) {
                    foreach ($goal->keyResults as $kr) {
                        $krClone = $kr->replicate(['current_value', 'progress_percentage', 'status']);
                        $krClone->goal_id = $clone->id;
                        $krClone->current_value = $kr->start_value;
                        $krClone->progress_percentage = 0;
                        $krClone->status = 'not_started';
                        $krClone->confidence = 'on_track';
                        $krClone->save();
                    }
                }

                $count++;
            }
        });

        return $count;
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
