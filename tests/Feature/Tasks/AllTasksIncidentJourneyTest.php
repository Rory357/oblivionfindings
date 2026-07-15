<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\ControlRoom\AlertTask;
use App\Models\ControlRoomAlert;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\HsInvestigation;
use App\Models\IncidentFollowup;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\ControlRoom\ControlRoomAlertLifecycleService;
use App\Services\Tasks\TaskAggregator;
use Database\Seeders\RbacSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

function makeIncidentJourneyTasksUser(Site $site, array $permissionKeys): User
{
    $user = User::factory()->create([
        'organization_id' => $site->tenant_id,
        'role' => 'coordinator',
        'approved_at' => now(),
    ]);

    foreach ($permissionKeys as $permissionKey) {
        $permission = Permission::query()->where('key', $permissionKey)->firstOrFail();
        $user->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => true],
        ]);
    }

    HrEmployeeProfile::factory()->create([
        'tenant_id' => $site->tenant_id,
        'user_id' => $user->id,
        'position_role' => 'coordinator',
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
    ]);

    return $user;
}

/**
 * @return array{incident: ClientIncident, alert: ?ControlRoomAlert, event: HsEvent, followup: IncidentFollowup, investigation: HsInvestigation, action: HsCorrectiveAction}
 */
function makeUniversalIncidentJourney(
    Site $site,
    Client $client,
    User $owner,
    string $source,
    bool $withAlert = true,
): array {
    $incident = ClientIncident::withoutEvents(
        fn () => ClientIncident::factory()->submitted()->atSite($site)->create([
            'client_id' => $client->id,
            'reported_by' => $owner->id,
            'submitted_at' => now(),
            'source' => $source,
            'title' => ucfirst(str_replace('_', ' ', $source)).' journey',
        ]),
    );
    $event = HsEvent::factory()->forClientIncident($incident)->handoverAccepted($owner, $owner)->create([
        'organization_id' => $site->tenant_id,
        'site_id' => $site->id,
        'client_id' => $client->id,
        'owner_user_id' => $owner->id,
    ]);
    $alert = $withAlert
        ? ControlRoomAlert::factory()->triaging()->create([
            'site_id' => $site->id,
            'client_id' => $client->id,
            'context' => ['incident_id' => $incident->id],
        ])
        : null;

    $incident->forceFill([
        'hs_event_id' => $event->id,
        'control_room_alert_id' => $alert?->id,
    ])->saveQuietly();
    $event->forceFill(['control_room_alert_id' => $alert?->id])->saveQuietly();

    $followup = IncidentFollowup::factory()->create([
        'client_incident_id' => $incident->id,
        'assigned_to_user_id' => $owner->id,
        'due_at' => now()->addDays(2),
        'completed_at' => null,
    ]);
    $investigation = HsInvestigation::factory()->inProgress()->create([
        'hs_event_id' => $event->id,
        'lead_investigator_id' => $owner->id,
        'target_completion_date' => now()->addDays(5),
    ]);
    $action = HsCorrectiveAction::factory()->inProgress()->create([
        'hs_event_id' => $event->id,
        'hs_investigation_id' => $investigation->id,
        'assigned_to_user_id' => $owner->id,
        'assigned_by_user_id' => $owner->id,
        'due_date' => now()->addDays(7),
    ]);

    return compact('incident', 'alert', 'event', 'followup', 'investigation', 'action');
}

it('groups the five incident entry paths under truthful journey references without collapsing separate work', function () {
    $site = Site::factory()->create(['tenant_id' => 1]);
    $user = makeIncidentJourneyTasksUser($site, [
        'incidents.viewAny',
        'controlRoom.viewAny',
        'hazards.view',
    ]);
    $client = Client::factory()->create([
        'organization_id' => $site->tenant_id,
        'site_id' => $site->id,
    ]);
    $journeys = collect([
        makeUniversalIncidentJourney($site, $client, $user, 'control_room'),
        makeUniversalIncidentJourney($site, $client, $user, 'support_worker', false),
        makeUniversalIncidentJourney($site, $client, $user, 'manual'),
        makeUniversalIncidentJourney($site, $client, $user, 'sensor'),
        makeUniversalIncidentJourney($site, $client, $user, 'medication'),
    ]);

    $items = collect((new TaskAggregator)->arrayFor($user));

    foreach ($journeys as $journey) {
        $incident = $journey['incident'];
        $event = $journey['event'];
        $expectedIds = collect(['incident', 'followup', 'hs_event', 'hs_investigation', 'corrective_action'])
            ->map(fn (string $source) => match ($source) {
                'incident' => "incident-{$incident->id}",
                'followup' => "followup-{$journey['followup']->id}",
                'hs_event' => "hs_event-{$event->id}",
                'hs_investigation' => "hs_investigation-{$journey['investigation']->id}",
                default => "corrective_action-{$journey['action']->id}",
            });
        if ($journey['alert']) {
            $expectedIds->push('alert-'.$journey['alert']->id);
        }

        $group = $items->where('journey.key', 'incident-'.$incident->id);

        expect($group->pluck('id')->sort()->values()->all())
            ->toBe($expectedIds->sort()->values()->all())
            ->and($group->every(fn (array $item) => data_get($item, 'journey.references.incident') === $incident->reference_number))
            ->toBeTrue()
            ->and($group->every(fn (array $item) => data_get($item, 'journey.references.health_safety') === $event->reference_number))
            ->toBeTrue()
            ->and($group->every(fn (array $item) => data_get($item, 'journey.references.control_room') === $journey['alert']?->reference_number))
            ->toBeTrue()
            ->and($group->every(fn (array $item) => $item['site']['id'] === $site->id))
            ->toBeTrue()
            ->and($group->every(fn (array $item) => $item['client']['id'] === $client->id))
            ->toBeTrue();
    }
});

