<?php

use App\Models\Client;
use App\Models\ClientDocument;
use App\Models\ClientIncident;
use App\Models\ClientIncidentAttachment;
use App\Models\ConsentType;
use App\Models\FamilyPortalSetting;
use App\Models\FamilyVisitRequest;
use App\Models\NextOfKin;
use App\Models\Permission;
use App\Models\Role;
use App\Models\TimelineEvent;
use App\Models\TimelineEventComment;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Support\AuthoritativeConsentFixture;

function makePortalDirectRouteUser(Client $client, array $permissions): User
{
    $user = User::factory()->create([
        'organization_id' => $client->organization_id,
        'role' => 'next_of_kin',
        'approved_at' => now(),
    ]);
    $role = Role::query()->firstOrCreate(
        ['name' => 'next_of_kin'],
        [
            'label' => 'Next of kin',
            'level' => 15,
            'type' => 'system',
        ],
    );
    foreach ($permissions as $permissionKey) {
        Permission::query()->firstOrCreate(
            ['key' => $permissionKey],
            [
                'description' => $permissionKey,
                'group' => 'test',
                'module' => 'Test',
            ],
        );
    }
    $role->permissions()->sync(
        Permission::query()->whereIn('key', $permissions)->pluck('id')->all(),
    );
    $user->roles()->sync([$role->id]);
    $client->portalUsers()->attach($user->id, ['relation' => 'next_of_kin']);
    NextOfKin::query()->create([
        'client_id' => $client->id,
        'user_id' => $user->id,
        'relationship' => 'guardian',
        'can_view_incidents' => true,
    ]);

    return $user;
}

function grantPortalDirectRouteFamilyDisclosure(Client $client, User $actor): void
{
    $type = ConsentType::factory()->create([
        'name' => 'Information Sharing with Whānau / Family',
        'category' => 'communication',
    ]);
    AuthoritativeConsentFixture::manualSelf($client, $type, $actor, [
        'status' => 'given',
        'given_at' => now()->subMinute(),
        'expires_at' => now()->addMonth(),
    ]);
    FamilyPortalSetting::query()->create([
        'organization_id' => $client->organization_id,
        'client_id' => $client->id,
        'show_shift_schedule' => true,
        'show_respite' => true,
        'show_care_notes' => true,
        'show_care_plans' => true,
        'show_medication_status' => true,
        'show_incidents' => true,
    ]);
}

it('denies direct portal incident attachment downloads without effective family disclosure', function () {
    Storage::fake('private');

    $client = Client::factory()->create(['organization_id' => 1]);
    $user = makePortalDirectRouteUser($client, [
        'clients.viewPortal',
        'incidents.view.portal',
        'incidents.attachments.view.portal',
    ]);
    $incident = ClientIncident::factory()->reviewed()->create([
        'client_id' => $client->id,
        'portal_visible' => true,
    ]);
    Storage::disk('private')->put('incidents/evidence.txt', 'private evidence');
    $attachment = ClientIncidentAttachment::query()->create([
        'incident_id' => $incident->id,
        'uploaded_by' => $user->id,
        'disk' => 'private',
        'original_name' => 'evidence.txt',
        'path' => 'incidents/evidence.txt',
        'mime_type' => 'text/plain',
        'size' => 16,
        'portal_visible' => true,
    ]);

    $this->actingAs($user)
        ->get(route('portal.clients.incidents.attachments.download', [
            'client' => $client,
            'incident' => $incident,
            'attachment' => $attachment,
        ], false))
        ->assertForbidden();
});

