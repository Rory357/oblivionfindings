<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Services\ItServiceIdentityCredentialService;
use App\Models\AuditLog;
use App\Models\ItServiceIdentity;
use App\Models\ItServiceIdentityCommandReceipt;
use App\Models\ItTicket;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Str;

function identityLifecycleManager(): User
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

function identityLifecycleAssignSite(User $user, Site $site): void
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
        'employee_number' => 'IDENTITY-'.$user->id,
        'work_email' => $user->email,
        'created_by' => $user->id,
        ...$attributes,
    ]);
}

/** @return array<string, mixed> */
function identityLifecycleIssuePayload(User $actor, Site $site, array $overrides = []): array
{
    return [
        'name' => 'Lifecycle monitoring connector',
        'description' => 'Creates approved incident work for lifecycle coverage.',
        'actor_user_id' => $actor->id,
        'abilities' => ['work:create', 'work:read', 'work:update', 'work:link'],
        'allowed_work_types' => ['incident'],
        'allowed_site_ids' => [$site->id],
        'create_fields' => ['title', 'description', 'category', 'priority', 'work_type', 'site_id'],
        'read_fields' => ['category'],
        'update_fields' => ['category', 'priority'],
        'require_signature' => false,
        'rate_limit_per_minute' => 60,
        'expires_at' => null,
        ...$overrides,
    ];
}

/** @return array<string, mixed> */
function identityLifecycleUpdatePayload(ItServiceIdentity $identity, Site $site, array $overrides = []): array
{
    return [
        'name' => $identity->name,
        'description' => $identity->description,
        'abilities' => $identity->abilities,
        'allowed_work_types' => $identity->allowed_work_types,
        'allowed_site_ids' => [$site->id],
        'create_fields' => $identity->allowed_fields['create'],
        'read_fields' => $identity->allowed_fields['read'],
        'update_fields' => $identity->allowed_fields['update'],
        'require_signature' => $identity->require_signature,
        'rate_limit_per_minute' => $identity->rate_limit_per_minute,
        'expires_at' => $identity->expires_at?->toIso8601String(),
        ...$overrides,
    ];
}

/** @return array<string, string> */
function identityLifecycleApiHeaders(string $token): array
{
    return [
        'Accept' => 'application/json',
        'Authorization' => "Bearer {$token}",
    ];
}

function identityLifecycleAssertNoSecret(string $secret, ItServiceIdentityCommandReceipt $receipt): void
{
    expect(json_encode($receipt->getAttributes()))->not->toContain($secret)
        ->and(json_encode($receipt->getAttributes()))->not->toContain('token')
        ->and(json_encode(AuditLog::query()->get(['id', 'action', 'auditable_type', 'auditable_id', 'meta'])->all()))
        ->not->toContain($secret);
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->manager = identityLifecycleManager();
    $this->site = Site::factory()->create();
    identityLifecycleAssignSite($this->manager, $this->site);
});

