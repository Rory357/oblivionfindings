<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Data\ItTransitionInput;
use App\Domain\It\Enums\ItWorkflowState;
use App\Domain\It\Services\ItApiWorkItemService;
use App\Domain\It\Services\ItServiceIdentityCredentialService;
use App\Models\Asset;
use App\Models\ItApiRequest;
use App\Models\ItService;
use App\Models\ItServiceIdentity;
use App\Models\ItTicket;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Event;

function apiBoundaryUser(): User
{
    $user = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $user->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->firstOrFail()->id,
    ]);

    return $user;
}

function apiBoundaryAssignSite(User $user, Site $site): void
{
    $profile = HrEmployeeProfile::query()->where('user_id', $user->id)->first();
    $attributes = [
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'is_active' => true,
        'start_date' => now()->subMonth()->toDateString(),
        'end_date' => null,
        'updated_by' => $user->id,
    ];

    if ($profile) {
        $profile->update($attributes);

        return;
    }

    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'created_by' => $user->id,
        ...$attributes,
    ]);
}

function apiBoundaryGrant(User $user, string ...$keys): void
{
    $role = Role::query()->create([
        'name' => 'api-boundary-'.str()->uuid(),
        'label' => 'API boundary authority',
        'level' => 50,
        'type' => 'custom',
    ]);
    foreach ($keys as $key) {
        $permission = Permission::query()->firstOrCreate(
            ['key' => $key],
            ['description' => $key, 'group' => 'it', 'module' => 'Operations'],
        );
        $role->permissions()->attach($permission);
    }
    $user->roles()->attach($role);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array{identity: ItServiceIdentity, secret: string, token: string}
 */
function apiBoundaryIdentity(User $actor, array $overrides = []): array
{
    return app(ItServiceIdentityCredentialService::class)->create(
        $actor,
        [
            'name' => 'Scoped native monitoring intake',
            'description' => 'Focused API boundary test identity.',
            'actor_user_id' => $actor->id,
            'abilities' => ['work:create', 'work:read', 'work:comment', 'work:transition'],
            'allowed_work_types' => ['incident'],
            'allowed_site_ids' => [],
            'allowed_fields' => [
                'create' => ['title', 'description', 'category', 'priority', 'work_type', 'site_id'],
                'read' => [],
            ],
            'require_signature' => false,
            'rate_limit_per_minute' => 100,
            ...$overrides,
        ],
    );
}

/** @return array<string, string> */
function apiBoundaryHeaders(string $token, ?string $idempotencyKey = null): array
{
    return array_filter([
        'Authorization' => "Bearer {$token}",
        'Accept' => 'application/json',
        'Idempotency-Key' => $idempotencyKey,
    ], fn (?string $value): bool => $value !== null);
}

/**
 * @param  array<string, mixed>  $payload
 * @return array<string, string>
 */
function apiBoundarySignedHeaders(
    string $token,
    string $secret,
    string $path,
    string $idempotencyKey,
    array $payload,
): array {
    $timestamp = (string) now()->timestamp;
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $canonical = implode("\n", [
        $timestamp,
        'POST',
        $path,
        $idempotencyKey,
        hash('sha256', (string) $body),
    ]);

    return [
        ...apiBoundaryHeaders($token, $idempotencyKey),
        'Content-Type' => 'application/json',
        'X-OF-Timestamp' => $timestamp,
        'X-OF-Signature' => 'v1='.hash_hmac('sha256', $canonical, $secret),
    ];
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->actor = apiBoundaryUser();
});