it('allows direct portal incident attachment downloads only with consent settings and NOK flags', function () {
    Storage::fake('private');

    $client = Client::factory()->create(['organization_id' => 1]);
    $user = makePortalDirectRouteUser($client, [
        'clients.viewPortal',
        'incidents.view.portal',
        'incidents.attachments.view.portal',
    ]);
    grantPortalDirectRouteFamilyDisclosure($client, $user);
    $incident = ClientIncident::factory()->reviewed()->create([
        'client_id' => $client->id,
        'portal_visible' => true,
    ]);
    Storage::disk('private')->put('incidents/evidence.txt', 'private evidence');
    $attachment = ClientIncidentAttachment::query()->create([
        'incident_id' => $incident->id,
        'uploaded_by' => $user->id,
        'disk' => 'private',
        'original_name' => 'evidence.txt',
        'path' => 'incidents/evidence.txt',
        'mime_type' => 'text/plain',
        'size' => 16,
        'portal_visible' => true,
    ]);

    $this->actingAs($user)
        ->get(route('portal.clients.incidents.attachments.download', [
            'client' => $client,
            'incident' => $incident,
            'attachment' => $attachment,
        ], false))
        ->assertOk()
        ->assertDownload('evidence.txt');
});

it('limits portal document reads without family disclosure to the users own uploads', function () {
    Storage::fake('local');

    $client = Client::factory()->create(['organization_id' => 1]);
    $user = makePortalDirectRouteUser($client, ['clients.viewPortal']);
    $staff = User::factory()->create(['organization_id' => 1]);

    Storage::disk('local')->put('client-documents/shared.txt', 'shared');
    $shared = ClientDocument::query()->create([
        'client_id' => $client->id,
        'uploaded_by_user_id' => $staff->id,
        'title' => 'Shared care document',
        'category' => 'care',
        'storage_disk' => 'local',
        'storage_path' => 'client-documents/shared.txt',
        'original_name' => 'shared.txt',
        'mime_type' => 'text/plain',
        'size_bytes' => 6,
        'portal_visible' => true,
    ]);

    Storage::disk('local')->put('client-documents/own.txt', 'own');
    $own = ClientDocument::query()->create([
        'client_id' => $client->id,
        'uploaded_by_user_id' => $user->id,
        'title' => 'Own upload',
        'category' => 'family_upload',
        'storage_disk' => 'local',
        'storage_path' => 'client-documents/own.txt',
        'original_name' => 'own.txt',
        'mime_type' => 'text/plain',
        'size_bytes' => 3,
        'portal_visible' => true,
    ]);

    $this->actingAs($user)
        ->get(route('portal.clients.documents.download', [
            'client' => $client,
            'document' => $shared,
        ], false))
        ->assertForbidden();

    $this->get(route('portal.clients.documents.download', [
        'client' => $client,
        'document' => $own,
    ], false))
        ->assertOk()
        ->assertDownload('own.txt');

    $this->get(route('portal.clients.documents', $client, false))
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('documents', 1)
            ->where('documents.0.id', $own->id));
});

it('denies portal timeline interactions when the underlying event is hidden by disclosure rules', function (string $action) {
    $client = Client::factory()->create(['organization_id' => 1]);
    $user = makePortalDirectRouteUser($client, ['clients.viewPortal']);
    $event = TimelineEvent::factory()->create([
        'client_id' => $client->id,
        'type' => 'progress_note',
        'visibility' => 'portal',
    ]);
    $comment = TimelineEventComment::query()->create([
        'timeline_event_id' => $event->id,
        'user_id' => $user->id,
        'body' => 'Existing comment',
    ]);

    $this->actingAs($user);

    $response = match ($action) {
        'comment' => $this->post(route('portal.clients.timeline.comments.store', [
            'client' => $client,
            'timelineEvent' => $event,
        ], false), ['body' => 'Hidden comment']),
        'reaction' => $this->post(route('portal.clients.timeline.react', [
            'client' => $client,
            'timelineEvent' => $event,
        ], false), ['emoji' => '👍']),
        'like' => $this->post(route('portal.clients.timeline.comments.like', [
            'client' => $client,
            'timelineEventComment' => $comment,
        ], false)),
        'delete' => $this->delete(route('portal.clients.timeline.comments.destroy', [
            'client' => $client,
            'timelineEventComment' => $comment,
        ], false)),
    };

    $response->assertForbidden();

    expect(TimelineEventComment::query()->where('body', 'Hidden comment')->exists())->toBeFalse()
        ->and($event->reactions()->exists())->toBeFalse()
        ->and($comment->likes()->exists())->toBeFalse()
        ->and($comment->fresh())->not->toBeNull();
})->with(['comment', 'reaction', 'like', 'delete']);

