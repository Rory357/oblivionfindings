<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\FamilyNote;
use App\Models\OpsConversation;
use App\Models\OpsMessage;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\ShiftTask;
use App\Models\Site;
use App\Models\TimelineEvent;
use App\Models\User;
use Carbon\Carbon;

function grantClientFamilyCommunicationPermissions(
    User $user,
    array $permissionKeys,
    ?string $roleName = null,
): void {
    $role = Role::query()->firstOrCreate(
        ['name' => $roleName ?? 'client_family_communication_'.$user->id],
        ['label' => 'Client Family Communication', 'level' => 50, 'type' => 'custom'],
    );

    foreach ($permissionKeys as $key) {
        Permission::query()->firstOrCreate(
            ['key' => $key],
            ['description' => $key, 'group' => 'test', 'module' => 'Test'],
        );
    }

    $role->permissions()->sync(
        Permission::query()->whereIn('key', $permissionKeys)->pluck('id')->all(),
    );
    $user->roles()->syncWithoutDetaching([$role->id]);

    if (
        collect($permissionKeys)->intersect([
            'family_portal.viewAny',
            'family_portal.manage',
        ])->isNotEmpty()
        && ! HrEmployeeProfile::query()->where('user_id', $user->id)->exists()
    ) {
        assignClientFamilyWorkerToSite($user, Site::factory()->create());
    }
}

function makeClientFamilyCommunicationNote(
    Client $client,
    User $creator,
    array $overrides = [],
): FamilyNote {
    return FamilyNote::query()->create([
        'client_id' => $client->id,
        'created_by' => $creator->id,
        'title' => 'Please confirm the weekend plan',
        'description' => 'Whānau need to know who will call on Saturday.',
        'note_type' => 'request',
        'priority' => 'normal',
        'status' => 'open',
        'visibility' => 'portal',
        ...$overrides,
    ]);
}

function makeClientFamilyCommunicationClient(?Site $site = null): Client
{
    return Client::factory()->create([
        'site_id' => ($site ?? Site::factory()->create())->id,
    ]);
}

function assignClientFamilyWorkerToSite(User $worker, Site $site): void
{
    HrEmployeeProfile::factory()->create([
        'user_id' => $worker->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'is_active' => true,
        'start_date' => now()->subMonth()->toDateString(),
        'end_date' => null,
    ]);
}

function makeClientFamilyConversation(
    Client $client,
    User $sender,
): OpsConversation {
    $conversation = OpsConversation::query()->create([
        'title' => 'Whānau chat',
        'conversation_type' => 'family',
        'client_id' => $client->id,
        'is_archived' => false,
    ]);

    OpsMessage::query()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $sender->id,
        'sender_type' => 'user',
        'message_type' => 'text',
        'content' => 'Existing private family message.',
        'client_id' => $client->id,
    ]);

    return $conversation;
}

it('denies family chat and family-note mutations to a client viewer without family capabilities', function () {
    $viewer = User::factory()->create();
    grantClientFamilyCommunicationPermissions($viewer, ['clients.viewAny']);
    $client = makeClientFamilyCommunicationClient();
    $note = makeClientFamilyCommunicationNote($client, $viewer);
    $conversation = makeClientFamilyConversation($client, $viewer);

    $this->actingAs($viewer)
        ->getJson("/operations/clients/{$client->id}/family-chat")
        ->assertForbidden();
    $this->actingAs($viewer)
        ->postJson("/operations/clients/{$client->id}/family-chat", [
            'content' => 'This user must not be able to contact whānau.',
        ])
        ->assertForbidden();
    $this->actingAs($viewer)
        ->post("/clients/{$client->id}/family-notes/{$note->id}/respond", [
            'staff_response' => 'Unauthorized response.',
        ])
        ->assertForbidden();
    $this->actingAs($viewer)
        ->post("/clients/{$client->id}/family-notes/{$note->id}/status", [
            'status' => 'completed',
        ])
        ->assertForbidden();

    expect($conversation->messages()->count())->toBe(1)
        ->and($note->fresh()->staff_response)->toBeNull()
        ->and($note->fresh()->status)->toBe('open');
});

