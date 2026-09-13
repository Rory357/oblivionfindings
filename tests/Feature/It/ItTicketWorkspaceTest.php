<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Presenters\ItTicketActivityPresenter;
use App\Domain\It\Services\ItEmailDeliveryService;
use App\Domain\It\Services\ItWorkAccessService;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\ControlRoom\Device as ControlRoomDevice;
use App\Models\ControlRoomAlert;
use App\Models\ItAttachment;
use App\Models\ItKbArticle;
use App\Models\ItQueue;
use App\Models\ItService;
use App\Models\ItTeam;
use App\Models\ItTicket;
use App\Models\ItTicketEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Notifications\It\TicketAssignedNotification;
use App\Notifications\It\TicketRepliedNotification;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

function itWorkspaceUser(string $role): User
{
    $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
    $user->roles()->syncWithoutDetaching([
        Role::query()->where('name', $role)->first()->id,
    ]);

    return $user;
}

function assignItWorkspaceUserToSite(User $user, Site $site): void
{
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'created_by' => $user->id,
        'updated_by' => $user->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'is_active' => true,
        'start_date' => now()->subMonth()->toDateString(),
        'end_date' => null,
    ]);
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SecurityDevicesPermissionsSeeder::class);
    $this->hr = itWorkspaceUser('hr');
    $this->worker = itWorkspaceUser('support_worker');
    $this->site = Site::factory()->create(['type' => 'house']);
    assignItWorkspaceUserToSite($this->hr, $this->site);
    assignItWorkspaceUserToSite($this->worker, $this->site);
    $houseSitePermission = Permission::query()->where('key', 'sites.type.house.view')->firstOrFail();
    $this->hr->permissionOverrides()->syncWithoutDetaching([
        Permission::query()->where('key', 'sites.viewAny')->firstOrFail()->id => ['allowed' => true],
        $houseSitePermission->id => ['allowed' => true],
    ]);
});

test('canonical routed ownership is technician visible and requester private', function () {
    $team = ItTeam::factory()->create(['manager_user_id' => $this->hr->id]);
    $queue = ItQueue::factory()->create(['team_id' => $team->id]);
    $ticket = ItTicket::factory()->create([
        'site_id' => $this->site->id,
        'requester_user_id' => $this->worker->id,
        'assigned_to_user_id' => $this->hr->id,
        'owner_user_id' => $this->hr->id,
        'team_id' => $team->id,
        'queue_id' => $queue->id,
    ]);

    $this->actingAs($this->hr)
        ->getJson("/it/tickets/{$ticket->id}")
        ->assertOk()
        ->assertJsonPath('ticket.routing.queue.name', $queue->name)
        ->assertJsonPath('ticket.routing.team.name', $team->name)
        ->assertJsonPath('ticket.routing.owner.name', $this->hr->name);

    $this->actingAs($this->worker)
        ->getJson("/it/tickets/{$ticket->id}")
        ->assertOk()
        ->assertJsonMissingPath('ticket.routing')
        ->assertJsonPath('ticket.assignee.name', $this->hr->name);
});

test('ticket associations expose only canonical destinations the viewer can open', function () {
    $admin = itWorkspaceUser('admin');
    assignItWorkspaceUserToSite($admin, $this->site);
    $admin->permissionOverrides()->syncWithoutDetaching([
        Permission::query()->where('key', 'sites.viewAny')->firstOrFail()->id => ['allowed' => true],
        Permission::query()->where('key', 'sites.type.house.view')->firstOrFail()->id => ['allowed' => true],
    ]);
    $watcher = itWorkspaceUser('hr');
    assignItWorkspaceUserToSite($watcher, $this->site);
    $asset = Asset::factory()->create([
        'site_id' => $this->site->id,
        'status' => 'active',
    ]);
    $ticket = ItTicket::factory()->create([
        'site_id' => $this->site->id,
        'asset_id' => $asset->id,
        'requester_user_id' => $this->worker->id,
        'assigned_to_user_id' => $this->hr->id,
    ]);
    $ticket->watchers()->attach($watcher->id);

    $requesterProfileId = $this->worker->hrEmployeeProfile()->value('id');
    $assigneeProfileId = $this->hr->hrEmployeeProfile()->value('id');
    $watcherProfileId = $watcher->hrEmployeeProfile()->value('id');

    $this->actingAs($admin)
        ->getJson("/it/tickets/{$ticket->id}")
        ->assertOk()
        ->assertJsonPath('ticket.requester.href', "/hr/people/{$requesterProfileId}")
        ->assertJsonPath('ticket.assignee.href', "/hr/people/{$assigneeProfileId}")
        ->assertJsonPath('ticket.watchers.0.href', "/hr/people/{$watcherProfileId}")
        ->assertJsonPath('ticket.asset.href', "/fleet-assets/assets/{$asset->id}")
        ->assertJsonPath('ticket.site.href', "/sites/{$this->site->id}");

    $this->actingAs($this->worker)
        ->getJson("/it/tickets/{$ticket->id}")
        ->assertOk()
        ->assertJsonPath('ticket.requester.href', null)
        ->assertJsonPath('ticket.assignee.href', null)
        ->assertJsonPath('ticket.watchers.0.href', null)
        ->assertJsonPath('ticket.asset.href', null)
        ->assertJsonPath('ticket.site.href', "/sites/{$this->site->id}");
});

