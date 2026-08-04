<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Services\ItWorkAccessService;
use App\Models\ItChange;
use App\Models\ItMajorIncident;
use App\Models\ItProblem;
use App\Models\ItQueue;
use App\Models\ItTeam;
use App\Models\ItTicket;
use App\Models\ItTicketApproval;
use App\Models\ItWorkTask;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Gate;

if (getenv('IT_WORK_ACCESS_USE_PREBUILT_DATABASE') === '1') {
    $appEnvironment = getenv('APP_ENV');
    $databaseConnection = getenv('DB_CONNECTION');
    $databasePath = getenv('DB_DATABASE');
    $safeRoot = realpath(__DIR__.'/../../../storage/framework/testing');
    $resolvedDatabasePath = is_string($databasePath) ? realpath($databasePath) : false;

    if ($appEnvironment !== 'testing'
        || $databaseConnection !== 'sqlite'
        || ! is_string($databasePath)
        || $databasePath === ''
        || $databasePath === ':memory:'
        || $safeRoot === false
        || $resolvedDatabasePath === false
        || ! str_starts_with(str_replace('\\', '/', $resolvedDatabasePath), str_replace('\\', '/', $safeRoot).'/')
    ) {
        throw new RuntimeException(
            'IT_WORK_ACCESS_USE_PREBUILT_DATABASE requires APP_ENV=testing and a file-backed SQLite database inside storage/framework/testing.',
        );
    }

    RefreshDatabaseState::$migrated = true;
}

function itWorkAccessActor(array $permissionKeys = []): User
{
    $actor = User::factory()->create(['approved_at' => now()]);
    $role = Role::query()->create([
        'name' => 'it-access-'.str()->uuid(),
        'label' => 'IT access test role',
        'level' => 50,
        'type' => 'custom',
    ]);

    foreach ($permissionKeys as $key) {
        $permission = Permission::query()->firstOrCreate(
            ['key' => $key],
            ['description' => $key, 'group' => 'it', 'module' => 'Operations'],
        );
        $role->permissions()->syncWithoutDetaching([$permission->id]);
    }
    $actor->roles()->attach($role);

    return $actor;
}

function assignItWorkActorToSite(User $actor, Site $site): void
{
    HrEmployeeProfile::factory()->create([
        'user_id' => $actor->id,
        'created_by' => $actor->id,
        'updated_by' => $actor->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'is_active' => true,
        'start_date' => now()->subMonth()->toDateString(),
        'end_date' => null,
    ]);
}

