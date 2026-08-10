<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Services\ItServiceIdentityCredentialService;
use App\Models\Asset;
use App\Models\AuditLog;
use App\Models\ItApiRequest;
use App\Models\ItServiceIdentity;
use App\Models\ItTicket;
use App\Models\ItTicketComment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Carbon;

function secureApiUser(string $role = 'hr'): User
{
    $user = User::factory()->create([
        'role' => $role,
        'approved_at' => now(),
    ]);
    $user->roles()->syncWithoutDetaching([
        Role::query()->where('name', $role)->firstOrFail()->id,
    ]);

    return $user;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array{identity: ItServiceIdentity, secret: string, token: string}
 */
function secureApiIdentity(User $actor, array $overrides = []): array
{
    return app(ItServiceIdentityCredentialService::class)->create(
        $actor,
        [
            'name' => 'Monitoring connector',
            'description' => 'Creates and follows approved monitoring work.',
            'actor_user_id' => $actor->id,
            'abilities' => ['work:create', 'work:read', 'work:comment', 'work:transition'],
            'allowed_work_types' => ['incident'],
            'allowed_site_ids' => [],
            'allowed_fields' => [
                'create' => ['title', 'description', 'category', 'priority', 'work_type', 'site_id'],
                'read' => [],
            ],
            'require_signature' => false,
            'rate_limit_per_minute' => 60,
            ...$overrides,
        ],
    );
}

/** @return array<string, string> */
function secureApiHeaders(string $token, array $headers = []): array
{
    return [
        'Authorization' => "Bearer {$token}",
        'Accept' => 'application/json',
        ...$headers,
    ];
}

/** @param array<string, mixed> $payload @return array<string, string> */
function signedSecureApiHeaders(string $token, string $secret, string $path, array $payload): array
{
    $timestamp = (string) now()->timestamp;
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
    $idempotencyKey = (string) fake()->uuid();
    $canonical = implode("\n", [
        $timestamp,
        'POST',
        $path,
        $idempotencyKey,
        hash('sha256', (string) $body),
    ]);

    return secureApiHeaders($token, [
        'Content-Type' => 'application/json',
        'Idempotency-Key' => $idempotencyKey,
        'X-OF-Timestamp' => $timestamp,
        'X-OF-Signature' => 'v1='.hash_hmac('sha256', $canonical, $secret),
    ]);
}

function secureApiAssignSite(User $user, Site $site): void
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
        'employee_number' => 'API-'.$user->id,
        'work_email' => $user->email,
        'created_by' => $user->id,
        ...$attributes,
    ]);
}

function secureApiGrant(User $user, string ...$keys): void
{
    $role = Role::query()->create([
        'name' => 'secure-api-extra-'.str()->uuid(),
        'label' => 'Secure API extra authority',
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

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->manager = secureApiUser();
    $this->defaultSite = Site::factory()->create();
    secureApiAssignSite($this->manager, $this->defaultSite);
});

test('admins create opaque hashed service identities and see the reusable secret once', function () {
    $rawToken = null;
    $response = $this->actingAs($this->manager)
        ->post('/it/setup/api-identities', [
            'name' => 'Auvik migration bridge',
            'description' => 'Temporary approved intake bridge.',
            'actor_user_id' => $this->manager->id,
            'abilities' => ['work:create', 'work:read'],
            'allowed_work_types' => ['incident'],
            'allowed_site_ids' => [],
            'create_fields' => ['title', 'description', 'category', 'priority', 'work_type'],
            'read_fields' => [],
            'require_signature' => true,
            'rate_limit_per_minute' => 30,
        ])
        ->assertRedirect('/it/setup')
        ->assertSessionHasNoErrors()
        ->assertSessionHas('it_api_credential', function (array $credential) use (&$rawToken): bool {
            $rawToken = $credential['token'] ?? null;

            return is_string($rawToken) && str_starts_with($rawToken, 'ofi_');
        });

    $identity = ItServiceIdentity::query()->sole();
    [, , $secret] = explode('_', (string) $rawToken, 3);
    expect($identity->token_hash)->toHaveLength(64)
        ->and($identity->token_hash)->toBe(hash('sha256', $secret))
        ->and($identity->token_hash)->not->toBe($secret)
        ->and($identity->getAttributes())->not->toHaveKey('secret')
        ->and($identity->getAttributes())->not->toHaveKey('token');

    $firstView = $this->actingAs($this->manager)->get('/it/setup');
    $firstView->assertInertia(fn ($page) => $page
        ->where('apiIdentities.0.id', $identity->id)
        ->where('apiIdentities.0.name', 'Auvik migration bridge')
        ->has('oneTimeApiCredential.token')
        ->where('oneTimeApiCredential.identity_id', $identity->id));
    $this->actingAs($this->manager)
        ->get('/it/setup')
        ->assertInertia(fn ($page) => $page
            ->where('oneTimeApiCredential', null)
            ->missing('apiIdentities.0.token_hash'));

    $this->actingAs($this->manager)
        ->post("/it/setup/api-identities/{$identity->id}/revoke")
        ->assertRedirect('/it/setup');

    expect($identity->refresh()->revoked_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'it.api.identity.created')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'it.api.identity.revoked')->exists())->toBeTrue();
});