it('does not let a family-portal viewer read a private conversation without joining it', function () {
    $viewer = User::factory()->create();
    grantClientFamilyCommunicationPermissions($viewer, [
        'clients.viewAny',
        'family_portal.viewAny',
    ]);
    $client = makeClientFamilyCommunicationClient();
    $note = makeClientFamilyCommunicationNote($client, $viewer);
    $conversation = makeClientFamilyConversation($client, $viewer);

    $this->actingAs($viewer)
        ->getJson("/operations/clients/{$client->id}/family-chat")
        ->assertOk()
        ->assertJsonPath('conversation', null)
        ->assertJsonCount(0, 'messages');

    $this->assertDatabaseMissing('ops_conversation_participants', [
        'conversation_id' => $conversation->id,
        'user_id' => $viewer->id,
    ]);
    $this->actingAs($viewer)
        ->postJson("/operations/clients/{$client->id}/family-chat", [
            'content' => 'Read-only users cannot send.',
        ])
        ->assertForbidden();
    $this->actingAs($viewer)
        ->post("/clients/{$client->id}/family-notes/{$note->id}/respond", [
            'staff_response' => 'Read-only users cannot respond.',
        ])
        ->assertForbidden();
});

it('resolves only the current staff participants private family conversation', function () {
    $site = Site::factory()->create();
    $client = makeClientFamilyCommunicationClient($site);
    $firstManager = User::factory()->create();
    $secondManager = User::factory()->create();
    foreach ([$firstManager, $secondManager] as $manager) {
        grantClientFamilyCommunicationPermissions($manager, [
            'clients.viewAny',
            'family_portal.manage',
        ]);
    }

    $firstConversation = makeClientFamilyConversation($client, $firstManager);
    $firstConversation->participants()->create([
        'user_id' => $firstManager->id,
        'role' => 'staff',
    ]);
    $secondConversation = makeClientFamilyConversation($client, $secondManager);
    $secondConversation->participants()->create([
        'user_id' => $secondManager->id,
        'role' => 'staff',
    ]);
    $secondConversation->messages()->latest()->firstOrFail()->update([
        'content' => 'Second worker private family message.',
    ]);

    $this->actingAs($firstManager)
        ->getJson("/operations/clients/{$client->id}/family-chat")
        ->assertOk()
        ->assertJsonPath('conversation.id', $firstConversation->id)
        ->assertJsonPath('messages.0.content', 'Existing private family message.')
        ->assertJsonMissing(['content' => 'Second worker private family message.']);
});