test('boolean and query access decisions share the same strict work visibility matrix', function () {
    $access = app(ItWorkAccessService::class);
    $approvedSite = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $requester = itWorkAccessActor(['it.request']);
    $requestedFor = itWorkAccessActor(['it.request']);
    $agent = itWorkAccessActor(['it.view', 'it.manage']);
    assignItWorkActorToSite($agent, $approvedSite);

    $team = ItTeam::factory()->create();
    $team->members()->attach($agent, ['role' => 'member']);
    $managedTeam = ItTeam::factory()->create(['manager_user_id' => $agent->id]);
    $wrongTeam = ItTeam::factory()->create();
    $inactiveTeam = ItTeam::factory()->create(['is_active' => false]);
    $inactiveTeam->members()->attach($agent, ['role' => 'member']);
    $queue = ItQueue::factory()->for($team, 'team')->create();
    $wrongQueue = ItQueue::factory()->for($wrongTeam, 'team')->create();
    $inactiveQueue = ItQueue::factory()->for($team, 'team')->create(['is_active' => false]);

    $tickets = collect([
        'requester' => ItTicket::factory()->create([
            'requester_user_id' => $requester->id,
            'site_id' => null,
            'is_organisation_wide' => false,
            'is_sensitive' => true,
        ]),
        'requested_for' => ItTicket::factory()->create([
            'requested_for_user_id' => $requestedFor->id,
            'site_id' => null,
            'is_organisation_wide' => false,
            'is_sensitive' => true,
        ]),
        'participant_agent' => ItTicket::factory()->create([
            'requester_user_id' => $agent->id,
            'site_id' => null,
            'is_organisation_wide' => false,
            'is_sensitive' => true,
        ]),
        'approved_site' => ItTicket::factory()->create(['site_id' => $approvedSite->id]),
        'unapproved_site' => ItTicket::factory()->create(['site_id' => $otherSite->id]),
        'direct_assignee' => ItTicket::factory()->create([
            'site_id' => $otherSite->id,
            'assigned_to_user_id' => $agent->id,
        ]),
        'direct_owner' => ItTicket::factory()->create([
            'site_id' => $otherSite->id,
            'owner_user_id' => $agent->id,
        ]),
        'responsible_team' => ItTicket::factory()->create([
            'site_id' => $otherSite->id,
            'team_id' => $team->id,
        ]),
        'managed_team' => ItTicket::factory()->create([
            'site_id' => $otherSite->id,
            'team_id' => $managedTeam->id,
        ]),
        'wrong_team' => ItTicket::factory()->create([
            'site_id' => $otherSite->id,
            'team_id' => $wrongTeam->id,
        ]),
        'inactive_team' => ItTicket::factory()->create([
            'site_id' => $otherSite->id,
            'team_id' => $inactiveTeam->id,
        ]),
        'responsible_queue' => ItTicket::factory()->create([
            'site_id' => $otherSite->id,
            'queue_id' => $queue->id,
        ]),
        'wrong_queue' => ItTicket::factory()->create([
            'site_id' => $otherSite->id,
            'queue_id' => $wrongQueue->id,
        ]),
        'inactive_queue' => ItTicket::factory()->create([
            'site_id' => $otherSite->id,
            'queue_id' => $inactiveQueue->id,
        ]),
        'sensitive_staff' => ItTicket::factory()->create([
            'site_id' => $approvedSite->id,
            'is_sensitive' => true,
        ]),
        'sensitive_direct' => ItTicket::factory()->create([
            'site_id' => $otherSite->id,
            'assigned_to_user_id' => $agent->id,
            'is_sensitive' => true,
        ]),
        'sensitive_team' => ItTicket::factory()->create([
            'site_id' => $otherSite->id,
            'team_id' => $team->id,
            'is_sensitive' => true,
        ]),
        'accidental_null_site' => ItTicket::factory()->create([
            'site_id' => null,
            'assigned_to_user_id' => $agent->id,
            'is_organisation_wide' => false,
        ]),
        'marked_wide_without_capability' => ItTicket::factory()->create([
            'site_id' => null,
            'is_organisation_wide' => true,
        ]),
    ]);

    $expectedForAgent = [
        'participant_agent',
        'approved_site',
        'direct_assignee',
        'direct_owner',
        'responsible_team',
        'managed_team',
        'responsible_queue',
    ];
    $queryIds = $access->applyViewScope(ItTicket::query(), $agent)->pluck('id')->all();

    foreach ($tickets as $name => $ticket) {
        expect($access->canView($agent, $ticket))
            ->toBe(in_array($name, $expectedForAgent, true), $name);
        expect(in_array($ticket->id, $queryIds, true))
            ->toBe(in_array($name, $expectedForAgent, true), "query: {$name}");
    }

    expect($access->canView($requester, $tickets['requester']))->toBeTrue()
        ->and($access->canWork($requester, $tickets['requester']))->toBeFalse()
        ->and($access->canView($requestedFor, $tickets['requested_for']))->toBeTrue()
        ->and($access->canWork($requestedFor, $tickets['requested_for']))->toBeFalse()
        ->and($access->canWork($agent, $tickets['participant_agent']))->toBeFalse()
        ->and($access->applyViewScope(ItTicket::query(), $requester)->pluck('id')->all())
        ->toBe([$tickets['requester']->id])
        ->and($access->applyViewScope(ItTicket::query(), $requestedFor)->pluck('id')->all())
        ->toBe([$tickets['requested_for']->id]);
});

