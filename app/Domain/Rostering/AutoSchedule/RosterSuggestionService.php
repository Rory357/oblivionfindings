<?php

namespace App\Domain\Rostering\AutoSchedule;

use App\Domain\Rostering\AutoSchedule\Strategies\EligibilityScoringStrategy;
use App\Domain\Rostering\RosterPeriodService;
use App\Jobs\GenerateRosterSuggestionsJob;
use App\Models\RosterSuggestion;
use App\Models\RosterSuggestionRun;
use App\Models\Shift;
use App\Models\User;
use App\Services\ShiftStaffEligibilityService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

class RosterSuggestionService
{
    public function __construct(
        private readonly RosterPeriodService $periods,
        private readonly EligibilityScoringStrategy $strategy,
        private readonly ShiftStaffEligibilityService $eligibility,
    ) {
    }

    public function generate(User $actor, CarbonInterface|string|null $week, int $siteId, int $limitPerShift = 3): RosterSuggestionRun
    {
        $weekStart = $this->periods->weekStart($week);
        $weekEnd = $weekStart->copy()->addDays(7);
        $estimatedEvaluations = $this->estimateEvaluationCount($actor, $weekStart, $weekEnd, $siteId);
        $run = $this->createRun($actor, $weekStart, $weekEnd, $siteId, $limitPerShift, RosterSuggestionRun::STATUS_RUNNING, [
            'estimated_evaluations' => $estimatedEvaluations,
        ]);

        return $this->processRun($run, $actor);
    }

    public function generateOrQueue(
        User $actor,
        CarbonInterface|string|null $week,
        int $siteId,
        int $limitPerShift = 3,
        ?int $queueThreshold = null,
    ): RosterSuggestionRun {
        $weekStart = $this->periods->weekStart($week);
        $weekEnd = $weekStart->copy()->addDays(7);
        $queueThreshold ??= (int) config('features.rostering.auto_schedule_queue_threshold', 1000);
        $estimatedEvaluations = $this->estimateEvaluationCount($actor, $weekStart, $weekEnd, $siteId);

        if ($estimatedEvaluations > $queueThreshold) {
            $run = $this->createRun($actor, $weekStart, $weekEnd, $siteId, $limitPerShift, RosterSuggestionRun::STATUS_PENDING, [
                'estimated_evaluations' => $estimatedEvaluations,
                'queue_threshold' => $queueThreshold,
            ]);

            GenerateRosterSuggestionsJob::dispatch($run->id);

            return $run;
        }

        $run = $this->createRun($actor, $weekStart, $weekEnd, $siteId, $limitPerShift, RosterSuggestionRun::STATUS_RUNNING, [
            'estimated_evaluations' => $estimatedEvaluations,
            'queue_threshold' => $queueThreshold,
        ]);

        return $this->processRun($run, $actor);
    }

    public function completePendingRun(RosterSuggestionRun $run): RosterSuggestionRun
    {
        $run->loadMissing('requestedBy');

        if ($run->isExpired()) {
            $run->forceFill(['status' => RosterSuggestionRun::STATUS_EXPIRED])->save();

            return $run->fresh() ?? $run;
        }

        if (! $run->requestedBy) {
            return $this->failRun($run, 'Suggestion run has no requesting user.');
        }

        return $this->processRun($run, $run->requestedBy);
    }

    public function estimateEvaluationCount(User $actor, CarbonInterface $weekStart, CarbonInterface $weekEnd, int $siteId): int
    {
        $openShiftCount = $this->openShiftsQuery($actor->organization_id, $siteId, $weekStart, $weekEnd)->count();

        if ($openShiftCount === 0) {
            return 0;
        }

        $candidateCount = User::staff()
            ->when($actor->organization_id, fn ($query) => $query->where('organization_id', $actor->organization_id))
            ->count();

        return $openShiftCount * max(1, $candidateCount);
    }

    private function createRun(
        User $actor,
        CarbonInterface $weekStart,
        CarbonInterface $weekEnd,
        int $siteId,
        int $limitPerShift,
        string $status,
        array $parameters = [],
    ): RosterSuggestionRun {
        $period = $this->periods->activeFor($actor->organization_id, $siteId, $weekStart);

        $run = RosterSuggestionRun::query()->create([
            'organization_id' => $actor->organization_id,
            'site_id' => $siteId,
            'roster_period_id' => $period?->id,
            'requested_by' => $actor->id,
            'week_start' => $weekStart->toDateString(),
            'week_end' => $weekEnd->toDateString(),
            'status' => $status,
            'strategy' => 'eligibility_scoring',
            'parameters' => [
                'limit_per_shift' => $limitPerShift,
                ...$parameters,
            ],
            'started_at' => $status === RosterSuggestionRun::STATUS_RUNNING ? now() : null,
            'expires_at' => now()->addDay(),
        ]);

        return $run;
    }

