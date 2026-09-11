<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrLeaveRequest;
use App\Domain\It\InboundEmailIngestor;
use App\Domain\It\Services\ItApiWorkItemService;
use App\Domain\It\Services\ItServiceIdentityCredentialService;
use App\Domain\It\Services\ItServiceManagementSetupService;
use App\Domain\It\Services\ItTicketIntakeService;
use App\Domain\It\Services\ItTicketPriorityService;
use App\Domain\It\Services\ItTicketRoutingEligibility;
use App\Domain\It\Services\ItTicketRoutingService;
use App\Domain\It\Services\ItTicketTriageService;
use App\Models\AuditLog;
use App\Models\ItQueue;
use App\Models\ItTeam;
use App\Models\ItTicket;
use App\Models\ItTicketCommandReceipt;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Validation\ValidationException;

function routingDecisionUser(Site $site, string $role = 'hr'): User
{
    $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
    $user->roles()->sync([Role::query()->where('name', $role)->firstOrFail()->id]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => [],
        'is_active' => true, 'start_date' => today()->subYear(), 'end_date' => null,
        'created_by' => $user->id, 'updated_by' => $user->id,
    ]);

    return $user;
}

function routingDecisionQueue(User $owner, User $cover, Site $site, array $rules = []): ItQueue
{
    $team = ItTeam::factory()->create(['manager_user_id' => $owner->id]);
    $team->members()->attach($cover->id, ['role' => 'member']);

    return ItQueue::factory()->create(['team_id' => $team->id, 'filter_rules' => [
        'is_default' => true, 'site_ids' => [$site->id], 'cover_user_id' => $cover->id,
        'default_assignee_user_id' => $owner->id, ...$rules,
    ]]);
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->site = Site::factory()->create();
    $this->agent = routingDecisionUser($this->site);
    $this->worker = routingDecisionUser($this->site, 'support_worker');
});

test('queue browser edits require the reviewed configuration and preserve concurrent routing changes', function () {
    $cover = routingDecisionUser($this->site);
    $queue = routingDecisionQueue($this->agent, $cover, $this->site);
    $setup = app(ItServiceManagementSetupService::class);
    $version = $setup->queueVersion($queue);
    $this->actingAs($this->agent)->patch("/it/setup/queues/{$queue->id}", ['name' => 'Missing review'])
        ->assertSessionHasErrors('configuration_version');
    $this->actingAs($this->agent)->patch("/it/setup/queues/{$queue->id}", [
        'configuration_version' => $version, 'routing_priority' => 45,
    ])->assertSessionHasNoErrors()->assertSessionHas('success', 'Queue updated.');
    $auditCount = AuditLog::query()->where('action', 'it.setup.queue.updated')->count();
    $this->actingAs($this->agent)->patch("/it/setup/queues/{$queue->id}", [
        'configuration_version' => $version, 'name' => 'My retained draft',
    ])->assertSessionHasErrors('configuration_version');
    expect($queue->fresh()->name)->toBe($queue->name)
        ->and(AuditLog::query()->where('action', 'it.setup.queue.updated')->count())->toBe($auditCount);
    $currentVersion = $setup->queueVersion($queue->fresh());
    $this->actingAs($this->agent)->get('/it/setup?tab=queues')->assertInertia(fn ($page) => $page
        ->where('agents', fn ($agents) => collect($agents)->contains(fn ($agent) => $agent['id'] === $cover->id && $agent['site_ids'] === [$this->site->id]
            && is_bool($agent['organisation_wide'])))
        ->where('queues.0.configuration_version', $currentVersion)
        ->where('queues.0.readiness.ready', true)
        ->where('queues.0.readiness.accountable_owner.id', $this->agent->id)
        ->where('queues.0.readiness.cover.id', $cover->id));
    expect(AuditLog::query()->where('action', 'it.setup.queue.updated')->count())->toBe($auditCount);
    $this->actingAs($this->agent)->patch("/it/setup/queues/{$queue->id}", [
        'configuration_version' => $currentVersion, 'name' => 'My retained draft',
    ])->assertSessionHasNoErrors();
    expect($queue->fresh()->name)->toBe('My retained draft')
        ->and($queue->fresh()->filter_rules['routing_priority'])->toBe(45);
    $this->actingAs($this->worker)->patch("/it/setup/queues/{$queue->id}", [
        'configuration_version' => $setup->queueVersion($queue->fresh()), 'name' => 'Denied',
    ])->assertForbidden();
});

