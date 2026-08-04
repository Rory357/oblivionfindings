<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\BehaviourAbcEntry;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\TimelineEvent;
use App\Models\TimelineEventComment;
use App\Models\User;

function grantClientProfileDirectRoutePermissions(User $user, array $permissionKeys): void
{
    $role = Role::query()->firstOrCreate(
        ['name' => 'client_profile_direct_routes_'.$user->id],
        ['label' => 'Client Profile Direct Routes', 'level' => 50, 'type' => 'custom'],
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
}

function clientProfileDirectRouteUser(array $permissionKeys, Site $site, string $role = 'manager'): User
{
    $user = User::factory()->create([
        'role' => $role,
        'approved_at' => now(),
    ]);

    grantClientProfileDirectRoutePermissions($user, $permissionKeys);
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
    ]);

    return $user;
}

beforeEach(function () {
    $this->clientProfileDirectRouteSite = Site::factory()->create();
});

/** @return array{event: TimelineEvent, comment: TimelineEventComment} */
function clientProfileDirectRouteTimelineFixture(User $actor, Client $client): array
{
    $event = TimelineEvent::factory()->create([
        'client_id' => $client->id,
        'actor_user_id' => $actor->id,
        'created_by' => $actor->id,
    ]);
    $comment = TimelineEventComment::query()->create([
        'timeline_event_id' => $event->id,
        'user_id' => $actor->id,
        'body' => 'Existing timeline comment.',
    ]);

    return compact('event', 'comment');
}

/** @return array<string, mixed> */
function clientProfileDirectRouteAbcPayload(array $overrides = []): array
{
    return array_merge([
        'occurred_at' => '2026-07-10T09:00',
        'setting' => 'Dining room at breakfast',
        'others_present' => 'Support worker',
        'antecedent' => 'The room became noisy.',
        'behaviour' => 'The client left the table.',
        'consequence' => 'A quiet space was offered.',
        'behaviour_tags' => ['Withdrawal'],
        'behaviour_function' => 'escape_avoidance',
        'intensity' => 'medium',
        'duration_seconds' => 180,
        'strategies_used' => 'Low-demand approach.',
        'harm_occurred' => false,
        'escalated' => false,
        'requires_followup' => false,
    ], $overrides);
}

it('requires canonical timeline read access for the direct client timeline route', function () {
    $viewer = clientProfileDirectRouteUser(['clients.viewAny'], $this->clientProfileDirectRouteSite);
    $client = Client::factory()->create(['site_id' => $this->clientProfileDirectRouteSite->id]);
    TimelineEvent::factory()->create([
        'client_id' => $client->id,
        'actor_user_id' => $viewer->id,
        'created_by' => $viewer->id,
    ]);

    $this->actingAs($viewer)
        ->get("/clients/{$client->id}/timeline")
        ->assertForbidden();
});

it('allows a global timeline viewer to read the direct client timeline route', function () {
    $viewer = clientProfileDirectRouteUser([
        'clients.viewAny',
        'timeline.viewAny',
    ], $this->clientProfileDirectRouteSite);
    $client = Client::factory()->create(['site_id' => $this->clientProfileDirectRouteSite->id]);

    $this->actingAs($viewer)
        ->get("/clients/{$client->id}/timeline")
        ->assertOk();
});

it('allows an assigned care worker with timeline create access to read the client timeline', function () {
    $worker = clientProfileDirectRouteUser([
        'clients.viewAssigned',
        'timeline.create',
    ], $this->clientProfileDirectRouteSite, role: 'support_worker');
    $client = Client::factory()->create(['site_id' => $this->clientProfileDirectRouteSite->id]);
    $client->supportWorkers()->attach($worker);

    $this->actingAs($worker)
        ->get("/clients/{$client->id}/timeline")
        ->assertOk();
});

it('forbids direct timeline reads across Sites despite global timeline permission', function () {
    $viewer = clientProfileDirectRouteUser([
        'clients.viewAssigned',
        'timeline.viewAny',
    ], $this->clientProfileDirectRouteSite);
    $foreignSite = Site::factory()->create();
    $foreignClient = Client::factory()->create(['site_id' => $foreignSite->id]);

    $this->actingAs($viewer)
        ->get("/clients/{$foreignClient->id}/timeline")
        ->assertForbidden();
});

