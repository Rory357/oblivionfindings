<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientPhoto;
use App\Models\ConsentType;
use App\Models\NextOfKin;
use App\Models\OpsConversation;
use App\Models\OpsConversationParticipant;
use App\Models\OpsMessage;
use App\Models\OpsMessageReaction;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Services\Operations\OpsMessageVisibilityService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\AuthoritativeConsentFixture;

function makePortalMessageMediaUser(Client $client): User
{
    $user = User::factory()->create([
        'role' => 'next_of_kin',
        'approved_at' => now(),
    ]);
    $portalRole = Role::query()->firstOrCreate(
        ['name' => 'next_of_kin'],
        ['label' => 'Next of Kin', 'level' => 1, 'type' => 'system'],
    );
    $user->roles()->syncWithoutDetaching([$portalRole->id]);

    $client->portalUsers()->attach($user->id, ['relation' => 'next_of_kin']);

    return $user;
}

function makePortalMessageMediaClient(?Site $site = null): Client
{
    return Client::factory()->create([
        'site_id' => ($site ?? Site::factory()->create())->id,
    ]);
}

function assignPortalMessageMediaWorkerToSite(User $worker, Site $site): void
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

function grantPortalMessageMediaFamilyDisclosure(Client $client, User $user): void
{
    NextOfKin::query()->firstOrCreate(
        ['client_id' => $client->id, 'user_id' => $user->id],
        ['relationship' => 'guardian'],
    );
    $type = ConsentType::factory()->create([
        'name' => 'Information Sharing with Whānau / Family',
        'category' => 'communication',
    ]);
    AuthoritativeConsentFixture::manualSelf($client, $type, $user, [
        'status' => 'given',
        'given_at' => now()->subMinute(),
        'expires_at' => now()->addMonth(),
    ]);
}

function grantPortalMessageMediaStaffRole(User $user, array $permissions): void
{
    $role = Role::query()->firstOrCreate(
        ['name' => 'manager'],
        ['label' => 'Manager', 'level' => 80, 'type' => 'system'],
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
}

function grantPortalMessageMediaWorkerPermissions(User $user): void
{
    $role = Role::query()->firstOrCreate(
        ['name' => 'portal_message_worker_'.$user->id],
        ['label' => 'Portal message worker', 'level' => 20, 'type' => 'custom'],
    );
    $permissions = collect(['progress_notes.viewAny', 'progress_notes.create'])
        ->map(fn (string $key) => Permission::query()->firstOrCreate(
            ['key' => $key],
            [
                'description' => $key,
                'group' => 'operations',
                'module' => 'Operations',
            ],
        ));
    $role->permissions()->sync($permissions->pluck('id')->all());
    $user->roles()->syncWithoutDetaching([$role->id]);
}

function makePortalMessageMediaConversation(
    Client $client,
    User $portalUser,
    ?User $worker = null,
): array {
    $conversation = OpsConversation::query()->create([
        'title' => 'Family chat',
        'conversation_type' => 'family',
        'client_id' => $client->id,
    ]);
    OpsConversationParticipant::query()->create([
        'conversation_id' => $conversation->id,
        'user_id' => $portalUser->id,
        'role' => 'family',
    ]);

    if ($worker) {
        OpsConversationParticipant::query()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $worker->id,
            'role' => 'staff',
        ]);
    }

    $message = OpsMessage::query()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $portalUser->id,
        'sender_type' => 'family',
        'message_type' => 'text',
        'content' => 'Hello',
        'client_id' => $client->id,
    ]);

    return [$conversation, $message];
}

function onePixelPortalMessageMediaPng(): string
{
    return (string) base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
        true,
    );
}