test('issue and rotate expose each credential only to the direct JSON command response', function () {
    $issueUuid = (string) Str::uuid();
    $issue = $this->actingAs($this->manager)
        ->postJson('/it/setup/api-identities', identityLifecycleIssuePayload($this->manager, $this->site, [
            'request_uuid' => $issueUuid,
            'viewer_user_id' => $this->manager->id,
        ]))
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertSessionMissing('it_api_credential')
        ->assertJsonPath('viewer_user_id', $this->manager->id)
        ->assertJsonPath('request_uuid', $issueUuid)
        ->assertJsonPath('operation', 'issue')
        ->assertJsonPath('state', 'confirmed')
        ->assertJsonPath('replayed', false);

    $issueToken = $issue->json('credential.token');
    expect($issueToken)->toBeString()->toStartWith('ofi_');

    $identity = ItServiceIdentity::query()->sole();
    [, , $issueSecret] = explode('_', $issueToken, 3);
    expect($issue->json('identity_id'))->toBe($identity->id)
        ->and($issue->json('configuration_version'))->toBe(1)
        ->and($identity->configuration_version)->toBe(1)
        ->and($identity->token_hash)->toBe(hash('sha256', $issueSecret));

    $issueReceipt = ItServiceIdentityCommandReceipt::query()
        ->where('actor_user_id', $this->manager->id)
        ->where('request_uuid', $issueUuid)
        ->sole();
    identityLifecycleAssertNoSecret($issueSecret, $issueReceipt);
    $createdAuditCount = AuditLog::query()->where('action', 'it.api.identity.created')->count();

    $this->actingAs($this->manager)
        ->postJson('/it/setup/api-identities', identityLifecycleIssuePayload($this->manager, $this->site, [
            'request_uuid' => $issueUuid,
            'viewer_user_id' => $this->manager->id,
        ]))
        ->assertOk()
        ->assertJsonPath('state', 'confirmed')
        ->assertJsonPath('replayed', true)
        ->assertJsonPath('credential', null)
        ->assertJsonPath('credential_unavailable', true)
        ->assertSessionMissing('it_api_credential');

    expect(ItServiceIdentity::query()->count())->toBe(1)
        ->and(ItServiceIdentityCommandReceipt::query()->where('request_uuid', $issueUuid)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'it.api.identity.created')->count())->toBe($createdAuditCount);

    $rotateUuid = (string) Str::uuid();
    $rotate = $this->actingAs($this->manager)
        ->postJson("/it/setup/api-identities/{$identity->id}/rotate", [
            'request_uuid' => $rotateUuid,
            'viewer_user_id' => $this->manager->id,
            'expected_version' => $identity->configuration_version,
        ])
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertSessionMissing('it_api_credential')
        ->assertJsonPath('operation', 'rotate')
        ->assertJsonPath('state', 'confirmed')
        ->assertJsonPath('replayed', false);
    $rotatedToken = $rotate->json('credential.token');
    expect($rotatedToken)->toBeString()->toStartWith('ofi_')->not->toBe($issueToken);

    $identity->refresh();
    expect($identity->configuration_version)->toBe(2)
        ->and($identity->last_rotated_at)->not->toBeNull();
    [, , $rotatedSecret] = explode('_', $rotatedToken, 3);
    $rotateReceipt = ItServiceIdentityCommandReceipt::query()->where('request_uuid', $rotateUuid)->sole();
    identityLifecycleAssertNoSecret($rotatedSecret, $rotateReceipt);
    $rotatedAuditCount = AuditLog::query()->where('action', 'it.api.identity.rotated')->count();

    $ticket = ItTicket::factory()->create([
        'site_id' => $this->site->id,
        'requester_user_id' => $this->manager->id,
        'work_type' => 'incident',
        'is_sensitive' => false,
        'is_organisation_wide' => false,
    ]);
    $this->withHeaders(identityLifecycleApiHeaders($issueToken))
        ->getJson("/api/v1/it/work-items/{$ticket->id}")
        ->assertUnauthorized();
    $this->withHeaders(identityLifecycleApiHeaders($rotatedToken))
        ->getJson("/api/v1/it/work-items/{$ticket->id}")
        ->assertOk();

    $this->actingAs($this->manager)
        ->postJson("/it/setup/api-identities/{$identity->id}/rotate", [
            'request_uuid' => $rotateUuid,
            'viewer_user_id' => $this->manager->id,
            'expected_version' => 1,
        ])
        ->assertOk()
        ->assertJsonPath('replayed', true)
        ->assertJsonPath('credential', null)
        ->assertJsonPath('credential_unavailable', true);

    expect($identity->refresh()->configuration_version)->toBe(2)
        ->and(AuditLog::query()->where('action', 'it.api.identity.rotated')->count())->toBe($rotatedAuditCount);

    $this->actingAs($this->manager)
        ->postJson('/it/setup/api-identities/commands/recover', [
            'request_uuid' => $rotateUuid,
            'viewer_user_id' => $this->manager->id,
        ])
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('state', 'confirmed')
        ->assertJsonPath('operation', 'rotate')
        ->assertJsonPath('credential', null)
        ->assertJsonPath('credential_unavailable', true)
        ->assertSessionMissing('it_api_credential');
});