it('requires timeline create access for staff timeline interactions', function (string $interaction) {
    $viewer = clientProfileDirectRouteUser([
        'clients.viewAny',
        'timeline.viewAny',
    ], $this->clientProfileDirectRouteSite);
    $client = Client::factory()->create(['site_id' => $this->clientProfileDirectRouteSite->id]);
    ['event' => $event, 'comment' => $comment] = clientProfileDirectRouteTimelineFixture($viewer, $client);

    $this->actingAs($viewer);

    $response = match ($interaction) {
        'comment' => $this->post("/clients/{$client->id}/timeline/{$event->id}/comments", [
            'body' => 'Blocked timeline comment.',
        ]),
        'delete' => $this->delete("/clients/{$client->id}/timeline/comments/{$comment->id}"),
        'like' => $this->post("/clients/{$client->id}/timeline/comments/{$comment->id}/like"),
        'reaction' => $this->post("/clients/{$client->id}/timeline/{$event->id}/react", [
            'emoji' => '👍',
        ]),
    };

    $response->assertForbidden();
    $this->assertDatabaseHas('timeline_event_comments', ['id' => $comment->id]);
    $this->assertDatabaseMissing('timeline_event_comments', ['body' => 'Blocked timeline comment.']);
    $this->assertDatabaseMissing('timeline_comment_likes', [
        'comment_id' => $comment->id,
        'user_id' => $viewer->id,
    ]);
    $this->assertDatabaseMissing('timeline_event_reactions', [
        'timeline_event_id' => $event->id,
        'user_id' => $viewer->id,
    ]);
})->with([
    'comment creation' => ['comment'],
    'comment deletion' => ['delete'],
    'comment like' => ['like'],
    'event reaction' => ['reaction'],
]);

it('allows staff timeline interactions with read and create access', function (string $interaction) {
    $viewer = clientProfileDirectRouteUser([
        'clients.viewAny',
        'timeline.viewAny',
        'timeline.create',
    ], $this->clientProfileDirectRouteSite);
    $client = Client::factory()->create(['site_id' => $this->clientProfileDirectRouteSite->id]);
    ['event' => $event, 'comment' => $comment] = clientProfileDirectRouteTimelineFixture($viewer, $client);

    $this->actingAs($viewer);

    $response = match ($interaction) {
        'comment' => $this->post("/clients/{$client->id}/timeline/{$event->id}/comments", [
            'body' => 'Allowed timeline comment.',
        ]),
        'delete' => $this->delete("/clients/{$client->id}/timeline/comments/{$comment->id}"),
        'like' => $this->post("/clients/{$client->id}/timeline/comments/{$comment->id}/like"),
        'reaction' => $this->post("/clients/{$client->id}/timeline/{$event->id}/react", [
            'emoji' => '👍',
        ]),
    };

    $response->assertRedirect();

    match ($interaction) {
        'comment' => $this->assertDatabaseHas('timeline_event_comments', [
            'timeline_event_id' => $event->id,
            'user_id' => $viewer->id,
            'body' => 'Allowed timeline comment.',
        ]),
        'delete' => $this->assertDatabaseMissing('timeline_event_comments', ['id' => $comment->id]),
        'like' => $this->assertDatabaseHas('timeline_comment_likes', [
            'comment_id' => $comment->id,
            'user_id' => $viewer->id,
        ]),
        'reaction' => $this->assertDatabaseHas('timeline_event_reactions', [
            'timeline_event_id' => $event->id,
            'user_id' => $viewer->id,
            'emoji' => '👍',
        ]),
    };
})->with([
    'comment creation' => ['comment'],
    'comment deletion' => ['delete'],
    'comment like' => ['like'],
    'event reaction' => ['reaction'],
]);

it('rejects staff timeline interactions through another client route', function (string $interaction) {
    $viewer = clientProfileDirectRouteUser([
        'clients.viewAny',
        'timeline.viewAny',
        'timeline.create',
    ], $this->clientProfileDirectRouteSite);
    $client = Client::factory()->create(['site_id' => $this->clientProfileDirectRouteSite->id]);
    $otherClient = Client::factory()->create(['site_id' => $this->clientProfileDirectRouteSite->id]);
    ['event' => $event, 'comment' => $comment] = clientProfileDirectRouteTimelineFixture($viewer, $otherClient);

    $this->actingAs($viewer);

    $response = match ($interaction) {
        'comment' => $this->post("/clients/{$client->id}/timeline/{$event->id}/comments", [
            'body' => 'Cross-client timeline comment.',
        ]),
        'delete' => $this->delete("/clients/{$client->id}/timeline/comments/{$comment->id}"),
        'like' => $this->post("/clients/{$client->id}/timeline/comments/{$comment->id}/like"),
        'reaction' => $this->post("/clients/{$client->id}/timeline/{$event->id}/react", [
            'emoji' => '👍',
        ]),
    };

    $response->assertNotFound();
    $this->assertDatabaseHas('timeline_event_comments', ['id' => $comment->id]);
    $this->assertDatabaseMissing('timeline_event_comments', ['body' => 'Cross-client timeline comment.']);
    $this->assertDatabaseMissing('timeline_comment_likes', [
        'comment_id' => $comment->id,
        'user_id' => $viewer->id,
    ]);
    $this->assertDatabaseMissing('timeline_event_reactions', [
        'timeline_event_id' => $event->id,
        'user_id' => $viewer->id,
    ]);
})->with([
    'comment creation' => ['comment'],
    'comment deletion' => ['delete'],
    'comment like' => ['like'],
    'event reaction' => ['reaction'],
]);

