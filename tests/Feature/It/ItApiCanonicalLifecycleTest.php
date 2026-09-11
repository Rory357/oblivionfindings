<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Services\ItServiceIdentityCredentialService;
use App\Jobs\DispatchItTicketNotifications;
use App\Models\AuditLog;
use App\Models\ItApiRequest;
use App\Models\ItEmailDelivery;
use App\Models\ItServiceIdentity;
use App\Models\ItTicket;
use App\Models\ItTicketCommandReceipt;
use App\Models\ItTicketComment;
use App\Models\ItTicketEvent;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

function apiCanonicalLifecycleUser(Site $site): User
{
    $user = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $user->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->firstOrFail()->id,
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'employee_number' => 'API-CANONICAL-'.$user->id,
        'work_email' => $user->email,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'is_active' => true,
        'start_date' => now()->subMonth()->toDateString(),
        'end_date' => null,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    return $user;
}

/** @return array{identity: ItServiceIdentity, token: string} */
function apiCanonicalLifecycleIdentity(User $actor, Site $site): array
{
    return app(ItServiceIdentityCredentialService::class)->create($actor, [
        'name' => 'Canonical lifecycle connector',
        'description' => 'Exercises canonical API intake and public conversation commands.',
        'actor_user_id' => $actor->id,
        'abilities' => ['work:create', 'work:comment'],
        'allowed_work_types' => ['incident'],
        'allowed_site_ids' => [$site->id],
        'allowed_fields' => [
            'create' => ['title', 'description', 'category', 'priority', 'work_type', 'site_id'],
            'read' => [],
        ],
        'require_signature' => false,
        'rate_limit_per_minute' => 60,
    ]);
}

/** @return array<string, string> */
function apiCanonicalLifecycleHeaders(string $token, string $key): array
{
    return [
        'Authorization' => "Bearer {$token}",
        'Accept' => 'application/json',
        'Idempotency-Key' => $key,
    ];
}

/** @return array<string, mixed> */
function apiCanonicalLifecycleCreatePayload(Site $site, string $title = 'Canonical API intake'): array
{
    return [
        'title' => $title,
        'description' => 'The API must use the same intake command as other trusted channels.',
        'category' => 'network',
        'priority' => 'high',
        'work_type' => 'incident',
        'site_id' => $site->id,
    ];
}

function apiCanonicalLifecycleCommandUuid(ItServiceIdentity $identity, ItApiRequest $apiRequest): string
{
    return Uuid::uuid5(
        Uuid::NAMESPACE_URL,
        'oblivion:it:service-api:'.$identity->id.':'.$apiRequest->id,
    )->toString();
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->canonicalSite = Site::factory()->create([
        'is_active' => true,
        'archived' => false,
    ]);
    $this->canonicalAgent = apiCanonicalLifecycleUser($this->canonicalSite);
    $this->canonicalRequester = apiCanonicalLifecycleUser($this->canonicalSite);
    $this->canonicalCredential = apiCanonicalLifecycleIdentity(
        $this->canonicalAgent,
        $this->canonicalSite,
    );
    Notification::fake();
    Bus::fake([DispatchItTicketNotifications::class]);
});