it('redacts unlinked portal and stale worker identities from staff participant payloads', function () {
    $site = Site::factory()->create();
    $client = makeClientFamilyCommunicationClient($site);
    $manager = User::factory()->create(['approved_at' => now()]);
    grantClientFamilyCommunicationPermissions($manager, [
        'clients.viewAny',
        'family_portal.manage',
    ]);
    $conversation = makeClientFamilyConversation($client, $manager);
    $conversation->participants()->create([
        'user_id' => $manager->id,
        'role' => 'staff',
    ]);

    $portalRole = Role::query()->firstOrCreate(
        ['name' => 'next_of_kin'],
        ['label' => 'Next of Kin', 'level' => 1, 'type' => 'system'],
    );
    $currentPortal = User::factory()->create([
        'role' => 'next_of_kin',
        'approved_at' => now(),
    ]);
    $currentPortal->roles()->syncWithoutDetaching([$portalRole->id]);
    $client->portalUsers()->attach($currentPortal->id, ['relation' => 'guardian']);
    $conversation->participants()->create([
        'user_id' => $currentPortal->id,
        'role' => 'family',
    ]);

    $unlinkedPortal = User::factory()->create([
        'role' => 'next_of_kin',
        'approved_at' => now(),
    ]);
    $unlinkedPortal->roles()->syncWithoutDetaching([$portalRole->id]);
    $conversation->participants()->create([
        'user_id' => $unlinkedPortal->id,
        'role' => 'family',
    ]);

    $staleWorker = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    grantClientFamilyCommunicationPermissions($staleWorker, [
        'progress_notes.viewAny',
        'progress_notes.create',
    ], 'support_worker');
    assignClientFamilyWorkerToSite($staleWorker, $site);
    $client->supportWorkers()->attach($staleWorker->id);
    $conversation->participants()->create([
        'user_id' => $staleWorker->id,
        'role' => 'staff',
    ]);
    $client->supportWorkers()->detach($staleWorker->id);

    $staleGlobalManager = User::factory()->create(['approved_at' => now()]);
    grantClientFamilyCommunicationPermissions($staleGlobalManager, [
        'family_portal.manage',
    ]);
    $conversation->participants()->create([
        'user_id' => $staleGlobalManager->id,
        'role' => 'staff',
    ]);
    HrEmployeeProfile::query()
        ->where('user_id', $staleGlobalManager->id)
        ->update([
            'is_active' => false,
            'end_date' => now()->subDay()->toDateString(),
        ]);

    $response = $this->actingAs($manager)
        ->getJson("/operations/clients/{$client->id}/family-chat")
        ->assertOk()
        ->assertJsonCount(2, 'conversation.participants');

    $participantIds = collect($response->json('conversation.participants'))->pluck('id');
    expect($participantIds)->toContain($manager->id, $currentPortal->id)
        ->not->toContain($unlinkedPortal->id, $staleWorker->id, $staleGlobalManager->id);
});

it('reports when the family chat response omits older messages', function () {
    $viewer = User::factory()->create();
    grantClientFamilyCommunicationPermissions($viewer, [
        'clients.viewAny',
        'family_portal.viewAny',
    ]);
    $client = makeClientFamilyCommunicationClient();
    $conversation = makeClientFamilyConversation($client, $viewer);
    $conversation->participants()->create([
        'user_id' => $viewer->id,
        'role' => 'staff',
    ]);

    foreach (range(2, 101) as $index) {
        OpsMessage::query()->create([
            'conversation_id' => $conversation->id,
            'sender_id' => $viewer->id,
            'sender_type' => 'user',
            'message_type' => 'text',
            'content' => "Family message {$index}",
            'client_id' => $client->id,
        ]);
    }

    $this->actingAs($viewer)
        ->getJson("/operations/clients/{$client->id}/family-chat")
        ->assertOk()
        ->assertJsonCount(100, 'messages')
        ->assertJsonPath('meta.total', 101)
        ->assertJsonPath('meta.loaded', 100)
        ->assertJsonPath('meta.has_more', true);
});