test('identity grants are versioned idempotent and cannot be changed after revocation', function () {
    $issueUuid = (string) Str::uuid();
    $issued = $this->actingAs($this->manager)->postJson('/it/setup/api-identities', identityLifecycleIssuePayload(
        $this->manager,
        $this->site,
        ['request_uuid' => $issueUuid, 'viewer_user_id' => $this->manager->id],
    ))->assertOk();
    $identity = ItServiceIdentity::query()->sole();
    $originalVersion = $identity->configuration_version;
    $updateUuid = (string) Str::uuid();
    $updatePayload = identityLifecycleUpdatePayload($identity, $this->site, [
        'description' => 'Rotating monitored grant boundary.',
        'request_uuid' => $updateUuid,
        'viewer_user_id' => $this->manager->id,
        'expected_version' => $originalVersion,
    ]);
    expect($updatePayload)->not->toHaveKey('actor_user_id');
    $updateAuditCount = AuditLog::query()->where('action', 'it.api.identity.updated')->count();

    $this->actingAs($this->manager)
        ->patchJson("/it/setup/api-identities/{$identity->id}", $updatePayload)
        ->assertOk()
        ->assertJsonPath('operation', 'update')
        ->assertJsonPath('state', 'confirmed')
        ->assertJsonPath('credential', null)
        ->assertJsonPath('credential_unavailable', false)
        ->assertJsonPath('replayed', false);
    expect($identity->refresh()->configuration_version)->toBe($originalVersion + 1)
        ->and($identity->description)->toBe('Rotating monitored grant boundary.');

    $this->actingAs($this->manager)
        ->patchJson("/it/setup/api-identities/{$identity->id}", $updatePayload)
        ->assertOk()
        ->assertJsonPath('replayed', true)
        ->assertJsonPath('configuration_version', $originalVersion + 1);
    expect(AuditLog::query()->where('action', 'it.api.identity.updated')->count())->toBe($updateAuditCount + 1);

    $this->actingAs($this->manager)
        ->patchJson("/it/setup/api-identities/{$identity->id}", [
            ...$updatePayload,
            'description' => 'Same receipt but different mutation.',
        ])
        ->assertConflict()
        ->assertJsonPath('code', 'command_conflict');

    $this->actingAs($this->manager)
        ->patchJson("/it/setup/api-identities/{$identity->id}", [
            ...identityLifecycleUpdatePayload($identity, $this->site),
            'request_uuid' => (string) Str::uuid(),
            'viewer_user_id' => $this->manager->id,
            'expected_version' => $originalVersion,
        ])
        ->assertConflict()
        ->assertJsonPath('code', 'identity_changed');

    $this->actingAs($this->manager)
        ->postJson("/it/setup/api-identities/{$identity->id}/revoke", [
            'request_uuid' => (string) Str::uuid(),
            'viewer_user_id' => $this->manager->id,
            'expected_version' => $originalVersion,
        ])
        ->assertConflict()
        ->assertJsonPath('code', 'identity_changed');

    $revokeUuid = (string) Str::uuid();
    $this->actingAs($this->manager)
        ->postJson("/it/setup/api-identities/{$identity->id}/revoke", [
            'request_uuid' => $revokeUuid,
            'viewer_user_id' => $this->manager->id,
            'expected_version' => $identity->refresh()->configuration_version,
        ])
        ->assertOk()
        ->assertJsonPath('operation', 'revoke')
        ->assertJsonPath('state', 'confirmed')
        ->assertJsonPath('credential', null);
    expect($identity->refresh()->revoked_at)->not->toBeNull();

    $this->actingAs($this->manager)
        ->postJson("/it/setup/api-identities/{$identity->id}/rotate", [
            'request_uuid' => (string) Str::uuid(),
            'viewer_user_id' => $this->manager->id,
            'expected_version' => $identity->configuration_version,
        ])
        ->assertConflict()
        ->assertJsonPath('code', 'identity_inactive');

    $this->actingAs($this->manager)
        ->postJson("/it/setup/api-identities/{$identity->id}/revoke", [
            'request_uuid' => (string) Str::uuid(),
            'viewer_user_id' => $this->manager->id,
            'expected_version' => $identity->configuration_version,
        ])
        ->assertConflict()
        ->assertJsonPath('code', 'identity_inactive');
});