test('API create uses the canonical intake receipt, activity, priority, routing and receipt outbox once', function () {
    $key = (string) Str::uuid();
    $payload = apiCanonicalLifecycleCreatePayload($this->canonicalSite);
    $beforeTickets = ItTicket::query()->count();

    $first = $this->withHeaders(apiCanonicalLifecycleHeaders($this->canonicalCredential['token'], $key))
        ->postJson('/api/v1/it/work-items', $payload)
        ->assertCreated();
    $ticketId = $first->json('data.id');
    $ticket = ItTicket::query()->findOrFail($ticketId);
    $apiRequest = ItApiRequest::query()->where('idempotency_key', $key)->sole();
    $commandUuid = apiCanonicalLifecycleCommandUuid(
        $this->canonicalCredential['identity'],
        $apiRequest,
    );
    $receipt = ItTicketCommandReceipt::query()
        ->where('channel', 'service_api')
        ->where('operation', ItTicketCommandReceipt::CREATE_OPERATION)
        ->where('request_uuid', $commandUuid)
        ->sole();

    expect($ticket->source)->toBe('system')
        ->and($ticket->requester_user_id)->toBe($this->canonicalAgent->id)
        ->and($ticket->priority_decision)->toBeArray()
        ->and($ticket->sla_policy_snapshot)->toBeArray()
        ->and($receipt->it_ticket_id)->toBe($ticket->id)
        ->and($receipt->actor_user_id)->toBe($this->canonicalAgent->id)
        ->and($receipt->committed_at)->not->toBeNull()
        ->and($ticket->events()->where('type', 'created')->where('payload->command_receipt_id', $receipt->id)
            ->where('payload->source_channel', 'service_api')->exists())->toBeTrue()
        ->and($ticket->events()->where('type', 'priority_assessed')->where('payload->via', 'intake')->exists())->toBeTrue()
        ->and($ticket->events()->where('type', 'routing_applied')->exists())->toBeTrue()
        ->and(ItEmailDelivery::query()->where('it_ticket_id', $ticket->id)
            ->where('notification_type', 'ticket_created')->where('audience', 'receipt')
            ->where('recipient_user_id', $this->canonicalAgent->id)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'it.ticket.created')->where('auditable_id', $ticket->id)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'it.api.work_item.created')->where('auditable_id', $ticket->id)
            ->where('meta->service_identity_id', $this->canonicalCredential['identity']->id)
            ->where('meta->api_request_id', $apiRequest->id)
            ->where('meta->command_request_uuid', $commandUuid)->count())->toBe(1);

    $afterFirst = [
        'events' => ItTicketEvent::query()->count(),
        'receipts' => ItTicketCommandReceipt::query()->count(),
        'deliveries' => ItEmailDelivery::query()->count(),
        'created_audits' => AuditLog::query()->where('action', 'it.ticket.created')->count(),
    ];

    $this->withHeaders(apiCanonicalLifecycleHeaders($this->canonicalCredential['token'], $key))
        ->postJson('/api/v1/it/work-items', $payload)
        ->assertCreated()
        ->assertHeader('X-Idempotent-Replay', 'true')
        ->assertJsonPath('data.id', $ticket->id);

    expect(ItTicket::query()->count())->toBe($beforeTickets + 1)
        ->and(ItTicketEvent::query()->count())->toBe($afterFirst['events'])
        ->and(ItTicketCommandReceipt::query()->count())->toBe($afterFirst['receipts'])
        ->and(ItEmailDelivery::query()->count())->toBe($afterFirst['deliveries'])
        ->and(AuditLog::query()->where('action', 'it.ticket.created')->count())->toBe($afterFirst['created_audits'])
        ->and(ItApiRequest::query()->where('idempotency_key', $key)->sole()->execution_state)->toBe('committed');
    Bus::assertDispatchedAfterResponseTimes(DispatchItTicketNotifications::class, 1);
});

test('an outer API request audit failure rolls back canonical intake records and its outbox', function () {
    $key = (string) Str::uuid();
    $payload = apiCanonicalLifecycleCreatePayload($this->canonicalSite, 'Canonical audit rollback');
    $failRequestAudit = true;
    AuditLog::creating(function (AuditLog $log) use (&$failRequestAudit): void {
        if ($failRequestAudit && $log->action === 'it.api.request') {
            throw new RuntimeException('Synthetic canonical API audit failure');
        }
    });

    try {
        $failed = $this->withHeaders(apiCanonicalLifecycleHeaders($this->canonicalCredential['token'], $key))
            ->postJson('/api/v1/it/work-items', $payload);
    } finally {
        $failRequestAudit = false;
    }
    $failed->assertStatus(500);

    expect(ItTicket::query()->where('title', $payload['title'])->count())->toBe(0)
        ->and(ItTicketCommandReceipt::query()->where('channel', 'service_api')->count())->toBe(0)
        ->and(ItTicketEvent::query()->count())->toBe(0)
        ->and(ItEmailDelivery::query()->count())->toBe(0)
        ->and(ItApiRequest::query()->where('idempotency_key', $key)->count())->toBe(0)
        ->and(AuditLog::query()->whereIn('action', ['it.ticket.created', 'it.api.request'])->count())->toBe(0);
});

