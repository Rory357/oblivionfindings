<?php

use App\Domain\Rostering\AutoSchedule\RosterSuggestionService;
use App\Jobs\GenerateRosterSuggestionsJob;
use App\Models\Client;
use App\Models\RosterSuggestionRun;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

it('queues large roster suggestion runs above the evaluation threshold', function () {
    Queue::fake();

    $site = Site::factory()->create();
    $client = Client::factory()->create([
        'organization_id' => 1,
        'site_id' => $site->id,
    ]);
    $actor = User::factory()->create(['organization_id' => 1]);
    User::factory()->count(2)->create(['organization_id' => 1]);
    $weekStart = Carbon::parse('2026-05-04', 'Pacific/Auckland')->startOfDay();

    Shift::factory()->unassigned()->create([
        'organization_id' => 1,
        'client_id' => $client->id,
        'site_id' => $site->id,
        'starts_at' => $weekStart->copy()->setTime(9, 0)->utc(),
        'ends_at' => $weekStart->copy()->setTime(13, 0)->utc(),
        'status' => 'scheduled',
    ]);

    $run = app(RosterSuggestionService::class)
        ->generateOrQueue($actor, $weekStart, $site->id, queueThreshold: 1);

    expect($run->status)->toBe(RosterSuggestionRun::STATUS_PENDING)
        ->and($run->started_at)->toBeNull()
        ->and($run->parameters['estimated_evaluations'])->toBeGreaterThan(1);

    Queue::assertPushed(
        GenerateRosterSuggestionsJob::class,
        fn (GenerateRosterSuggestionsJob $job) => $job->runId === $run->id,
    );
});

it('generate roster suggestions job completes only pending runs', function () {
    $run = RosterSuggestionRun::factory()->create([
        'status' => RosterSuggestionRun::STATUS_PENDING,
    ]);

    $service = Mockery::mock(RosterSuggestionService::class);
    $service->shouldReceive('completePendingRun')
        ->once()
        ->with(Mockery::on(fn (RosterSuggestionRun $candidate) => $candidate->is($run)));

    (new GenerateRosterSuggestionsJob($run->id))->handle($service);
});