    private function processRun(RosterSuggestionRun $run, User $actor): RosterSuggestionRun
    {
        $run->forceFill([
            'status' => RosterSuggestionRun::STATUS_RUNNING,
            'started_at' => $run->started_at ?? now(),
            'completed_at' => null,
            'failure_message' => null,
        ])->save();

        $weekStart = $run->week_start->copy()->startOfDay();
        $weekEnd = $run->week_end->copy()->startOfDay();
        $limitPerShift = (int) ($run->parameters['limit_per_shift'] ?? 3);

        try {
            $shifts = $this->openShiftsQuery($run->organization_id, (int) $run->site_id, $weekStart, $weekEnd)
                ->with(['client:id,first_name,last_name,site_id', 'site:id,name'])
                ->orderBy('starts_at')
                ->get();

            $context = new RosterSuggestionContext($run, $actor, $shifts);
            foreach ($shifts as $shift) {
                $context->setCandidatePool($shift, $this->eligibility->candidatesFor($shift));
            }

            $suggestions = $this->strategy->suggest(
                $context,
                $limitPerShift,
            );

            DB::transaction(function () use ($run, $suggestions, $shifts): void {
                RosterSuggestion::query()
                    ->where('roster_suggestion_run_id', $run->id)
                    ->delete();

                foreach ($suggestions as $suggestion) {
                    RosterSuggestion::query()->create([
                        'roster_suggestion_run_id' => $run->id,
                        'shift_id' => $suggestion['shift']->id,
                        'candidate_user_id' => $suggestion['candidate_user_id'],
                        'rank' => $suggestion['rank'],
                        'score' => $suggestion['score'],
                        'reasons' => $suggestion['reasons'],
                        'eligibility_snapshot' => $suggestion['eligibility_snapshot'],
                        'status' => RosterSuggestion::STATUS_SUGGESTED,
                    ]);
                }

                $run->forceFill([
                    'status' => RosterSuggestionRun::STATUS_COMPLETED,
                    'completed_at' => now(),
                    'totals' => [
                        'open_shifts' => $shifts->count(),
                        'suggested_shifts' => $suggestions
                            ->map(fn (array $suggestion) => $suggestion['shift']->id)
                            ->unique()
                            ->count(),
                        'suggestion_count' => $suggestions->count(),
                    ],
                ])->save();
            });
        } catch (Throwable $exception) {
            $this->failRun($run, $exception->getMessage());

            throw $exception;
        }

        return $run->fresh(['suggestions']) ?? $run;
    }

    private function openShiftsQuery(?int $organizationId, int $siteId, CarbonInterface $weekStart, CarbonInterface $weekEnd): Builder
    {
        return Shift::query()
            ->where('organization_id', $organizationId)
            ->where('site_id', $siteId)
            ->whereNull('user_id')
            ->where('status', '!=', 'cancelled')
            ->where('starts_at', '<', $weekEnd)
            ->where('ends_at', '>', $weekStart);
    }

    private function failRun(RosterSuggestionRun $run, string $message): RosterSuggestionRun
    {
        $run->forceFill([
            'status' => RosterSuggestionRun::STATUS_FAILED,
            'completed_at' => now(),
            'failure_message' => $message,
        ])->save();

        return $run->fresh() ?? $run;
    }

    public function accept(RosterSuggestion $suggestion, User $actor): RosterSuggestion
    {
        $this->assertFresh($suggestion);

        $suggestion->forceFill([
            'status' => RosterSuggestion::STATUS_ACCEPTED,
            'accepted_by' => $actor->id,
            'accepted_at' => now(),
            'dismissed_by' => null,
            'dismissed_at' => null,
        ])->save();

        return $suggestion->fresh() ?? $suggestion;
    }

    public function dismiss(RosterSuggestion $suggestion, User $actor): RosterSuggestion
    {
        $suggestion->forceFill([
            'status' => RosterSuggestion::STATUS_DISMISSED,
            'dismissed_by' => $actor->id,
            'dismissed_at' => now(),
        ])->save();

        return $suggestion->fresh() ?? $suggestion;
    }

    public function expireStaleRuns(): int
    {
        return RosterSuggestionRun::query()
            ->whereIn('status', [RosterSuggestionRun::STATUS_PENDING, RosterSuggestionRun::STATUS_RUNNING, RosterSuggestionRun::STATUS_COMPLETED])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->update(['status' => RosterSuggestionRun::STATUS_EXPIRED]);
    }

    private function assertFresh(RosterSuggestion $suggestion): void
    {
        $suggestion->loadMissing('run');

        if ($suggestion->run?->isExpired()) {
            $suggestion->forceFill(['status' => RosterSuggestion::STATUS_STALE])->save();

            abort(422, 'This roster suggestion has expired. Generate a fresh run before applying it.');
        }
    }
}