it('preserves the family-portal manager chat and family-note workflow', function () {
    $manager = User::factory()->create();
    grantClientFamilyCommunicationPermissions($manager, [
        'clients.viewAny',
        'family_portal.manage',
    ]);
    $client = makeClientFamilyCommunicationClient();
    $note = makeClientFamilyCommunicationNote($client, $manager);
    $shift = Shift::factory()->create([
        'client_id' => $client->id,
        'site_id' => $client->site_id,
        'user_id' => $manager->id,
        'created_by' => $manager->id,
        'status' => 'scheduled',
    ]);

    $this->actingAs($manager)
        ->getJson("/operations/clients/{$client->id}/family-chat")
        ->assertOk()
        ->assertJsonPath('conversation', null);
    $this->actingAs($manager)
        ->postJson("/operations/clients/{$client->id}/family-chat", [
            'content' => 'We will call whānau after the Saturday shift.',
        ])
        ->assertOk();
    $this->actingAs($manager)
        ->post("/clients/{$client->id}/family-notes/{$note->id}/respond", [
            'staff_response' => 'The Saturday worker will call at 4pm.',
        ])
        ->assertRedirect();
    $this->actingAs($manager)
        ->post("/clients/{$client->id}/family-notes/{$note->id}/status", [
            'status' => 'in_progress',
        ])
        ->assertRedirect();
    $this->actingAs($manager)
        ->post("/clients/{$client->id}/family-notes/{$note->id}/assign-shift", [
            'shift_id' => $shift->id,
        ])
        ->assertRedirect();
    $this->actingAs($manager)
        ->post("/clients/{$client->id}/family-notes/{$note->id}/status", [
            'status' => 'completed',
        ])
        ->assertRedirect();

    $note->refresh();
    expect($note->staff_response)->toBe('The Saturday worker will call at 4pm.')
        ->and($note->staff_responded_by)->toBe($manager->id)
        ->and($note->assigned_to_shift_id)->toBe($shift->id)
        ->and($note->status)->toBe('completed')
        ->and($note->completed_by)->toBe($manager->id)
        ->and($note->completed_at)->not->toBeNull();
    $this->assertDatabaseHas('ops_messages', [
        'sender_id' => $manager->id,
        'client_id' => $client->id,
        'content' => 'We will call whānau after the Saturday shift.',
    ]);
    $this->assertDatabaseHas('shift_tasks', [
        'shift_id' => $shift->id,
        'label' => 'Family note: '.$note->title,
    ]);
    expect(TimelineEvent::query()
        ->where('source_type', FamilyNote::class)
        ->where('source_id', $note->id)
        ->where('type', 'family_note_completed')
        ->count())->toBe(1);
});

it('preserves the assigned support-worker workflow with progress-note capabilities', function () {
    $worker = User::factory()->create([
        'role' => 'support_worker',
    ]);
    grantClientFamilyCommunicationPermissions($worker, [
        'clients.viewAssigned',
        'progress_notes.viewAny',
        'progress_notes.create',
    ], 'support_worker');
    $client = makeClientFamilyCommunicationClient();
    assignClientFamilyWorkerToSite($worker, $client->site);
    $client->supportWorkers()->attach($worker->id);
    $note = makeClientFamilyCommunicationNote($client, $worker);

    $this->actingAs($worker)
        ->getJson("/operations/clients/{$client->id}/family-chat")
        ->assertOk();
    $this->actingAs($worker)
        ->postJson("/operations/clients/{$client->id}/family-chat", [
            'content' => 'Assigned worker update for whānau.',
        ])
        ->assertOk();
    $this->actingAs($worker)
        ->post("/clients/{$client->id}/family-notes/{$note->id}/respond", [
            'staff_response' => 'I will follow this up on shift.',
        ])
        ->assertRedirect();
    $this->actingAs($worker)
        ->post("/clients/{$client->id}/family-notes/{$note->id}/status", [
            'status' => 'in_progress',
        ])
        ->assertRedirect();

    expect($note->fresh()->staff_responded_by)->toBe($worker->id)
        ->and($note->fresh()->status)->toBe('in_progress');
});

it('keeps an assigned support worker with read-only progress-note access read-only', function () {
    $worker = User::factory()->create([
        'role' => 'support_worker',
    ]);
    grantClientFamilyCommunicationPermissions($worker, [
        'clients.viewAssigned',
        'progress_notes.viewAny',
    ], 'support_worker');
    $client = makeClientFamilyCommunicationClient();
    assignClientFamilyWorkerToSite($worker, $client->site);
    $client->supportWorkers()->attach($worker->id);
    $note = makeClientFamilyCommunicationNote($client, $worker);
    $conversation = makeClientFamilyConversation($client, $worker);
    $conversation->participants()->create([
        'user_id' => $worker->id,
        'role' => 'staff',
    ]);

    $this->actingAs($worker)
        ->getJson("/operations/clients/{$client->id}/family-chat")
        ->assertOk()
        ->assertJsonPath('conversation.id', $conversation->id);
    $this->actingAs($worker)
        ->postJson("/operations/clients/{$client->id}/family-chat", [
            'content' => 'Read-only assigned workers cannot send.',
        ])
        ->assertForbidden();
    $this->actingAs($worker)
        ->post("/clients/{$client->id}/family-notes/{$note->id}/respond", [
            'staff_response' => 'Read-only assigned workers cannot respond.',
        ])
        ->assertForbidden();
    $this->actingAs($worker)
        ->post("/clients/{$client->id}/family-notes/{$note->id}/status", [
            'status' => 'completed',
        ])
        ->assertForbidden();

    expect($conversation->messages()->count())->toBe(1)
        ->and($note->fresh()->staff_response)->toBeNull()
        ->and($note->fresh()->status)->toBe('open');
});

