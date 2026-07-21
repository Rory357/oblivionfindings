<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Asset;
use App\Models\ControlRoom\Device as ControlRoomDevice;
use App\Models\ControlRoomAlert;
use App\Models\ItKbArticle;
use App\Models\ItTicket;
use App\Models\ItTicketEvent;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Notifications\It\TicketAssignedNotification;
use App\Notifications\It\TicketRepliedNotification;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

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
    $this->site = Site::factory()->create();
    assignItWorkspaceUserToSite($this->hr, $this->site);
    assignItWorkspaceUserToSite($this->worker, $this->site);
});

test('linked context follows canonical device and alert permissions without leaking raw payloads', function () {
    $agent = itWorkspaceUser('admin');
    assignItWorkspaceUserToSite($agent, $this->site);
    $ticket = ItTicket::factory()->create([
        'site_id' => $this->site->id,
        'requester_user_id' => $this->worker->id,
    ]);
    $device = Device::factory()->itInfrastructure()->create([
        'tenant_id' => $ticket->tenant_id,
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
            'tenant_id' => $ticket->tenant_id,
            'relationship' => 'affected_device',
            'linkable_type' => $device->getMorphClass(),
            'linkable_id' => $device->id,
        ],
        [
            'tenant_id' => $ticket->tenant_id,
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

test('the workspace strips internal notes from requester payloads server-side', function () {
    $ticket = ItTicket::factory()->create([
        'site_id' => $this->site->id,
        'requester_user_id' => $this->worker->id,
    ]);
    $ticket->comments()->create([
        'tenant_id' => 1,
        'author_user_id' => $this->worker->id,
        'body' => 'Public: it is still broken.',
        'is_internal' => false,
    ]);
    $ticket->comments()->create([
        'tenant_id' => 1,
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
});

test('a requester reply resumes a waiting ticket and banks the paused minutes', function () {
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
    expect($ticket->sla_paused_minutes)->toBeGreaterThanOrEqual(29);
    expect(
        $ticket->events()->where('type', 'status_changed')->get()
            ->contains(fn (ItTicketEvent $e) => ($e->payload['via'] ?? null) === 'requester_reply'),
    )->toBeTrue();
});

test('public replies notify the other side of the conversation only', function () {
    Notification::fake();
    $watcher = itWorkspaceUser('hr');
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
        'tenant_id' => 1,
        'author_user_id' => $this->worker->id,
        'body' => 'Public message.',
        'is_internal' => false,
    ]);
    $ticket->comments()->create([
        'tenant_id' => 1,
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

test('the rail can retag category, subcategory and linked asset', function () {
    $ticket = ItTicket::factory()->create([
        'site_id' => $this->site->id,
        'requester_user_id' => $this->worker->id,
    ]);
    $asset = Asset::factory()->create([
        'site_id' => $this->site->id,
        'status' => 'active',
    ]);

    $this->actingAs($this->hr)
        ->patch("/it/tickets/{$ticket->id}", [
            'category' => 'network',
            'subcategory' => 'VPN',
            'asset_id' => $asset->id,
        ])
        ->assertRedirect();

    $ticket->refresh();
    expect($ticket->category)->toBe('network');
    expect($ticket->subcategory)->toBe('VPN');
    expect((int) $ticket->asset_id)->toBe($asset->id);

    // The workspace offers agents the asset picker; requesters never get it.
    $this->actingAs($this->hr)
        ->get("/it/tickets/{$ticket->id}")
        ->assertInertia(fn ($page) => $page->has('assetOptions'));
    $this->actingAs($this->worker)
        ->get("/it/tickets/{$ticket->id}")
        ->assertInertia(fn ($page) => $page->has('assetOptions', 0));
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
});

test('triage updates write the activity trail and notify the new assignee', function () {
    Notification::fake();
    $ticket = ItTicket::factory()->create([
        'site_id' => $this->site->id,
        'requester_user_id' => $this->worker->id,
    ]);
    $colleague = itWorkspaceUser('hr');

    $this->actingAs($this->hr)
        ->patch("/it/tickets/{$ticket->id}", [
            'assigned_to_user_id' => $colleague->id,
            'priority' => 'high',
        ])
        ->assertRedirect();

    expect($ticket->events()->where('type', 'assigned')->count())->toBe(1);
    expect($ticket->events()->where('type', 'priority_changed')->count())->toBe(1);
    Notification::assertSentTo($colleague, TicketAssignedNotification::class);

    Notification::fake();

    // Assign-to-self never self-notifies.
    $this->actingAs($this->hr)
        ->patch("/it/tickets/{$ticket->id}", ['assigned_to_user_id' => $this->hr->id])
        ->assertRedirect();
    Notification::assertNotSentTo($this->hr, TicketAssignedNotification::class);

    // waiting via PATCH pauses; leaving banks the minutes.
    $this->actingAs($this->hr)
        ->patch("/it/tickets/{$ticket->id}", ['status' => 'waiting'])
        ->assertRedirect();
    expect($ticket->fresh()->waiting_since)->not->toBeNull();

    $this->travel(45)->minutes();
    $this->actingAs($this->hr)
        ->patch("/it/tickets/{$ticket->id}", ['status' => 'in_progress'])
        ->assertRedirect();
    $ticket->refresh();
    expect($ticket->status)->toBe('in_progress');
    expect($ticket->waiting_since)->toBeNull();
    expect($ticket->sla_paused_minutes)->toBeGreaterThanOrEqual(44);
});
