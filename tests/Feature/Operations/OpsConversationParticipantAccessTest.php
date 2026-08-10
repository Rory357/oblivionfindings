<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\OpsConversation;
use App\Models\OpsConversationParticipant;
use App\Models\OpsMessage;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\Operations\OpsMessageVisibilityService;
use Inertia\Testing\AssertableInertia as Assert;

function makeDirectStaffConversation(User $creator, User $recipient): array
{
    $conversation = OpsConversation::query()->create([
        'conversation_type' => 'direct',
        'title' => 'Private staff handover',
    ]);

    foreach ([$creator, $recipient] as $participant) {
        OpsConversationParticipant::query()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $participant->id,
            'role' => $participant->is($creator) ? 'admin' : 'member',
        ]);
    }

    $message = OpsMessage::query()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $creator->id,
        'sender_type' => 'user',
        'message_type' => 'text',
        'content' => 'Participant-only operational context.',
    ]);

    return [$conversation, $message];
}

function grantOpsFamilyConversationAccess(User $user, Site $site): void
{
    $role = Role::query()->firstOrCreate(
        ['name' => 'ops_family_conversation_'.$user->id],
        ['label' => 'Ops family conversation', 'level' => 50, 'type' => 'custom'],
    );
    $permission = Permission::query()->firstOrCreate(
        ['key' => 'family_portal.manage'],
        [
            'description' => 'Manage family portal',
            'group' => 'operations',
            'module' => 'Operations',
        ],
    );
    $role->permissions()->syncWithoutDetaching([$permission->id]);
    $user->roles()->syncWithoutDetaching([$role->id]);

    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'is_active' => true,
        'start_date' => now()->subMonth()->toDateString(),
        'end_date' => null,
    ]);
}

function makeCurrentOpsConversationStaff(Site $site): User
{
    $user = User::factory()->create([
        'approved_at' => now(),
        'role' => 'staff',
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'is_active' => true,
        'start_date' => now()->subMonth()->toDateString(),
        'end_date' => null,
    ]);

    return $user;
}

it('allows only conversation participants to read and mutate direct staff messages', function () {
    $site = Site::factory()->create();
    $creator = makeCurrentOpsConversationStaff($site);
    $recipient = makeCurrentOpsConversationStaff($site);
    $outsider = makeCurrentOpsConversationStaff($site);
    [$conversation, $message] = makeDirectStaffConversation($creator, $recipient);

    $this->actingAs($recipient)
        ->get(route('operations.messages.show', $conversation))
        ->assertOk();

    $this->actingAs($outsider)
        ->get(route('operations.messages.show', $conversation))
        ->assertForbidden();

    $this->actingAs($outsider)
        ->post(route('operations.messages.react', $message), ['emoji' => '✅'])
        ->assertForbidden();

    $this->actingAs($outsider)
        ->post(route('operations.messages.pin', $message))
        ->assertForbidden();

    expect($message->fresh()->is_pinned)->toBeFalse()
        ->and($message->reactions()->count())->toBe(0);
});