it('does not treat progress-note permissions as family access without an actual assignment', function () {
    $unassigned = User::factory()->create();
    grantClientFamilyCommunicationPermissions($unassigned, [
        'clients.viewAny',
        'progress_notes.viewAny',
        'progress_notes.create',
    ]);
    $client = makeClientFamilyCommunicationClient();
    $note = makeClientFamilyCommunicationNote($client, $unassigned);

    $this->actingAs($unassigned)
        ->getJson("/operations/clients/{$client->id}/family-chat")
        ->assertForbidden();
    $this->actingAs($unassigned)
        ->postJson("/operations/clients/{$client->id}/family-chat", [
            'content' => 'Not assigned.',
        ])
        ->assertForbidden();
    $this->actingAs($unassigned)
        ->post("/clients/{$client->id}/family-notes/{$note->id}/status", [
            'status' => 'completed',
        ])
        ->assertForbidden();
});

it('does not treat an ineligible staff pivot as a care-worker assignment', function () {
    $financeUser = User::factory()->create([
        'role' => 'finance_manager',
    ]);
    grantClientFamilyCommunicationPermissions($financeUser, [
        'clients.viewAny',
        'progress_notes.viewAny',
        'progress_notes.create',
    ], 'finance_manager');
    $client = makeClientFamilyCommunicationClient();
    $client->supportWorkers()->attach($financeUser->id);
    $note = makeClientFamilyCommunicationNote($client, $financeUser);

    $this->actingAs($financeUser)
        ->getJson("/operations/clients/{$client->id}/family-chat")
        ->assertForbidden();
    $this->actingAs($financeUser)
        ->postJson("/operations/clients/{$client->id}/family-chat", [
            'content' => 'A malformed pivot must not grant family access.',
        ])
        ->assertForbidden();
    $this->actingAs($financeUser)
        ->post("/clients/{$client->id}/family-notes/{$note->id}/status", [
            'status' => 'completed',
        ])
        ->assertForbidden();

    expect($note->fresh()->status)->toBe('open');
});

it('rejects an assignment outside the workers current Site as family communication authority', function () {
    $worker = User::factory()->create([
        'role' => 'support_worker',
    ]);
    grantClientFamilyCommunicationPermissions($worker, [
        'clients.viewAssigned',
        'progress_notes.viewAny',
        'progress_notes.create',
    ], 'support_worker');
    $workerSite = Site::factory()->create();
    $foreignClient = makeClientFamilyCommunicationClient();
    assignClientFamilyWorkerToSite($worker, $workerSite);
    $foreignClient->supportWorkers()->attach($worker->id);
    $note = makeClientFamilyCommunicationNote($foreignClient, $worker);

    $this->actingAs($worker)
        ->getJson("/operations/clients/{$foreignClient->id}/family-chat")
        ->assertForbidden();
    $this->actingAs($worker)
        ->post("/clients/{$foreignClient->id}/family-notes/{$note->id}/status", [
            'status' => 'completed',
        ])
        ->assertForbidden();
});

