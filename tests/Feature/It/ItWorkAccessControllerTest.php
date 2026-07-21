<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\ItAttachment;
use App\Models\ItChange;
use App\Models\ItMajorIncident;
use App\Models\ItProblem;
use App\Models\ItTicket;
use App\Models\ItTicketApproval;
use App\Models\ItWorkTask;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabaseState;

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

function itControllerAccessActor(array $permissionKeys, ?Site $site = null): User
{
    $actor = User::factory()->create(['approved_at' => now()]);
    $role = Role::query()->create([
        'name' => 'it-controller-'.str()->uuid(),
        'label' => 'IT controller test role',
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

    if ($site) {
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

    return $actor;
}

test('the main queue payload and summary exclude inaccessible work', function () {
    $approvedSite = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $agent = itControllerAccessActor(['it.view', 'it.manage'], $approvedSite);
    $requester = User::factory()->create();
    $visible = ItTicket::factory()->create([
        'title' => 'Visible approved-site ticket',
        'requester_user_id' => $requester->id,
        'site_id' => $approvedSite->id,
    ]);
    ItTicket::factory()->create([
        'title' => 'Hidden other-site ticket',
        'requester_user_id' => $requester->id,
        'site_id' => $otherSite->id,
    ]);

    $this->actingAs($agent)
        ->get(route('it.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('it/index')
            ->has('tickets.data', 1)
            ->where('tickets.data.0.id', $visible->id)
            ->where('summary.tickets.open', 1));

    $this->actingAs($agent)
        ->get(route('it.index', ['q' => 'Hidden other-site ticket']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('it/index')
            ->has('tickets.data', 0));
});

test('direct ticket reads and mutations conceal inaccessible work', function () {
    $approvedSite = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $agent = itControllerAccessActor(['it.view', 'it.manage'], $approvedSite);
    $hidden = ItTicket::factory()->create(['site_id' => $otherSite->id, 'status' => 'open']);

    $this->actingAs($agent)
        ->get(route('it.tickets.show', $hidden))
        ->assertNotFound();

    $this->actingAs($agent)
        ->patch(route('it.tickets.update', $hidden), ['priority' => 'urgent'])
        ->assertNotFound();

    expect($hidden->fresh()->priority)->not->toBe('urgent');
});

test('ticket workspace options and bulk assignment follow exact Site access', function () {
    $approvedSite = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $agent = itControllerAccessActor(['it.view', 'it.manage', 'assets.viewAny'], $approvedSite);
    $localTechnician = itControllerAccessActor(['it.view', 'it.manage'], $approvedSite);
    $remoteTechnician = itControllerAccessActor(['it.view', 'it.manage'], $otherSite);
    $inactiveTechnician = itControllerAccessActor(['it.view', 'it.manage'], $approvedSite);
    $inactiveTechnician->update(['approved_at' => null]);
    $ticket = ItTicket::factory()->create([
        'site_id' => $approvedSite->id,
        'assigned_to_user_id' => null,
    ]);
    $localAsset = Asset::factory()->create(['site_id' => $approvedSite->id, 'status' => 'active']);
    Asset::factory()->create(['site_id' => $otherSite->id, 'status' => 'active']);

    $this->actingAs($agent)
        ->get(route('it.tickets.show', $ticket))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('assignees', fn ($agents) => collect($agents)->pluck('id')->sort()->values()->all()
                === collect([$agent->id, $localTechnician->id])->sort()->values()->all())
            ->where('assetOptions', fn ($assets) => collect($assets)->pluck('id')->values()->all()
                === [$localAsset->id]));

    $this->actingAs($agent)
        ->post(route('it.tickets.bulk'), [
            'ids' => [$ticket->id],
            'action' => 'assign',
            'assigned_to_user_id' => $remoteTechnician->id,
        ])
        ->assertRedirect()
        ->assertSessionHas('success', '0 ticket(s) assigned · 1 unchanged.');

    expect($ticket->fresh()->assigned_to_user_id)->toBeNull();
});

test('bulk work silently excludes forged inaccessible ticket ids', function () {
    $approvedSite = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $agent = itControllerAccessActor(['it.view', 'it.manage'], $approvedSite);
    $visible = ItTicket::factory()->create(['site_id' => $approvedSite->id, 'priority' => 'normal']);
    $hidden = ItTicket::factory()->create(['site_id' => $otherSite->id, 'priority' => 'normal']);

    $this->actingAs($agent)
        ->post(route('it.tickets.bulk'), [
            'ids' => [$visible->id, $hidden->id],
            'action' => 'priority',
            'priority' => 'high',
        ])
        ->assertRedirect()
        ->assertSessionHas('success', '1 ticket(s) reprioritised · 1 unchanged.');

    expect($visible->fresh()->priority)->toBe('high')
        ->and($hidden->fresh()->priority)->toBe('normal');
});

test('ticket creation derives a self service site and rejects forged staff scope', function () {
    $approvedSite = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $requester = itControllerAccessActor(['it.request'], $approvedSite);
    $agent = itControllerAccessActor(['it.view', 'it.manage'], $approvedSite);

    $this->actingAs($requester)
        ->post(route('it.tickets.store'), [
            'title' => 'Self-service scoped ticket',
            'category' => 'hardware',
            'priority' => 'normal',
        ])
        ->assertRedirect();

    $created = ItTicket::query()->where('title', 'Self-service scoped ticket')->firstOrFail();
    expect((int) $created->site_id)->toBe($approvedSite->id)
        ->and($created->is_organisation_wide)->toBeFalse();

    $this->actingAs($agent)
        ->post(route('it.tickets.store'), [
            'title' => 'Forged staff scope ticket',
            'category' => 'network',
            'priority' => 'high',
            'site_id' => $otherSite->id,
        ])
        ->assertForbidden();

    expect(ItTicket::query()->where('title', 'Forged staff scope ticket')->exists())->toBeFalse();
});

test('ticket updates reject unapproved and conflicting scope changes', function () {
    $approvedSite = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $agent = itControllerAccessActor(['it.view', 'it.manage'], $approvedSite);
    $ticket = ItTicket::factory()->create(['site_id' => $approvedSite->id]);

    $this->actingAs($agent)
        ->patch(route('it.tickets.update', $ticket), ['site_id' => $otherSite->id])
        ->assertForbidden();

    $this->actingAs($agent)
        ->from(route('it.tickets.show', $ticket))
        ->patch(route('it.tickets.update', $ticket), [
            'site_id' => $approvedSite->id,
            'is_organisation_wide' => true,
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('site_id');

    expect((int) $ticket->fresh()->site_id)->toBe($approvedSite->id)
        ->and((bool) $ticket->is_organisation_wide)->toBeFalse();
});

test('organisation wide creation requires the explicit capability', function () {
    $approvedSite = Site::factory()->create();
    $allowedAgent = itControllerAccessActor(['it.view', 'it.manage', 'it.organisationWide'], $approvedSite);
    $restrictedAgent = itControllerAccessActor(['it.view', 'it.manage'], $approvedSite);

    $this->actingAs($allowedAgent)
        ->post(route('it.tickets.store'), [
            'title' => 'Approved organisation-wide ticket',
            'category' => 'network',
            'priority' => 'urgent',
            'site_id' => null,
            'is_organisation_wide' => true,
        ])
        ->assertRedirect();

    $created = ItTicket::query()->where('title', 'Approved organisation-wide ticket')->firstOrFail();
    expect($created->site_id)->toBeNull()
        ->and($created->is_organisation_wide)->toBeTrue();

    $this->actingAs($restrictedAgent)
        ->post(route('it.tickets.store'), [
            'title' => 'Denied organisation-wide ticket',
            'category' => 'network',
            'priority' => 'urgent',
            'site_id' => null,
            'is_organisation_wide' => true,
        ])
        ->assertForbidden();

    expect(ItTicket::query()->where('title', 'Denied organisation-wide ticket')->exists())->toBeFalse();
});

test('problem change and major incident lists inherit parent ticket visibility', function () {
    $approvedSite = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $agent = itControllerAccessActor(['it.view', 'it.manage'], $approvedSite);
    $visibleTicket = ItTicket::factory()->create(['site_id' => $approvedSite->id]);
    $hiddenTicket = ItTicket::factory()->create(['site_id' => $otherSite->id]);

    $visibleProblem = ItProblem::factory()->create(['ticket_id' => $visibleTicket->id]);
    ItProblem::factory()->create(['ticket_id' => $hiddenTicket->id]);
    $visibleChange = ItChange::factory()->create(['ticket_id' => $visibleTicket->id]);
    ItChange::factory()->create(['ticket_id' => $hiddenTicket->id]);
    $visibleMajorIncident = ItMajorIncident::factory()->create(['ticket_id' => $visibleTicket->id]);
    ItMajorIncident::factory()->create(['ticket_id' => $hiddenTicket->id]);

    $this->actingAs($agent)
        ->get(route('it.problems.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('problems.data', 1)
            ->where('problems.data.0.problem_id', $visibleProblem->id));

    $this->actingAs($agent)
        ->get(route('it.changes.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('changes.data', 1)
            ->where('changes.data.0.change_id', $visibleChange->id));

    $this->actingAs($agent)
        ->get(route('it.major-incidents.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('majorIncidents.data', 1)
            ->where('majorIncidents.data.0.major_incident_id', $visibleMajorIncident->id));
});

test('new problem change and major incident work derives an approved site', function () {
    $approvedSite = Site::factory()->create();
    $agent = itControllerAccessActor(['it.view', 'it.manage'], $approvedSite);

    $this->actingAs($agent)->post(route('it.problems.store'), [
        'title' => 'Scoped recurring fault',
        'category' => 'network',
        'priority' => 'high',
    ])->assertRedirect();

    $this->actingAs($agent)->post(route('it.changes.store'), [
        'title' => 'Scoped network change',
        'category' => 'network',
        'priority' => 'high',
        'change_type' => 'standard',
        'risk_level' => 'low',
    ])->assertRedirect();

    $this->actingAs($agent)->post(route('it.major-incidents.store'), [
        'title' => 'Scoped major incident',
        'category' => 'network',
        'priority' => 'urgent',
        'severity' => 'sev1',
        'impact_summary' => 'The approved Site is unavailable.',
        'target_update_minutes' => 30,
    ])->assertRedirect();

    expect(ItTicket::query()
        ->whereIn('title', ['Scoped recurring fault', 'Scoped network change', 'Scoped major incident'])
        ->where('site_id', $approvedSite->id)
        ->count())->toBe(3);
});

test('reports and exports exclude inaccessible ticket data', function () {
    $approvedSite = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $agent = itControllerAccessActor(['it.view', 'it.manage'], $approvedSite);
    ItTicket::factory()->create(['site_id' => $approvedSite->id, 'status' => 'open']);
    ItTicket::factory()->create(['site_id' => $otherSite->id, 'status' => 'open']);

    $this->actingAs($agent)
        ->getJson(route('it.reports.data'))
        ->assertOk()
        ->assertJsonPath('kpis.open', 1);

    $export = $this->actingAs($agent)
        ->get(route('it.reports.export', ['card' => 'summary']))
        ->assertOk();

    expect($export->streamedContent())->toContain("Open,1\n")
        ->not->toContain("Open,2\n");
});

test('merge approval attachment and nested task routes conceal inaccessible parents', function () {
    $approvedSite = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $agent = itControllerAccessActor(['it.view', 'it.manage'], $approvedSite);
    $requester = User::factory()->create();
    $visible = ItTicket::factory()->create(['site_id' => $approvedSite->id, 'status' => 'open']);
    $otherVisible = ItTicket::factory()->create(['site_id' => $approvedSite->id, 'status' => 'open']);
    $hidden = ItTicket::factory()->create(['site_id' => $otherSite->id, 'status' => 'open']);

    $this->actingAs($agent)
        ->post(route('it.tickets.merge', $visible), ['target_ticket_id' => $hidden->id])
        ->assertNotFound();

    $approvalScopeColumn = (new ItTicketApproval)->getFillable()[0];
    $approval = ItTicketApproval::query()->create([
        $approvalScopeColumn => $hidden->getAttribute($approvalScopeColumn),
        'it_ticket_id' => $hidden->id,
        'requested_by' => $requester->id,
        'status' => 'pending',
    ]);
    $this->actingAs($agent)
        ->post(route('it.approvals.decide', $approval), ['decision' => 'approve'])
        ->assertNotFound();

    $attachmentScopeColumn = (new ItAttachment)->getFillable()[0];
    $attachment = ItAttachment::query()->create([
        $attachmentScopeColumn => $hidden->getAttribute($attachmentScopeColumn),
        'attachable_type' => $hidden->getMorphClass(),
        'attachable_id' => $hidden->id,
        'path' => 'it/test/hidden.txt',
        'original_name' => 'hidden.txt',
        'mime' => 'text/plain',
        'size' => 6,
        'uploaded_by' => $requester->id,
    ]);
    $this->actingAs($agent)
        ->get(route('it.attachments.download', $attachment))
        ->assertNotFound();

    $this->actingAs($agent)
        ->post(route('it.tickets.comments.store', $hidden), ['body' => 'Forged reply'])
        ->assertNotFound();

    $this->actingAs($agent)
        ->post(route('it.tickets.transitions.store', $hidden), [
            'workflow_state' => 'in_progress',
            'reason' => 'Forged transition',
        ])
        ->assertNotFound();

    $this->actingAs($agent)
        ->post(route('it.tickets.resolve', $hidden), ['note' => 'Forged resolution'])
        ->assertNotFound();

    $this->actingAs($agent)
        ->post(route('it.tickets.approvals.request', $hidden), ['reason' => 'Forged approval'])
        ->assertNotFound();

    $this->actingAs($agent)
        ->post(route('it.tickets.csat', $hidden), ['score' => 5])
        ->assertNotFound();

    $this->actingAs($agent)
        ->post(route('it.tickets.comments.store', $hidden), [])
        ->assertNotFound();

    $this->actingAs($agent)
        ->post(route('it.tickets.transitions.store', $hidden), [])
        ->assertNotFound();

    $this->actingAs($agent)
        ->post(route('it.tickets.resolve', $hidden), [])
        ->assertNotFound();

    $this->actingAs($agent)
        ->post(route('it.tickets.csat', $hidden), [])
        ->assertNotFound();

    $this->actingAs($agent)
        ->post(route('it.tickets.merge', $hidden), [])
        ->assertNotFound();

    $this->actingAs($agent)
        ->post(route('it.approvals.decide', $approval), [])
        ->assertNotFound();

    $hiddenProblem = ItProblem::factory()->create(['ticket_id' => $hidden->id]);
    $this->actingAs($agent)
        ->post(route('it.problems.transitions.store', $hiddenProblem), [])
        ->assertNotFound();

    $hiddenChange = ItChange::factory()->create(['ticket_id' => $hidden->id]);
    $this->actingAs($agent)
        ->post(route('it.changes.transitions.store', $hiddenChange), [])
        ->assertNotFound();

    $hiddenMajorIncident = ItMajorIncident::factory()->create(['ticket_id' => $hidden->id]);
    $this->actingAs($agent)
        ->post(route('it.major-incidents.updates.store', $hiddenMajorIncident), [])
        ->assertNotFound();

    $this->actingAs($agent)
        ->post(route('it.tickets.tasks.store', $hidden), [])
        ->assertNotFound();

    $task = ItWorkTask::factory()->create(['ticket_id' => $otherVisible->id]);
    $originalTaskTitle = $task->title;
    $this->actingAs($agent)
        ->patch(route('it.tickets.tasks.update', [$visible, $task]), [])
        ->assertNotFound();

    expect($task->fresh()->title)->toBe($originalTaskTitle)
        ->and($visible->fresh()->merged_into_ticket_id)->toBeNull()
        ->and($approval->fresh()->status)->toBe('pending');
});