test('a revoked cover blocks activation but does not prevent disabling the queue', function () {
    $cover = routingDecisionUser($this->site);
    $queue = routingDecisionQueue($this->agent, $cover, $this->site);
    $setup = app(ItServiceManagementSetupService::class);
    HrEmployeeProfile::query()->where('user_id', $cover->id)->update(['is_active' => false]);
    expect(app(ItTicketRoutingService::class)->queueReadiness($queue)['ready'])->toBeFalse();
    $disabled = $setup->updateQueue($queue, $this->agent, [
        'configuration_version' => $setup->queueVersion($queue), 'is_active' => false,
    ]);
    expect($disabled->is_active)->toBeFalse()->and($disabled->filter_rules['cover_user_id'])->toBe($cover->id);
    expect(fn () => $setup->updateQueue($disabled, $this->agent, [
        'configuration_version' => $setup->queueVersion($disabled), 'is_active' => true,
    ]))->toThrow(DomainException::class);
    expect($queue->fresh()->is_active)->toBeFalse();
});

test('organisation-wide access cannot configure a foreign Site and fallback coverage cannot silently widen', function () {
    $otherSite = Site::factory()->create();
    $admin = routingDecisionUser($this->site, 'admin');
    $cover = routingDecisionUser($this->site);
    $queue = routingDecisionQueue($admin, $cover, $this->site);
    $setup = app(ItServiceManagementSetupService::class);
    expect($admin->canDo('it.organisationWide'))->toBeTrue();
    expect(fn () => $setup->updateQueue($queue, $admin, [
        'site_ids' => [$otherSite->id], 'is_default' => false,
    ]))->toThrow(DomainException::class, 'Routing can only use Sites in your approved Site access.');
    expect(fn () => $setup->updateQueue($queue, $admin, ['site_ids' => []]))
        ->toThrow(DomainException::class, 'every Site this queue covers');
    expect($queue->fresh()->filter_rules['site_ids'])->toBe([$this->site->id]);
});

test('routing stops using a default assignee or cover removed from its team', function () {
    $cover = routingDecisionUser($this->site);
    $assignee = routingDecisionUser($this->site);
    $queue = routingDecisionQueue($this->agent, $cover, $this->site, ['default_assignee_user_id' => $assignee->id]);
    $queue->team->members()->attach($assignee->id, ['role' => 'member']);
    $ticket = ItTicket::factory()->create(['site_id' => $this->site->id]);
    $router = app(ItTicketRoutingService::class);
    $router->route($ticket);
    expect($ticket->fresh()->assigned_to_user_id)->toBe($assignee->id);
    $queue->team->members()->detach([$cover->id, $assignee->id]);
    $router->route($ticket->fresh());
    expect($ticket->fresh()->assigned_to_user_id)->toBeNull()
        ->and($ticket->fresh()->routing_decision['cover_user_id'])->toBe($cover->id)
        ->and($ticket->fresh()->routing_decision['gaps'])->toContain('no_available_cover')
        ->and($router->queueReadiness($queue)['ready'])->toBeFalse();
});

test('priority decisions cover every impact and urgency with monotonic escalation', function () {
    $expected = [
        'individual' => ['low', 'normal', 'high', 'urgent'],
        'team' => ['normal', 'normal', 'high', 'urgent'],
        'site' => ['normal', 'high', 'urgent', 'urgent'],
        'organization' => ['high', 'high', 'urgent', 'urgent'],
    ];
    foreach ($expected as $impact => $priorities) {
        foreach (ItTicket::URGENCIES as $index => $urgency) {
            $decision = app(ItTicketPriorityService::class)->decide(compact('impact', 'urgency'), $this->worker);
            expect($decision['priority'])->toBe($priorities[$index])
                ->and($decision['priority_decision']['mode'])->toBe('automatic');
        }
    }
});