it('enforces nested Client binding for family-note actions', function () {
    $manager = User::factory()->create();
    grantClientFamilyCommunicationPermissions($manager, [
        'clients.viewAny',
        'family_portal.manage',
    ]);
    $site = Site::factory()->create();
    $client = makeClientFamilyCommunicationClient($site);
    $otherClient = makeClientFamilyCommunicationClient($site);
    $note = makeClientFamilyCommunicationNote($client, $manager);
    $otherNote = makeClientFamilyCommunicationNote($otherClient, $manager);
    $otherClientsShift = Shift::factory()->create([
        'client_id' => $otherClient->id,
        'site_id' => $site->id,
        'user_id' => $manager->id,
        'created_by' => $manager->id,
        'status' => 'scheduled',
    ]);

    $this->actingAs($manager)
        ->post("/clients/{$client->id}/family-notes/{$otherNote->id}/respond", [
            'staff_response' => 'Wrong client.',
        ])
        ->assertNotFound();
    $this->actingAs($manager)
        ->post("/clients/{$client->id}/family-notes/{$note->id}/assign-shift", [
            'shift_id' => $otherClientsShift->id,
        ])
        ->assertNotFound();
    expect($note->fresh()->assigned_to_shift_id)->toBeNull();
    expect(ShiftTask::query()
        ->where('shift_id', $otherClientsShift->id)
        ->exists())->toBeFalse();
});

it('treats repeated assignment to the same shift as an idempotent success', function () {
    $manager = User::factory()->create();
    grantClientFamilyCommunicationPermissions($manager, [
        'clients.viewAny',
        'family_portal.manage',
    ]);
    $client = makeClientFamilyCommunicationClient();
    $note = makeClientFamilyCommunicationNote($client, $manager);
    $shift = Shift::factory()->create([
        'client_id' => $client->id,
        'site_id' => $client->site_id,
        'user_id' => $manager->id,
        'created_by' => $manager->id,
        'status' => 'scheduled',
    ]);

    $this->travelTo(Carbon::parse('2026-07-10 10:00:00'));
    $this->actingAs($manager)
        ->post("/clients/{$client->id}/family-notes/{$note->id}/assign-shift", [
            'shift_id' => $shift->id,
        ])
        ->assertRedirect();

    $firstUpdatedAt = $note->fresh()->updated_at->copy();

    $this->travelTo(Carbon::parse('2026-07-10 10:05:00'));
    $this->actingAs($manager)
        ->post("/clients/{$client->id}/family-notes/{$note->id}/assign-shift", [
            'shift_id' => $shift->id,
        ])
        ->assertRedirect();
    $note->refresh();
    $this->travelBack();

    expect($note->updated_at->equalTo($firstUpdatedAt))->toBeTrue()
        ->and(ShiftTask::query()
            ->where('shift_id', $shift->id)
            ->where('label', 'Family note: '.$note->title)
            ->count())->toBe(1)
        ->and(TimelineEvent::query()
            ->where('source_type', FamilyNote::class)
            ->where('source_id', $note->id)
            ->where('type', 'family_note_assigned_to_shift')
            ->count())->toBe(1);
});