it('forbids timeline interaction writes across Sites', function () {
    $viewer = clientProfileDirectRouteUser([
        'clients.viewAssigned',
        'timeline.viewAny',
        'timeline.create',
    ], $this->clientProfileDirectRouteSite);
    $foreignSite = Site::factory()->create();
    $foreignClient = Client::factory()->create(['site_id' => $foreignSite->id]);
    ['event' => $event] = clientProfileDirectRouteTimelineFixture($viewer, $foreignClient);

    $this->actingAs($viewer)
        ->post("/clients/{$foreignClient->id}/timeline/{$event->id}/react", ['emoji' => '👍'])
        ->assertForbidden();

    $this->assertDatabaseMissing('timeline_event_reactions', [
        'timeline_event_id' => $event->id,
        'user_id' => $viewer->id,
    ]);
});

it('requires canonical behaviour read access for direct ABC index and detail routes', function () {
    $viewer = clientProfileDirectRouteUser([
        'clients.viewAny',
        'clinical.events.viewAssigned',
    ], $this->clientProfileDirectRouteSite);
    $client = Client::factory()->create(['site_id' => $this->clientProfileDirectRouteSite->id]);
    $entry = BehaviourAbcEntry::factory()->create(['client_id' => $client->id]);

    $this->actingAs($viewer)
        ->getJson("/clients/{$client->id}/behaviour/abc")
        ->assertForbidden();
    $this->actingAs($viewer)
        ->getJson("/clients/{$client->id}/behaviour/abc/{$entry->id}")
        ->assertForbidden();
});

it('allows a global behaviour viewer to read direct ABC index and detail routes', function () {
    $viewer = clientProfileDirectRouteUser([
        'clients.viewAny',
        'clinical.behaviour.viewAny',
    ], $this->clientProfileDirectRouteSite);
    $client = Client::factory()->create(['site_id' => $this->clientProfileDirectRouteSite->id]);
    $entry = BehaviourAbcEntry::factory()->create(['client_id' => $client->id]);

    $this->actingAs($viewer)
        ->getJson("/clients/{$client->id}/behaviour/abc")
        ->assertOk()
        ->assertJsonPath('data.0.id', $entry->id);
    $this->actingAs($viewer)
        ->getJson("/clients/{$client->id}/behaviour/abc/{$entry->id}")
        ->assertOk()
        ->assertJsonPath('id', $entry->id);
});

it('allows an assigned clinical events viewer to read direct ABC routes for their client', function () {
    $worker = clientProfileDirectRouteUser([
        'clients.viewAssigned',
        'clinical.events.viewAssigned',
    ], $this->clientProfileDirectRouteSite, role: 'support_worker');
    $client = Client::factory()->create(['site_id' => $this->clientProfileDirectRouteSite->id]);
    $client->supportWorkers()->attach($worker);
    $entry = BehaviourAbcEntry::factory()->create(['client_id' => $client->id]);

    $this->actingAs($worker)
        ->getJson("/clients/{$client->id}/behaviour/abc")
        ->assertOk();
    $this->actingAs($worker)
        ->getJson("/clients/{$client->id}/behaviour/abc/{$entry->id}")
        ->assertOk();
});

it('rejects an ABC detail record through another client route', function () {
    $viewer = clientProfileDirectRouteUser([
        'clients.viewAny',
        'clinical.behaviour.viewAny',
    ], $this->clientProfileDirectRouteSite);
    $client = Client::factory()->create(['site_id' => $this->clientProfileDirectRouteSite->id]);
    $otherClient = Client::factory()->create(['site_id' => $this->clientProfileDirectRouteSite->id]);
    $entry = BehaviourAbcEntry::factory()->create(['client_id' => $otherClient->id]);

    $this->actingAs($viewer)
        ->getJson("/clients/{$client->id}/behaviour/abc/{$entry->id}")
        ->assertNotFound();
});