it('binds every direct portal message mutation through its conversation client and participant', function (string $action) {
    $site = Site::factory()->create();
    $owningClient = makePortalMessageMediaClient($site);
    $wrongClient = makePortalMessageMediaClient($site);
    $user = makePortalMessageMediaUser($owningClient);
    $wrongClient->portalUsers()->attach($user->id, ['relation' => 'next_of_kin']);
    [, $message] = makePortalMessageMediaConversation($owningClient, $user);

    $response = match ($action) {
        'react' => $this->actingAs($user)->post(
            "/portal/clients/{$wrongClient->id}/messages/react/{$message->id}",
            ['emoji' => '👍'],
        ),
        'pin' => $this->actingAs($user)->post(
            "/portal/clients/{$wrongClient->id}/messages/pin/{$message->id}",
        ),
        'archive' => $this->actingAs($user)->delete(
            "/portal/clients/{$wrongClient->id}/messages/archive/{$message->id}",
        ),
    };

    $response->assertForbidden();
    expect(OpsMessageReaction::query()->where('message_id', $message->id)->exists())->toBeFalse()
        ->and($message->fresh())->not->toBeNull()
        ->and((bool) $message->fresh()?->is_pinned)->toBeFalse();
})->with(['react', 'pin', 'archive']);

it('denies direct message mutation to a linked portal user who is not a conversation participant', function () {
    $client = makePortalMessageMediaClient();
    $participant = makePortalMessageMediaUser($client);
    $outsider = makePortalMessageMediaUser($client);
    [, $message] = makePortalMessageMediaConversation($client, $participant);

    $this->actingAs($outsider)
        ->post("/portal/clients/{$client->id}/messages/pin/{$message->id}")
        ->assertForbidden();

    expect((bool) $message->fresh()?->is_pinned)->toBeFalse();
});

it('rejects a message whose redundant client binding disagrees with its family conversation', function () {
    $site = Site::factory()->create();
    $client = makePortalMessageMediaClient($site);
    $otherClient = makePortalMessageMediaClient($site);
    $user = makePortalMessageMediaUser($client);
    [$conversation] = makePortalMessageMediaConversation($client, $user);
    $message = OpsMessage::query()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $user->id,
        'sender_type' => 'family',
        'message_type' => 'text',
        'content' => 'Mismatched client field',
        'client_id' => $otherClient->id,
    ]);

    $this->actingAs($user)
        ->post("/portal/clients/{$client->id}/messages/pin/{$message->id}")
        ->assertForbidden();

    expect((bool) $message->fresh()?->is_pinned)->toBeFalse();
});

it('keeps the newest canonical portal message when a newer mismatched row exists', function () {
    $site = Site::factory()->create();
    $client = makePortalMessageMediaClient($site);
    $otherClient = makePortalMessageMediaClient($site);
    $portalUser = makePortalMessageMediaUser($client);
    [$conversation, $canonicalMessage] = makePortalMessageMediaConversation($client, $portalUser);
    OpsMessage::query()->create([
        'conversation_id' => $conversation->id,
        'sender_id' => $portalUser->id,
        'sender_type' => 'family',
        'message_type' => 'text',
        'content' => 'Newer mismatched provenance',
        'client_id' => $otherClient->id,
        'created_at' => now()->addMinute(),
        'updated_at' => now()->addMinute(),
    ]);

    $this->actingAs($portalUser)
        ->get("/portal/clients/{$client->id}/messages")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('conversations.0.latest_message.id', $canonicalMessage->id)
            ->where('conversations.0.latest_message.content', 'Hello'));
});