it('allows open and in-progress family notes to be cancelled as a terminal transition', function () {
    $manager = User::factory()->create();
    grantClientFamilyCommunicationPermissions($manager, [
        'clients.viewAny',
        'family_portal.manage',
    ]);
    $client = makeClientFamilyCommunicationClient();
    $openNote = makeClientFamilyCommunicationNote($client, $manager);
    $inProgressNote = makeClientFamilyCommunicationNote($client, $manager, [
        'status' => 'in_progress',
    ]);

    foreach ([$openNote, $inProgressNote] as $note) {
        $this->actingAs($manager)
            ->post("/clients/{$client->id}/family-notes/{$note->id}/status", [
                'status' => 'cancelled',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();
    }

    $this->actingAs($manager)
        ->post("/clients/{$client->id}/family-notes/{$openNote->id}/status", [
            'status' => 'completed',
        ])
        ->assertSessionHasErrors('status');
    $this->actingAs($manager)
        ->post("/clients/{$client->id}/family-notes/{$inProgressNote->id}/respond", [
            'staff_response' => 'Cancelled notes stay closed.',
        ])
        ->assertSessionHasErrors('family_note');

    expect($openNote->fresh()->status)->toBe('cancelled')
        ->and($inProgressNote->fresh()->status)->toBe('cancelled')
        ->and($inProgressNote->fresh()->staff_response)->toBeNull();
});

it('rejects reopening and actions on completed family notes', function () {
    $manager = User::factory()->create();
    grantClientFamilyCommunicationPermissions($manager, [
        'clients.viewAny',
        'family_portal.manage',
    ]);
    $client = makeClientFamilyCommunicationClient();
    $completedNote = makeClientFamilyCommunicationNote($client, $manager, [
        'status' => 'completed',
        'completed_at' => now()->subHour(),
        'completed_by' => $manager->id,
    ]);
    $shift = Shift::factory()->create([
        'client_id' => $client->id,
        'site_id' => $client->site_id,
        'user_id' => $manager->id,
        'created_by' => $manager->id,
        'status' => 'scheduled',
    ]);

    $this->actingAs($manager)
        ->post("/clients/{$client->id}/family-notes/{$completedNote->id}/status", [
            'status' => 'in_progress',
        ])
        ->assertSessionHasErrors('status');
    $this->actingAs($manager)
        ->post("/clients/{$client->id}/family-notes/{$completedNote->id}/respond", [
            'staff_response' => 'A terminal note must stay closed.',
        ])
        ->assertSessionHasErrors('family_note');
    $this->actingAs($manager)
        ->post("/clients/{$client->id}/family-notes/{$completedNote->id}/assign-shift", [
            'shift_id' => $shift->id,
        ])
        ->assertSessionHasErrors('family_note');

    expect($completedNote->fresh()->status)->toBe('completed')
        ->and($completedNote->fresh()->staff_response)->toBeNull()
        ->and($completedNote->fresh()->assigned_to_shift_id)->toBeNull();
});

it('does not surface a family conversation bound to another Client', function () {
    $manager = User::factory()->create();
    grantClientFamilyCommunicationPermissions($manager, [
        'clients.viewAny',
        'family_portal.manage',
    ]);
    $site = Site::factory()->create();
    $client = makeClientFamilyCommunicationClient($site);
    $otherClient = makeClientFamilyCommunicationClient($site);
    $otherConversation = makeClientFamilyConversation($otherClient, $manager);

    $this->actingAs($manager)
        ->getJson("/operations/clients/{$client->id}/family-chat")
        ->assertOk()
        ->assertJsonPath('conversation', null)
        ->assertJsonCount(0, 'messages');
    $this->actingAs($manager)
        ->postJson("/operations/clients/{$client->id}/family-chat", [
            'content' => 'Create the correctly scoped conversation.',
        ])
        ->assertOk();

    expect(OpsConversation::query()
        ->where('client_id', $client->id)
        ->where('conversation_type', 'family')
        ->exists())->toBeTrue();
    $this->assertDatabaseHas('ops_messages', [
        'client_id' => $client->id,
        'content' => 'Create the correctly scoped conversation.',
    ]);
    expect($otherConversation->messages()->count())->toBe(1);
});

it('does not surface messages whose Client does not match the family conversation', function () {
    $manager = User::factory()->create();
    grantClientFamilyCommunicationPermissions($manager, [
        'clients.viewAny',
        'family_portal.manage',
    ]);
    $site = Site::factory()->create();
    $client = makeClientFamilyCommunicationClient($site);
    $otherClient = makeClientFamilyCommunicationClient($site);
    $conversation = makeClientFamilyConversation($client, $manager);
    $conversation->participants()->create([
        'user_id' => $manager->id,
        'role' => 'staff',
    ]);

    OpsMessage::query()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $manager->id,
        'sender_type' => 'user',
        'message_type' => 'text',
        'content' => 'Wrong client message.',
        'client_id' => $otherClient->id,
    ]);

    $this->actingAs($manager)
        ->getJson("/operations/clients/{$client->id}/family-chat")
        ->assertOk()
        ->assertJsonCount(1, 'messages')
        ->assertJsonPath('messages.0.content', 'Existing private family message.')
        ->assertJsonMissing(['content' => 'Wrong client message.']);
});