test('sensitive and organisation wide capabilities unlock only their explicit staff paths', function () {
    $access = app(ItWorkAccessService::class);
    $site = Site::factory()->create();
    $sensitiveAgent = itWorkAccessActor(['it.view', 'it.viewSensitive']);
    $wideAgent = itWorkAccessActor(['it.manage', 'it.organisationWide']);
    assignItWorkActorToSite($sensitiveAgent, $site);

    $sensitive = ItTicket::factory()->create(['site_id' => $site->id, 'is_sensitive' => true]);
    $wide = ItTicket::factory()->create(['site_id' => null, 'is_organisation_wide' => true]);
    $sensitiveWide = ItTicket::factory()->create([
        'site_id' => null,
        'is_organisation_wide' => true,
        'is_sensitive' => true,
    ]);
    $fullyPrivilegedAgent = itWorkAccessActor([
        'it.view',
        'it.viewSensitive',
        'it.organisationWide',
    ]);
    $accidental = ItTicket::factory()->create([
        'site_id' => null,
        'is_organisation_wide' => false,
        'assigned_to_user_id' => $wideAgent->id,
    ]);

    expect($access->canView($sensitiveAgent, $sensitive))->toBeTrue()
        ->and($access->canWork($sensitiveAgent, $sensitive))->toBeFalse()
        ->and($access->canView($wideAgent, $wide))->toBeTrue()
        ->and($access->canWork($wideAgent, $wide))->toBeTrue()
        ->and($access->canView($wideAgent, $sensitiveWide))->toBeFalse()
        ->and($access->canView($fullyPrivilegedAgent, $sensitiveWide))->toBeTrue()
        ->and($access->canView($wideAgent, $accidental))->toBeFalse();
});

test('only current employment and active non archived primary or secondary sites become approved', function () {
    $access = app(ItWorkAccessService::class);
    $primarySite = Site::factory()->create();
    $secondarySite = Site::factory()->create();
    $inactiveSite = Site::factory()->create(['is_active' => false]);
    $archivedSite = Site::factory()->create(['archived' => true]);
    $endedActor = itWorkAccessActor(['it.view']);
    $futureActor = itWorkAccessActor(['it.view']);
    $currentActor = itWorkAccessActor(['it.view']);
    $inactiveSiteActor = itWorkAccessActor(['it.view']);
    $archivedSiteActor = itWorkAccessActor(['it.view']);

    assignItWorkActorToSite($endedActor, Site::factory()->create());
    $endedActor->hrEmployeeProfile()->update(['end_date' => now()->subDay()->toDateString()]);
    assignItWorkActorToSite($futureActor, Site::factory()->create());
    $futureActor->hrEmployeeProfile()->update(['start_date' => now()->addDay()->toDateString()]);
    assignItWorkActorToSite($currentActor, $primarySite);
    $currentActor->hrEmployeeProfile()->update(['secondary_site_ids' => [$secondarySite->id]]);
    assignItWorkActorToSite($inactiveSiteActor, $inactiveSite);
    assignItWorkActorToSite($archivedSiteActor, $archivedSite);

    expect($access->approvedSiteIds($endedActor))->toBe([])
        ->and($access->approvedSiteIds($futureActor))->toBe([])
        ->and($access->approvedSiteIds($currentActor))->toBe([$primarySite->id, $secondarySite->id])
        ->and($access->approvedSiteIds($inactiveSiteActor))->toBe([])
        ->and($access->approvedSiteIds($archivedSiteActor))->toBe([]);
});

test('direct decisions reload canonical site team and queue fields before authorising', function () {
    $access = app(ItWorkAccessService::class);
    $approvedSite = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $agent = itWorkAccessActor(['it.view']);
    assignItWorkActorToSite($agent, $approvedSite);

    $team = ItTeam::factory()->create();
    $team->members()->attach($agent, ['role' => 'member']);
    $queue = ItQueue::factory()->for($team, 'team')->create();
    $ticket = ItTicket::factory()->create(['site_id' => $otherSite->id]);

    $ticket->site_id = $approvedSite->id;
    $ticket->team_id = $team->id;
    $ticket->queue_id = $queue->id;

    expect($access->canView($agent, $ticket))->toBeFalse();
});