test('scoped identities create and read only allowed site work type and fields with minimal responses', function () {
    $allowedSite = Site::factory()->create();
    $hiddenSite = Site::factory()->create();
    secureApiAssignSite($this->manager, $allowedSite);
    $credential = secureApiIdentity($this->manager, [
        'allowed_site_ids' => [$allowedSite->id],
    ]);
    $headers = secureApiHeaders($credential['token'], [
        'Idempotency-Key' => (string) fake()->uuid(),
    ]);

    $response = $this->withHeaders($headers)->postJson('/api/v1/it/work-items', [
        'title' => 'WAN edge unreachable',
        'description' => 'Native monitor saw five failed probes.',
        'category' => 'network',
        'priority' => 'high',
        'work_type' => 'incident',
        'site_id' => $allowedSite->id,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.title', 'WAN edge unreachable')
        ->assertJsonMissingPath('data.site_id')
        ->assertJsonMissingPath('data.context')
        ->assertJsonMissingPath('data.description')
        ->assertJsonMissingPath('data.internal_notes')
        ->assertJsonMissingPath('data.raw_device_config')
        ->assertJsonMissingPath('data.command_capability');
    $ticket = ItTicket::query()->sole();
    expect($ticket->source)->toBe('system')
        ->and($ticket->requester_user_id)->toBe($this->manager->id)
        ->and($ticket->description)->toContain('five failed probes');

    $this->withHeaders(secureApiHeaders($credential['token']))
        ->getJson("/api/v1/it/work-items/{$ticket->id}")
        ->assertOk()
        ->assertJsonPath('data.reference', $ticket->reference)
        ->assertJsonMissingPath('data.description');

    $unapprovedType = ItTicket::factory()->create([
        'site_id' => $allowedSite->id,
        'requester_user_id' => $this->manager->id,
        'work_type' => 'change',
    ]);
    $sensitive = ItTicket::factory()->create([
        'site_id' => $allowedSite->id,
        'requester_user_id' => $this->manager->id,
        'work_type' => 'incident',
        'is_sensitive' => true,
    ]);
    $this->withHeaders(secureApiHeaders($credential['token']))
        ->getJson("/api/v1/it/work-items/{$unapprovedType->id}")->assertNotFound();
    $this->withHeaders(secureApiHeaders($credential['token']))
        ->getJson("/api/v1/it/work-items/{$sensitive->id}")->assertNotFound();

    $this->withHeaders(secureApiHeaders($credential['token'], ['Idempotency-Key' => (string) fake()->uuid()]))
        ->postJson('/api/v1/it/work-items', [
            'title' => 'Unapproved site attempt', 'category' => 'network', 'priority' => 'normal',
            'work_type' => 'incident', 'site_id' => $hiddenSite->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('site_id');

    $this->withHeaders(secureApiHeaders($credential['token'], ['Idempotency-Key' => (string) fake()->uuid()]))
        ->postJson('/api/v1/it/work-items', [
            'title' => 'Unapproved change', 'category' => 'network', 'priority' => 'normal',
            'work_type' => 'change', 'site_id' => $allowedSite->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('work_type');

    $this->withHeaders(secureApiHeaders($credential['token'], ['Idempotency-Key' => (string) fake()->uuid()]))
        ->postJson('/api/v1/it/work-items', [
            'title' => 'Unsafe payload', 'category' => 'network', 'priority' => 'normal',
            'work_type' => 'incident', 'site_id' => $allowedSite->id,
            'raw_device_config' => ['community' => 'private'],
        ])->assertUnprocessable()->assertJsonValidationErrors('raw_device_config');
});

test('idempotency replays the original result and rejects a conflicting payload', function () {
    $site = Site::factory()->create();
    secureApiAssignSite($this->manager, $site);
    $credential = secureApiIdentity($this->manager, ['allowed_site_ids' => [$site->id]]);
    $key = (string) fake()->uuid();
    $headers = secureApiHeaders($credential['token'], ['Idempotency-Key' => $key]);
    $payload = [
        'title' => 'DNS probe failed', 'description' => 'Resolver timeout.',
        'category' => 'network', 'priority' => 'normal', 'work_type' => 'incident',
        'site_id' => $site->id,
    ];

    $first = $this->withHeaders($headers)->postJson('/api/v1/it/work-items', $payload);
    $first->assertCreated();
    $ticketId = $first->json('data.id');

    $this->withHeaders($headers)
        ->postJson('/api/v1/it/work-items', $payload)
        ->assertCreated()
        ->assertHeader('X-Idempotent-Replay', 'true')
        ->assertJsonPath('data.id', $ticketId);

    $this->withHeaders($headers)
        ->postJson('/api/v1/it/work-items', [...$payload, 'title' => 'Different request'])
        ->assertConflict()
        ->assertJsonPath('code', 'idempotency_conflict');

    expect(ItTicket::query()->count())->toBe(1)
        ->and(ItApiRequest::query()->where('idempotency_key', $key)->count())->toBe(1);
});

test('required request signatures reject missing invalid and stale signatures', function () {
    Carbon::setTestNow('2026-07-19 12:00:00');
    $site = Site::factory()->create();
    secureApiAssignSite($this->manager, $site);
    $credential = secureApiIdentity($this->manager, [
        'require_signature' => true,
        'allowed_site_ids' => [$site->id],
    ]);
    $payload = [
        'title' => 'Signed monitor event', 'category' => 'network',
        'priority' => 'high', 'work_type' => 'incident',
        'site_id' => $site->id,
    ];

    $this->withHeaders(secureApiHeaders($credential['token'], ['Idempotency-Key' => (string) fake()->uuid()]))
        ->postJson('/api/v1/it/work-items', $payload)
        ->assertUnauthorized()
        ->assertJsonPath('code', 'signature_required');

    $bad = signedSecureApiHeaders($credential['token'], 'wrong-secret', '/api/v1/it/work-items', $payload);
    $this->withHeaders($bad)->postJson('/api/v1/it/work-items', $payload)
        ->assertUnauthorized()->assertJsonPath('code', 'signature_invalid');

    $signed = signedSecureApiHeaders(
        $credential['token'],
        $credential['secret'],
        '/api/v1/it/work-items',
        $payload,
    );
    $this->withHeaders($signed)->postJson('/api/v1/it/work-items', $payload)->assertCreated();

    $stale = $signed;
    $stale['Idempotency-Key'] = (string) fake()->uuid();
    $stale['X-OF-Timestamp'] = (string) now()->subMinutes(6)->timestamp;
    $this->withHeaders($stale)->postJson('/api/v1/it/work-items', $payload)
        ->assertUnauthorized()->assertJsonPath('code', 'signature_stale');

    Carbon::setTestNow();
});

test('revoked expired unprivileged and over-limit identities are denied', function () {
    $revoked = secureApiIdentity($this->manager);
    $revoked['identity']->update(['revoked_at' => now()]);
    $this->withHeaders(secureApiHeaders($revoked['token']))
        ->getJson('/api/v1/it/work-items/999')
        ->assertUnauthorized()->assertJsonPath('code', 'identity_inactive');

    $expired = secureApiIdentity($this->manager, ['expires_at' => now()->subMinute()]);
    $this->withHeaders(secureApiHeaders($expired['token']))
        ->getJson('/api/v1/it/work-items/999')
        ->assertUnauthorized()->assertJsonPath('code', 'identity_inactive');

    $readOnly = secureApiIdentity($this->manager, ['abilities' => ['work:read']]);
    $this->withHeaders(secureApiHeaders($readOnly['token'], ['Idempotency-Key' => (string) fake()->uuid()]))
        ->postJson('/api/v1/it/work-items', [
            'title' => 'Denied', 'category' => 'network', 'priority' => 'normal', 'work_type' => 'incident',
        ])->assertForbidden()->assertJsonPath('code', 'ability_denied');

    $limited = secureApiIdentity($this->manager, ['rate_limit_per_minute' => 1]);
    $this->withHeaders(secureApiHeaders($limited['token']))
        ->getJson('/api/v1/it/work-items/999')->assertNotFound();
    $this->withHeaders(secureApiHeaders($limited['token']))
        ->getJson('/api/v1/it/work-items/999')->assertTooManyRequests();
});

test('service identities append public comments and transition through canonical lifecycle services with audit', function () {
    $site = Site::factory()->create();
    secureApiAssignSite($this->manager, $site);
    $credential = secureApiIdentity($this->manager, ['allowed_site_ids' => [$site->id]]);
    $ticket = ItTicket::factory()->create([
        'site_id' => $site->id,
        'requester_user_id' => $this->manager->id,
        'work_type' => 'incident',
        'workflow_state' => 'submitted',
        'status' => 'open',
    ]);

    $this->withHeaders(secureApiHeaders($credential['token'], ['Idempotency-Key' => (string) fake()->uuid()]))
        ->postJson("/api/v1/it/work-items/{$ticket->id}/comments", [
            'body' => 'Monitoring evidence: TCP 443 recovered for three consecutive checks.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.is_internal', false);

    $comment = ItTicketComment::query()->sole();
    expect($comment->is_internal)->toBeFalse()
        ->and($comment->author_user_id)->toBe($this->manager->id);

    $this->withHeaders(secureApiHeaders($credential['token'], ['Idempotency-Key' => (string) fake()->uuid()]))
        ->postJson("/api/v1/it/work-items/{$ticket->id}/transitions", [
            'to' => 'in_progress',
            'reason' => 'Native monitor recovery is being verified.',
            'next_action' => 'Observe for ten minutes.',
        ])
        ->assertOk()
        ->assertJsonPath('data.workflow_state', 'in_progress');

    expect($ticket->refresh()->status)->toBe('in_progress')
        ->and($ticket->workflow_state)->toBe('in_progress')
        ->and($ticket->events()->where('type', 'workflow_transitioned')->where('payload->via', 'service_api')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'it.api.comment.created')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'it.api.transition.completed')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'it.api.request')->count())->toBe(2);
});

test('service API reauthorizes execution account site sensitivity and explicit organisation wide scope before validation', function () {
    $allowedSite = Site::factory()->create();
    $hiddenSite = Site::factory()->create();
    secureApiAssignSite($this->manager, $allowedSite);
    $credential = secureApiIdentity($this->manager, [
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

    $headers = secureApiHeaders($credential['token']);
    $this->withHeaders($headers)->getJson("/api/v1/it/work-items/{$visible->id}")->assertOk();
    foreach ([$hidden, $sensitive, $accidentalNull, $organisationWide] as $concealed) {
        $this->withHeaders($headers)
            ->getJson("/api/v1/it/work-items/{$concealed->id}")
            ->assertNotFound();
    }

    foreach (['comments', 'transitions'] as $operation) {
        $this->withHeaders(secureApiHeaders($credential['token'], [
            'Idempotency-Key' => (string) fake()->uuid(),
        ]))->postJson("/api/v1/it/work-items/{$hidden->id}/{$operation}", [])
            ->assertNotFound()
            ->assertJsonMissingValidationErrors(['body', 'to']);
    }
    expect($hidden->comments()->count())->toBe(0);

    secureApiGrant($this->manager, 'it.viewSensitive', 'it.organisationWide');
    $credential['identity']->update(['abilities' => [
        ...$credential['identity']->abilities,
        'work:sensitive',
        'work:organisation-wide',
    ]]);

    $this->withHeaders($headers)->getJson("/api/v1/it/work-items/{$sensitive->id}")->assertOk();
    $this->withHeaders($headers)->getJson("/api/v1/it/work-items/{$organisationWide->id}")->assertOk();
    $this->withHeaders($headers)->getJson("/api/v1/it/work-items/{$accidentalNull->id}")->assertNotFound();
});

test('service API field policy and replay use current authority and bind signatures to the idempotency key', function () {
    $site = Site::factory()->create();
    $hiddenSite = Site::factory()->create();
    secureApiAssignSite($this->manager, $site);
    $hiddenAsset = Asset::factory()->create(['site_id' => $hiddenSite->id]);
    $credential = secureApiIdentity($this->manager, [
        'allowed_site_ids' => [$site->id],
        'allowed_fields' => [
            'create' => ['title', 'description', 'category', 'priority', 'work_type', 'site_id', 'asset_id'],
            'read' => ['description', 'site', 'asset'],
        ],
    ]);
    $credential['identity']->update(['allowed_site_ids' => [$site->id, $hiddenSite->id]]);
    $key = (string) fake()->uuid();
    $payload = [
        'title' => 'Signed scoped incident',
        'description' => 'Do not replay after read authority changes.',
        'category' => 'network',
        'priority' => 'high',
        'work_type' => 'incident',
        'site_id' => $site->id,
    ];

    $first = $this->withHeaders(secureApiHeaders($credential['token'], ['Idempotency-Key' => $key]))
        ->postJson('/api/v1/it/work-items', $payload)
        ->assertCreated()
        ->assertJsonPath('data.description', $payload['description'])
        ->assertJsonPath('data.context.site.id', $site->id)
        ->assertJsonMissingPath('data.site_id');

    $ticket = ItTicket::query()->findOrFail($first->json('data.id'));
    $ticket->update(['asset_id' => $hiddenAsset->id]);
    $credential['identity']->update(['allowed_fields' => [
        'create' => $credential['identity']->allowed_fields['create'],
        'read' => [],
    ]]);

    $this->withHeaders(secureApiHeaders($credential['token'], ['Idempotency-Key' => $key]))
        ->postJson('/api/v1/it/work-items', $payload)
        ->assertCreated()
        ->assertHeader('X-Idempotent-Replay', 'true')
        ->assertJsonMissingPath('data.description')
        ->assertJsonMissingPath('data.context');

    $credential['identity']->update(['abilities' => ['work:read']]);
    $this->withHeaders(secureApiHeaders($credential['token'], ['Idempotency-Key' => $key]))
        ->postJson('/api/v1/it/work-items', $payload)
        ->assertForbidden()
        ->assertJsonPath('code', 'ability_denied');

    $credential['identity']->update(['abilities' => ['work:create', 'work:read']]);
    $this->manager->hrEmployeeProfile()->update([
        'primary_site_id' => $hiddenSite->id,
        'secondary_site_ids' => [],
    ]);
    $this->withHeaders(secureApiHeaders($credential['token'], ['Idempotency-Key' => $key]))
        ->postJson('/api/v1/it/work-items', $payload)
        ->assertNotFound();

    $signed = signedSecureApiHeaders(
        $credential['token'],
        $credential['secret'],
        '/api/v1/it/work-items',
        $payload,
    );
    $credential['identity']->update(['require_signature' => true]);
    $capturedWithFreshKey = $signed;
    $capturedWithFreshKey['Idempotency-Key'] = (string) fake()->uuid();
    $this->withHeaders($capturedWithFreshKey)
        ->postJson('/api/v1/it/work-items', $payload)
        ->assertUnauthorized()
        ->assertJsonPath('code', 'signature_invalid');
});