test('a receipt binding failure rolls back rotation and the same command can retry', function () {
    $credential = app(ItServiceIdentityCredentialService::class)->create($this->manager, [
        ...identityLifecycleIssuePayload($this->manager, $this->site),
        'allowed_fields' => [
            'create' => ['title', 'description', 'category', 'priority', 'work_type', 'site_id'],
            'read' => ['category'],
            'update' => ['category', 'priority'],
        ],
    ]);
    $identity = $credential['identity'];
    $beforeHash = $identity->token_hash;
    $beforeVersion = $identity->configuration_version;
    $rotatedAuditCount = AuditLog::query()->where('action', 'it.api.identity.rotated')->count();
    $requestUuid = (string) Str::uuid();
    $failReceipt = true;
    ItServiceIdentityCommandReceipt::creating(function () use (&$failReceipt): void {
        if ($failReceipt) {
            $failReceipt = false;

            throw new RuntimeException('Injected identity command receipt failure.');
        }
    });

    $this->withoutExceptionHandling();
    expect(fn () => $this->actingAs($this->manager)
        ->postJson("/it/setup/api-identities/{$identity->id}/rotate", [
            'request_uuid' => $requestUuid,
            'viewer_user_id' => $this->manager->id,
            'expected_version' => $beforeVersion,
        ]))
        ->toThrow(RuntimeException::class, 'Injected identity command receipt failure.');

    expect($identity->refresh()->token_hash)->toBe($beforeHash)
        ->and($identity->configuration_version)->toBe($beforeVersion)
        ->and(AuditLog::query()->where('action', 'it.api.identity.rotated')->count())->toBe($rotatedAuditCount)
        ->and(ItServiceIdentityCommandReceipt::query()->where('request_uuid', $requestUuid)->exists())->toBeFalse();

    $this->actingAs($this->manager)
        ->postJson("/it/setup/api-identities/{$identity->id}/rotate", [
            'request_uuid' => $requestUuid,
            'viewer_user_id' => $this->manager->id,
            'expected_version' => $beforeVersion,
        ])
        ->assertOk()
        ->assertJsonPath('operation', 'rotate')
        ->assertJsonPath('state', 'confirmed')
        ->assertJsonPath('replayed', false)
        ->assertSessionMissing('it_api_credential');

    expect($identity->refresh()->token_hash)->not->toBe($beforeHash)
        ->and($identity->configuration_version)->toBe($beforeVersion + 1)
        ->and(AuditLog::query()->where('action', 'it.api.identity.rotated')->count())->toBe($rotatedAuditCount + 1)
        ->and(ItServiceIdentityCommandReceipt::query()->where('request_uuid', $requestUuid)->count())->toBe(1);
});

test('grant edits preserve immutable identity fields and immediately change API capabilities', function () {
    $issued = $this->actingAs($this->manager)->postJson('/it/setup/api-identities', identityLifecycleIssuePayload(
        $this->manager, $this->site, ['request_uuid' => (string) Str::uuid(), 'viewer_user_id' => $this->manager->id],
    ))->assertOk();
    $identity = ItServiceIdentity::query()->sole();
    $original = $identity->only(['public_id', 'actor_user_id', 'created_by_user_id', 'token_hash', 'configuration_version']);
    $payload = [...identityLifecycleUpdatePayload($identity, $this->site),
        'viewer_user_id' => $this->manager->id, 'expected_version' => $identity->configuration_version];
    foreach (['actor_user_id' => $this->manager->id, 'token_hash' => str_repeat('a', 64), 'public_id' => 'forged'] as $field => $value) {
        $this->actingAs($this->manager)->patchJson("/it/setup/api-identities/{$identity->id}", [
            ...$payload, 'request_uuid' => (string) Str::uuid(), $field => $value,
        ])->assertUnprocessable()->assertJsonValidationErrors('identity');
        expect($identity->refresh()->only(array_keys($original)))->toBe($original);
    }
    $ticket = ItTicket::factory()->create([
        'site_id' => $this->site->id, 'requester_user_id' => $this->manager->id,
        'work_type' => 'incident', 'is_sensitive' => false, 'is_organisation_wide' => false,
    ]);
    $this->withHeaders(identityLifecycleApiHeaders($issued->json('credential.token')))
        ->getJson("/api/v1/it/work-items/{$ticket->id}")->assertOk();
    expect($identity->refresh()->configuration_version)->toBe($original['configuration_version']);
    $this->actingAs($this->manager)->patchJson("/it/setup/api-identities/{$identity->id}", [
        ...$payload, 'request_uuid' => (string) Str::uuid(), 'abilities' => ['work:create'], 'update_fields' => [],
    ])->assertOk();
    $this->withHeaders(identityLifecycleApiHeaders($issued->json('credential.token')))
        ->getJson("/api/v1/it/work-items/{$ticket->id}")->assertForbidden();
    expect($identity->refresh()->token_hash)->toBe($original['token_hash'])
        ->and($identity->configuration_version)->toBe($original['configuration_version'] + 1);
});