it('rejects a portal timeline reply parent from another event', function () {
    $client = Client::factory()->create(['organization_id' => 1]);
    $user = makePortalDirectRouteUser($client, ['clients.viewPortal']);
    grantPortalDirectRouteFamilyDisclosure($client, $user);
    $event = TimelineEvent::factory()->create([
        'client_id' => $client->id,
        'type' => 'progress_note',
        'visibility' => 'portal',
    ]);
    $otherEvent = TimelineEvent::factory()->create([
        'client_id' => $client->id,
        'type' => 'progress_note',
        'visibility' => 'portal',
    ]);
    $foreignParent = TimelineEventComment::query()->create([
        'timeline_event_id' => $otherEvent->id,
        'user_id' => $user->id,
        'body' => 'Other event parent',
    ]);

    $this->actingAs($user)
        ->postJson(route('portal.clients.timeline.comments.store', [
            'client' => $client,
            'timelineEvent' => $event,
        ], false), [
            'body' => 'Invalid cross-event reply',
            'parent_id' => $foreignParent->id,
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('parent_id');

    $this->assertDatabaseMissing('timeline_event_comments', [
        'timeline_event_id' => $event->id,
        'body' => 'Invalid cross-event reply',
    ]);
});

it('preserves portal timeline interactions for events visible under effective disclosure', function () {
    $client = Client::factory()->create(['organization_id' => 1]);
    $user = makePortalDirectRouteUser($client, ['clients.viewPortal']);
    grantPortalDirectRouteFamilyDisclosure($client, $user);
    $event = TimelineEvent::factory()->create([
        'client_id' => $client->id,
        'type' => 'progress_note',
        'visibility' => 'portal',
    ]);
    $comment = TimelineEventComment::query()->create([
        'timeline_event_id' => $event->id,
        'user_id' => $user->id,
        'body' => 'Visible comment',
    ]);

    $this->actingAs($user)
        ->post(route('portal.clients.timeline.react', [
            'client' => $client,
            'timelineEvent' => $event,
        ], false), ['emoji' => '👍'])
        ->assertRedirect();

    $this->post(route('portal.clients.timeline.comments.like', [
        'client' => $client,
        'timelineEventComment' => $comment,
    ], false))->assertRedirect();

    $this->post(route('portal.clients.timeline.comments.store', [
        'client' => $client,
        'timelineEvent' => $event,
    ], false), [
        'body' => 'Valid reply',
        'parent_id' => $comment->id,
    ])->assertRedirect();

    $this->assertDatabaseHas('timeline_event_reactions', [
        'timeline_event_id' => $event->id,
        'user_id' => $user->id,
        'emoji' => '👍',
    ]);
    $this->assertDatabaseHas('timeline_comment_likes', [
        'comment_id' => $comment->id,
        'user_id' => $user->id,
    ]);
    $this->assertDatabaseHas('timeline_event_comments', [
        'timeline_event_id' => $event->id,
        'parent_id' => $comment->id,
        'body' => 'Valid reply',
    ]);
});

it('binds portal visit cancellation to both the linked client and the visit owner', function () {
    $owningClient = Client::factory()->create(['organization_id' => 1]);
    $wrongClient = Client::factory()->create(['organization_id' => 1]);
    $user = makePortalDirectRouteUser($owningClient, ['clients.viewPortal']);
    $wrongClient->portalUsers()->attach($user->id, ['relation' => 'next_of_kin']);
    $visit = FamilyVisitRequest::query()->create([
        'user_id' => $user->id,
        'client_id' => $owningClient->id,
        'requested_date' => now()->addDay()->toDateString(),
        'visit_type' => 'in_person',
        'status' => 'pending',
    ]);

    $this->actingAs($user)
        ->post(route('portal.clients.visit-requests.cancel', [
            'client' => $wrongClient,
            'visit' => $visit,
        ], false))
        ->assertNotFound();

    expect($visit->fresh()->status)->toBe('pending');

    $this->post(route('portal.clients.visit-requests.cancel', [
        'client' => $owningClient,
        'visit' => $visit,
    ], false))->assertRedirect();

    expect($visit->fresh()->status)->toBe('cancelled');
});