test('ticket associations withhold HR and asset links outside the viewers Sites despite module permissions', function () {
    $hiddenSite = Site::factory()->create(['name' => 'Hidden association Site']);
    $hiddenRequester = itWorkspaceUser('support_worker');
    $hiddenAssignee = itWorkspaceUser('hr');
    $hiddenWatcher = itWorkspaceUser('hr');
    foreach ([$hiddenRequester, $hiddenAssignee, $hiddenWatcher] as $staff) {
        assignItWorkspaceUserToSite($staff, $hiddenSite);
    }
    $hiddenAsset = Asset::factory()->create([
        'site_id' => $hiddenSite->id,
        'status' => 'active',
    ]);
    $this->hr->permissionOverrides()->syncWithoutDetaching([
        Permission::query()->where('key', 'assets.viewAny')->firstOrFail()->id => ['allowed' => true],
    ]);
    $ticket = ItTicket::factory()->create([
        'site_id' => $this->site->id,
        'asset_id' => $hiddenAsset->id,
        'requester_user_id' => $hiddenRequester->id,
        'assigned_to_user_id' => $hiddenAssignee->id,
    ]);
    $ticket->watchers()->attach($hiddenWatcher->id);

    $this->actingAs($this->hr)
        ->getJson("/it/tickets/{$ticket->id}")
        ->assertOk()
        ->assertJsonPath('ticket.requester.href', null)
        ->assertJsonPath('ticket.assignee.href', null)
        ->assertJsonPath('ticket.watchers.0.href', null)
        ->assertJsonPath('ticket.asset.href', null)
        ->assertJsonPath('ticket.site.href', "/sites/{$this->site->id}");
});

test('linked context follows canonical device and alert permissions without leaking raw payloads', function () {
    $agent = itWorkspaceUser('admin');
    assignItWorkspaceUserToSite($agent, $this->site);
    $ticket = ItTicket::factory()->create([
        'site_id' => $this->site->id,
        'requester_user_id' => $this->worker->id,
    ]);
    $device = Device::factory()->itInfrastructure()->create([
        'name' => 'Core switch',
        'config' => ['snmp_community' => 'private-secret'],
    ]);
    DeviceAssignment::query()->create([
        'device_id' => $device->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $this->site->id,
        'assignment_type' => 'permanent',
        'assigned_at' => now(),
        'assigned_by_user_id' => $agent->id,
    ]);
    $projection = ControlRoomDevice::create([
        'name' => $device->name,
        'canonical_device_id' => $device->id,
        'site_id' => $this->site->id,
        'type' => ControlRoomDevice::TYPE_NETWORK,
    ]);
    $alert = ControlRoomAlert::factory()->create([
        'source' => 'security_devices',
        'device_id' => $projection->id,
        'site_id' => $this->site->id,
        'context' => [
            'signal_payload' => ['credential' => 'never-return-this'],
            'client_id' => 999,
        ],
    ]);
    $ticket->links()->createMany([
        [
            'relationship' => 'affected_device',
            'linkable_type' => $device->getMorphClass(),
            'linkable_id' => $device->id,
        ],
        [
            'relationship' => 'source_alert',
            'linkable_type' => $alert->getMorphClass(),
            'linkable_id' => $alert->id,
        ],
    ]);

    $this->actingAs($agent)
        ->getJson("/it/tickets/{$ticket->id}")
        ->assertOk()
        ->assertJsonPath('linked_context.devices.0.id', $device->id)
        ->assertJsonPath('linked_context.devices.0.name', $device->name)
        ->assertJsonPath('linked_context.devices.0.health_status', $device->health_status->value)
        ->assertJsonPath('linked_context.alerts.0.id', $alert->id)
        ->assertJsonPath('linked_context.alerts.0.status', $alert->status)
        ->assertJsonPath('linked_context.alerts.0.reference', $alert->reference_number)
        ->assertJsonMissingPath('linked_context.devices.0.config')
        ->assertJsonMissingPath('linked_context.devices.0.command_capability')
        ->assertJsonMissingPath('linked_context.alerts.0.context')
        ->assertJsonMissingPath('linked_context.alerts.0.payload')
        ->assertJsonMissingPath('linked_context.alerts.0.client_id');

    $this->actingAs($this->worker)
        ->getJson("/it/tickets/{$ticket->id}")
        ->assertOk()
        ->assertJsonPath('linked_context.devices.0.id', $device->id)
        ->assertJsonPath('linked_context.alerts.0.id', $alert->id)
        ->assertJsonMissingPath('linked_context.devices.0.config')
        ->assertJsonMissingPath('linked_context.devices.0.command_capability');
});