test('expired identities require an explicit expiry edit before a new rotation', function () {
    $issued = $this->actingAs($this->manager)->postJson('/it/setup/api-identities', identityLifecycleIssuePayload(
        $this->manager, $this->site, ['request_uuid' => (string) Str::uuid(), 'viewer_user_id' => $this->manager->id,
            'expires_at' => now()->addMinute()->toIso8601String()],
    ))->assertOk();
    $identity = ItServiceIdentity::query()->sole();
    $oldHash = $identity->token_hash;
    $this->travel(2)->minutes();
    $this->withHeaders(identityLifecycleApiHeaders($issued->json('credential.token')))
        ->getJson('/api/v1/it/work-items/999999')->assertUnauthorized();
    $this->actingAs($this->manager)->postJson("/it/setup/api-identities/{$identity->id}/rotate", [
        'request_uuid' => (string) Str::uuid(), 'viewer_user_id' => $this->manager->id, 'expected_version' => 1,
    ])->assertConflict()->assertJsonPath('code', 'identity_inactive');
    expect($identity->refresh()->token_hash)->toBe($oldHash)->and($identity->configuration_version)->toBe(1);
    $this->actingAs($this->manager)->patchJson("/it/setup/api-identities/{$identity->id}", [
        ...identityLifecycleUpdatePayload($identity, $this->site),
        'request_uuid' => (string) Str::uuid(), 'viewer_user_id' => $this->manager->id, 'expected_version' => 1,
        'expires_at' => now()->addDay()->toIso8601String(),
    ])->assertOk()->assertJsonPath('configuration_version', 2);
    $this->actingAs($this->manager)->postJson("/it/setup/api-identities/{$identity->id}/rotate", [
        'request_uuid' => (string) Str::uuid(), 'viewer_user_id' => $this->manager->id, 'expected_version' => 2,
    ])->assertOk()->assertJsonPath('configuration_version', 3);
    expect($identity->refresh()->isActive())->toBeTrue()->and($identity->token_hash)->not->toBe($oldHash);
});

test('identity commands conceal inaccessible identities and reject stale viewers without changing state', function () {
    $issueUuid = (string) Str::uuid();
    $this->actingAs($this->manager)->postJson('/it/setup/api-identities', identityLifecycleIssuePayload(
        $this->manager,
        $this->site,
        ['request_uuid' => $issueUuid, 'viewer_user_id' => $this->manager->id],
    ))->assertOk();
    $identity = ItServiceIdentity::query()->sole();
    $baselineVersion = $identity->configuration_version;

    $otherManager = identityLifecycleManager();
    identityLifecycleAssignSite($otherManager, $this->site);
    $this->actingAs($otherManager)
        ->patchJson("/it/setup/api-identities/{$identity->id}", [
            ...identityLifecycleUpdatePayload($identity, $this->site),
            'request_uuid' => (string) Str::uuid(),
            'viewer_user_id' => $otherManager->id,
            'expected_version' => $baselineVersion,
        ])
        ->assertNotFound();

    $wrongViewer = identityLifecycleManager();
    identityLifecycleAssignSite($wrongViewer, $this->site);
    $viewerMismatch = $this->actingAs($this->manager)
        ->postJson("/it/setup/api-identities/{$identity->id}/revoke", [
            'request_uuid' => (string) Str::uuid(),
            'viewer_user_id' => $wrongViewer->id,
            'expected_version' => $baselineVersion,
        ]);
    $viewerMismatch->assertForbidden();

    $otherSite = Site::factory()->create();
    identityLifecycleAssignSite($this->manager, $otherSite);
    $this->actingAs($this->manager)
        ->postJson("/it/setup/api-identities/{$identity->id}/revoke", [
            'request_uuid' => (string) Str::uuid(),
            'viewer_user_id' => $this->manager->id,
            'expected_version' => $baselineVersion,
        ])
        ->assertNotFound();

    identityLifecycleAssignSite($this->manager, $this->site);
    $this->manager->roles()->detach();
    $this->actingAs($this->manager)
        ->postJson("/it/setup/api-identities/{$identity->id}/revoke", [
            'request_uuid' => (string) Str::uuid(),
            'viewer_user_id' => $this->manager->id,
            'expected_version' => $baselineVersion,
        ])
        ->assertForbidden();

    expect($identity->refresh()->configuration_version)->toBe($baselineVersion)
        ->and($identity->revoked_at)->toBeNull();
});