it('restricts new family conversations to eligible workers in the canonical client care team', function () {
    $site = Site::factory()->create();
    $otherSite = Site::factory()->create();
    $client = makePortalMessageMediaClient($site);
    $portalUser = makePortalMessageMediaUser($client);
    $assignedWorker = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $unassignedWorker = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $foreignShiftWorker = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    assignPortalMessageMediaWorkerToSite($assignedWorker, $site);
    assignPortalMessageMediaWorkerToSite($unassignedWorker, $site);
    assignPortalMessageMediaWorkerToSite($foreignShiftWorker, $otherSite);
    $client->supportWorkers()->attach($assignedWorker->id);
    Shift::factory()->create([
        'client_id' => $client->id,
        'site_id' => $site->id,
        'user_id' => $foreignShiftWorker->id,
        'status' => 'scheduled',
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHours(4),
    ]);

    $this->actingAs($portalUser)
        ->get("/portal/clients/{$client->id}/messages")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->has('supportWorkers', 1)
            ->where('supportWorkers.0.id', $assignedWorker->id));

    foreach ([$unassignedWorker, $foreignShiftWorker] as $invalidWorker) {
        $this->postJson("/portal/clients/{$client->id}/messages/start", [
            'worker_id' => $invalidWorker->id,
            'content' => 'Hello',
        ])->assertUnprocessable()->assertJsonValidationErrors('worker_id');
    }

    $this->post("/portal/clients/{$client->id}/messages/start", [
        'worker_id' => $assignedWorker->id,
        'content' => 'Hello care team',
    ])->assertRedirect();

    $conversation = OpsConversation::query()
        ->where('client_id', $client->id)
        ->whereHas('participants', fn ($query) => $query->where('user_id', $assignedWorker->id))
        ->first();
    expect($conversation)->not->toBeNull();
});

it('redacts unlinked portal and stale worker identities from every portal participant payload', function () {
    $site = Site::factory()->create();
    $client = makePortalMessageMediaClient($site);
    $actor = makePortalMessageMediaUser($client);
    $currentPortal = makePortalMessageMediaUser($client);
    $unlinkedPortal = makePortalMessageMediaUser($client);
    $currentWorker = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $staleWorker = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    foreach ([$currentWorker, $staleWorker] as $worker) {
        grantPortalMessageMediaWorkerPermissions($worker);
        assignPortalMessageMediaWorkerToSite($worker, $site);
        $client->supportWorkers()->attach($worker->id);
    }

    [$conversation] = makePortalMessageMediaConversation($client, $actor);
    foreach ([$currentPortal, $unlinkedPortal, $currentWorker, $staleWorker] as $participant) {
        OpsConversationParticipant::query()->create([
            'conversation_id' => $conversation->id,
            'user_id' => $participant->id,
            'role' => $participant->hasRole('next_of_kin') ? 'family' : 'staff',
        ]);
    }

    // Preserve stale rows deliberately: the response layer must not trust
    // historical participant storage as current authority.
    $client->portalUsers()->detach($unlinkedPortal->id);
    HrEmployeeProfile::query()
        ->where('user_id', $staleWorker->id)
        ->update(['end_date' => now()->subDay()->toDateString()]);

    expect(app(OpsMessageVisibilityService::class)->unreadCount($unlinkedPortal))->toBe(0)
        ->and(app(OpsMessageVisibilityService::class)->unreadCount($staleWorker))->toBe(0);

    $expectedIds = collect([$actor->id, $currentPortal->id, $currentWorker->id])
        ->sort()
        ->values()
        ->all();
    $hasOnlyExpectedParticipants = fn ($participants): bool => collect($participants)
        ->pluck('id')
        ->sort()
        ->values()
        ->all() === $expectedIds;

    $this->actingAs($actor)
        ->get("/portal/clients/{$client->id}/messages")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('conversations.0.participants', $hasOnlyExpectedParticipants));
    $this->get("/portal/clients/{$client->id}/messages/{$conversation->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('activeConversation.participants', $hasOnlyExpectedParticipants)
            ->where('conversations.0.participants', $hasOnlyExpectedParticipants));

    $this->actingAs($unlinkedPortal)
        ->get("/portal/clients/{$client->id}/messages/{$conversation->id}")
        ->assertForbidden();
    $this->actingAs($staleWorker)
        ->get(route('operations.messages.show', $conversation))
        ->assertForbidden();
});