test('service API reauthorizes the execution account and conceals route objects before validation', function () {
    $allowedSite = Site::factory()->create();
    $hiddenSite = Site::factory()->create();
    apiBoundaryAssignSite($this->actor, $allowedSite);
    $credential = apiBoundaryIdentity($this->actor, [
        'allowed_site_ids' => [$allowedSite->id],
    ]);
    $credential['identity']->update(['allowed_site_ids' => [$allowedSite->id, $hiddenSite->id]]);

    $visible = ItTicket::factory()->create([
        'site_id' => $allowedSite->id,
        'work_type' => 'incident',
    ]);
    $hidden = ItTicket::factory()->create([
        'site_id' => $hiddenSite->id,
        'work_type' => 'incident',
    ]);
    $sensitive = ItTicket::factory()->create([
        'site_id' => $allowedSite->id,
        'work_type' => 'incident',
        'is_sensitive' => true,
    ]);
    $accidentalNull = ItTicket::factory()->create([
        'site_id' => null,
        'is_organisation_wide' => false,
        'work_type' => 'incident',
    ]);
    $organisationWide = ItTicket::factory()->create([
        'site_id' => null,
        'is_organisation_wide' => true,
        'work_type' => 'incident',
    ]);

    $headers = apiBoundaryHeaders($credential['token']);
    $this->withHeaders($headers)->getJson("/api/v1/it/work-items/{$visible->id}")->assertOk();
    foreach ([$hidden, $sensitive, $accidentalNull, $organisationWide] as $concealed) {
        $this->withHeaders($headers)
            ->getJson("/api/v1/it/work-items/{$concealed->id}")
            ->assertNotFound();
    }

    foreach (['comments', 'transitions'] as $operation) {
        $this->withHeaders(apiBoundaryHeaders($credential['token'], (string) fake()->uuid()))
            ->postJson("/api/v1/it/work-items/{$hidden->id}/{$operation}", [])
            ->assertNotFound()
            ->assertJsonMissingValidationErrors(['body', 'to']);
    }
    expect($hidden->comments()->count())->toBe(0);

    apiBoundaryGrant($this->actor, 'it.viewSensitive', 'it.organisationWide');
    $credential['identity']->update(['abilities' => [
        ...$credential['identity']->abilities,
        'work:sensitive',
        'work:organisation-wide',
    ]]);

    $this->withHeaders($headers)->getJson("/api/v1/it/work-items/{$sensitive->id}")->assertOk();
    $this->withHeaders($headers)->getJson("/api/v1/it/work-items/{$organisationWide->id}")->assertOk();
    $this->withHeaders($headers)->getJson("/api/v1/it/work-items/{$accidentalNull->id}")->assertNotFound();

    $this->actor->update(['approved_at' => null]);
    $this->withHeaders($headers)
        ->getJson("/api/v1/it/work-items/{$visible->id}")
        ->assertUnauthorized()
        ->assertJsonPath('code', 'identity_inactive');

    $this->actor->update(['approved_at' => now()]);
    $this->actor->hrEmployeeProfile()->update(['end_date' => now()->subDay()->toDateString()]);
    $this->withHeaders($headers)
        ->getJson("/api/v1/it/work-items/{$visible->id}")
        ->assertUnauthorized()
        ->assertJsonPath('code', 'identity_inactive');
});

