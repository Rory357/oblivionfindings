<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Rostering\AutoSchedule\RosterSuggestionService;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RosterSuggestion;
use App\Models\RosterSuggestionRun;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/*
 * Manual-first audit trail: accepting or dismissing a roster suggestion is a
 * manager decision about paid work, so the actor and time must be recorded on
 * the suggestion row. (Applying a suggestion already writes a shift timeline
 * event via ShiftLifecycleService::assign; accept/dismiss are planning-stage
 * decisions audited here, on the suggestion itself.)
 */

function makeSuggestionForRun(array $runAttributes = []): RosterSuggestion
{
    $site = Site::factory()->create(['is_active' => true, 'archived_at' => null]);
    $client = Client::factory()->create(['site_id' => $site->id, 'service_context_id' => null]);
    $run = RosterSuggestionRun::factory()->create(array_merge([
        'organization_id' => 1,
        'site_id' => $site->id,
    ], $runAttributes));

    $shift = Shift::factory()->unassigned()->create([
        'organization_id' => 1,
        'client_id' => $client->id,
        'site_id' => $site->id,
        'service_context_id' => null,
        'starts_at' => Carbon::parse('2026-05-04 09:00:00', 'Pacific/Auckland')->utc(),
        'ends_at' => Carbon::parse('2026-05-04 13:00:00', 'Pacific/Auckland')->utc(),
        'status' => 'scheduled',
    ]);

    return RosterSuggestion::factory()->create([
        'roster_suggestion_run_id' => $run->id,
        'shift_id' => $shift->id,
        'candidate_user_id' => User::factory()->create(['organization_id' => 1])->id,
        'rank' => 1,
        'status' => RosterSuggestion::STATUS_SUGGESTED,
    ]);
}

function makeSuggestionAuditActor(RosterSuggestion $suggestion): User
{
    $actor = User::factory()->create([
        'organization_id' => 1,
        'role' => 'support_worker',
        'approved_at' => now(),
        'external_clinical_account' => false,
    ]);
    $role = Role::create(['name' => 'suggestion-audit-'.Str::uuid(), 'label' => 'Suggestion audit test', 'type' => 'custom', 'level' => 10]);
    $permission = Permission::firstOrCreate(['key' => 'rostering.autoSchedule'],
        ['description' => 'rostering.autoSchedule', 'group' => 'Workforce', 'module' => 'operations']);
    $role->permissions()->attach($permission);
    $actor->roles()->attach($role);
    HrEmployeeProfile::factory()->create([
        'user_id' => $actor->id,
        'primary_site_id' => $suggestion->run->site_id,
        'secondary_site_ids' => [],
        'is_active' => true,
        'start_date' => '2025-01-01',
        'end_date' => null,
        'created_by' => $actor->id,
        'updated_by' => $actor->id,
    ]);

    return $actor;
}

it('records who accepted a suggestion and when', function () {
    $suggestion = makeSuggestionForRun();
    $actor = makeSuggestionAuditActor($suggestion);

    $accepted = app(RosterSuggestionService::class)->accept($suggestion, $actor);

    expect($accepted->status)->toBe(RosterSuggestion::STATUS_ACCEPTED)
        ->and($accepted->accepted_by)->toBe($actor->id)
        ->and($accepted->accepted_at)->not->toBeNull()
        ->and($accepted->dismissed_by)->toBeNull()
        ->and($accepted->dismissed_at)->toBeNull();
});

it('records who dismissed a suggestion and when', function () {
    $suggestion = makeSuggestionForRun();
    $actor = makeSuggestionAuditActor($suggestion);

    $dismissed = app(RosterSuggestionService::class)->dismiss($suggestion, $actor);

    expect($dismissed->status)->toBe(RosterSuggestion::STATUS_DISMISSED)
        ->and($dismissed->dismissed_by)->toBe($actor->id)
        ->and($dismissed->dismissed_at)->not->toBeNull();
});

it('re-accepting after a dismissal clears the dismissal attribution', function () {
    $service = app(RosterSuggestionService::class);
    $suggestion = makeSuggestionForRun();
    $dismisser = makeSuggestionAuditActor($suggestion);
    $accepter = makeSuggestionAuditActor($suggestion);

    $service->dismiss($suggestion, $dismisser);
    $accepted = $service->accept($suggestion->fresh(), $accepter);

    expect($accepted->status)->toBe(RosterSuggestion::STATUS_ACCEPTED)
        ->and($accepted->accepted_by)->toBe($accepter->id)
        ->and($accepted->dismissed_by)->toBeNull()
        ->and($accepted->dismissed_at)->toBeNull();
});

it('refuses to accept a suggestion from an expired run and marks it stale', function () {
    $suggestion = makeSuggestionForRun([
        'expires_at' => now()->subHour(),
    ]);
    $actor = makeSuggestionAuditActor($suggestion);

    try {
        app(RosterSuggestionService::class)->accept($suggestion, $actor);
        $this->fail('Expected accepting an expired suggestion to abort with 422.');
    } catch (HttpException $e) {
        expect($e->getStatusCode())->toBe(422);
    }

    expect($suggestion->fresh()->status)->toBe(RosterSuggestion::STATUS_STALE)
        ->and($suggestion->fresh()->accepted_by)->toBeNull();
});
