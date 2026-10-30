<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Rostering\AutoSchedule\RosterSuggestionService;
use App\Jobs\GenerateRosterSuggestionsJob;
use App\Models\Client;
use App\Models\RosterSuggestionRun;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\ShiftStaffEligibilityService;
use Database\Seeders\OperationsPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config()->set('app.timezone', 'UTC');
    config()->set('app.worker_timezone', 'Pacific/Auckland');
    $this->seed(RbacSeeder::class);
    $this->seed(OperationsPermissionsSeeder::class);
});

afterEach(function (): void {
    $this->travelBack();
});

it('estimates and suggests only shifts overlapping the local roster week', function (
    string $week,
    int $startOffsetHours,
    int $endOffsetHours,
    bool $queued,
): void {
    $timezone = (string) config('app.worker_timezone', 'Pacific/Auckland');
    $weekStart = Carbon::parse($week, $timezone)->startOfDay();
    $weekEnd = $weekStart->copy()->addDays(7);
    $this->travelTo($weekStart->copy()->subDays(2)->setTime(12, 0)->utc());

    expect($weekStart->offsetHours)->toBe($startOffsetHours)
        ->and($weekEnd->offsetHours)->toBe($endOffsetHours);

    $site = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
    $outsideSite = Site::factory()->create(['is_active' => true, 'archived' => false, 'archived_at' => null]);
    $manager = suggestionTimezoneStaffAt($site, 'coordinator');
    $candidate = suggestionTimezoneStaffAt($site, 'support_worker');
    $candidatePool = collect([$manager, $candidate]);
    expect(User::staff()->whereIn('id', $candidatePool->pluck('id'))->count())->toBe($candidatePool->count());

    $client = Client::factory()->create(['site_id' => $site->id, 'status' => 'active']);
    $outsideClient = Client::factory()->create(['site_id' => $outsideSite->id, 'status' => 'active']);
    $serviceContext = ServiceContext::factory()->create(['type' => 'residential', 'is_active' => true]);

    $createShift = fn (Site $shiftSite, Client $shiftClient, Carbon $startsAt, Carbon $endsAt): Shift => Shift::factory()
        ->unassigned()
        ->create([
            'site_id' => $shiftSite->id,
            'client_id' => $shiftClient->id,
            'service_context_id' => $serviceContext->id,
            'created_by' => $manager->id,
            'starts_at' => $startsAt->copy()->utc(),
            'ends_at' => $endsAt->copy()->utc(),
            'coverage_roles' => ['caregiver'],
            'status' => 'scheduled',
        ]);

    $included = collect([
        $createShift($site, $client, $weekStart, $weekStart->copy()->addMinutes(30)),
        $createShift($site, $client, $weekStart->copy()->setTime(9, 0), $weekStart->copy()->setTime(13, 0)),
        $createShift($site, $client, $weekEnd->copy()->subMinutes(30), $weekEnd),
    ]);
    $excluded = collect([
        $createShift($site, $client, $weekStart->copy()->subMinutes(30), $weekStart),
        $createShift($site, $client, $weekEnd, $weekEnd->copy()->addMinutes(30)),
        $createShift($outsideSite, $outsideClient, $weekStart->copy()->setTime(9, 0), $weekStart->copy()->setTime(13, 0)),
    ]);

    $eligibility = app(ShiftStaffEligibilityService::class);
    foreach ($included as $shift) {
        expect($eligibility->evaluate($shift, $candidate->fresh())->blocking_reasons)->toBe([]);
    }

    $service = app(RosterSuggestionService::class);
    $expectedEvaluations = $included->count() * $candidatePool->count();
    expect($service->estimateEvaluationCount($manager, $weekStart, $weekEnd, $site->id))->toBe($expectedEvaluations);

    Queue::fake();
    if ($queued) {
        $run = $service->generateOrQueue($manager, $week, $site->id, queueThreshold: 0);
        $run = $run->fresh();
        expect($run->status)->toBe(RosterSuggestionRun::STATUS_PENDING)
            ->and($run->started_at)->toBeNull()
            ->and($run->getRawOriginal('week_start'))->toBe($weekStart->toDateString())
            ->and($run->getRawOriginal('week_end'))->toBe($weekEnd->toDateString())
            ->and($run->suggestions()->exists())->toBeFalse();
        Queue::assertPushed(GenerateRosterSuggestionsJob::class, fn (GenerateRosterSuggestionsJob $job) => $job->runId === $run->id);

        (new GenerateRosterSuggestionsJob($run->id))->handle($service);
        $run = $run->fresh('suggestions');
    } else {
        $run = $service->generate($manager, $week, $site->id);
        Queue::assertNothingPushed();
    }

    $suggestedShiftIds = $run->suggestions->pluck('shift_id')->unique()->sort()->values()->all();
    expect($run->status)->toBe(RosterSuggestionRun::STATUS_COMPLETED)
        ->and($run->parameters['estimated_evaluations'])->toBe($expectedEvaluations)
        ->and($run->week_start->toDateString())->toBe($weekStart->toDateString())
        ->and($run->week_end->toDateString())->toBe($weekEnd->toDateString())
        ->and($run->totals['open_shifts'])->toBe($included->count())
        ->and($run->totals['suggested_shifts'])->toBe($included->count())
        ->and($suggestedShiftIds)->toBe($included->pluck('id')->sort()->values()->all())
        ->and($run->suggestions->whereIn('shift_id', $excluded->pluck('id'))->count())->toBe(0);
})->with([
    'NZ summer' => ['2026-10-12', 13, 13],
    'NZ winter' => ['2026-06-08', 12, 12],
    'NZ daylight saving ends' => ['2026-03-30', 13, 12],
    'NZ daylight saving starts' => ['2026-09-21', 12, 13],
])->with([
    'synchronous generation' => false,
    'queued persisted dates' => true,
]);

function suggestionTimezoneStaffAt(Site $site, string $role): User
{
    $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'position_role' => $role,
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    return $user;
}