it('stores all new gallery photos on private storage and emits only authenticated media URLs', function () {
    Storage::fake('public');
    Storage::fake('local');

    $client = makePortalMessageMediaClient();
    $portalUser = makePortalMessageMediaUser($client);

    $this->actingAs($portalUser)
        ->post("/portal/clients/{$client->id}/photos", [
            'photo' => UploadedFile::fake()->image('family-photo.png', 20, 20),
            'caption' => 'A safe photo',
        ])
        ->assertRedirect();

    $photo = ClientPhoto::query()->sole();
    expect($photo->storage_disk)->toBe('local');
    Storage::disk('local')->assertExists($photo->storage_path);
    Storage::disk('public')->assertMissing($photo->storage_path);

    $this->get("/portal/clients/{$client->id}/photos")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('photos.data.0.id', $photo->id)
            ->where(
                'photos.data.0.url',
                "/portal/clients/{$client->id}/photos/{$photo->id}/media",
            )
            ->where(
                'photos.data.0.thumbnail_url',
                "/portal/clients/{$client->id}/photos/{$photo->id}/thumbnail",
            ));
});

it('stores chat photos privately and strips public paths from the message payload', function () {
    Storage::fake('public');
    Storage::fake('local');

    $client = makePortalMessageMediaClient();
    $portalUser = makePortalMessageMediaUser($client);
    [$conversation] = makePortalMessageMediaConversation($client, $portalUser);

    $this->actingAs($portalUser)
        ->post("/portal/clients/{$client->id}/messages/{$conversation->id}", [
            'attachment' => UploadedFile::fake()->image('chat-photo.jpg', 20, 20),
            'content' => 'Chat photo',
        ])
        ->assertRedirect();

    $photo = ClientPhoto::query()->sole();
    expect($photo->storage_disk)->toBe('local');
    Storage::disk('local')->assertExists($photo->storage_path);
    Storage::disk('public')->assertMissing($photo->storage_path);

    $this->get("/portal/clients/{$client->id}/messages/{$conversation->id}")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where(
                'activeMessages.1.attachments.0.url',
                "/portal/clients/{$client->id}/photos/{$photo->id}/media",
            )
            ->where(
                'activeMessages.1.attachments.0.thumbnail_url',
                "/portal/clients/{$client->id}/photos/{$photo->id}/thumbnail",
            )
            ->missing('activeMessages.1.attachments.0.path')
            ->missing('activeMessages.1.attachments.0.thumbnail_path'));
});

