<?php

use App\Domain\It\Services\ItServiceIdentityCredentialService;
use App\Models\AuditLog;
use App\Models\ItApiRequest;
use App\Models\ItServiceIdentity;
use App\Models\ItTicket;
use App\Models\ItTicketComment;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Carbon;

function secureApiUser(string $role = 'hr', int $tenantId = 1): User
{
    $user = User::factory()->create([
        'role' => $role,
        'approved_at' => now(),
        'organization_id' => $tenantId,
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
        (int) $actor->organization_id,
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
    $canonical = implode("\n", [
        $timestamp,
        'POST',
        $path,
        hash('sha256', (string) $body),
    ]);

    return secureApiHeaders($token, [
        'Content-Type' => 'application/json',
        'Idempotency-Key' => (string) fake()->uuid(),
        'X-OF-Timestamp' => $timestamp,
        'X-OF-Signature' => 'v1='.hash_hmac('sha256', $canonical, $secret),
    ]);
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->manager = secureApiUser();
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

test('scoped identities create and read only allowed tenant site work type and fields with minimal responses', function () {
    $allowedSite = Site::factory()->create(['tenant_id' => 1]);
    $foreignSite = Site::factory()->create(['tenant_id' => 2]);
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
        ->assertJsonPath('data.site_id', $allowedSite->id)
        ->assertJsonMissingPath('data.description')
        ->assertJsonMissingPath('data.internal_notes')
        ->assertJsonMissingPath('data.raw_device_config')
        ->assertJsonMissingPath('data.command_capability');
    $ticket = ItTicket::query()->sole();
    expect($ticket->tenant_id)->toBe(1)
        ->and($ticket->source)->toBe('system')
        ->and($ticket->requester_user_id)->toBe($this->manager->id)
        ->and($ticket->description)->toContain('five failed probes');

    $this->withHeaders(secureApiHeaders($credential['token']))
        ->getJson("/api/v1/it/work-items/{$ticket->id}")
        ->assertOk()
        ->assertJsonPath('data.reference', $ticket->reference)
        ->assertJsonMissingPath('data.description');

    $unapprovedType = ItTicket::factory()->create([
        'tenant_id' => 1,
        'requester_user_id' => $this->manager->id,
        'work_type' => 'change',
    ]);
    $sensitive = ItTicket::factory()->create([
        'tenant_id' => 1,
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
            'title' => 'Foreign site attempt', 'category' => 'network', 'priority' => 'normal',
            'work_type' => 'incident', 'site_id' => $foreignSite->id,
        ])->assertUnprocessable()->assertJsonValidationErrors('site_id');

    $this->withHeaders(secureApiHeaders($credential['token'], ['Idempotency-Key' => (string) fake()->uuid()]))
        ->postJson('/api/v1/it/work-items', [
            'title' => 'Unapproved change', 'category' => 'network', 'priority' => 'normal',
            'work_type' => 'change',
        ])->assertUnprocessable()->assertJsonValidationErrors('work_type');

    $this->withHeaders(secureApiHeaders($credential['token'], ['Idempotency-Key' => (string) fake()->uuid()]))
        ->postJson('/api/v1/it/work-items', [
            'title' => 'Unsafe payload', 'category' => 'network', 'priority' => 'normal',
            'work_type' => 'incident', 'raw_device_config' => ['community' => 'private'],
        ])->assertUnprocessable()->assertJsonValidationErrors('raw_device_config');
});

test('idempotency replays the original result and rejects a conflicting payload', function () {
    $credential = secureApiIdentity($this->manager);
    $key = (string) fake()->uuid();
    $headers = secureApiHeaders($credential['token'], ['Idempotency-Key' => $key]);
    $payload = [
        'title' => 'DNS probe failed', 'description' => 'Resolver timeout.',
        'category' => 'network', 'priority' => 'normal', 'work_type' => 'incident',
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
    $credential = secureApiIdentity($this->manager, ['require_signature' => true]);
    $payload = [
        'title' => 'Signed monitor event', 'category' => 'network',
        'priority' => 'high', 'work_type' => 'incident',
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
    $credential = secureApiIdentity($this->manager);
    $ticket = ItTicket::factory()->create([
        'tenant_id' => 1,
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