test('identity commands reject browser posts instead of redirecting or retaining a credential', function () {
    $this->actingAs($this->manager)
        ->post('/it/setup/api-identities', identityLifecycleIssuePayload($this->manager, $this->site, [
            'request_uuid' => (string) Str::uuid(),
            'viewer_user_id' => $this->manager->id,
        ]))
        ->assertBadRequest()
        ->assertSessionMissing('it_api_credential');

    expect(ItServiceIdentity::query()->count())->toBe(0);

    $this->actingAs($this->manager)
        ->withHeader('X-Inertia', 'true')
        ->postJson('/it/setup/api-identities', identityLifecycleIssuePayload($this->manager, $this->site, [
            'request_uuid' => (string) Str::uuid(),
            'viewer_user_id' => $this->manager->id,
        ]))
        ->assertBadRequest()
        ->assertSessionMissing('it_api_credential');

    expect(ItServiceIdentity::query()->count())->toBe(0);
});

test('cancelled command tombstones and recovery never releases a retained credential', function () {
    $cancelledUuid = (string) Str::uuid();
    $this->actingAs($this->manager)
        ->postJson('/it/setup/api-identities/commands/cancel', [
            'request_uuid' => $cancelledUuid,
            'viewer_user_id' => $this->manager->id,
        ])
        ->assertOk()
        ->assertJsonPath('state', 'cancelled')
        ->assertJsonPath('credential', null);

    $this->actingAs($this->manager)
        ->postJson('/it/setup/api-identities', identityLifecycleIssuePayload($this->manager, $this->site, [
            'request_uuid' => $cancelledUuid,
            'viewer_user_id' => $this->manager->id,
        ]))
        ->assertOk()
        ->assertJsonPath('state', 'cancelled')
        ->assertJsonPath('credential', null);
    expect(ItServiceIdentity::query()->count())->toBe(0)
        ->and(ItServiceIdentityCommandReceipt::query()->where('request_uuid', $cancelledUuid)->count())->toBe(1);

    $missingUuid = (string) Str::uuid();
    $this->actingAs($this->manager)
        ->postJson('/it/setup/api-identities/commands/recover', [
            'request_uuid' => $missingUuid,
            'viewer_user_id' => $this->manager->id,
        ])
        ->assertOk()
        ->assertJsonPath('state', 'not_found');

    $issueUuid = (string) Str::uuid();
    $issued = $this->actingAs($this->manager)
        ->postJson('/it/setup/api-identities', identityLifecycleIssuePayload($this->manager, $this->site, [
            'request_uuid' => $issueUuid,
            'viewer_user_id' => $this->manager->id,
        ]))
        ->assertOk();
    $token = $issued->json('credential.token');

    $recovery = $this->actingAs($this->manager)
        ->postJson('/it/setup/api-identities/commands/recover', [
            'request_uuid' => $issueUuid,
            'viewer_user_id' => $this->manager->id,
        ])
        ->assertOk()
        ->assertJsonPath('state', 'confirmed')
        ->assertJsonPath('operation', 'issue')
        ->assertJsonPath('credential', null)
        ->assertJsonPath('credential_unavailable', true)
        ->assertSessionMissing('it_api_credential');
    expect($recovery->getContent())->not->toContain($token)
        ->and(ItServiceIdentity::query()->count())->toBe(1);

    $this->actingAs($this->manager)
        ->getJson('/it/setup/api-identities')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('viewer_user_id', $this->manager->id)
        ->assertJsonPath('identities.0.id', ItServiceIdentity::query()->sole()->id)
        ->assertJsonStructure(['identities' => [[
            'id', 'configuration_version', 'last_rotated_at',
        ]]])
        ->assertDontSee($token)
        ->assertSessionMissing('it_api_credential');
});
