<?php

namespace App\Domain\Rostering\AutoSchedule;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\HrCurrentStaffService;
use App\Domain\Rostering\AutoSchedule\Strategies\EligibilityScoringStrategy;
use App\Domain\Rostering\RosterPeriodService;
use App\Jobs\GenerateRosterSuggestionsJob;
use App\Models\RosterSuggestion;
use App\Models\RosterSuggestionRun;
use App\Models\Shift;
use App\Models\User;
use App\Services\UserSiteAccessService;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class RosterSuggestionService
{
    public function __construct(
        private readonly RosterPeriodService $periods,
        private readonly EligibilityScoringStrategy $strategy,
        private readonly HrCurrentStaffService $currentStaff,
        private readonly UserSiteAccessService $siteAccess,
    ) {}

    public function generate(User $actor, CarbonInterface|string|null $week, int $siteId, int $limitPerShift = 3): RosterSuggestionRun
    {
        $this->siteAccess->assertCanAccessSiteId($actor, $siteId, ['shifts.manageAny']);
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
        $this->siteAccess->assertCanAccessSiteId($actor, $siteId, ['shifts.manageAny']);
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
        $openShiftCount = $this->openShiftsQuery($siteId, $weekStart, $weekEnd)->count();

        if ($openShiftCount === 0) {
            return 0;
        }

        $candidateCount = $this->currentSiteCandidates($siteId)->count();

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
        $period = $this->periods->activeFor($siteId, $weekStart);

        $run = RosterSuggestionRun::query()->create([
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
        $this->siteAccess->assertCanAccessSiteId($actor, (int) $run->site_id, ['shifts.manageAny']);

        $run->forceFill([
            'status' => RosterSuggestionRun::STATUS_RUNNING,
            'started_at' => $run->started_at ?? now(),
            'completed_at' => null,
            'failure_message' => null,
        ])->save();

        $timezone = (string) config('app.worker_timezone', 'Pacific/Auckland');
        $weekStart = Carbon::parse($run->week_start->toDateString(), $timezone)->startOfDay();
        $weekEnd = Carbon::parse($run->week_end->toDateString(), $timezone)->startOfDay();
        $limitPerShift = (int) ($run->parameters['limit_per_shift'] ?? 3);

        try {
            $shifts = $this->openShiftsQuery((int) $run->site_id, $weekStart, $weekEnd)
                ->with(['client:id,first_name,last_name,site_id', 'site:id,name'])
                ->orderBy('starts_at')
                ->get()
                ->filter(fn (Shift $shift) => $shift->site && (int) $shift->site->id === (int) $run->site_id
                    && ($shift->client_id === null || ($shift->client
                        && (int) $shift->client->id === (int) $shift->client_id
                        && (int) $shift->client->site_id === (int) $run->site_id)));

            $currentCandidates = $this->currentSiteCandidates((int) $run->site_id);
            $context = new RosterSuggestionContext($run, $actor, $shifts);
            foreach ($shifts as $shift) {
                $context->setCandidatePool($shift, $this->candidatesForShift($shift, $currentCandidates));
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

    private function openShiftsQuery(int $siteId, CarbonInterface $weekStart, CarbonInterface $weekEnd): Builder
    {
        return $this->siteAccess->applyShiftIntegrityScope(Shift::query()
            ->employeeDuties()
            ->where('site_id', $siteId)
            ->whereNull('user_id')
            ->where('status', '!=', 'cancelled')
            ->where('starts_at', '<', $weekEnd->copy()->utc())
            ->where('ends_at', '>', $weekStart->copy()->utc()));
    }

    /** @return Collection<int, User> */
    private function currentSiteCandidates(int $siteId): Collection
    {
        return $this->currentStaff->currentUsersQuery()
            ->with('hrEmployeeProfile')
            ->get()
            ->filter(function (User $candidate) use ($siteId): bool {
                $profile = $candidate->hrEmployeeProfile;

                return $profile instanceof HrEmployeeProfile
                    && ((int) $profile->primary_site_id === $siteId
                        || collect($profile->secondary_site_ids ?? [])->contains(
                            fn (mixed $assignedSiteId): bool => (int) $assignedSiteId === $siteId,
                        ));
            })
            ->values();
    }

    /**
     * Current assignment authority and employment throughout the shift are
     * prerequisites. The strategy still evaluates the complete eligibility
     * stack before any candidate becomes a persisted suggestion.
     *
     * @param  Collection<int, User>  $currentCandidates
     * @return Collection<int, User>
     */
    private function candidatesForShift(Shift $shift, Collection $currentCandidates): Collection
    {
        $timezone = (string) config('app.worker_timezone', 'Pacific/Auckland');
        $startsOn = $shift->starts_at->copy()->timezone($timezone)->toDateString();
        $endsOn = $shift->ends_at->copy()->timezone($timezone)->toDateString();

        return $currentCandidates->filter(function (User $candidate) use ($startsOn, $endsOn): bool {
            $profile = $candidate->hrEmployeeProfile;

            return ($profile->start_date === null || $profile->start_date->toDateString() <= $startsOn)
                && ($profile->end_date === null || $profile->end_date->toDateString() >= $endsOn);
        })->values();
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
        $result = app(RosterSuggestionCommand::class)->decide('accept', $suggestion, $actor);
        if ($result->outcome === 'expired_marked_stale') {
            // The legacy stale marker is already persisted before the rejection.
            abort(422, 'This roster suggestion has expired. Generate a fresh run before applying it.');
        }

        return $result->model;
    }

    public function dismiss(RosterSuggestion $suggestion, User $actor): RosterSuggestion
    {
        return app(RosterSuggestionCommand::class)->decide('dismiss', $suggestion, $actor)->model;
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