it('does not let a staff chat request enrol a portal identity', function () {
    $staff = makeCurrentOpsConversationStaff(Site::factory()->create());
    $portalUser = User::factory()->create(['approved_at' => now(), 'role' => 'next_of_kin']);

    $this->actingAs($portalUser)
        ->get(route('operations.messages.index'))
        ->assertForbidden();

    $this->actingAs($staff)
        ->postJson(route('operations.messages.create'), [
            'participant_ids' => [$portalUser->id],
            'title' => 'Invalid direct chat',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('participant_ids');

    expect(OpsConversation::query()->count())->toBe(0);
});

it('does not let participant membership bypass Client family communication access', function () {
    $site = Site::factory()->create();
    $client = Client::factory()->create(['site_id' => $site->id]);
    $staff = makeCurrentOpsConversationStaff($site);
    $conversation = OpsConversation::query()->create([
        'conversation_type' => 'family',
        'client_id' => $client->id,
        'title' => 'Client family thread',
    ]);
    OpsConversationParticipant::query()->create([
        'conversation_id' => $conversation->id,
        'user_id' => $staff->id,
        'role' => 'staff',
    ]);

    $this->actingAs($staff)
        ->get(route('operations.messages.show', $conversation))
        ->assertForbidden();
});

it('enforces exact Client provenance for every staff family-message path', function () {
    $site = Site::factory()->create();
    $client = Client::factory()->create(['site_id' => $site->id]);
    $otherClient = Client::factory()->create(['site_id' => $site->id]);
    $manager = User::factory()->create(['approved_at' => now(), 'role' => 'staff']);
    $otherSender = User::factory()->create(['approved_at' => now(), 'role' => 'staff']);
    grantOpsFamilyConversationAccess($manager, $site);

    $conversation = OpsConversation::query()->create([
        'conversation_type' => 'family',
        'client_id' => $client->id,
        'title' => 'Canonical family thread',
    ]);
    OpsConversationParticipant::query()->create([
        'conversation_id' => $conversation->id,
        'user_id' => $manager->id,
        'role' => 'staff',
    ]);

    $visible = OpsMessage::query()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $otherSender->id,
        'sender_type' => 'user',
        'message_type' => 'text',
        'content' => 'Visible canonical provenance needle',
        'client_id' => $client->id,
    ]);
    $wrongClient = OpsMessage::query()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $manager->id,
        'sender_type' => 'user',
        'message_type' => 'text',
        'content' => 'Hidden wrong Client provenance needle',
        'client_id' => $otherClient->id,
        'is_pinned' => true,
    ]);
    $missingClient = OpsMessage::query()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $otherSender->id,
        'sender_type' => 'user',
        'message_type' => 'text',
        'content' => 'Hidden null Client provenance needle',
        'client_id' => null,
    ]);

    $this->actingAs($manager)
        ->get(route('operations.messages.show', $conversation))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('messages.data', 1)
            ->where('messages.data.0.id', $visible->id)
            ->has('pinnedMessages', 0));

    expect($visible->fresh()->is_read)->toBeFalse()
        ->and($wrongClient->fresh()->is_read)->toBeFalse()
        ->and($missingClient->fresh()->is_read)->toBeFalse()
        ->and($conversation->participants()
            ->where('user_id', $manager->id)
            ->value('last_read_at'))->not->toBeNull();

    $this->getJson(route('operations.messages.search', ['q' => 'provenance needle']))
        ->assertOk()
        ->assertJsonCount(1)
        ->assertJsonPath('0.id', $visible->id);

    $this->post(route('operations.messages.store', $conversation), [
        'content' => 'Canonical staff reply',
    ])->assertRedirect();
    $this->assertDatabaseHas('ops_messages', [
        'conversation_id' => $conversation->id,
        'client_id' => $client->id,
        'content' => 'Canonical staff reply',
    ]);

    foreach ([$wrongClient, $missingClient] as $invalidMessage) {
        $this->post(route('operations.messages.react', $invalidMessage), ['emoji' => 'x'])
            ->assertForbidden();
        $this->post(route('operations.messages.pin', $invalidMessage))
            ->assertForbidden();
    }
    $this->delete(route('operations.messages.archive', $wrongClient))
        ->assertForbidden();
});