it('uses the same tenant and incident-time site scope as the source modules', function () {
    $visibleSite = Site::factory()->create(['tenant_id' => 1]);
    $hiddenSite = Site::factory()->create(['tenant_id' => 1]);
    $user = makeIncidentJourneyTasksUser($visibleSite, [
        'incidents.viewAny',
        'controlRoom.viewAny',
        'hazards.view',
    ]);
    $visibleClient = Client::factory()->create([
        'organization_id' => 1,
        'site_id' => $visibleSite->id,
    ]);
    $hiddenClient = Client::factory()->create([
        'organization_id' => 1,
        'site_id' => $hiddenSite->id,
    ]);
    $visible = makeUniversalIncidentJourney($visibleSite, $visibleClient, $user, 'manual');
    $hidden = makeUniversalIncidentJourney($hiddenSite, $hiddenClient, $user, 'manual');

    $ids = collect((new TaskAggregator)->arrayFor($user))->pluck('id');

    expect($ids)->toContain('incident-'.$visible['incident']->id)
        ->and($ids)->toContain('alert-'.$visible['alert']->id)
        ->and($ids)->toContain('corrective_action-'.$visible['action']->id)
        ->and($ids)->not->toContain('incident-'.$hidden['incident']->id)
        ->and($ids)->not->toContain('alert-'.$hidden['alert']->id)
        ->and($ids)->not->toContain('hs_event-'.$hidden['event']->id)
        ->and($ids)->not->toContain('hs_investigation-'.$hidden['investigation']->id)
        ->and($ids)->not->toContain('corrective_action-'.$hidden['action']->id);
});

it('finds every related responsibility by any official journey reference', function () {
    $site = Site::factory()->create(['tenant_id' => 1]);
    $user = makeIncidentJourneyTasksUser($site, [
        'incidents.viewAny',
        'controlRoom.viewAny',
        'hazards.view',
    ]);
    $client = Client::factory()->create(['organization_id' => 1, 'site_id' => $site->id]);
    $journey = makeUniversalIncidentJourney($site, $client, $user, 'sensor');
    $expectedIds = collect((new TaskAggregator)->arrayFor($user))
        ->where('journey.key', 'incident-'.$journey['incident']->id)
        ->pluck('id')
        ->sort()
        ->values()
        ->all();

    foreach ([
        $journey['alert']->reference_number,
        $journey['incident']->reference_number,
        $journey['event']->reference_number,
    ] as $reference) {
        $found = collect((new TaskAggregator)->arrayFor($user, ['q' => $reference]))
            ->pluck('id')
            ->sort()
            ->values()
            ->all();

        expect($found)->toBe($expectedIds);
    }
});

it('keeps completed journey work in explicit history only', function () {
    $site = Site::factory()->create(['tenant_id' => 1]);
    $user = makeIncidentJourneyTasksUser($site, ['hazards.view']);
    $event = HsEvent::factory()->closed()->create([
        'organization_id' => 1,
        'site_id' => $site->id,
    ]);
    $action = HsCorrectiveAction::factory()->closed()->create(['hs_event_id' => $event->id]);

    $activeIds = collect((new TaskAggregator)->arrayFor($user))->pluck('id');
    $historyIds = collect((new TaskAggregator)->arrayFor($user, ['include_done' => true]))->pluck('id');

    expect($activeIds)->not->toContain('hs_event-'.$event->id)
        ->and($activeIds)->not->toContain('corrective_action-'.$action->id)
        ->and($historyIds)->toContain('hs_event-'.$event->id)
        ->and($historyIds)->toContain('corrective_action-'.$action->id);
});

it('replaces transferred operational work with one retry-safe H&S responsibility', function () {
    $site = Site::factory()->create(['tenant_id' => 1]);
    $user = makeIncidentJourneyTasksUser($site, [
        'controlRoom.viewAny',
        'hazards.view',
        'hazards.manage',
    ]);
    $client = Client::factory()->create(['organization_id' => 1, 'site_id' => $site->id]);
    $journey = makeUniversalIncidentJourney($site, $client, $user, 'control_room');
    $task = AlertTask::query()->create([
        'alert_id' => $journey['alert']->id,
        'title' => 'Replace unsafe bathroom rail',
        'description' => 'Permanent repair with evidence required.',
        'assigned_to_user_id' => $user->id,
        'created_by_user_id' => $user->id,
        'status' => AlertTask::STATUS_IN_PROGRESS,
        'priority' => 'high',
        'due_at' => now()->addDays(3),
    ]);

    $lifecycle = app(ControlRoomAlertLifecycleService::class);
    $first = $lifecycle->transferTaskToHealthSafety($task, $user);
    $retried = $lifecycle->transferTaskToHealthSafety($task, $user);
    $items = collect((new TaskAggregator)->arrayFor($user))
        ->where('journey.key', 'incident-'.$journey['incident']->id);

    expect($retried->id)->toBe($first->id)
        ->and($task->fresh()->status)->toBe(AlertTask::STATUS_TRANSFERRED)
        ->and($items->where('id', 'corrective_action-'.$first->id))->toHaveCount(1)
        ->and($items->pluck('id')->contains("alert_task-{$task->id}"))->toBeFalse()
        ->and(HsCorrectiveAction::query()->whereKey($first->id)->count())->toBe(1);
});