test('ticket scope assignment accepts only one authorised scope path', function () {
    $access = app(ItWorkAccessService::class);
    $site = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $requester = itWorkAccessActor(['it.request']);
    $wideAgent = itWorkAccessActor(['it.manage', 'it.organisationWide']);
    assignItWorkActorToSite($requester, $site);

    expect($access->canAssignScope($requester, $site->id, false))->toBeTrue()
        ->and($access->canAssignScope($requester, $otherSite->id, false))->toBeFalse()
        ->and($access->canAssignScope($requester, null, false))->toBeFalse()
        ->and($access->canAssignScope($requester, null, true))->toBeFalse()
        ->and($access->canAssignScope($wideAgent, null, true))->toBeTrue()
        ->and($access->canAssignScope($wideAgent, $site->id, true))->toBeFalse()
        ->and($access->defaultSiteId($requester))->toBe($site->id);
});

test('ticket and child policies delegate direct reads and writes to the canonical parent scope', function () {
    $site = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $agent = itWorkAccessActor(['it.view', 'it.manage']);
    $unrelatedAgent = itWorkAccessActor(['it.view', 'it.manage']);
    assignItWorkActorToSite($agent, $site);
    assignItWorkActorToSite($unrelatedAgent, $otherSite);

    $ticket = ItTicket::factory()->create([
        'site_id' => $site->id,
        'requires_approval' => true,
    ]);
    $problem = ItProblem::factory()->create(['ticket_id' => $ticket->id]);
    $change = ItChange::factory()->create(['ticket_id' => $ticket->id]);
    $majorIncident = ItMajorIncident::factory()->create(['ticket_id' => $ticket->id]);
    $task = ItWorkTask::factory()->create(['ticket_id' => $ticket->id]);
    $approval = ItTicketApproval::query()->create([
        'it_ticket_id' => $ticket->id,
        'requested_by' => $unrelatedAgent->id,
        'status' => 'pending',
    ]);

    foreach ([$problem, $change, $majorIncident, $task] as $child) {
        expect(Gate::forUser($agent)->allows('view', $child))->toBeTrue()
            ->and(Gate::forUser($agent)->allows('update', $child))->toBeTrue()
            ->and(Gate::forUser($unrelatedAgent)->allows('view', $child))->toBeFalse()
            ->and(Gate::forUser($unrelatedAgent)->allows('update', $child))->toBeFalse();
    }

    expect(Gate::forUser($agent)->allows('view', $ticket))->toBeTrue()
        ->and(Gate::forUser($agent)->allows('update', $ticket))->toBeTrue()
        ->and(Gate::forUser($agent)->allows('decide', $approval))->toBeTrue()
        ->and(Gate::forUser($unrelatedAgent)->allows('view', $ticket))->toBeFalse()
        ->and(Gate::forUser($unrelatedAgent)->allows('decide', $approval))->toBeFalse();
});

test('merge requires canonical work access to both source and target Sites', function () {
    $site = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $agent = itWorkAccessActor(['it.manage']);
    assignItWorkActorToSite($agent, $site);

    $source = ItTicket::factory()->create(['site_id' => $site->id]);
    $conversationAudience = [
        'requester_user_id' => $source->requester_user_id,
        'requested_for_user_id' => $source->requested_for_user_id,
    ];
    $allowedTarget = ItTicket::factory()->create([
        'site_id' => $site->id,
        ...$conversationAudience,
    ]);
    $deniedTarget = ItTicket::factory()->create([
        'site_id' => $otherSite->id,
        ...$conversationAudience,
    ]);

    expect(Gate::forUser($agent)->allows('merge', [$source, $allowedTarget]))->toBeTrue()
        ->and(Gate::forUser($agent)->allows('merge', [$source, $deniedTarget]))->toBeFalse();
});

test('restricted IT scope permissions are seeded to administrators only', function () {
    $this->seed(RbacSeeder::class);

    $restrictedKeys = ['it.organisationWide', 'it.viewSensitive'];
    $admin = Role::query()->where('name', 'admin')->firstOrFail();

    expect($admin->permissions()->whereIn('key', $restrictedKeys)->pluck('key')->sort()->values()->all())
        ->toBe(collect($restrictedKeys)->sort()->values()->all())
        ->and(Role::query()
            ->where('name', '!=', 'admin')
            ->whereHas('permissions', fn ($permissions) => $permissions->whereIn('key', $restrictedKeys))
            ->exists())
        ->toBeFalse();
});