test('agents govern affected Device links while monitoring evidence remains immutable', function () {
    $agent = itWorkspaceUser('admin');
    assignItWorkspaceUserToSite($agent, $this->site);
    $otherSite = Site::factory()->create();
    $ticket = ItTicket::factory()->create([
        'site_id' => $this->site->id,
        'requester_user_id' => $this->worker->id,
        'work_type' => 'incident',
        'status' => 'open',
    ]);
    $localDevice = Device::factory()->itInfrastructure()->create(['name' => 'Ward switch']);
    $healthcareDevice = Device::factory()->iotHealthcare()->create(['name' => 'Ward fall monitor']);
    $otherDevice = Device::factory()->itInfrastructure()->create(['name' => 'Remote switch']);
    foreach ([[$localDevice, $this->site], [$otherDevice, $otherSite]] as [$device, $site]) {
        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assignment_type' => 'permanent',
            'assigned_at' => now(),
            'assigned_by_user_id' => $agent->id,
        ]);
    }
    $healthcareAsset = Asset::factory()->create([
        'name' => 'Bedside fall detection kit',
        'category' => 'medical_equipment',
        'site_id' => $this->site->id,
        'home_site_id' => $this->site->id,
        'status' => 'active',
    ]);
    $healthcareDevice->assetLinks()->create([
        'asset_id' => $healthcareAsset->id,
        'link_type' => 'primary',
        'linked_at' => now(),
        'linked_by_user_id' => $agent->id,
    ]);

    $this->actingAs($agent)
        ->get(route('it.tickets.show', $ticket))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('deviceOptions', 2)
            ->where('deviceOptions', fn ($devices) => collect($devices)
                ->pluck('site_id', 'id')
                ->all() === [
                    $healthcareDevice->id => $this->site->id,
                    $localDevice->id => $this->site->id,
                ]));

    $this->actingAs($agent)
        ->post(route('it.tickets.devices.store', $ticket), ['device_id' => $otherDevice->id])
        ->assertRedirect()
        ->assertSessionHas('error', 'Choose a Device in the ticket Site.');
    expect($ticket->links()->where('relationship', 'affected_device')->exists())->toBeFalse();

    $this->actingAs($agent)
        ->post(route('it.tickets.devices.store', $ticket), ['device_id' => $healthcareDevice->id])
        ->assertRedirect()
        ->assertSessionHas('success', 'Device linked to ticket.');
    $this->actingAs($agent)
        ->post(route('it.tickets.devices.store', $ticket), ['device_id' => $healthcareDevice->id])
        ->assertRedirect()
        ->assertSessionHas('success', 'Device is already linked to this ticket.');

    expect($ticket->links()->where('relationship', 'affected_device')->count())->toBe(1)
        ->and($ticket->events()->where('type', 'context_linked')->count())->toBe(1)
        ->and(AuditLog::query()
            ->where('action', 'it.ticket.device.linked')
            ->where('auditable_id', $ticket->id)
            ->where('meta->device_id', $healthcareDevice->id)
            ->count())->toBe(1);

    $this->actingAs($agent)
        ->delete(route('it.tickets.devices.destroy', [$ticket, $healthcareDevice]))
        ->assertRedirect()
        ->assertSessionHas('success', 'Device link removed.');

    expect($ticket->links()->where('relationship', 'affected_device')->exists())->toBeFalse()
        ->and($ticket->events()->where('type', 'context_unlinked')->count())->toBe(1)
        ->and(AuditLog::query()
            ->where('action', 'it.ticket.device.unlinked')
            ->where('auditable_id', $ticket->id)
            ->where('meta->device_id', $healthcareDevice->id)
            ->count())->toBe(1);

    $ticket->links()->create([
        'relationship' => 'affected_device',
        'linkable_type' => $healthcareDevice->getMorphClass(),
        'linkable_id' => $healthcareDevice->id,
        'context' => ['system_principal' => 'oblivion_monitoring_ticketing'],
    ]);
    $this->actingAs($agent)
        ->delete(route('it.tickets.devices.destroy', [$ticket, $healthcareDevice]))
        ->assertRedirect()
        ->assertSessionHas('error', 'Monitoring evidence is managed by Oblivion monitoring and cannot be removed here.');

    expect($ticket->links()->where('relationship', 'affected_device')->exists())->toBeTrue();
    $this->actingAs($this->worker)
        ->delete(route('it.tickets.devices.destroy', [$ticket, $healthcareDevice]))
        ->assertForbidden();
});

test('the workspace strips internal notes from requester payloads server-side', function () {
    $ticket = ItTicket::factory()->create([
        'site_id' => $this->site->id,
        'requester_user_id' => $this->worker->id,
    ]);
    $ticket->comments()->create([
        'author_user_id' => $this->worker->id,
        'body' => 'Public: it is still broken.',
        'is_internal' => false,
    ]);
    $ticket->comments()->create([
        'author_user_id' => $this->hr->id,
        'body' => 'Internal: suspect Hemi dropped it — order a rugged case.',
        'is_internal' => true,
    ]);

    // Requester: own ticket loads, internal note absent from the PAYLOAD.
    $this->actingAs($this->worker)
        ->get("/it/tickets/{$ticket->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('it/tickets/show')
            ->where('ticket.id', $ticket->id)
            ->has('comments', 1)
            ->where('comments.0.is_internal', false)
            ->where('can.manage', false)
            ->where('can.internal', false));

    // Agent: both messages.
    $this->actingAs($this->hr)
        ->get("/it/tickets/{$ticket->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('comments', 2)
            ->where('can.manage', true));

    // A different requester: not their ticket, not their business.
    $stranger = itWorkspaceUser('support_worker');
    $this->actingAs($stranger)->get("/it/tickets/{$ticket->id}")->assertNotFound();
});