test('API public comments reject settled and merged tickets as known lifecycle failures', function (string $lifecycle): void {
    $overrides = $lifecycle === 'merged'
        ? [
            'merged_into_ticket_id' => ItTicket::factory()
                ->create(['site_id' => $this->canonicalSite->id])->id,
            'merged_at' => now(),
        ]
        : ['status' => 'resolved', 'resolved_at' => now()];
    $ticket = ItTicket::factory()->create([
        'site_id' => $this->canonicalSite->id,
        'requester_user_id' => $this->canonicalRequester->id,
        'requested_for_user_id' => $this->canonicalRequester->id,
        'assigned_to_user_id' => $this->canonicalAgent->id,
        ...$overrides,
    ]);
    $key = (string) Str::uuid();

    $this->withHeaders(apiCanonicalLifecycleHeaders($this->canonicalCredential['token'], $key))
        ->postJson("/api/v1/it/work-items/{$ticket->id}/comments", [
            'body' => 'This public comment must not be appended to a settled lifecycle.',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'comment_rejected')
        ->assertJsonMissingPath('data.id');

    expect(ItTicketComment::query()->where('ticket_id', $ticket->id)->count())->toBe(0)
        ->and(ItTicketCommandReceipt::query()->where('channel', 'service_api')->count())->toBe(0)
        ->and(ItApiRequest::query()->where('idempotency_key', $key)->sole()->response_status)->toBe(422)
        ->and(ItApiRequest::query()->where('idempotency_key', $key)->sole()->execution_state)->toBe('rolled_back');
})->with([
    'settled ticket' => ['settled'],
    'merged ticket' => ['merged'],
]);

test('API public replies use the canonical conversation and SLA command exactly once per idempotency key', function () {
    $ticket = ItTicket::factory()->create([
        'site_id' => $this->canonicalSite->id,
        'requester_user_id' => $this->canonicalRequester->id,
        'requested_for_user_id' => $this->canonicalRequester->id,
        'assigned_to_user_id' => $this->canonicalAgent->id,
        'status' => 'open',
        'workflow_state' => 'in_progress',
        'first_responded_at' => null,
    ]);
    $agentKey = (string) Str::uuid();
    $beforeVersion = $ticket->lock_version;
    $beforeAudits = AuditLog::query()->where('action', 'it.ticket.comment.added')->count();
    $first = $this->withHeaders(apiCanonicalLifecycleHeaders($this->canonicalCredential['token'], $agentKey))
        ->postJson("/api/v1/it/work-items/{$ticket->id}/comments", [
            'body' => 'An IT technician has started investigating the reported outage.',
        ])
        ->assertCreated();
    $commentId = $first->json('data.id');
    $ticket->refresh();
    $comment = ItTicketComment::query()->findOrFail($commentId);
    $agentApiRequest = ItApiRequest::query()->where('idempotency_key', $agentKey)->sole();
    $agentCommandUuid = apiCanonicalLifecycleCommandUuid(
        $this->canonicalCredential['identity'],
        $agentApiRequest,
    );
    $agentReceipt = ItTicketCommandReceipt::query()
        ->where('channel', 'service_api')
        ->where('operation', ItTicketCommandReceipt::COMMENT_OPERATION)
        ->where('request_uuid', $agentCommandUuid)
        ->sole();

    expect($comment->speaker_side)->toBe('it')
        ->and($comment->source_channel)->toBe('service_api')
        ->and($ticket->first_responded_at)->not->toBeNull()
        ->and($ticket->next_response_party)->toBe('requester')
        ->and($ticket->last_public_comment_id)->toBe($comment->id)
        ->and($ticket->last_public_speaker_side)->toBe('it')
        ->and($ticket->lock_version)->toBeGreaterThan($beforeVersion)
        ->and($agentReceipt->it_ticket_comment_id)->toBe($comment->id)
        ->and($agentReceipt->committed_ticket_version)->toBe($ticket->lock_version)
        ->and($ticket->events()->where('type', 'api_public_comment')
            ->where('payload->comment_id', $comment->id)
            ->where('payload->service_identity_id', $this->canonicalCredential['identity']->id)
            ->where('payload->command_request_uuid', $agentCommandUuid)->count())->toBe(1)
        ->and(ItEmailDelivery::query()->where('it_ticket_comment_id', $comment->id)
            ->where('notification_type', 'ticket_replied')->where('audience', 'requester')
            ->where('recipient_user_id', $this->canonicalRequester->id)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'it.ticket.comment.added')->where('auditable_id', $ticket->id)->count())->toBe($beforeAudits + 1);
    expect(AuditLog::query()->where('action', 'it.api.comment.created')->where('auditable_id', $ticket->id)
        ->where('meta->service_identity_id', $this->canonicalCredential['identity']->id)
        ->where('meta->api_request_id', $agentApiRequest->id)
        ->where('meta->command_request_uuid', $agentCommandUuid)->count())->toBe(1);

    $this->withHeaders(apiCanonicalLifecycleHeaders($this->canonicalCredential['token'], $agentKey))
        ->postJson("/api/v1/it/work-items/{$ticket->id}/comments", [
            'body' => 'An IT technician has started investigating the reported outage.',
        ])
        ->assertCreated()
        ->assertHeader('X-Idempotent-Replay', 'true')
        ->assertJsonPath('data.id', $comment->id);
    expect(ItTicketComment::query()->where('ticket_id', $ticket->id)->count())->toBe(1)
        ->and(ItEmailDelivery::query()->where('it_ticket_comment_id', $comment->id)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'it.ticket.comment.added')->where('auditable_id', $ticket->id)->count())->toBe($beforeAudits + 1);
    Bus::assertDispatchedAfterResponseTimes(DispatchItTicketNotifications::class, 1);

    // A measurable waiting pause needs the policy snapshot captured at the time it begins.
    // Legacy tickets intentionally resume without inventing a pause duration.
    $this->travelTo(CarbonImmutable::parse('2026-09-28 09:00', 'Pacific/Auckland')->utc());
    $ticket->refresh();
    $ticket->stampSlaDueDates();
    $ticket->startWaiting();
    $ticket->forceFill([
        'workflow_state' => 'waiting',
        'waiting_party' => 'requester',
        'sla_paused_minutes' => 0,
    ])->save();
    $this->travel(10)->minutes();
    $requesterCredential = apiCanonicalLifecycleIdentity($this->canonicalRequester, $this->canonicalSite);
    $requesterKey = (string) Str::uuid();
    $requester = $this->withHeaders(apiCanonicalLifecycleHeaders($requesterCredential['token'], $requesterKey))
        ->postJson("/api/v1/it/work-items/{$ticket->id}/comments", [
            'body' => 'The requester has supplied the requested current status.',
        ])
        ->assertCreated();
    $requesterComment = ItTicketComment::query()->findOrFail($requester->json('data.id'));
    $ticket->refresh();

    expect($requesterComment->speaker_side)->toBe('requester')
        ->and($requesterComment->source_channel)->toBe('service_api')
        ->and($ticket->status)->toBe('in_progress')
        ->and($ticket->workflow_state)->toBe('in_progress')
        ->and($ticket->waiting_party)->toBeNull()
        ->and($ticket->waiting_since)->toBeNull()
        ->and($ticket->sla_paused_minutes)->toBeGreaterThan(0)
        ->and($ticket->sla_policy_snapshot['pause_unit'] ?? null)->toBe('business_minutes')
        ->and($ticket->next_response_party)->toBe('it')
        ->and($ticket->last_public_comment_id)->toBe($requesterComment->id)
        ->and($ticket->first_responded_at)->not->toBeNull()
        ->and(ItEmailDelivery::query()->where('it_ticket_comment_id', $requesterComment->id)
            ->where('notification_type', 'ticket_replied')->where('audience', 'agent_side')
            ->where('recipient_user_id', $this->canonicalAgent->id)->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'it.ticket.comment.added')->where('auditable_id', $ticket->id)->count())->toBe($beforeAudits + 2);
});