it('rejects client-bound direct conversations and messages on every staff path', function () {
    $site = Site::factory()->create();
    $creator = makeCurrentOpsConversationStaff($site);
    $recipient = makeCurrentOpsConversationStaff($site);
    $client = Client::factory()->create(['site_id' => $site->id]);
    [$boundConversation, $boundConversationMessage] = makeDirectStaffConversation($creator, $recipient);
    $boundConversation->update(['client_id' => $client->id]);

    expect(app(OpsMessageVisibilityService::class)->unreadCount($recipient))->toBe(0);

    $this->actingAs($creator)
        ->get(route('operations.messages.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('conversations', 0));
    $this->get(route('operations.messages.show', $boundConversation))->assertForbidden();
    $this->post(route('operations.messages.store', $boundConversation), ['content' => 'Blocked'])
        ->assertForbidden();
    $this->patch(route('operations.messages.read', $boundConversation))->assertForbidden();
    $this->post(route('operations.messages.pin', $boundConversationMessage))->assertForbidden();
    $this->getJson(route('operations.messages.search', ['q' => 'operational context']))
        ->assertOk()
        ->assertJsonCount(0);

    [$validConversation, $clientBoundMessage] = makeDirectStaffConversation($creator, $recipient);
    $clientBoundMessage->update(['client_id' => $client->id]);

    $this->get(route('operations.messages.show', $validConversation))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('messages.data', 0));
    $this->post(route('operations.messages.pin', $clientBoundMessage))->assertForbidden();
});

it('fails closed when a stored staff conversation participant becomes a portal identity', function () {
    $site = Site::factory()->create();
    $creator = makeCurrentOpsConversationStaff($site);
    $recipient = makeCurrentOpsConversationStaff($site);
    [$conversation, $message] = makeDirectStaffConversation($creator, $recipient);
    $portalRole = Role::query()->firstOrCreate(
        ['name' => 'next_of_kin'],
        ['label' => 'Next of Kin', 'level' => 1, 'type' => 'system'],
    );
    $recipient->roles()->syncWithoutDetaching([$portalRole->id]);

    $this->actingAs($creator)
        ->get(route('operations.messages.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->has('conversations', 0));
    $this->get(route('operations.messages.show', $conversation))->assertForbidden();
    $this->post(route('operations.messages.store', $conversation), ['content' => 'Blocked'])
        ->assertForbidden();
    $this->patch(route('operations.messages.read', $conversation))->assertForbidden();
    $this->post(route('operations.messages.pin', $message))->assertForbidden();
    $this->getJson(route('operations.messages.search', ['q' => 'operational context']))
        ->assertOk()
        ->assertJsonCount(0);
});

it('redacts stale family participants from the general staff messaging workspace', function () {
    $site = Site::factory()->create();
    $client = Client::factory()->create(['site_id' => $site->id]);
    $manager = User::factory()->create(['approved_at' => now(), 'role' => 'staff']);
    grantOpsFamilyConversationAccess($manager, $site);

    $unlinkedPortal = User::factory()->create([
        'approved_at' => now(),
        'role' => 'next_of_kin',
    ]);
    $portalRole = Role::query()->firstOrCreate(
        ['name' => 'next_of_kin'],
        ['label' => 'Next of Kin', 'level' => 1, 'type' => 'system'],
    );
    $unlinkedPortal->roles()->syncWithoutDetaching([$portalRole->id]);

    $conversation = OpsConversation::query()->create([
        'conversation_type' => 'family',
        'client_id' => $client->id,
        'title' => 'Current identities only',
    ]);
    foreach ([$manager, $unlinkedPortal] as $participant) {
        $conversation->participants()->create([
            'user_id' => $participant->id,
            'role' => $participant->is($manager) ? 'staff' : 'family',
        ]);
    }

    $this->actingAs($manager)
        ->get(route('operations.messages.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('conversations', 1)
            ->has('conversations.0.participants', 1)
            ->where('conversations.0.participants.0.user.id', $manager->id));

    $this->get(route('operations.messages.show', $conversation))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('conversation.participants', 1)
            ->where('conversation.participants.0.user.id', $manager->id)
            ->has('conversations.0.participants', 1)
            ->where('conversations.0.participants.0.user.id', $manager->id));
});

it('removes offboarded staff from direct chat access directories and unread counts', function () {
    $site = Site::factory()->create();
    $creator = makeCurrentOpsConversationStaff($site);
    $recipient = makeCurrentOpsConversationStaff($site);
    [$conversation] = makeDirectStaffConversation($creator, $recipient);

    expect(app(OpsMessageVisibilityService::class)->unreadCount($recipient))->toBe(1);

    HrEmployeeProfile::query()
        ->where('user_id', $recipient->id)
        ->update([
            'is_active' => false,
            'end_date' => now()->subDay()->toDateString(),
        ]);

    expect(app(OpsMessageVisibilityService::class)->unreadCount($recipient))->toBe(0);

    $this->actingAs($recipient)
        ->get(route('operations.messages.index'))
        ->assertForbidden();

    $this->actingAs($creator)
        ->get(route('operations.messages.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('conversations', 0)
            ->where('auth.unreadMessageCount', 0));

    $this->get(route('operations.messages.show', $conversation))
        ->assertForbidden();
});

it('tracks unread state independently for every conversation participant', function () {
    $site = Site::factory()->create();
    $sender = makeCurrentOpsConversationStaff($site);
    $firstRecipient = makeCurrentOpsConversationStaff($site);
    $secondRecipient = makeCurrentOpsConversationStaff($site);
    $conversation = OpsConversation::query()->create([
        'conversation_type' => 'group',
        'title' => 'Independent read state',
    ]);
    foreach ([$sender, $firstRecipient, $secondRecipient] as $participant) {
        $conversation->participants()->create([
            'user_id' => $participant->id,
            'role' => $participant->is($sender) ? 'admin' : 'member',
        ]);
    }
    OpsMessage::query()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $sender->id,
        'sender_type' => 'user',
        'message_type' => 'text',
        'content' => 'Unread by each recipient independently.',
    ]);

    $visibility = app(OpsMessageVisibilityService::class);
    expect($visibility->unreadCount($firstRecipient))->toBe(1)
        ->and($visibility->unreadCount($secondRecipient))->toBe(1);

    $this->actingAs($firstRecipient)
        ->get(route('operations.messages.show', $conversation))
        ->assertOk();

    expect($visibility->unreadCount($firstRecipient))->toBe(0)
        ->and($visibility->unreadCount($secondRecipient))->toBe(1);

    $this->actingAs($secondRecipient)
        ->patch(route('operations.messages.read', $conversation))
        ->assertRedirect();

    expect($visibility->unreadCount($secondRecipient))->toBe(0);
});

it('uses deterministic latest messages and does not duplicate a direct staff pair', function () {
    $site = Site::factory()->create();
    $creator = makeCurrentOpsConversationStaff($site);
    $recipient = makeCurrentOpsConversationStaff($site);

    foreach (range(1, 2) as $attempt) {
        $this->actingAs($creator)
            ->post(route('operations.messages.create'), [
                'participant_ids' => [$recipient->id],
                'title' => 'One canonical direct thread',
            ])
            ->assertRedirect();
    }

    $conversation = OpsConversation::query()->sole();
    expect($conversation->participants()->count())->toBe(2);

    $timestamp = now()->startOfSecond();
    $first = OpsMessage::query()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $creator->id,
        'sender_type' => 'user',
        'message_type' => 'text',
        'content' => 'First tied message',
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);
    $second = OpsMessage::query()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $recipient->id,
        'sender_type' => 'user',
        'message_type' => 'text',
        'content' => 'Second tied message',
        'created_at' => $timestamp,
        'updated_at' => $timestamp,
    ]);

    expect($second->id)->toBeGreaterThan($first->id);
    $this->actingAs($creator)
        ->get(route('operations.messages.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('conversations.0.latest_message.id', $second->id));
});