test('legacy priority adapters preserve their level while new dimensions govern assessment', function () {
    foreach (ItTicket::PRIORITIES as $priority) {
        expect(app(ItTicketPriorityService::class)->decide(['priority' => $priority])['priority'])->toBe($priority);
    }
    expect(fn () => app(ItTicketPriorityService::class)->decide([
        'impact' => 'site', 'urgency' => 'critical', 'priority' => 'low',
    ], $this->worker))->toThrow(ValidationException::class);
    expect(fn () => app(ItTicketPriorityService::class)->decide([
        'impact' => 'site', 'urgency' => 'critical', 'priority' => 'low',
    ], $this->agent))->toThrow(ValidationException::class);
});

test('new responsibility cannot inherit site access from an old assignment or inactive employment', function () {
    $otherSite = Site::factory()->create();
    $ticket = ItTicket::factory()->create([
        'site_id' => $otherSite->id, 'assigned_to_user_id' => $this->agent->id,
    ]);
    expect(app(ItTicketRoutingEligibility::class)->agent($this->agent->id, $ticket))->toBeNull();
    $ticket->site_id = $this->site->id;
    expect(app(ItTicketRoutingEligibility::class)->agent($this->agent->id, $ticket)?->id)->toBe($this->agent->id);
    HrEmployeeProfile::query()->where('user_id', $this->agent->id)->update(['is_active' => false]);
    expect(app(ItTicketRoutingEligibility::class)->agent($this->agent->id, $ticket))->toBeNull();
});

test('cover selection consumes approved HR absence without exposing or copying private leave details', function () {
    $ticket = ItTicket::factory()->create(['site_id' => $this->site->id]);
    $leave = HrLeaveRequest::factory()->create([
        'user_id' => $this->agent->id, 'starts_at' => now()->subHour(),
        'ends_at' => now()->addHour(), 'status' => 'pending',
    ]);
    expect(app(ItTicketRoutingEligibility::class)->agent($this->agent->id, $ticket)?->id)->toBe($this->agent->id);
    $leave->update(['status' => 'approved']);
    expect(app(ItTicketRoutingEligibility::class)->agent($this->agent->id, $ticket))->toBeNull()
        ->and(app(ItTicketRoutingEligibility::class)->agent($this->agent->id, $ticket, available: false)?->id)->toBe($this->agent->id);
    $this->travel(2)->hours();
    expect(app(ItTicketRoutingEligibility::class)->agent($this->agent->id, $ticket)?->id)->toBe($this->agent->id);
    $this->travelBack();
});

test('priority overrides preserve their reason through later impact changes until explicit release', function () {
    $policy = app(ItTicketPriorityService::class);
    $ticket = ItTicket::factory()->create([
        'site_id' => $this->site->id,
        ...$policy->decide(['impact' => 'site', 'urgency' => 'critical', 'priority' => 'high',
            'priority_reason' => 'An approved alternate connection is maintaining service.'], $this->agent),
    ]);
    $ticket->update($policy->decide(['impact' => 'organization'], $this->agent, $ticket));
    expect($ticket->fresh()->priority)->toBe('high')
        ->and($ticket->priority_decision['mode'])->toBe('override')
        ->and($ticket->priority_decision['reason'])->toBe('An approved alternate connection is maintaining service.')
        ->and($ticket->priority_decision['derived_priority'])->toBe('urgent');
    expect(fn () => $policy->decide(['release_priority_override' => true], $this->agent, $ticket))
        ->toThrow(ValidationException::class);
    $ticket->update($policy->decide([
        'release_priority_override' => true, 'priority_reason' => 'The alternate connection is no longer available.',
    ], $this->agent, $ticket));
    expect($ticket->fresh()->priority)->toBe('urgent')
        ->and($ticket->priority_decision['mode'])->toBe('automatic')
        ->and($ticket->priority_decision['released_by_user_id'])->toBe($this->agent->id);
});

