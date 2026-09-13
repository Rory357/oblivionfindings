<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Services\ItServiceIdentityCredentialService;
use App\Models\AuditLog;
use App\Models\ItApiRequest;
use App\Models\ItServiceIdentity;
use App\Models\ItTicket;
use App\Models\ItTicketCommandReceipt;
use App\Models\ItTicketEvent;
use App\Models\ItTicketLink;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

function apiMutationContractActor(Site $site): User
{
    $actor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $actor->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->firstOrFail()->id,
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $actor->id,
        'created_by' => $actor->id,
        'updated_by' => $actor->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'is_active' => true,
        'start_date' => now()->subDay()->toDateString(),
        'end_date' => null,
    ]);

    return $actor;
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array{identity: ItServiceIdentity, secret: string, token: string}
 */
function apiMutationContractIdentity(User $actor, Site $site, array $overrides = []): array
{
    return app(ItServiceIdentityCredentialService::class)->create($actor, [
        'name' => 'Mutation contract connector',
        'description' => 'Exercises the bounded service API mutation contracts.',
        'actor_user_id' => $actor->id,
        'abilities' => ['work:read', 'work:update', 'work:link', 'work:transition'],
        'allowed_work_types' => ['incident'],
        'allowed_site_ids' => [$site->id],
        'allowed_fields' => [
            'create' => [],
            'update' => ['category', 'subcategory', 'priority', 'impact', 'urgency'],
            'read' => [],
        ],
        'require_signature' => false,
        'rate_limit_per_minute' => 60,
        ...$overrides,
    ]);
}

/** @return array<string, string> */
function apiMutationContractHeaders(string $token, string $key): array
{
    return [
        'Authorization' => "Bearer {$token}",
        'Accept' => 'application/json',
        'Idempotency-Key' => $key,
    ];
}

/** @return array<string, string> */
function apiMutationContractRawHeaders(string $token, string $key): array
{
    return [
        'Authorization' => "Bearer {$token}",
        'Idempotency-Key' => $key,
    ];
}

function apiMutationContractTicket(Site $site, array $overrides = []): ItTicket
{
    return ItTicket::factory()->create([
        'site_id' => $site->id,
        'work_type' => 'incident',
        'status' => 'open',
        'workflow_state' => 'submitted',
        'description' => 'Private diagnostic evidence must never be returned without an explicit read grant.',
        ...$overrides,
    ]);
}

/** @return array<string, int|string> */
function apiMutationContractRelationshipPayload(ItTicket $source, ItTicket $target, string $action = 'add'): array
{
    return [
        'target_ticket_id' => $target->id,
        'source_version' => $source->fresh()->lock_version,
        'target_version' => $target->fresh()->lock_version,
        'action' => $action,
        'relationship' => 'related_ticket',
    ];
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->apiMutationSite = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->apiMutationActor = apiMutationContractActor($this->apiMutationSite);
});

test('API updates commit once, replay safely, reject conflicting or stale input, and omit private current data', function () {
    $credential = apiMutationContractIdentity($this->apiMutationActor, $this->apiMutationSite);
    $ticket = apiMutationContractTicket($this->apiMutationSite, ['category' => 'network']);
    $key = (string) Str::uuid();
    $beforeVersion = $ticket->lock_version;
    $payload = [
        'expected_version' => $beforeVersion,
        'category' => 'hardware',
    ];
    $auditIds = AuditLog::query()->orderBy('id')->pluck('id')->all();

    $first = $this->withHeaders(apiMutationContractHeaders($credential['token'], $key))
        ->patchJson("/api/v1/it/work-items/{$ticket->id}", $payload)
        ->assertOk()
        ->assertJsonPath('data.id', $ticket->id)
        ->assertJsonMissingPath('data.description')
        ->assertJsonMissingPath('data.category');
    $savedVersion = $first->json('data.lock_version');

    $this->withHeaders(apiMutationContractHeaders($credential['token'], $key))
        ->patchJson("/api/v1/it/work-items/{$ticket->id}", $payload)
        ->assertOk()
        ->assertHeader('X-Idempotent-Replay', 'true')
        ->assertJsonPath('data.lock_version', $savedVersion)
        ->assertJsonMissingPath('data.description');

    $this->withHeaders(apiMutationContractHeaders($credential['token'], $key))
        ->patchJson("/api/v1/it/work-items/{$ticket->id}", [...$payload, 'category' => 'software'])
        ->assertConflict()
        ->assertJsonPath('code', 'idempotency_conflict');

    $this->flushHeaders()->withHeaders(apiMutationContractRawHeaders($credential['token'], (string) Str::uuid()))
        ->patch("/api/v1/it/work-items/{$ticket->id}", $payload)
        ->assertConflict()
        ->assertJsonPath('code', 'stale_ticket')
        ->assertJsonMissingPath('current')
        ->assertDontSee('Private diagnostic evidence');

    expect($ticket->fresh()->category)->toBe('hardware')
        ->and($ticket->fresh()->lock_version)->toBe($savedVersion)
        ->and(ItTicketCommandReceipt::query()
            ->where('channel', 'service_api')
            ->where('operation', ItTicketCommandReceipt::UPDATE_OPERATION)
            ->where('it_ticket_id', $ticket->id)
            ->count())->toBe(1)
        ->and(AuditLog::query()
            ->whereNotIn('id', $auditIds)
            ->where('action', 'it.api.work_item.updated')
            ->where('auditable_id', $ticket->id)
            ->count())->toBe(1);
});