it('forbids direct ABC reads across Sites despite global behaviour permission', function () {
    $viewer = clientProfileDirectRouteUser([
        'clients.viewAssigned',
        'clinical.behaviour.viewAny',
    ], $this->clientProfileDirectRouteSite);
    $foreignSite = Site::factory()->create();
    $foreignClient = Client::factory()->create(['site_id' => $foreignSite->id]);

    $this->actingAs($viewer)
        ->getJson("/clients/{$foreignClient->id}/behaviour/abc")
        ->assertForbidden();
});

it('does not treat clinical event recording as ABC correction authority', function (string $mutation) {
    $viewer = clientProfileDirectRouteUser([
        'clients.viewAny',
        'clinical.behaviour.viewAny',
        'clinical.events.record',
    ], $this->clientProfileDirectRouteSite);
    $client = Client::factory()->create(['site_id' => $this->clientProfileDirectRouteSite->id]);
    $entry = BehaviourAbcEntry::factory()->create([
        'client_id' => $client->id,
        'behaviour' => 'Original behaviour description.',
    ]);

    $this->actingAs($viewer);

    $response = match ($mutation) {
        'update' => $this->putJson(
            "/clients/{$client->id}/behaviour/abc/{$entry->id}",
            clientProfileDirectRouteAbcPayload(['behaviour' => 'Blocked correction.']),
        ),
        'delete' => $this->deleteJson("/clients/{$client->id}/behaviour/abc/{$entry->id}"),
    };

    $response->assertForbidden();
    $this->assertDatabaseHas('behaviour_abc_entries', [
        'id' => $entry->id,
        'behaviour' => 'Original behaviour description.',
        'deleted_at' => null,
    ]);
})->with([
    'update' => ['update'],
    'delete' => ['delete'],
]);

it('allows ABC update and deletion with the existing correction capability', function (string $mutation) {
    $viewer = clientProfileDirectRouteUser([
        'clients.viewAny',
        'clinical.behaviour.viewAny',
        'clinical.observations.correct',
    ], $this->clientProfileDirectRouteSite);
    $client = Client::factory()->create(['site_id' => $this->clientProfileDirectRouteSite->id]);
    $entry = BehaviourAbcEntry::factory()->create([
        'client_id' => $client->id,
        'behaviour' => 'Original behaviour description.',
    ]);

    $this->actingAs($viewer);

    $response = match ($mutation) {
        'update' => $this->putJson(
            "/clients/{$client->id}/behaviour/abc/{$entry->id}",
            clientProfileDirectRouteAbcPayload(['behaviour' => 'Corrected behaviour description.']),
        ),
        'delete' => $this->deleteJson("/clients/{$client->id}/behaviour/abc/{$entry->id}"),
    };

    $response->assertOk();

    match ($mutation) {
        'update' => $this->assertDatabaseHas('behaviour_abc_entries', [
            'id' => $entry->id,
            'behaviour' => 'Corrected behaviour description.',
        ]),
        'delete' => $this->assertSoftDeleted('behaviour_abc_entries', ['id' => $entry->id]),
    };
})->with([
    'update' => ['update'],
    'delete' => ['delete'],
]);

it('rejects an ABC correction through another client route', function () {
    $viewer = clientProfileDirectRouteUser([
        'clients.viewAny',
        'clinical.behaviour.viewAny',
        'clinical.observations.correct',
    ], $this->clientProfileDirectRouteSite);
    $client = Client::factory()->create(['site_id' => $this->clientProfileDirectRouteSite->id]);
    $otherClient = Client::factory()->create(['site_id' => $this->clientProfileDirectRouteSite->id]);
    $entry = BehaviourAbcEntry::factory()->create(['client_id' => $otherClient->id]);

    $this->actingAs($viewer)
        ->putJson(
            "/clients/{$client->id}/behaviour/abc/{$entry->id}",
            clientProfileDirectRouteAbcPayload(['behaviour' => 'Cross-client correction.']),
        )
        ->assertNotFound();
});

it('forbids ABC deletion across Sites despite correction capability', function () {
    $viewer = clientProfileDirectRouteUser([
        'clients.viewAssigned',
        'clinical.behaviour.viewAny',
        'clinical.observations.correct',
    ], $this->clientProfileDirectRouteSite);
    $foreignSite = Site::factory()->create();
    $foreignClient = Client::factory()->create(['site_id' => $foreignSite->id]);
    $entry = BehaviourAbcEntry::factory()->create(['client_id' => $foreignClient->id]);

    $this->actingAs($viewer)
        ->deleteJson("/clients/{$foreignClient->id}/behaviour/abc/{$entry->id}")
        ->assertForbidden();

    $this->assertDatabaseHas('behaviour_abc_entries', [
        'id' => $entry->id,
        'deleted_at' => null,
    ]);
});