test('fallback activation requires a distinct current scoped cover while incomplete setup remains editable inactive', function () {
    $service = app(ItServiceManagementSetupService::class);
    $team = ItTeam::factory()->create(['manager_user_id' => $this->agent->id]);
    $base = ['team_id' => $team->id, 'key' => 'it-service-desk', 'name' => 'IT service desk',
        'is_default' => true, 'is_active' => true, 'site_ids' => [$this->site->id]];
    expect(fn () => $service->createQueue($this->agent, $base))->toThrow(DomainException::class);
    $draft = $service->createQueue($this->agent, [...$base, 'is_active' => false]);
    expect($draft->is_active)->toBeFalse();
    expect(fn () => $service->updateQueue($draft, $this->agent, [
        'is_active' => true, 'cover_user_id' => $this->agent->id,
    ]))->toThrow(DomainException::class);
    $cover = routingDecisionUser($this->site);
    $team->members()->attach($cover->id, ['role' => 'member']);
    $queue = $service->updateQueue($draft, $this->agent, ['is_active' => true, 'cover_user_id' => $cover->id]);
    expect($queue->is_active)->toBeTrue()
        ->and($queue->filter_rules['cover_user_id'])->toBe($cover->id)
        ->and($queue->filter_rules['default_assignee_user_id'])->toBeNull();
    $third = Site::factory()->create();
    expect(fn () => $service->updateQueue($queue, $this->agent, ['site_ids' => [$third->id]]))
        ->toThrow(DomainException::class);
    HrEmployeeProfile::query()->where('user_id', $cover->id)->update(['is_active' => false]);
    expect(fn () => $service->updateQueue($queue, $this->agent, ['name' => 'Desk']))
        ->toThrow(DomainException::class);
});

test('a requester chooses either approved site and cannot submit an active third site', function () {
    $second = Site::factory()->create();
    $third = Site::factory()->create();
    HrEmployeeProfile::query()->where('user_id', $this->worker->id)->update(['secondary_site_ids' => [$second->id]]);
    foreach ([$this->site, $second] as $site) {
        $this->actingAs($this->worker)->post('/it/tickets', [
            'title' => 'Chosen site '.$site->id, 'category' => 'network',
            'impact' => 'site', 'urgency' => 'normal', 'site_id' => $site->id,
        ])->assertRedirect()->assertSessionDoesntHaveErrors();
        $ticket = ItTicket::query()->where('title', 'Chosen site '.$site->id)->sole();
        expect($ticket->site_id)->toBe($site->id)->and($ticket->priority)->toBe('high')
            ->and($ticket->routing_decision['gaps'])->toContain('no_eligible_queue');
    }
    $count = ItTicket::query()->count();
    $this->actingAs($this->worker)->postJson('/it/tickets', [
        'title' => 'Unapproved third site', 'category' => 'network',
        'impact' => 'site', 'urgency' => 'normal', 'site_id' => $third->id,
    ])->assertUnprocessable()->assertJsonValidationErrors('site_id');
    expect(ItTicket::query()->count())->toBe($count);
});

test('browser email and scoped service API intake share the same accountable fallback rule', function () {
    $cover = routingDecisionUser($this->site);
    $queue = routingDecisionQueue($this->agent, $cover, $this->site);
    $this->actingAs($this->worker)->post('/it/tickets', [
        'title' => 'Browser routing', 'category' => 'other', 'priority' => 'normal', 'site_id' => $this->site->id,
    ])->assertRedirect()->assertSessionDoesntHaveErrors();
    $browser = ItTicket::query()->where('title', 'Browser routing')->sole();
    $email = app(InboundEmailIngestor::class)->ingest([
        'from' => $this->worker->email, 'subject' => 'Email routing', 'text' => 'A routing test',
        'message_id' => '<w03-routing-'.str()->uuid().'@example.test>',
    ]);
    expect($email->status)->toBe('processed')->and($email->quarantine_reason)->toBeNull();
    $identity = app(ItServiceIdentityCredentialService::class)->create($this->agent, [
        'name' => 'W03 isolated routing adapter', 'actor_user_id' => $this->agent->id,
        'abilities' => ['work:create'], 'allowed_work_types' => ['incident'],
        'allowed_site_ids' => [$this->site->id],
        'allowed_fields' => ['create' => ['title', 'category', 'priority', 'work_type', 'site_id'], 'read' => []],
        'require_signature' => false, 'rate_limit_per_minute' => 10,
    ])['identity'];
    $system = app(ItApiWorkItemService::class)->create($identity, [
        'title' => 'System routing', 'category' => 'other', 'priority' => 'normal',
        'work_type' => 'incident', 'site_id' => $this->site->id,
    ]);
    foreach ([$browser, ItTicket::query()->findOrFail($email->it_ticket_id), $system] as $ticket) {
        expect($ticket->queue_id)->toBe($queue->id)->and($ticket->team_id)->toBe($queue->team_id)
            ->and($ticket->owner_user_id)->toBe($this->agent->id)
            ->and($ticket->routing_decision['strategy'])->toBe('fallback')
            ->and($ticket->routing_decision['gaps'])->toBe([]);
    }
});

