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
use App\Services\Tasks\Providers\ClientIncidentProvider;
use App\Services\Tasks\TaskAggregator;
use App\Services\Tasks\TaskItem;
use App\Services\Tasks\TaskSearch;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

function makeIncidentJourneyTasksUser(Site $site, array $permissionKeys): User
{
    $user = User::factory()->create([
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
    $site = Site::factory()->create();
    $user = makeIncidentJourneyTasksUser($site, [
        'incidents.viewAny',
        'controlRoom.viewAny',
        'hazards.view',
    ]);
    $client = Client::factory()->create([
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

it('uses the same current and incident-time Site access as the source modules', function () {
    $visibleSite = Site::factory()->create();
    $hiddenSite = Site::factory()->create();
    $user = makeIncidentJourneyTasksUser($visibleSite, [
        'incidents.viewAny',
        'controlRoom.viewAny',
        'hazards.view',
    ]);
    $visibleClient = Client::factory()->create([
        'site_id' => $visibleSite->id,
    ]);
    $hiddenClient = Client::factory()->create([
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
    $site = Site::factory()->create();
    $user = makeIncidentJourneyTasksUser($site, [
        'incidents.viewAny',
        'controlRoom.viewAny',
        'hazards.view',
    ]);
    $client = Client::factory()->create(['site_id' => $site->id]);
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

it('keeps private journey search relations out of the normal capped incident feed', function () {
    $site = Site::factory()->create();
    $user = makeIncidentJourneyTasksUser($site, ['incidents.viewAny']);
    $client = Client::factory()->create([
        'site_id' => $site->id,
    ]);
    makeUniversalIncidentJourney($site, $client, $user, 'manual');
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    $items = (new ClientIncidentProvider)->tasks($user);

    expect($items)->not->toBeEmpty()
        ->and(collect($queries)->contains(fn (string $sql) => str_contains($sql, 'incident_followups')))->toBeFalse()
        ->and(collect($queries)->contains(fn (string $sql) => str_contains($sql, 'control_room_alert_tasks')))->toBeFalse()
        ->and(collect($queries)->contains(fn (string $sql) => str_contains($sql, 'hs_investigations')))->toBeFalse()
        ->and(collect($queries)->contains(fn (string $sql) => str_contains($sql, 'hs_corrective_actions')))->toBeFalse();
});

it('keeps completed corrective actions active with truthful independent verification state', function () {
    $site = Site::factory()->create();
    $user = makeIncidentJourneyTasksUser($site, ['hazards.view']);
    $event = HsEvent::factory()->create([
        'site_id' => $site->id,
    ]);
    $action = HsCorrectiveAction::factory()->completed()->create([
        'hs_event_id' => $event->id,
        'assigned_to_user_id' => $user->id,
    ]);
    $verified = HsCorrectiveAction::factory()->verified()->create([
        'hs_event_id' => $event->id,
        'assigned_to_user_id' => $user->id,
    ]);

    $items = collect((new TaskAggregator)->itemsFor(
        $user,
        ['include_done' => true],
    ));
    $item = $items->firstWhere('id', 'corrective_action-'.$action->id);
    $verifiedItem = $items->firstWhere(
        'id',
        'corrective_action-'.$verified->id,
    );

    expect($item)->not->toBeNull()
        ->and($item->status)->toBe(HsCorrectiveAction::STATUS_COMPLETED)
        ->and($item->displayState)->toBe('Awaiting independent verification')
        ->and($item->bucket)->toBe(TaskItem::BUCKET_IN_PROGRESS)
        ->and($verifiedItem?->displayState)->toBe('Verified — ready to close')
        ->and($verifiedItem?->bucket)->toBe(TaskItem::BUCKET_IN_PROGRESS);
});

it('finds the incident journey by people place source work narrative references and display state', function () {
    $site = Site::factory()->create([
        'name' => 'Playwright Incident Handover House',
    ]);
    $viewer = makeIncidentJourneyTasksUser($site, [
        'incidents.viewAny',
        'controlRoom.viewAny',
        'hazards.view',
    ]);
    $viewer->forceFill(['name' => 'Playwright Queue Viewer'])->save();
    $incidentOwner = User::factory()->create([
        'name' => 'Playwright Incident Investigator',
        'approved_at' => now(),
    ]);
    $controlRoomOwner = User::factory()->create([
        'name' => 'Playwright Control Room Operator',
        'approved_at' => now(),
    ]);
    $followupOwner = User::factory()->create([
        'name' => 'Playwright Follow-up Owner',
        'approved_at' => now(),
    ]);
    $investigationOwner = User::factory()->create([
        'name' => 'Playwright H&S Investigator',
        'approved_at' => now(),
    ]);
    $actionOwner = User::factory()->create([
        'name' => 'Playwright Corrective Action Owner',
        'approved_at' => now(),
    ]);
    $client = Client::factory()->create([
        'site_id' => $site->id,
        'first_name' => 'Playwright Aroha',
        'last_name' => 'Handover',
    ]);
    $journey = makeUniversalIncidentJourney(
        $site,
        $client,
        $viewer,
        'control_room',
    );
    $journey['incident']->forceFill([
        'title' => 'Playwright Aroha Handover',
        'description' => 'The incident narrative records a bathroom rail failure.',
        'investigation_assigned_to' => $incidentOwner->id,
    ])->saveQuietly();
    $journey['alert']->forceFill([
        'assigned_to_user_id' => $controlRoomOwner->id,
    ])->saveQuietly();
    $journey['followup']->forceFill(['assigned_to_user_id' => $followupOwner->id])->saveQuietly();
    $journey['investigation']->forceFill(['lead_investigator_id' => $investigationOwner->id])->saveQuietly();
    $sourceTask = AlertTask::query()->create([
        'alert_id' => $journey['alert']->id,
        'title' => 'Replace the unsafe bathroom rail',
        'description' => 'Permanent repair with a wide-angle completion photo.',
        'assigned_to_user_id' => $controlRoomOwner->id,
        'created_by_user_id' => $viewer->id,
        'status' => AlertTask::STATUS_TRANSFERRED,
        'priority' => 'high',
        'transferred_at' => now(),
        'transferred_by_user_id' => $viewer->id,
        'transferred_to_hs_corrective_action_id' => $journey['action']->id,
    ]);
    $journey['action']->forceFill([
        'source_control_room_task_id' => $sourceTask->id,
        'assigned_to_user_id' => $actionOwner->id,
        'status' => HsCorrectiveAction::STATUS_COMPLETED,
        'completed_at' => now(),
        'completed_by_user_id' => $actionOwner->id,
        'completion_notes' => 'Permanent repair completed.',
    ])->saveQuietly();

    $serializedItems = collect((new TaskAggregator)->arrayFor($viewer));
    $serializedAction = $serializedItems->firstWhere('id', 'corrective_action-'.$journey['action']->id);
    $allJourneyIds = $serializedItems
        ->where('journey.key', 'incident-'.$journey['incident']->id)
        ->pluck('id')
        ->sort()
        ->values()
        ->all();
    $wholeJourneySearches = [
        'Playwright Aroha Handover',
        'Playwright Incident Handover House',
        'Replace the unsafe bathroom rail',
        'Permanent repair with a wide-angle completion photo.',
        'Playwright Incident Investigator',
        'Playwright Control Room Operator',
        'Playwright Follow-up Owner',
        'Playwright H&S Investigator',
        'Playwright Corrective Action Owner',
        $journey['alert']->reference_number,
        $journey['incident']->reference_number,
        $journey['event']->reference_number,
        $journey['investigation']->reference_number,
        $journey['action']->reference_number,
        'bathroom rail failure',
    ];

    foreach ($wholeJourneySearches as $search) {
        $found = collect((new TaskAggregator)->arrayFor($viewer, ['q' => $search]))
            ->where('journey.key', 'incident-'.$journey['incident']->id)
            ->pluck('id')
            ->sort()
            ->values()
            ->all();

        expect($found)->toBe($allJourneyIds, "Search [{$search}] did not find the full incident journey.");
    }

    $verificationResults = collect((new TaskAggregator)->arrayFor(
        $viewer,
        ['q' => 'awaiting independent verification'],
    ));
    expect($verificationResults->pluck('id'))
        ->toContain('corrective_action-'.$journey['action']->id)
        ->and($serializedAction)->not->toHaveKey('searchTerms')
        ->and($serializedAction['journey'])->not->toHaveKey('search_terms');
});

it('searches incident journey responsibilities beyond each providers normal 300 row dashboard cap', function () {
    $site = Site::factory()->create();
    $viewer = makeIncidentJourneyTasksUser($site, [
        'incidents.viewAny',
        'controlRoom.viewAny',
        'hazards.view',
        'fleet.viewAny',
    ]);
    $client = Client::factory()->create([
        'site_id' => $site->id,
    ]);
    $journey = makeUniversalIncidentJourney($site, $client, $viewer, 'sensor');
    $targetAction = $journey['action'];
    $targetAction->forceFill([
        'reference_number' => 'CA-BOUNDARY-TARGET',
        'priority' => HsCorrectiveAction::PRIORITY_CRITICAL,
        'due_date' => now()->subDay(),
        'created_at' => now()->subYear(),
        'updated_at' => now()->subYear(),
    ])->saveQuietly();

    $now = now();
    DB::table('hs_corrective_actions')->insert(
        collect(range(1, 301))
            ->map(fn (int $index) => [
                'hs_event_id' => $journey['event']->id,
                'reference_number' => sprintf('CA-DECOY-%04d', $index),
                'action_type' => HsCorrectiveAction::TYPE_CORRECTIVE,
                'priority' => HsCorrectiveAction::PRIORITY_LOW,
                'title' => "Newer corrective action {$index}",
                'status' => HsCorrectiveAction::STATUS_OPEN,
                'due_date' => now()->addMonth()->toDateString(),
                'created_by' => $viewer->id,
                'created_at' => $now,
                'updated_at' => $now,
            ])
            ->all(),
    );
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    $this->actingAs($viewer)
        ->get('/tasks?sources=corrective_action&q='.$targetAction->reference_number)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('items', fn ($items) => collect($items)
                ->contains(fn ($item) => $item['id'] === 'corrective_action-'.$targetAction->id)));

    expect(TaskSearch::hasQuery(['q' => '0']))->toBeTrue()
        ->and(collect($queries)->filter(fn (string $sql) => str_contains($sql, 'fleet_work_orders')))->toHaveCount(2)
        ->and(collect($queries)->filter(fn (string $sql) => str_contains($sql, 'fleet_service_schedules')))->toHaveCount(2)
        ->and(collect($queries)->contains(
            fn (string $sql) => str_contains($sql, 'hs_corrective_actions')
                && str_contains($sql, ' like '),
        ))->toBeTrue();
});

it('keeps completed journey work in explicit history only', function () {
    $site = Site::factory()->create();
    $user = makeIncidentJourneyTasksUser($site, ['hazards.view']);
    $event = HsEvent::factory()->closed()->create([
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
    $site = Site::factory()->create();
    $user = makeIncidentJourneyTasksUser($site, [
        'controlRoom.viewAny',
        'hazards.view',
        'hazards.manage',
    ]);
    $client = Client::factory()->create(['site_id' => $site->id]);
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
        ->and($first->fresh()->source_control_room_task_id)->toBe($task->id)
        ->and($task->fresh()->transferredCorrectiveAction->is($first))->toBeTrue()
        ->and($items->where('id', 'corrective_action-'.$first->id))->toHaveCount(1)
        ->and($items->pluck('id')->contains("alert_task-{$task->id}"))->toBeFalse()
        ->and(HsCorrectiveAction::query()->whereKey($first->id)->count())->toBe(1);
});