test('service API create reauthorizes Site and requires explicit organisation wide scope', function () {
    $allowedSite = Site::factory()->create();
    $hiddenSite = Site::factory()->create();
    apiBoundaryAssignSite($this->actor, $allowedSite);
    $credential = apiBoundaryIdentity($this->actor, [
        'allowed_site_ids' => [$allowedSite->id],
        'allowed_fields' => [
            'create' => [
                'title', 'category', 'priority', 'work_type', 'site_id', 'is_organisation_wide',
            ],
            'read' => [],
        ],
    ]);
    $credential['identity']->update(['allowed_site_ids' => [$allowedSite->id, $hiddenSite->id]]);
    $payload = [
        'title' => 'Scoped native monitor event',
        'category' => 'network',
        'priority' => 'high',
        'work_type' => 'incident',
    ];

    $this->withHeaders(apiBoundaryHeaders($credential['token'], (string) fake()->uuid()))
        ->postJson('/api/v1/it/work-items', [...$payload, 'site_id' => $allowedSite->id])
        ->assertCreated();

    $this->withHeaders(apiBoundaryHeaders($credential['token'], (string) fake()->uuid()))
        ->postJson('/api/v1/it/work-items', [...$payload, 'site_id' => $hiddenSite->id])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('site_id');

    $this->withHeaders(apiBoundaryHeaders($credential['token'], (string) fake()->uuid()))
        ->postJson('/api/v1/it/work-items', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('site_id');

    $this->withHeaders(apiBoundaryHeaders($credential['token'], (string) fake()->uuid()))
        ->postJson('/api/v1/it/work-items', [...$payload, 'is_organisation_wide' => true])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('is_organisation_wide');

    apiBoundaryGrant($this->actor, 'it.organisationWide');
    $credential['identity']->update(['abilities' => [
        ...$credential['identity']->abilities,
        'work:organisation-wide',
    ]]);

    $wide = $this->withHeaders(apiBoundaryHeaders($credential['token'], (string) fake()->uuid()))
        ->postJson('/api/v1/it/work-items', [...$payload, 'is_organisation_wide' => true])
        ->assertCreated();

    $wideTicket = ItTicket::query()->findOrFail($wide->json('data.id'));
    expect($wideTicket->site_id)->toBeNull()
        ->and($wideTicket->is_organisation_wide)->toBeTrue();
});

test('idempotent replay rechecks current route authority and re-presents current fields', function () {
    $site = Site::factory()->create();
    $otherSite = Site::factory()->create();
    apiBoundaryAssignSite($this->actor, $site);
    $credential = apiBoundaryIdentity($this->actor, [
        'allowed_site_ids' => [$site->id],
        'allowed_fields' => [
            'create' => ['title', 'description', 'category', 'priority', 'work_type', 'site_id'],
            'read' => ['description', 'site'],
        ],
    ]);
    $key = (string) fake()->uuid();
    $payload = [
        'title' => 'Replay-sensitive monitor event',
        'description' => 'Current field policy must win.',
        'category' => 'network',
        'priority' => 'high',
        'work_type' => 'incident',
        'site_id' => $site->id,
    ];

    $created = $this->withHeaders(apiBoundaryHeaders($credential['token'], $key))
        ->postJson('/api/v1/it/work-items', $payload)
        ->assertCreated()
        ->assertJsonPath('data.description', $payload['description'])
        ->assertJsonPath('data.context.site.id', $site->id);
    $ticket = ItTicket::query()->findOrFail($created->json('data.id'));
    $storedResponse = json_encode(
        ItApiRequest::query()->where('idempotency_key', $key)->sole()->response_body,
    );
    expect($storedResponse)->not->toContain($payload['description'])
        ->and($storedResponse)->not->toContain('context')
        ->and($storedResponse)->not->toContain($site->name);

    $credential['identity']->update(['allowed_fields' => [
        'create' => $credential['identity']->allowed_fields['create'],
        'read' => [],
    ]]);
    $this->withHeaders(apiBoundaryHeaders($credential['token'], $key))
        ->postJson('/api/v1/it/work-items', $payload)
        ->assertCreated()
        ->assertHeader('X-Idempotent-Replay', 'true')
        ->assertJsonMissingPath('data.description')
        ->assertJsonMissingPath('data.context');

    $credential['identity']->update(['abilities' => ['work:read']]);
    $this->withHeaders(apiBoundaryHeaders($credential['token'], $key))
        ->postJson('/api/v1/it/work-items', $payload)
        ->assertForbidden()
        ->assertJsonPath('code', 'ability_denied');

    $credential['identity']->update(['abilities' => ['work:create', 'work:read']]);
    apiBoundaryAssignSite($this->actor, $otherSite);
    $this->withHeaders(apiBoundaryHeaders($credential['token'], $key))
        ->postJson('/api/v1/it/work-items', $payload)
        ->assertNotFound();

    expect($ticket->refresh()->site_id)->toBe($site->id)
        ->and(ItApiRequest::query()->where('idempotency_key', $key)->count())->toBe(1);
});

test('signed requests bind the idempotency key against captured fresh key replay', function () {
    $site = Site::factory()->create();
    apiBoundaryAssignSite($this->actor, $site);
    $credential = apiBoundaryIdentity($this->actor, [
        'allowed_site_ids' => [$site->id],
        'require_signature' => true,
    ]);
    $payload = [
        'title' => 'Signed monitor event',
        'category' => 'network',
        'priority' => 'high',
        'work_type' => 'incident',
        'site_id' => $site->id,
    ];
    $key = (string) fake()->uuid();
    $headers = apiBoundarySignedHeaders(
        $credential['token'],
        $credential['secret'],
        '/api/v1/it/work-items',
        $key,
        $payload,
    );

    $this->withHeaders($headers)
        ->postJson('/api/v1/it/work-items', $payload)
        ->assertCreated();

    $captured = $headers;
    $captured['Idempotency-Key'] = (string) fake()->uuid();
    $this->withHeaders($captured)
        ->postJson('/api/v1/it/work-items', $payload)
        ->assertUnauthorized()
        ->assertJsonPath('code', 'signature_invalid');

    expect(ItTicket::query()->count())->toBe(1);
});

test('API resource exposes only field-gated linked context authorized at use time', function () {
    $site = Site::factory()->create();
    $hiddenSite = Site::factory()->create();
    apiBoundaryAssignSite($this->actor, $site);
    apiBoundaryGrant($this->actor, 'assets.viewAny');
    $service = ItService::factory()->create();
    $asset = Asset::factory()->create(['site_id' => $site->id]);
    $hiddenAsset = Asset::factory()->create(['site_id' => $hiddenSite->id]);
    $credential = apiBoundaryIdentity($this->actor, [
        'allowed_site_ids' => [$site->id],
        'allowed_fields' => [
            'create' => ['title', 'category', 'priority', 'work_type', 'site_id'],
            'read' => ['site', 'service', 'asset'],
        ],
    ]);
    $ticket = ItTicket::factory()->create([
        'site_id' => $site->id,
        'it_service_id' => $service->id,
        'asset_id' => $asset->id,
        'work_type' => 'incident',
    ]);

    $this->withHeaders(apiBoundaryHeaders($credential['token']))
        ->getJson("/api/v1/it/work-items/{$ticket->id}")
        ->assertOk()
        ->assertJsonPath('data.context.site.id', $site->id)
        ->assertJsonPath('data.context.service.id', $service->id)
        ->assertJsonPath('data.context.asset.id', $asset->id)
        ->assertJsonMissingPath('data.site_id')
        ->assertJsonMissingPath('data.it_service_id');

    $ticket->update(['asset_id' => $hiddenAsset->id]);
    $this->withHeaders(apiBoundaryHeaders($credential['token']))
        ->getJson("/api/v1/it/work-items/{$ticket->id}")
        ->assertOk()
        ->assertJsonMissingPath('data.context.asset');

    $service->update(['is_active' => false]);
    $this->withHeaders(apiBoundaryHeaders($credential['token']))
        ->getJson("/api/v1/it/work-items/{$ticket->id}")
        ->assertOk()
        ->assertJsonMissingPath('data.context.service');

    $credential['identity']->update(['allowed_fields' => [
        'create' => $credential['identity']->allowed_fields['create'],
        'read' => [],
    ]]);
    $this->withHeaders(apiBoundaryHeaders($credential['token']))
        ->getJson("/api/v1/it/work-items/{$ticket->id}")
        ->assertOk()
        ->assertJsonMissingPath('data.context');
});

test('API create rejects inactive service links and replay storage never retains comment text', function () {
    $site = Site::factory()->create();
    apiBoundaryAssignSite($this->actor, $site);
    $inactiveService = ItService::factory()->create(['is_active' => false]);
    $credential = apiBoundaryIdentity($this->actor, [
        'allowed_site_ids' => [$site->id],
        'allowed_fields' => [
            'create' => ['title', 'category', 'priority', 'work_type', 'site_id', 'it_service_id'],
            'read' => [],
        ],
    ]);
    $payload = [
        'title' => 'Inactive service link',
        'category' => 'network',
        'priority' => 'normal',
        'work_type' => 'incident',
        'site_id' => $site->id,
        'it_service_id' => $inactiveService->id,
    ];

    $this->withHeaders(apiBoundaryHeaders($credential['token'], (string) fake()->uuid()))
        ->postJson('/api/v1/it/work-items', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('it_service_id');

    $ticket = ItTicket::factory()->create([
        'site_id' => $site->id,
        'work_type' => 'incident',
    ]);
    $key = (string) fake()->uuid();
    $comment = 'Evidence text must never be cached in the API request replay record.';
    $this->withHeaders(apiBoundaryHeaders($credential['token'], $key))
        ->postJson("/api/v1/it/work-items/{$ticket->id}/comments", ['body' => $comment])
        ->assertCreated()
        ->assertJsonPath('data.body', $comment);

    $record = ItApiRequest::query()->where('idempotency_key', $key)->sole();
    expect(json_encode($record->response_body))->not->toContain($comment);

    $this->withHeaders(apiBoundaryHeaders($credential['token'], $key))
        ->postJson("/api/v1/it/work-items/{$ticket->id}/comments", ['body' => $comment])
        ->assertCreated()
        ->assertHeader('X-Idempotent-Replay', 'true')
        ->assertJsonMissingPath('data.body');
});

test('comment and transition services reauthorize the locked canonical ticket before mutation', function () {
    $site = Site::factory()->create();
    $hiddenSite = Site::factory()->create();
    apiBoundaryAssignSite($this->actor, $site);
    $credential = apiBoundaryIdentity($this->actor, ['allowed_site_ids' => [$site->id]]);
    $staleCommentTicket = ItTicket::factory()->create([
        'site_id' => $site->id,
        'work_type' => 'incident',
    ]);
    $staleTransitionTicket = ItTicket::factory()->create([
        'site_id' => $site->id,
        'work_type' => 'incident',
        'workflow_state' => 'submitted',
        'status' => 'open',
    ]);
    ItTicket::query()->whereKey([
        $staleCommentTicket->id,
        $staleTransitionTicket->id,
    ])->update(['site_id' => $hiddenSite->id]);

    expect(fn () => app(ItApiWorkItemService::class)->addPublicComment(
        $credential['identity'],
        $staleCommentTicket,
        'Must not be written.',
    ))->toThrow(ModelNotFoundException::class);

    expect(fn () => app(ItApiWorkItemService::class)->transition(
        $credential['identity'],
        $staleTransitionTicket,
        new ItTransitionInput(
            actor: $this->actor,
            to: ItWorkflowState::InProgress,
            reason: 'Must not transition.',
            source: 'service_api',
        ),
    ))->toThrow(ModelNotFoundException::class);

    expect($staleCommentTicket->comments()->count())->toBe(0)
        ->and($staleTransitionTicket->refresh()->workflow_state)->toBe('submitted');
});

test('identity setup rejects Sites and exceptional abilities unavailable to the execution account', function () {
    $allowedSite = Site::factory()->create();
    $hiddenSite = Site::factory()->create();
    apiBoundaryAssignSite($this->actor, $allowedSite);

    $this->actingAs($this->actor)
        ->post('/it/setup/api-identities', [
            'name' => 'Over-broad connector',
            'actor_user_id' => $this->actor->id,
            'abilities' => ['work:create', 'work:read', 'work:sensitive', 'work:organisation-wide'],
            'allowed_work_types' => ['incident'],
            'allowed_site_ids' => [$hiddenSite->id],
            'create_fields' => ['title', 'category', 'priority', 'work_type', 'site_id'],
            'read_fields' => [],
            'require_signature' => true,
            'rate_limit_per_minute' => 30,
        ])
        ->assertSessionHasErrors(['allowed_site_ids.0', 'abilities']);

    expect(ItServiceIdentity::query()->count())->toBe(0);
});

test('identity setup cannot borrow another execution accounts wider Sites or exceptional authority', function () {
    $managerSite = Site::factory()->create();
    $executionSite = Site::factory()->create();
    apiBoundaryAssignSite($this->actor, $managerSite);
    $executionAccount = apiBoundaryUser();
    apiBoundaryAssignSite($executionAccount, $executionSite);
    apiBoundaryGrant($executionAccount, 'it.viewSensitive', 'it.organisationWide');
    $payload = [
        'name' => 'Cross Site escalation attempt',
        'actor_user_id' => $executionAccount->id,
        'abilities' => ['work:create', 'work:read', 'work:sensitive', 'work:organisation-wide'],
        'allowed_work_types' => ['incident'],
        'allowed_site_ids' => [$executionSite->id],
        'create_fields' => ['title', 'category', 'priority', 'work_type', 'site_id'],
        'read_fields' => [],
        'require_signature' => true,
        'rate_limit_per_minute' => 30,
    ];

    $this->actingAs($this->actor)
        ->post('/it/setup/api-identities', $payload)
        ->assertSessionHasErrors(['allowed_site_ids.0', 'abilities']);

    expect(fn () => app(ItServiceIdentityCredentialService::class)->create($this->actor, [
        ...$payload,
        'allowed_fields' => [
            'create' => $payload['create_fields'],
            'read' => [],
        ],
    ]))->toThrow(DomainException::class, 'both the manager and execution account');

    $otherCredential = apiBoundaryIdentity($executionAccount, [
        'allowed_site_ids' => [$executionSite->id],
    ]);
    $this->actingAs($this->actor)
        ->post(route('it.setup.api-identities.revoke', $otherCredential['identity']))
        ->assertNotFound();
    expect($otherCredential['identity']->fresh()->revoked_at)->toBeNull();
});

test('create recording retains its authorization subject when authority changes before response recording', function () {
    $site = Site::factory()->create();
    $otherSite = Site::factory()->create();
    apiBoundaryAssignSite($this->actor, $site);
    $credential = apiBoundaryIdentity($this->actor, ['allowed_site_ids' => [$site->id]]);
    $actor = $this->actor;
    Event::listen('eloquent.created: '.ItTicket::class, function () use ($actor, $otherSite): void {
        $actor->hrEmployeeProfile()->update(['primary_site_id' => $otherSite->id]);
    });
    $key = (string) fake()->uuid();
    $payload = [
        'title' => 'Authority changes during response recording',
        'category' => 'network',
        'priority' => 'high',
        'work_type' => 'incident',
        'site_id' => $site->id,
    ];

    $this->withHeaders(apiBoundaryHeaders($credential['token'], $key))
        ->postJson('/api/v1/it/work-items', $payload)
        ->assertNotFound()
        ->assertJsonPath('code', 'work_item_not_found')
        ->assertJsonMissingPath('data.id');

    $record = ItApiRequest::query()->where('idempotency_key', $key)->sole();
    expect($record->ticket_id)->toBeInt()
        ->and($record->response_body)->not->toHaveKey('data');

    $this->withHeaders(apiBoundaryHeaders($credential['token'], $key))
        ->postJson('/api/v1/it/work-items', $payload)
        ->assertNotFound()
        ->assertJsonPath('code', 'work_item_not_found')
        ->assertJsonMissingPath('data.id');
});