test('specific routing is deterministic and a fallback cannot ignore its site restriction', function () {
    $cover = routingDecisionUser($this->site);
    $first = routingDecisionQueue($this->agent, $cover, $this->site, [
        'is_default' => false, 'categories' => ['network'], 'routing_priority' => 20,
    ]);
    routingDecisionQueue($this->agent, $cover, $this->site, [
        'is_default' => false, 'categories' => ['network'], 'routing_priority' => 20,
    ]);
    $remote = Site::factory()->create();
    routingDecisionQueue($this->agent, $cover, $remote);
    $ticket = ItTicket::factory()->create(['site_id' => $this->site->id, 'category' => 'network']);
    $router = app(ItTicketRoutingService::class);
    expect($router->route($ticket)->queue_id)->toBe($first->id);
    $ticket->refresh()->update(['category' => 'hardware']);
    $ticket = $router->route($ticket);
    expect($ticket->queue_id)->toBeNull()->and($ticket->routing_decision['gaps'])->toContain('no_eligible_queue');
});

test('approved absence routes to named cover and returning primary resumes automatic responsibility', function () {
    $cover = routingDecisionUser($this->site);
    routingDecisionQueue($this->agent, $cover, $this->site);
    HrLeaveRequest::factory()->create(['user_id' => $this->agent->id, 'status' => 'approved',
        'starts_at' => now()->subHour(), 'ends_at' => now()->addHour()]);
    $router = app(ItTicketRoutingService::class);
    $ticket = $router->route(ItTicket::factory()->create(['site_id' => $this->site->id]));
    expect($ticket->owner_user_id)->toBe($cover->id)->and($ticket->assigned_to_user_id)->toBe($cover->id)
        ->and($ticket->routing_decision['accountable_user_id'])->toBe($this->agent->id)
        ->and($ticket->routing_decision['cover_applied'])->toBeTrue();
    $this->travel(2)->hours();
    $ticket = $router->route($ticket);
    expect($ticket->owner_user_id)->toBe($this->agent->id)->and($ticket->assigned_to_user_id)->toBe($this->agent->id)
        ->and($ticket->routing_decision['cover_applied'])->toBeFalse();
    $this->travelBack();
});