it('rejects active portal and chat upload formats', function (string $extension, string $content) {
    Storage::fake('public');
    Storage::fake('local');

    $client = makePortalMessageMediaClient();
    $portalUser = makePortalMessageMediaUser($client);
    [$conversation] = makePortalMessageMediaConversation($client, $portalUser);

    $activeUpload = UploadedFile::fake()->createWithContent(
        "active.{$extension}",
        $content,
    );

    $this->actingAs($portalUser)
        ->post("/portal/clients/{$client->id}/messages/{$conversation->id}", [
            'attachment' => $activeUpload,
        ])
        ->assertSessionHasErrors('attachment');

    expect(ClientPhoto::query()->exists())->toBeFalse()
        ->and(OpsMessage::query()->where('message_type', 'attachment')->exists())->toBeFalse();
})->with([
    'svg' => ['svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
    'html' => ['html', '<script>alert(1)</script>'],
]);

it('serves private photo bytes only through client-bound disclosure-aware routes with safe headers', function () {
    expect(Schema::hasColumn('client_photos', 'storage_disk'))->toBeTrue();

    Storage::fake('local');
    $site = Site::factory()->create();
    $client = makePortalMessageMediaClient($site);
    $wrongClient = makePortalMessageMediaClient($site);
    $owner = makePortalMessageMediaUser($client);
    $viewer = makePortalMessageMediaUser($client);
    $wrongClient->portalUsers()->attach($owner->id, ['relation' => 'next_of_kin']);
    Storage::disk('local')->put('client-photos/private/photo.png', onePixelPortalMessageMediaPng());
    Storage::disk('local')->put('client-photos/private/thumb.png', onePixelPortalMessageMediaPng());
    $photo = ClientPhoto::query()->create([
        'client_id' => $client->id,
        'uploaded_by_user_id' => $owner->id,
        'storage_disk' => 'local',
        'storage_path' => 'client-photos/private/photo.png',
        'thumbnail_path' => 'client-photos/private/thumb.png',
        'original_name' => 'photo.png',
        'mime_type' => 'image/png',
        'size_bytes' => strlen(onePixelPortalMessageMediaPng()),
        'visibility' => 'family',
        'status' => 'approved',
    ]);

    $this->actingAs($viewer)
        ->get("/portal/clients/{$client->id}/photos/{$photo->id}/media")
        ->assertForbidden();

    $ownResponse = $this->actingAs($owner)
        ->get("/portal/clients/{$client->id}/photos/{$photo->id}/media")
        ->assertOk()
        ->assertHeader('content-type', 'image/png')
        ->assertHeader('x-content-type-options', 'nosniff')
        ->assertHeader('cross-origin-resource-policy', 'same-origin');
    expect($ownResponse->headers->get('content-disposition'))->toContain('inline');

    $this->get("/portal/clients/{$wrongClient->id}/photos/{$photo->id}/media")
        ->assertNotFound();

    grantPortalMessageMediaFamilyDisclosure($client, $viewer);
    $this->actingAs($viewer)
        ->get("/portal/clients/{$client->id}/photos/{$photo->id}/thumbnail")
        ->assertOk()
        ->assertHeader('x-content-type-options', 'nosniff');
});

it('authorizes staff photo delivery against the client policy section and nested photo binding', function () {
    expect(Schema::hasColumn('client_photos', 'storage_disk'))->toBeTrue();

    Storage::fake('local');
    $site = Site::factory()->create();
    $client = makePortalMessageMediaClient($site);
    $wrongClient = makePortalMessageMediaClient($site);
    $uploader = User::factory()->create();
    $manager = User::factory()->create([
        'role' => 'manager',
        'approved_at' => now(),
    ]);
    grantPortalMessageMediaStaffRole($manager, ['clients.viewAny', 'clients.update']);
    Storage::disk('local')->put('client-photos/private/staff.png', onePixelPortalMessageMediaPng());
    $photo = ClientPhoto::query()->create([
        'client_id' => $client->id,
        'uploaded_by_user_id' => $uploader->id,
        'storage_disk' => 'local',
        'storage_path' => 'client-photos/private/staff.png',
        'original_name' => 'staff.png',
        'mime_type' => 'image/png',
        'size_bytes' => strlen(onePixelPortalMessageMediaPng()),
        'visibility' => 'staff_only',
        'status' => 'approved',
    ]);

    $this->actingAs($manager)
        ->get("/operations/clients/{$client->id}/gallery-photos/{$photo->id}/media")
        ->assertOk()
        ->assertHeader('x-content-type-options', 'nosniff');
    $this->get("/operations/clients/{$wrongClient->id}/gallery-photos/{$photo->id}/media")
        ->assertNotFound();
});

it('refuses to serve a legacy active image even when its row predates strict upload validation', function () {
    expect(Schema::hasColumn('client_photos', 'storage_disk'))->toBeTrue();

    Storage::fake('local');
    $client = makePortalMessageMediaClient();
    $owner = makePortalMessageMediaUser($client);
    Storage::disk('local')->put('client-photos/private/active.svg', '<svg><script>alert(1)</script></svg>');
    $photo = ClientPhoto::query()->create([
        'client_id' => $client->id,
        'uploaded_by_user_id' => $owner->id,
        'storage_disk' => 'local',
        'storage_path' => 'client-photos/private/active.svg',
        'original_name' => 'active.svg',
        'mime_type' => 'image/svg+xml',
        'size_bytes' => 38,
        'visibility' => 'family',
        'status' => 'approved',
    ]);

    $this->actingAs($owner)
        ->get("/portal/clients/{$client->id}/photos/{$photo->id}/media")
        ->assertStatus(415);
});

it('migrates legacy public client photos idempotently only after byte verification', function () {
    expect(Schema::hasColumn('client_photos', 'storage_disk'))->toBeTrue();

    Storage::fake('public');
    Storage::fake('local');
    $client = makePortalMessageMediaClient();
    $uploader = User::factory()->create();
    Storage::disk('public')->put('client-photos/legacy/photo.png', 'original bytes');
    Storage::disk('public')->put('client-photos/legacy/thumb.jpg', 'thumbnail bytes');
    $photo = ClientPhoto::query()->create([
        'client_id' => $client->id,
        'uploaded_by_user_id' => $uploader->id,
        'storage_disk' => 'public',
        'storage_path' => 'client-photos/legacy/photo.png',
        'thumbnail_path' => 'client-photos/legacy/thumb.jpg',
        'original_name' => 'photo.png',
        'mime_type' => 'image/png',
        'size_bytes' => 14,
        'visibility' => 'family',
        'status' => 'approved',
    ]);

    $this->artisan('client-photos:migrate-private', ['--dry-run' => true])
        ->assertSuccessful();
    expect($photo->fresh()->storage_disk)->toBe('public');
    Storage::disk('public')->assertExists($photo->storage_path);
    Storage::disk('local')->assertMissing($photo->storage_path);

    $this->artisan('client-photos:migrate-private')->assertSuccessful();
    expect($photo->fresh()->storage_disk)->toBe('local')
        ->and(Storage::disk('local')->get($photo->storage_path))->toBe('original bytes')
        ->and(Storage::disk('local')->get($photo->thumbnail_path))->toBe('thumbnail bytes');
    Storage::disk('public')->assertMissing($photo->storage_path);
    Storage::disk('public')->assertMissing($photo->thumbnail_path);

    $this->artisan('client-photos:migrate-private')->assertSuccessful();
    expect($photo->fresh()->storage_disk)->toBe('local');
});

it('preserves the public source and row metadata when legacy media verification fails', function () {
    expect(Schema::hasColumn('client_photos', 'storage_disk'))->toBeTrue();

    Storage::fake('public');
    Storage::fake('local');
    $client = makePortalMessageMediaClient();
    $uploader = User::factory()->create();
    Storage::disk('public')->put('client-photos/legacy/conflict.png', 'trusted source');
    Storage::disk('local')->put('client-photos/legacy/conflict.png', 'different destination');
    $photo = ClientPhoto::query()->create([
        'client_id' => $client->id,
        'uploaded_by_user_id' => $uploader->id,
        'storage_disk' => 'public',
        'storage_path' => 'client-photos/legacy/conflict.png',
        'original_name' => 'conflict.png',
        'mime_type' => 'image/png',
        'size_bytes' => 14,
        'visibility' => 'family',
        'status' => 'approved',
    ]);

    $this->artisan('client-photos:migrate-private')->assertFailed();
    expect($photo->fresh()->storage_disk)->toBe('public')
        ->and(Storage::disk('public')->get($photo->storage_path))->toBe('trusted source')
        ->and(Storage::disk('local')->get($photo->storage_path))->toBe('different destination');
});

it('refuses the schema rollback while private media metadata remains', function () {
    $migrationPath = database_path('migrations/2026_07_10_000004_add_storage_disk_to_client_photos_table.php');
    expect(is_file($migrationPath))->toBeTrue();

    $client = makePortalMessageMediaClient();
    $uploader = User::factory()->create();
    ClientPhoto::query()->create([
        'client_id' => $client->id,
        'uploaded_by_user_id' => $uploader->id,
        'storage_disk' => 'local',
        'storage_path' => 'client-photos/private/photo.png',
        'original_name' => 'photo.png',
        'mime_type' => 'image/png',
        'size_bytes' => 1,
        'visibility' => 'family',
        'status' => 'approved',
    ]);

    $migration = require $migrationPath;
    expect(fn () => $migration->down())
        ->toThrow(RuntimeException::class, 'private client photo media');
    expect(Schema::hasColumn('client_photos', 'storage_disk'))->toBeTrue();
});