test('participant-only technicians receive public ticket projections in every workspace surface', function (bool $sensitive, string $participantColumn) {
    Storage::fake(ItAttachment::DISK);
    $ticketSite = $sensitive ? $this->site : Site::factory()->create();
    $technician = itWorkspaceUser('admin');
    assignItWorkspaceUserToSite($technician, $ticketSite);
    $ticket = ItTicket::factory()->create([
        'site_id' => $ticketSite->id,
        'requester_user_id' => $this->worker->id,
        $participantColumn => $this->hr->id,
        'assigned_to_user_id' => $technician->id,
        'is_sensitive' => $sensitive,
        'status' => 'waiting',
        'waiting_party' => 'supplier',
        'waiting_reason' => 'Private supplier investigation waiting reason.',
        'next_action' => 'Private supplier escalation next action.',
        'waiting_since' => now()->subHour(),
        'routing_override' => [
            'reason' => 'Private routing override reason.',
            'actor_user_id' => $technician->id,
            'fields' => ['assigned_to_user_id' => $technician->id],
        ],
    ]);
    $access = app(ItWorkAccessService::class);
    expect($this->hr->canDo('it.manage'))->toBeTrue()
        ->and($access->canView($this->hr, $ticket))->toBeTrue()
        ->and($access->canWork($this->hr, $ticket))->toBeFalse()
        ->and($access->canWork($technician, $ticket))->toBeTrue();

    $publicComment = $ticket->comments()->create([
        'author_user_id' => $technician->id,
        'body' => 'Public update for the participant.',
        'is_internal' => false,
    ]);
    $internalComment = $ticket->comments()->create([
        'author_user_id' => $technician->id,
        'body' => 'Restricted investigation must remain internal.',
        'is_internal' => true,
    ]);
    $files = collect([$publicComment, $internalComment])->map(function ($comment) use ($technician) {
        $name = $comment->is_internal ? 'restricted-evidence.txt' : 'public-evidence.txt';
        $path = "it/privacy/{$comment->id}/{$name}";
        Storage::disk(ItAttachment::DISK)->put($path, 'Disposable privacy evidence.');

        return $comment->attachments()->create([
            'path' => $path,
            'original_name' => $name,
            'mime' => 'text/plain',
            'size' => 28,
            'uploaded_by' => $technician->id,
        ]);
    });
    $publicEvent = ItTicketEvent::record($ticket, 'workflow_transitioned', $technician->id, [
        'from' => 'open',
        'to' => 'in_progress',
        'reason' => 'Private transition explanation.',
    ]);
    $internalEvent = ItTicketEvent::record($ticket, 'priority_changed', $technician->id, [
        'from' => 'normal',
        'to' => 'high',
        'reason' => 'Restricted investigation priority.',
    ]);

    $this->actingAs($this->hr)->get(route('it.tickets.show', $ticket))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('it/tickets/show')
            ->has('comments', 1)
            ->where('comments.0.id', $publicComment->id)
            ->has('comments.0.attachments', 1)
            ->where('comments.0.attachments.0.id', $files[0]->id)
            ->has('events', 1)
            ->where('events.0.id', $publicEvent->id)
            ->missing('events.0.payload.reason')
            ->missing('ticket.routing')
            ->where('can.view', false)
            ->where('can.manage', false)
            ->where('can.internal', false)
            ->has('kbSuggestions', 0));

    $this->actingAs($this->hr)->getJson(route('it.tickets.show', $ticket))
        ->assertOk()
        ->assertJsonCount(1, 'comments')
        ->assertJsonPath('comments.0.id', $publicComment->id)
        ->assertJsonCount(1, 'events')
        ->assertJsonCount(2, 'events.0.payload')
        ->assertJsonPath('events.0.payload.from', 'open')
        ->assertJsonPath('events.0.payload.to', 'in_progress')
        ->assertJsonMissingPath('ticket.routing')
        ->assertJsonPath('can.view', false)
        ->assertJsonMissing(['body' => $internalComment->body])
        ->assertJsonMissing(['name' => $files[1]->original_name]);

    $this->actingAs($this->hr)->get(route('it.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('tickets.data', 1)
            ->where('tickets.data.0.can.manage', false)
            ->where('tickets.data.0.waiting_party', 'other')
            ->missing('tickets.data.0.waiting_reason')
            ->missing('tickets.data.0.next_action')
            ->missing('tickets.data.0.waiting_since')
            ->missing('tickets.data.0.routing')
            ->has('overview.recent_activity', 1)
            ->where('overview.recent_activity.0.id', $publicEvent->id)
            ->missing('overview.recent_activity.0.payload.reason'));
    $this->actingAs($this->hr)->get(route('it.attachments.download', $files[0]))->assertOk();
    $this->actingAs($this->hr)->get(route('it.attachments.download', $files[1]))->assertNotFound();
    $this->actingAs($this->hr)->post(route('it.tickets.comments.store', $ticket), [
        'body' => 'An internal note is not participant work.',
        'is_internal' => true,
    ])->assertForbidden();

    $this->actingAs($technician)->getJson(route('it.tickets.show', $ticket))
        ->assertOk()
        ->assertJsonCount(2, 'comments')
        ->assertJsonPath('comments.1.attachments.0.id', $files[1]->id)
        ->assertJsonCount(2, 'events')
        ->assertJsonPath('events.1.id', $internalEvent->id)
        ->assertJsonPath('events.0.payload.reason', 'Private transition explanation.')
        ->assertJsonPath('can.view', true)
        ->assertJsonPath('can.internal', true);
    $this->actingAs($technician)->get(route('it.attachments.download', $files[1]))->assertOk();
    $this->actingAs($technician)->get(route('it.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('tickets.data.0.can.manage', true)
            ->where('tickets.data.0.waiting_reason', 'Private supplier investigation waiting reason.')
            ->where('tickets.data.0.next_action', 'Private supplier escalation next action.')
            ->where('tickets.data.0.routing.override.reason', 'Private routing override reason.')
            ->has('overview.recent_activity', 2)
            ->where('overview.recent_activity', fn ($events) => collect($events)
                ->contains(fn ($event) => $event['id'] === $internalEvent->id
                    && $event['payload']['reason'] === 'Restricted investigation priority.')));

    // An already-open preview does not authorize a later request after the
    // canonical participant link is revoked.
    $ticket->update([$participantColumn => $this->worker->id]);
    expect(app(ItTicketActivityPresenter::class)->present($ticket, $this->hr))->toBe([])
        ->and(app(ItTicketActivityPresenter::class)->presentEvent($publicEvent, $this->hr))->toBeNull();
    $this->actingAs($this->hr)->getJson(route('it.tickets.show', $ticket))->assertNotFound();
    $this->actingAs($this->hr)->get(route('it.attachments.download', $files[0]))->assertNotFound();
    $this->actingAs($this->hr)->post(route('it.tickets.comments.store', $ticket), [
        'body' => 'A stale participant reply.',
    ])->assertNotFound();
})->with([
    'sensitive requester' => [true, 'requester_user_id'],
    'sensitive requested-for' => [true, 'requested_for_user_id'],
    'unapproved-site requester' => [false, 'requester_user_id'],
    'unapproved-site requested-for' => [false, 'requested_for_user_id'],
]);

test('read-only IT access does not widen the internal work boundary', function () {
    $managePermission = Permission::query()->where('key', 'it.manage')->firstOrFail();
    $this->hr->permissionOverrides()->syncWithoutDetaching([$managePermission->id => ['allowed' => false]]);
    $ticket = ItTicket::factory()->create([
        'site_id' => $this->site->id,
        'requester_user_id' => $this->worker->id,
    ]);
    $ticket->comments()->create([
        'author_user_id' => $this->worker->id,
        'body' => 'A public request update.',
        'is_internal' => false,
    ]);
    $ticket->comments()->create([
        'author_user_id' => $this->hr->id,
        'body' => 'A historical internal investigation.',
        'is_internal' => true,
    ]);

    expect($this->hr->canDo('it.view'))->toBeTrue()
        ->and(app(ItWorkAccessService::class)->canView($this->hr, $ticket))->toBeTrue()
        ->and(app(ItWorkAccessService::class)->canWork($this->hr, $ticket))->toBeFalse();
    $this->actingAs($this->hr)->getJson(route('it.tickets.show', $ticket))
        ->assertOk()
        ->assertJsonCount(1, 'comments')
        ->assertJsonMissingPath('ticket.routing')
        ->assertJsonPath('can.view', false)
        ->assertJsonPath('can.internal', false);
    $this->actingAs($this->hr)->get('/it?tab=tickets')->assertOk()
        ->assertInertia(fn ($page) => $page->where('tickets.data.0.can.manage', false)
            ->missing('tickets.data.0.routing')->missing('tickets.data.0.waiting_reason')
            ->missing('tickets.data.0.next_action')->missing('tickets.data.0.waiting_since'));
});

test('comments respect the internal gate and stamp the first agent response', function () {
    Notification::fake();
    $ticket = ItTicket::factory()->create([
        'site_id' => $this->site->id,
        'requester_user_id' => $this->worker->id,
    ]);

    // Requester replies publicly — fine; internal — forbidden.
    $this->actingAs($this->worker)
        ->post("/it/tickets/{$ticket->id}/comments", ['body' => 'Any update?'])
        ->assertRedirect();
    $this->actingAs($this->worker)
        ->post("/it/tickets/{$ticket->id}/comments", ['body' => 'sneaky', 'is_internal' => true])
        ->assertForbidden();

    // Agent internal note: allowed, does NOT stamp first response.
    $this->actingAs($this->hr)
        ->post("/it/tickets/{$ticket->id}/comments", ['body' => 'Checking the MDM logs.', 'is_internal' => true])
        ->assertRedirect();
    expect($ticket->fresh()->first_responded_at)->toBeNull();

    // First PUBLIC agent reply stamps it; a second one does not move it.
    $this->actingAs($this->hr)
        ->post("/it/tickets/{$ticket->id}/comments", ['body' => 'On it — swap unit heading your way.'])
        ->assertRedirect();
    $stamped = $ticket->fresh()->first_responded_at;
    expect($stamped)->not->toBeNull();

    $this->travel(10)->minutes();
    $this->actingAs($this->hr)
        ->post("/it/tickets/{$ticket->id}/comments", ['body' => 'Also updating the OS.'])
        ->assertRedirect();
    expect($ticket->fresh()->first_responded_at->equalTo($stamped))->toBeTrue();
    expect($ticket->events()->where('type', 'first_response_recorded')->count())->toBe(1);
    expect(AuditLog::query()
        ->where('action', 'it.ticket.comment.added')
        ->where('auditable_type', $ticket->getMorphClass())
        ->where('auditable_id', $ticket->id)
        ->count())->toBe(4);
});

test('settled ticket conversations are read only until the ticket is reopened', function () {
    $ticket = ItTicket::factory()->create([
        'site_id' => $this->site->id,
        'requester_user_id' => $this->worker->id,
        'status' => 'resolved',
        'resolved_at' => now(),
    ]);

    $this->actingAs($this->worker)
        ->get("/it/tickets/{$ticket->id}")
        ->assertInertia(fn ($page) => $page
            ->where('can.comment', false)
            ->where('replyUnavailableReason', 'Reopen this ticket before adding another reply.'));

    $this->actingAs($this->worker)
        ->post("/it/tickets/{$ticket->id}/comments", ['body' => 'This did not stay fixed.'])
        ->assertRedirect()
        ->assertSessionHasErrors(['body' => 'Reopen this ticket before adding another reply or note.'])
        ->assertSessionMissing('success');

    expect($ticket->comments()->count())->toBe(0);
});

test('a requester reply resumes a legacy wait without inventing measured pause minutes', function () {
    $ticket = ItTicket::factory()->create([
        'site_id' => $this->site->id,
        'requester_user_id' => $this->worker->id,
        'status' => 'waiting',
        'waiting_since' => now()->subMinutes(30),
    ]);

    $this->actingAs($this->worker)
        ->post("/it/tickets/{$ticket->id}/comments", ['body' => 'Here is the photo you asked for.'])
        ->assertRedirect();

    $ticket->refresh();
    expect($ticket->status)->toBe('in_progress');
    expect($ticket->waiting_since)->toBeNull();
    expect($ticket->sla_paused_minutes)->toBe(0)
        ->and($ticket->sla_state)->toBe('unmeasured')
        ->and($ticket->sla_policy_snapshot['pause_unit'])->toBe('legacy_unknown');
    expect(
        $ticket->events()->where('type', 'status_changed')->get()
            ->contains(fn (ItTicketEvent $e) => ($e->payload['via'] ?? null) === 'requester_reply'),
    )->toBeTrue();
});

test('public replies notify the other side of the conversation only', function () {
    Notification::fake();
    $watcher = itWorkspaceUser('hr');
    assignItWorkspaceUserToSite($watcher, $this->site);
    $ticket = ItTicket::factory()->create([
        'site_id' => $this->site->id,
        'requester_user_id' => $this->worker->id,
        'assigned_to_user_id' => $this->hr->id,
        'status' => 'in_progress',
    ]);
    $ticket->watchers()->attach($watcher->id);

    // Agent public reply → requester hears about it; agent side does not.
    $this->actingAs($this->hr)
        ->post("/it/tickets/{$ticket->id}/comments", ['body' => 'Replacement approved.'])
        ->assertRedirect();
    Notification::assertSentTo(
        $this->worker,
        TicketRepliedNotification::class,
        fn (TicketRepliedNotification $n) => $n->toArray($this->worker)['audience'] === 'requester',
    );
    Notification::assertNotSentTo($watcher, TicketRepliedNotification::class);

    Notification::fake();

    // Requester reply → assignee + watcher hear; the actor never does.
    $this->actingAs($this->worker)
        ->post("/it/tickets/{$ticket->id}/comments", ['body' => 'Thanks — when?'])
        ->assertRedirect();
    Notification::assertSentTo($this->hr, TicketRepliedNotification::class);
    Notification::assertSentTo($watcher, TicketRepliedNotification::class);
    Notification::assertNotSentTo($this->worker, TicketRepliedNotification::class);

    Notification::fake();

    // Internal notes notify nobody.
    $this->actingAs($this->hr)
        ->post("/it/tickets/{$ticket->id}/comments", ['body' => 'internal musing', 'is_internal' => true])
        ->assertRedirect();
    Notification::assertNothingSent();
});

test('the quick-peek JSON branch mirrors the page payload and its stripping', function () {
    $ticket = ItTicket::factory()->create([
        'site_id' => $this->site->id,
        'requester_user_id' => $this->worker->id,
    ]);
    $ticket->comments()->create([
        'author_user_id' => $this->worker->id,
        'body' => 'Public message.',
        'is_internal' => false,
    ]);
    $ticket->comments()->create([
        'author_user_id' => $this->hr->id,
        'body' => 'Internal-only note.',
        'is_internal' => true,
    ]);

    // Agent: full thread over JSON (the drawer's fetch path).
    $this->actingAs($this->hr)
        ->getJson("/it/tickets/{$ticket->id}")
        ->assertOk()
        ->assertJsonPath('ticket.id', $ticket->id)
        ->assertJsonCount(2, 'comments');

    // Requester: the JSON branch strips internal notes exactly like the page.
    $this->actingAs($this->worker)
        ->getJson("/it/tickets/{$ticket->id}")
        ->assertOk()
        ->assertJsonCount(1, 'comments')
        ->assertJsonPath('comments.0.is_internal', false);

    // Policy runs on the JSON branch too.
    $stranger = itWorkspaceUser('support_worker');
    $this->actingAs($stranger)->getJson("/it/tickets/{$ticket->id}")->assertNotFound();
});

test('the rail can classify reroute and retag a ticket', function () {
    $secondSite = Site::factory()->create(['name' => 'South Campus']);
    HrEmployeeProfile::query()
        ->where('user_id', $this->hr->id)
        ->update(['secondary_site_ids' => [$secondSite->id]]);
    $staleAssignee = itWorkspaceUser('hr');
    assignItWorkspaceUserToSite($staleAssignee, $this->site);
    $ticket = ItTicket::factory()->create([
        'site_id' => $this->site->id,
        'requester_user_id' => $this->worker->id,
        'assigned_to_user_id' => $staleAssignee->id,
    ]);
    $asset = Asset::factory()->create([
        'site_id' => $this->site->id,
        'status' => 'active',
    ]);
    $service = ItService::factory()->create(['name' => 'Site connectivity']);
    $queue = ItQueue::factory()->create([
        'filter_rules' => [
            'routing_priority' => 50,
            'is_default' => false,
            'work_types' => ['service_request'],
            'categories' => ['network'],
            'service_ids' => [$service->id],
            'site_ids' => [$this->site->id],
        ],
    ]);

    $this->actingAs($this->hr)
        ->patch("/it/tickets/{$ticket->id}", [
            'expected_version' => $ticket->fresh()->lock_version,
            'category' => 'network',
            'work_type' => 'service_request',
            'it_service_id' => $service->id,
            'subcategory' => 'VPN',
            'asset_id' => $asset->id,
        ])
        ->assertRedirect();

    $ticket->refresh();
    expect($ticket->category)->toBe('network');
    expect($ticket->work_type)->toBe('service_request');
    expect((int) $ticket->it_service_id)->toBe($service->id);
    expect((int) $ticket->queue_id)->toBe($queue->id);
    expect($ticket->subcategory)->toBe('VPN');
    expect((int) $ticket->asset_id)->toBe($asset->id);

    // The workspace offers agents the asset picker; requesters never get it.
    $this->actingAs($this->hr)
        ->get("/it/tickets/{$ticket->id}")
        ->assertInertia(fn ($page) => $page
            ->has('assetOptions', 1)
            ->where('assetOptions.0.id', $asset->id)
            ->has('serviceOptions', 1)
            ->where('serviceOptions.0.id', $service->id)
            ->has('siteOptions', 2)
            ->where('siteOptions', fn ($sites) => collect($sites)->pluck('id')->sort()->values()->all()
                === collect([$this->site->id, $secondSite->id])->sort()->values()->all())
            ->where('can.assignApplicationWide', false)
            ->where('ticket.work_type', 'service_request')
            ->where('ticket.service.id', $service->id)
            ->where('ticket.service.name', 'Site connectivity')
            ->where('ticket.site.id', $this->site->id)
            ->where('ticket.site.name', $this->site->name)
            ->where('ticket.is_organisation_wide', false));
    $this->actingAs($this->worker)
        ->get("/it/tickets/{$ticket->id}")
        ->assertInertia(fn ($page) => $page
            ->has('assetOptions', 0)
            ->has('serviceOptions', 0)
            ->has('siteOptions', 0)
            ->where('can.assignApplicationWide', false)
            ->where('ticket.work_type', 'service_request')
            ->where('ticket.service.name', 'Site connectivity')
            ->where('ticket.site.id', $this->site->id));

    // A Site-only move cannot strand a linked Asset in the old Site.
    $this->actingAs($this->hr)
        ->patch("/it/tickets/{$ticket->id}", [
            'expected_version' => $ticket->fresh()->lock_version,
            'site_id' => $secondSite->id,
            'is_organisation_wide' => false,
        ])
        ->assertRedirect()
        ->assertSessionHas('error', 'Remove or change the linked Asset before changing the ticket Site.');
    expect((int) $ticket->fresh()->site_id)->toBe($this->site->id);

    // Removing the incompatible Asset in the same update permits the move;
    // stale routing and an assignee without the new Site are released.
    $this->actingAs($this->hr)
        ->patch("/it/tickets/{$ticket->id}", [
            'expected_version' => $ticket->fresh()->lock_version,
            'asset_id' => null,
            'site_id' => $secondSite->id,
            'is_organisation_wide' => false,
        ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Ticket updated.');

    $ticket->refresh();
    expect((int) $ticket->site_id)->toBe($secondSite->id)
        ->and($ticket->asset_id)->toBeNull()
        ->and($ticket->assigned_to_user_id)->toBeNull()
        ->and($ticket->queue_id)->toBeNull();
});

test('only an explicitly application-wide manager can move a ticket to all Sites', function () {
    $admin = itWorkspaceUser('admin');
    assignItWorkspaceUserToSite($admin, $this->site);
    $ticket = ItTicket::factory()->create([
        'site_id' => $this->site->id,
        'is_organisation_wide' => false,
        'requester_user_id' => $this->worker->id,
    ]);

    $this->actingAs($admin)
        ->get("/it/tickets/{$ticket->id}")
        ->assertInertia(fn ($page) => $page
            ->where('can.assignApplicationWide', true)
            ->where('ticket.site.id', $this->site->id));

    $this->actingAs($admin)
        ->patch("/it/tickets/{$ticket->id}", [
            'expected_version' => $ticket->fresh()->lock_version,
            'site_id' => null,
            'is_organisation_wide' => true,
        ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Ticket updated.');

    $ticket->refresh();
    expect($ticket->site_id)->toBeNull()
        ->and($ticket->is_organisation_wide)->toBeTrue();

    $this->actingAs($this->worker)
        ->get("/it/tickets/{$ticket->id}")
        ->assertInertia(fn ($page) => $page
            ->where('ticket.site', null)
            ->where('ticket.is_organisation_wide', true)
            ->has('siteOptions', 0)
            ->where('can.assignApplicationWide', false));
});

test('the composer carries published KB to agents for suggestion, never to requesters', function () {
    ItKbArticle::factory()->published()->create(['title' => 'Reset your password']);
    ItKbArticle::factory()->create(['title' => 'Secret runbook']); // draft — never suggested
    $ticket = ItTicket::factory()->create([
        'site_id' => $this->site->id,
        'requester_user_id' => $this->worker->id,
    ]);

    // Agent: the workspace payload carries published articles (drafts excluded).
    $this->actingAs($this->hr)
        ->get("/it/tickets/{$ticket->id}")
        ->assertInertia(fn ($page) => $page
            ->has('kbSuggestions', 1)
            ->where('kbSuggestions.0.title', 'Reset your password')
            ->missing('kbSuggestions.0.body')); // lean — title/category only, never body

    // The requester on their own ticket never receives the suggestion list.
    $this->actingAs($this->worker)
        ->get("/it/tickets/{$ticket->id}")
        ->assertInertia(fn ($page) => $page->has('kbSuggestions', 0));

    // The JSON (drawer) branch mirrors the agent payload.
    $this->actingAs($this->hr)
        ->getJson("/it/tickets/{$ticket->id}")
        ->assertOk()
        ->assertJsonCount(1, 'kbSuggestions')
        ->assertJsonPath('kbSuggestions.0.title', 'Reset your password');
});

test('watch and unwatch are agent actions recorded on the trail', function () {
    $ticket = ItTicket::factory()->create([
        'site_id' => $this->site->id,
        'requester_user_id' => $this->worker->id,
    ]);

    $this->actingAs($this->worker)
        ->post("/it/tickets/{$ticket->id}/watch")
        ->assertForbidden();

    $this->actingAs($this->hr)->post("/it/tickets/{$ticket->id}/watch")->assertRedirect();
    expect($ticket->watchers()->count())->toBe(1);
    expect($ticket->events()->where('type', 'watcher_added')->count())->toBe(1);

    // Idempotent: watching twice records once.
    $this->actingAs($this->hr)->post("/it/tickets/{$ticket->id}/watch")->assertRedirect();
    expect($ticket->events()->where('type', 'watcher_added')->count())->toBe(1);

    $this->actingAs($this->hr)->post("/it/tickets/{$ticket->id}/unwatch")->assertRedirect();
    expect($ticket->watchers()->count())->toBe(0);
    expect($ticket->events()->where('type', 'watcher_removed')->count())->toBe(1);
    expect(AuditLog::query()
        ->where('auditable_type', $ticket->getMorphClass())
        ->where('auditable_id', $ticket->id)
        ->whereIn('action', ['it.ticket.watcher.added', 'it.ticket.watcher.removed'])
        ->count())->toBe(2);
});

test('triage updates write the activity trail and notify the new assignee', function () {
    Notification::fake();
    $ticket = ItTicket::factory()->create([
        'site_id' => $this->site->id,
        'requester_user_id' => $this->worker->id,
    ]);
    $colleague = itWorkspaceUser('hr');
    assignItWorkspaceUserToSite($colleague, $this->site);

    $this->actingAs($this->hr)
        ->patch("/it/tickets/{$ticket->id}", [
            'expected_version' => $ticket->fresh()->lock_version,
            'assigned_to_user_id' => $colleague->id,
            'routing_reason' => 'The colleague is handling the replacement.',
            'priority' => 'high',
        ])
        ->assertRedirect();

    expect($ticket->events()->where('type', 'assigned')->count())->toBe(1);
    expect($ticket->events()->where('type', 'priority_changed')->count())->toBe(1);
    app(ItEmailDeliveryService::class)->dispatchPending();
    Notification::assertSentTo($colleague, TicketAssignedNotification::class);

    Notification::fake();

    // Assign-to-self never self-notifies.
    $this->actingAs($this->hr)
        ->patch("/it/tickets/{$ticket->id}", ['expected_version' => $ticket->fresh()->lock_version, 'assigned_to_user_id' => $this->hr->id,
            'routing_reason' => 'I am taking over the replacement.'])
        ->assertRedirect();
    Notification::assertNotSentTo($this->hr, TicketAssignedNotification::class);

    // waiting via PATCH pauses; leaving banks the minutes.
    $this->actingAs($this->hr)
        ->patch("/it/tickets/{$ticket->id}", [
            'expected_version' => $ticket->fresh()->lock_version,
            'status' => 'waiting',
            'waiting_party' => 'requester',
            'waiting_reason' => 'Waiting for the requester to confirm the result.',
            'next_action' => 'Resume work when the requester replies.',
        ])
        ->assertRedirect();
    expect($ticket->fresh()->waiting_since)->not->toBeNull();

    $this->travel(45)->minutes();
    $this->actingAs($this->hr)
        ->patch("/it/tickets/{$ticket->id}", ['expected_version' => $ticket->fresh()->lock_version, 'status' => 'in_progress'])
        ->assertRedirect();
    $ticket->refresh();
    expect($ticket->status)->toBe('in_progress');
    expect($ticket->waiting_since)->toBeNull();
    expect($ticket->sla_paused_minutes)->toBeGreaterThanOrEqual(44);
});

test('waiting ownership is explicit revisable and requester safe', function () {
    $ticket = ItTicket::factory()->create([
        'site_id' => $this->site->id,
        'requester_user_id' => $this->worker->id,
        'status' => 'in_progress',
        'workflow_state' => 'in_progress',
    ]);

    $this->actingAs($this->hr)
        ->patch("/it/tickets/{$ticket->id}", [
            'expected_version' => $ticket->fresh()->lock_version,
            'status' => 'waiting',
            'waiting_party' => 'vendor',
            'waiting_reason' => 'The supplier must confirm the replacement serial number.',
            'next_action' => 'Review the supplier response tomorrow morning.',
        ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Ticket updated.');

    $ticket->refresh();
    $waitingSince = $ticket->waiting_since?->copy();
    expect($ticket->status)->toBe('waiting')
        ->and($ticket->waiting_party)->toBe('vendor')
        ->and($ticket->waiting_reason)->toBe('The supplier must confirm the replacement serial number.')
        ->and($ticket->next_action)->toBe('Review the supplier response tomorrow morning.')
        ->and($waitingSince)->not->toBeNull();

    $this->actingAs($this->hr)
        ->getJson("/it/tickets/{$ticket->id}")
        ->assertOk()
        ->assertJsonPath('ticket.waiting.party', 'vendor')
        ->assertJsonPath('ticket.waiting.reason', 'The supplier must confirm the replacement serial number.')
        ->assertJsonPath('ticket.waiting.next_action', 'Review the supplier response tomorrow morning.')
        ->assertJsonPath('ticket.waiting.since', $ticket->waiting_since?->toIso8601String());

    $this->actingAs($this->worker)
        ->getJson("/it/tickets/{$ticket->id}")
        ->assertOk()
        ->assertJsonPath('ticket.waiting.party', 'other')
        ->assertJsonMissingPath('ticket.waiting.reason')
        ->assertJsonMissingPath('ticket.waiting.next_action');

    $this->travel(10)->minutes();
    $this->actingAs($this->hr)
        ->patch("/it/tickets/{$ticket->id}", [
            'expected_version' => $ticket->fresh()->lock_version,
            'status' => 'waiting',
            'waiting_party' => 'approver',
            'waiting_reason' => 'The change owner must approve the revised scope.',
            'next_action' => 'Escalate if no decision is recorded by noon.',
        ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Ticket updated.');

    $ticket->refresh();
    expect($ticket->waiting_party)->toBe('approver')
        ->and($ticket->waiting_reason)->toBe('The change owner must approve the revised scope.')
        ->and($ticket->next_action)->toBe('Escalate if no decision is recorded by noon.')
        ->and($ticket->waiting_since?->equalTo($waitingSince))->toBeTrue()
        ->and($ticket->events()->where('type', 'waiting_updated')->count())->toBe(1)
        ->and(AuditLog::query()
            ->where('action', 'it.work.waiting.updated')
            ->where('auditable_id', $ticket->id)
            ->where('meta->waiting_party', 'approver')
            ->where('meta->reason_recorded', true)
            ->exists())->toBeTrue();

    $this->actingAs($this->hr)
        ->patch("/it/tickets/{$ticket->id}", [
            'expected_version' => $ticket->fresh()->lock_version,
            'status' => 'waiting',
            'waiting_party' => '',
            'waiting_reason' => '',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors(['waiting_party', 'waiting_reason']);

    expect($ticket->fresh()->waiting_party)->toBe('approver')
        ->and($ticket->fresh()->waiting_reason)->toBe('The change owner must approve the revised scope.');
});

test('triage cannot bypass configured approval by changing the ticket category', function () {
    config(['it.approval.categories' => ['account']]);
    $ticket = ItTicket::factory()->create([
        'site_id' => $this->site->id,
        'requester_user_id' => $this->worker->id,
        'category' => 'hardware',
        'requires_approval' => false,
    ]);

    $this->actingAs($this->hr)
        ->patch("/it/tickets/{$ticket->id}", ['expected_version' => $ticket->fresh()->lock_version, 'category' => 'account'])
        ->assertRedirect();

    expect($ticket->refresh()->requires_approval)->toBeTrue()
        ->and(AuditLog::query()
            ->where('action', 'it.ticket.triage.updated')
            ->where('auditable_type', $ticket->getMorphClass())
            ->where('auditable_id', $ticket->id)
            ->count())->toBe(1);

    $this->actingAs($this->hr)
        ->post("/it/tickets/{$ticket->id}/resolve", [
            'expected_version' => $ticket->fresh()->lock_version,
            'note' => 'Account restored.',
            'resolution_code' => 'restored',
            'resolution_verification' => 'Checked the synthetic account access twice.',
        ])
        ->assertSessionHas('error', 'Required approval must be approved before settlement.');

    expect($ticket->refresh()->status)->not->toBe('resolved');
});