test('manual queue and unassigned choices survive classification until a reasoned explicit release', function () {
    $cover = routingDecisionUser($this->site);
    $network = routingDecisionQueue($this->agent, $cover, $this->site, ['is_default' => false, 'categories' => ['network']]);
    $hardware = routingDecisionQueue($this->agent, $cover, $this->site, ['is_default' => false, 'categories' => ['hardware']]);
    $ticket = app(ItTicketRoutingService::class)->route(ItTicket::factory()->create([
        'site_id' => $this->site->id, 'category' => 'network',
    ]));
    $triage = app(ItTicketTriageService::class);
    $before = $ticket->lock_version;
    expect(fn () => $triage->update($ticket, $this->agent, [
        'expected_version' => $before, 'queue_id' => $hardware->id,
    ]))->toThrow(ValidationException::class);
    expect($ticket->fresh()->lock_version)->toBe($before);
    $ticket = $triage->update($ticket, $this->agent, [
        'expected_version' => $ticket->lock_version, 'queue_id' => $network->id,
        'assigned_to_user_id' => null, 'routing_reason' => 'Network team will coordinate the device investigation.',
    ]);
    $ticket = $triage->update($ticket, $this->agent, [
        'expected_version' => $ticket->lock_version, 'category' => 'hardware',
    ]);
    expect($ticket->queue_id)->toBe($network->id)->and($ticket->assigned_to_user_id)->toBeNull()
        ->and($ticket->routing_override['reason'])->toBe('Network team will coordinate the device investigation.');
    $ticket = $triage->update($ticket, $this->agent, [
        'expected_version' => $ticket->lock_version, 'release_routing_override' => true,
        'routing_reason' => 'Hardware replacement has been confirmed; resume normal routing.',
    ]);
    expect($ticket->queue_id)->toBe($hardware->id)->and($ticket->routing_override)->toBeNull()
        ->and($ticket->assigned_to_user_id)->toBe($this->agent->id)
        ->and($ticket->events()->where('type', 'routing_override_released')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'it.ticket.routing.applied')->where('auditable_id', $ticket->id)->exists())->toBeTrue();
});

test('an inactive manual assignee is suspended without erasing the recorded intention', function () {
    $cover = routingDecisionUser($this->site);
    $chosen = routingDecisionUser($this->site);
    routingDecisionQueue($this->agent, $cover, $this->site);
    $router = app(ItTicketRoutingService::class);
    $ticket = $router->route(ItTicket::factory()->create(['site_id' => $this->site->id]));
    $ticket = app(ItTicketTriageService::class)->update($ticket, $this->agent, [
        'expected_version' => $ticket->lock_version, 'assigned_to_user_id' => $chosen->id,
        'routing_reason' => 'The technician is continuing the existing investigation.',
    ]);
    HrEmployeeProfile::query()->where('user_id', $chosen->id)->update(['is_active' => false]);
    $ticket = $router->route($ticket);
    expect($ticket->assigned_to_user_id)->toBe($this->agent->id)
        ->and($ticket->routing_override['fields']['assigned_to_user_id'])->toBe($chosen->id)
        ->and($ticket->routing_decision['override_suspended_fields'])->toContain('assigned_to_user_id')
        ->and($ticket->routing_decision['gaps'])->toContain('manual_override_suspended');
});

test('receipts committed by the W02 payload contract still replay after dimensional intake is added', function () {
    $ticket = ItTicket::factory()->create(['site_id' => $this->site->id, 'requester_user_id' => $this->worker->id]);
    $uuid = (string) str()->uuid();
    // Frozen W02 normalized command shape, before W03 added assessment fields.
    $legacy = [
        'title' => 'Legacy committed request', 'description' => null, 'category' => 'hardware',
        'priority' => 'normal', 'subcategory' => null, 'work_type' => 'incident',
        'it_service_id' => null, 'site_id' => $this->site->id, 'requester_user_id' => null,
        'assigned_to_user_id' => null, 'asset_id' => null, 'device_id' => null,
        'provisioning_request_id' => null, 'is_organisation_wide' => false, 'watchers' => [], 'attachments' => [],
    ];
    ItTicketCommandReceipt::query()->create([
        'actor_user_id' => $this->worker->id, 'channel' => ItTicketCommandReceipt::CHANNEL,
        'operation' => ItTicketCommandReceipt::CREATE_OPERATION, 'request_uuid' => $uuid,
        'request_hash' => hash('sha256', json_encode($legacy, JSON_THROW_ON_ERROR)),
        'it_ticket_id' => $ticket->id, 'committed_at' => now(),
    ]);
    $result = app(ItTicketIntakeService::class)->createCommand($this->worker, [
        'request_uuid' => $uuid, 'title' => 'Legacy committed request', 'category' => 'hardware',
        'priority' => 'normal', 'site_id' => $this->site->id,
    ]);
    expect($result->replayed)->toBeTrue()->and($result->ticket->id)->toBe($ticket->id)
        ->and(ItTicket::query()->where('title', 'Legacy committed request')->count())->toBe(0);
});