test('API priority updates require a recorded override reason and retain the assessed dimensions', function () {
    $credential = apiMutationContractIdentity($this->apiMutationActor, $this->apiMutationSite, [
        'allowed_fields' => ['create' => [], 'update' => ['priority'], 'read' => []],
    ]);
    $ticket = apiMutationContractTicket($this->apiMutationSite, [
        'impact' => 'individual',
        'urgency' => 'normal',
        'priority' => 'normal',
    ]);
    $version = $ticket->lock_version;

    $this->withHeaders(apiMutationContractHeaders($credential['token'], (string) Str::uuid()))
        ->patchJson("/api/v1/it/work-items/{$ticket->id}", [
            'expected_version' => $version,
            'priority' => 'urgent',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('priority_reason');

    $this->withHeaders(apiMutationContractHeaders($credential['token'], (string) Str::uuid()))
        ->patchJson("/api/v1/it/work-items/{$ticket->id}", [
            'expected_version' => $version,
            'priority' => 'urgent',
            'priority_reason' => 'The local outage now affects the whole approved service.',
        ])
        ->assertOk();

    $saved = $ticket->fresh();
    expect($saved->priority)->toBe('urgent')
        ->and($saved->impact)->toBe('individual')
        ->and($saved->urgency)->toBe('normal')
        ->and($saved->priority_decision)->toMatchArray([
            'mode' => 'override',
            'reason' => 'The local outage now affects the whole approved service.',
        ]);
});

test('API updates deny ungranted fields, concealed Sites, and absent capabilities before changing a ticket', function () {
    $credential = apiMutationContractIdentity($this->apiMutationActor, $this->apiMutationSite, [
        'allowed_fields' => ['create' => [], 'update' => ['category'], 'read' => []],
    ]);
    $ticket = apiMutationContractTicket($this->apiMutationSite, ['category' => 'network']);
    $version = $ticket->lock_version;

    $this->withHeaders(apiMutationContractHeaders($credential['token'], (string) Str::uuid()))
        ->patchJson("/api/v1/it/work-items/{$ticket->id}", [
            'expected_version' => $version,
            'priority' => 'high',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('priority');

    $hiddenSite = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $hidden = apiMutationContractTicket($hiddenSite, [
        'category' => 'network',
        'description' => 'Private counterpart diagnostics must remain concealed.',
    ]);
    $credential['identity']->update(['allowed_site_ids' => [$this->apiMutationSite->id, $hiddenSite->id]]);

    $this->withHeaders(apiMutationContractHeaders($credential['token'], (string) Str::uuid()))
        ->patchJson("/api/v1/it/work-items/{$hidden->id}", [
            'expected_version' => $hidden->lock_version,
            'category' => 'hardware',
        ])
        ->assertNotFound()
        ->assertJsonMissingPath('data')
        ->assertDontSee('Private counterpart diagnostics');

    $credential['identity']->update(['abilities' => ['work:read']]);
    $this->withHeaders(apiMutationContractHeaders($credential['token'], (string) Str::uuid()))
        ->patchJson("/api/v1/it/work-items/{$ticket->id}", [
            'expected_version' => $version,
            'category' => 'hardware',
        ])
        ->assertForbidden()
        ->assertJsonPath('code', 'ability_denied');

    expect($ticket->fresh()->lock_version)->toBe($version)
        ->and($ticket->fresh()->category)->not->toBe('hardware')
        ->and($hidden->fresh()->category)->not->toBe('hardware')
        ->and(ItTicketCommandReceipt::query()
            ->where('channel', 'service_api')
            ->where('operation', ItTicketCommandReceipt::UPDATE_OPERATION)
            ->count())->toBe(0);
});

test('API relationship commands add remove and replay through the channel receipt while current target access can be revoked', function () {
    $credential = apiMutationContractIdentity($this->apiMutationActor, $this->apiMutationSite);
    $source = apiMutationContractTicket($this->apiMutationSite);
    $target = apiMutationContractTicket($this->apiMutationSite);
    $addKey = (string) Str::uuid();
    $add = apiMutationContractRelationshipPayload($source, $target);
    $auditIds = AuditLog::query()->orderBy('id')->pluck('id')->all();

    $added = $this->withHeaders(apiMutationContractHeaders($credential['token'], $addKey))
        ->postJson("/api/v1/it/work-items/{$source->id}/relationships", $add)
        ->assertOk()
        ->assertJsonPath('data.id', $source->id)
        ->assertJsonMissingPath('data.description');
    $addedVersion = $added->json('data.lock_version');

    $this->withHeaders(apiMutationContractHeaders($credential['token'], $addKey))
        ->postJson("/api/v1/it/work-items/{$source->id}/relationships", $add)
        ->assertOk()
        ->assertHeader('X-Idempotent-Replay', 'true')
        ->assertJsonPath('data.lock_version', $addedVersion);

    $this->withHeaders(apiMutationContractHeaders($credential['token'], $addKey))
        ->postJson("/api/v1/it/work-items/{$source->id}/relationships", [...$add, 'relationship' => 'duplicate_ticket'])
        ->assertConflict()
        ->assertJsonPath('code', 'idempotency_conflict');

    expect(ItTicketLink::query()->whereIn('relationship', ItTicketLink::BROWSER_RELATIONSHIPS)->count())->toBe(2)
        ->and(ItTicketEvent::query()->where('type', 'related_work_linked')->count())->toBe(2);

    $hiddenSource = apiMutationContractTicket(
        Site::factory()->create(['is_active' => true, 'archived' => false]),
        ['description' => 'The inaccessible source must not reach relationship validation.'],
    );
    $this->withHeaders(apiMutationContractHeaders($credential['token'], (string) Str::uuid()))
        ->postJson("/api/v1/it/work-items/{$hiddenSource->id}/relationships", [
            'target_ticket_id' => 'not-a-ticket',
            'source_version' => $hiddenSource->lock_version,
            'target_version' => $target->fresh()->lock_version,
            'action' => 'add',
            'relationship' => 'related_ticket',
        ])
        ->assertNotFound()
        ->assertJsonMissingPath('data')
        ->assertDontSee('The inaccessible source');

    $removeKey = (string) Str::uuid();
    $remove = apiMutationContractRelationshipPayload($source, $target, 'remove');
    $this->withHeaders(apiMutationContractHeaders($credential['token'], $removeKey))
        ->postJson("/api/v1/it/work-items/{$source->id}/relationships", $remove)
        ->assertOk()
        ->assertJsonPath('data.id', $source->id);
    $this->withHeaders(apiMutationContractHeaders($credential['token'], $removeKey))
        ->postJson("/api/v1/it/work-items/{$source->id}/relationships", $remove)
        ->assertOk()
        ->assertHeader('X-Idempotent-Replay', 'true');

    $eventIds = ItTicketEvent::query()->whereIn('type', ['related_work_linked', 'related_work_unlinked'])
        ->orderBy('id')->pluck('id')->all();
    $target->update(['site_id' => Site::factory()->create(['is_active' => true, 'archived' => false])->id]);
    $moved = apiMutationContractRelationshipPayload($source, $target);
    $this->withHeaders(apiMutationContractHeaders($credential['token'], (string) Str::uuid()))
        ->postJson("/api/v1/it/work-items/{$source->id}/relationships", $moved)
        ->assertNotFound()
        ->assertJsonMissingPath('data');

    $credential['identity']->update(['allowed_site_ids' => []]);
    $revoked = apiMutationContractRelationshipPayload($source, $target);
    $this->withHeaders(apiMutationContractHeaders($credential['token'], (string) Str::uuid()))
        ->postJson("/api/v1/it/work-items/{$source->id}/relationships", $revoked)
        ->assertNotFound()
        ->assertJsonMissingPath('data');
    $this->withHeaders(apiMutationContractHeaders($credential['token'], $addKey))
        ->postJson("/api/v1/it/work-items/{$source->id}/relationships", [...$add, 'relationship' => 'duplicate_ticket'])
        ->assertNotFound()
        ->assertJsonMissingPath('data');

    expect(ItTicketLink::query()->whereIn('relationship', ItTicketLink::BROWSER_RELATIONSHIPS)->count())->toBe(0)
        ->and(ItTicketEvent::query()->whereIn('type', ['related_work_linked', 'related_work_unlinked'])
            ->orderBy('id')->pluck('id')->all())->toBe($eventIds)
        ->and(ItTicketCommandReceipt::query()
            ->where('channel', 'service_api')
            ->where('operation', ItTicketCommandReceipt::RELATIONSHIP_OPERATION)
            ->count())->toBe(2)
        ->and(AuditLog::query()
            ->whereNotIn('id', $auditIds)
            ->where('action', 'it.api.relationship.changed')
            ->count())->toBe(2);
});

test('API transitions require a current version and return a stale conflict without recording a transition', function () {
    $credential = apiMutationContractIdentity($this->apiMutationActor, $this->apiMutationSite);
    $ticket = apiMutationContractTicket($this->apiMutationSite);
    $staleVersion = $ticket->lock_version;

    $this->withHeaders(apiMutationContractHeaders($credential['token'], (string) Str::uuid()))
        ->postJson("/api/v1/it/work-items/{$ticket->id}/transitions", [
            'to' => 'in_progress',
            'reason' => 'The connector has started the diagnostic review.',
            'resolution_verification' => 'Retained for the governed resolution journey.',
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('expected_version');

    $ticket->update(['title' => 'Ticket changed after the transition was prepared']);
    $this->flushHeaders()->withHeaders(apiMutationContractRawHeaders($credential['token'], (string) Str::uuid()))
        ->post("/api/v1/it/work-items/{$ticket->id}/transitions", [
            'expected_version' => $staleVersion,
            'to' => 'in_progress',
            'reason' => 'The connector has started the diagnostic review.',
            'resolution_verification' => 'Retained for the governed resolution journey.',
        ])
        ->assertConflict()
        ->assertJsonPath('code', 'stale_ticket')
        ->assertJsonMissingPath('current');

    expect($ticket->fresh()->workflow_state)->toBe('submitted')
        ->and($ticket->events()
            ->where('type', 'workflow_transitioned')->count())->toBe(0);
});

test('failed API mutation publication rolls back domain changes and retries the same intent once', function (string $operation) {
    $credential = apiMutationContractIdentity($this->apiMutationActor, $this->apiMutationSite);
    $source = apiMutationContractTicket($this->apiMutationSite, ['category' => 'network']);
    $target = apiMutationContractTicket($this->apiMutationSite);
    $key = (string) Str::uuid();
    $sourceVersion = $source->lock_version;
    $targetVersion = $target->lock_version;
    $payload = $operation === 'update'
        ? ['expected_version' => $sourceVersion, 'category' => 'hardware']
        : apiMutationContractRelationshipPayload($source, $target);
    $path = '/api/v1/it/work-items/'.$source->id.($operation === 'link' ? '/relationships' : '');
    $receiptOperation = $operation === 'update' ? ItTicketCommandReceipt::UPDATE_OPERATION : ItTicketCommandReceipt::RELATIONSHIP_OPERATION;
    $failNext = true;
    Event::listen('eloquent.creating: '.ItTicketCommandReceipt::class, function (ItTicketCommandReceipt $receipt) use (&$failNext, $receiptOperation): void {
        if ($failNext && $receipt->operation === $receiptOperation) {
            $failNext = false;
            throw new RuntimeException('Synthetic command binding interruption.');
        }
    });
    $send = function () use ($credential, $key, $operation, $path, $payload) {
        $this->withHeaders(apiMutationContractHeaders($credential['token'], $key));

        return $operation === 'update' ? $this->patchJson($path, $payload) : $this->postJson($path, $payload);
    };
    $send()->assertStatus(500)->assertJsonPath('code', 'command_not_applied');
    expect($source->fresh()->lock_version)->toBe($sourceVersion)
        ->and($source->fresh()->category)->toBe('network')
        ->and($target->fresh()->lock_version)->toBe($targetVersion)
        ->and($source->events()->count())->toBe(0)
        ->and(ItTicketLink::query()->whereIn('relationship', ItTicketLink::BROWSER_RELATIONSHIPS)->count())->toBe(0)
        ->and(ItTicketCommandReceipt::query()->where('operation', $receiptOperation)->count())->toBe(0)
        ->and(ItApiRequest::query()->where('idempotency_key', $key)->sole()->execution_state)->toBe('rolled_back');
    $send()->assertOk();
    $send()->assertOk()->assertHeader('X-Idempotent-Replay', 'true');
    expect(ItTicketCommandReceipt::query()->where('operation', $receiptOperation)->count())->toBe(1)
        ->and(ItApiRequest::query()->where('idempotency_key', $key)->sole()->attempt_count)->toBe(2);
})->with(['update', 'link']);
