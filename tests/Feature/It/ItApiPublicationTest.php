<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\It\Services\ItApiWorkItemService;
use App\Domain\It\Services\ItServiceIdentityCredentialService;
use App\Http\Middleware\RecordItApiRequest;
use App\Models\AuditLog;
use App\Models\ItApiRequest;
use App\Models\ItEmailDelivery;
use App\Models\ItServiceIdentity;
use App\Models\ItTicket;
use App\Models\ItTicketCommandReceipt;
use App\Models\ItTicketEvent;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

function apiPublicationUser(): User
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

function apiPublicationAssignSite(User $user, Site $site): void
{
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'employee_number' => 'PUBLICATION-'.$user->id,
        'work_email' => $user->email,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'is_active' => true,
        'start_date' => now()->subMonth()->toDateString(),
        'end_date' => null,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);
}

/** @return array{identity: ItServiceIdentity, token: string} */
function apiPublicationIdentity(User $actor, Site $site): array
{
    return app(ItServiceIdentityCredentialService::class)->create($actor, [
        'name' => 'Publication atomicity connector',
        'description' => 'Creates approved incident work for publication tests.',
        'actor_user_id' => $actor->id,
        'abilities' => ['work:create'],
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

/** @return array<string, mixed> */
function apiPublicationPayload(Site $site, string $title = 'Publication atomicity incident'): array
{
    return [
        'title' => $title,
        'description' => 'The transaction must publish every record together.',
        'category' => 'network',
        'priority' => 'high',
        'work_type' => 'incident',
        'site_id' => $site->id,
    ];
}

/** @return array<string, string> */
function apiPublicationHeaders(string $token, string $key): array
{
    return [
        'Authorization' => "Bearer {$token}",
        'Accept' => 'application/json',
        'Idempotency-Key' => $key,
    ];
}

/**
 * Invoke the real request recorder against a named API route while allowing a
 * controlled downstream command to return or throw after it has written.
 *
 * @param  callable(Request): Response  $command
 */
function apiPublicationControlledRequest(
    ItServiceIdentity $identity,
    string $key,
    array $payload,
    callable $command,
): Response {
    $path = '/api/v1/it/publication-atomicity';
    $request = Request::create($path, 'POST', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_IDEMPOTENCY_KEY' => $key,
    ], json_encode($payload, JSON_THROW_ON_ERROR));
    $route = (new Route(['POST'], $path, static fn () => null))
        ->name('api.v1.it.work-items.store');
    $route->bind($request);
    $request->setRouteResolver(static fn (): Route => $route);
    $request->attributes->set('it_service_identity', $identity);

    return app(RecordItApiRequest::class)->handle($request, $command);
}

/** @return array<string, mixed> */
function apiPublicationResponse(Response $response): array
{
    $body = json_decode((string) $response->getContent(), true);

    return is_array($body) ? $body : [];
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->publicationActor = apiPublicationUser();
    $this->publicationSite = Site::factory()->create();
    apiPublicationAssignSite($this->publicationActor, $this->publicationSite);
    $this->publicationCredential = apiPublicationIdentity(
        $this->publicationActor,
        $this->publicationSite,
    );
});

test('a receipt-save failure after real create rolls back every published record and a retry creates one', function () {
    $key = (string) fake()->uuid();
    $failReceiptSave = true;
    ItApiRequest::saving(function (ItApiRequest $receipt) use (&$failReceiptSave): void {
        if ($failReceiptSave && $receipt->response_status !== null) {
            throw new RuntimeException('Synthetic receipt-save failure');
        }
    });

    $payload = apiPublicationPayload($this->publicationSite);
    try {
        $failed = $this->withHeaders(apiPublicationHeaders($this->publicationCredential['token'], $key))
            ->postJson('/api/v1/it/work-items', $payload);
    } finally {
        $failReceiptSave = false;
    }
    $failed->assertStatus(500);

    expect(ItTicket::query()->count())->toBe(0)
        ->and(ItTicketCommandReceipt::query()->count())->toBe(0)
        ->and(ItEmailDelivery::query()->count())->toBe(0)
        ->and(ItTicketEvent::query()->count())->toBe(0)
        ->and(ItApiRequest::query()->count())->toBe(0)
        ->and(AuditLog::query()->whereIn('action', [
            'it.api.work_item.created',
            'it.api.request',
        ])->count())->toBe(0);

    $first = $this->withHeaders(apiPublicationHeaders($this->publicationCredential['token'], $key))
        ->postJson('/api/v1/it/work-items', $payload)
        ->assertCreated();
    $ticketId = $first->json('data.id');

    $eventCount = ItTicketEvent::query()->count();

    $this->withHeaders(apiPublicationHeaders($this->publicationCredential['token'], $key))
        ->postJson('/api/v1/it/work-items', $payload)
        ->assertCreated()
        ->assertHeader('X-Idempotent-Replay', 'true')
        ->assertJsonPath('data.id', $ticketId);

    expect(ItTicket::query()->count())->toBe(1)
        ->and($eventCount)->toBeGreaterThan(0)
        ->and(ItApiRequest::query()->count())->toBe(1)
        ->and(ItApiRequest::query()->sole()->execution_state)->toBe('committed')
        ->and(ItApiRequest::query()->sole()->attempt_count)->toBe(1);

    expect(ItTicketEvent::query()->count())->toBe($eventCount);
});

test('an it api request audit failure after real create rolls back every published record and the same key can create once', function () {
    $key = (string) fake()->uuid();
    $failRequestAudit = true;
    AuditLog::creating(function (AuditLog $log) use (&$failRequestAudit): void {
        if ($failRequestAudit && $log->action === 'it.api.request') {
            throw new RuntimeException('Synthetic API request audit failure');
        }
    });

    $payload = apiPublicationPayload($this->publicationSite, 'Audit publication failure');
    try {
        $failed = $this->withHeaders(apiPublicationHeaders($this->publicationCredential['token'], $key))
            ->postJson('/api/v1/it/work-items', $payload);
    } finally {
        $failRequestAudit = false;
    }
    $failed->assertStatus(500);

    expect(ItTicket::query()->count())->toBe(0)
        ->and(ItTicketCommandReceipt::query()->count())->toBe(0)
        ->and(ItEmailDelivery::query()->count())->toBe(0)
        ->and(ItTicketEvent::query()->count())->toBe(0)
        ->and(ItApiRequest::query()->count())->toBe(0)
        ->and(AuditLog::query()->whereIn('action', [
            'it.api.work_item.created',
            'it.api.request',
        ])->count())->toBe(0);

    $this->withHeaders(apiPublicationHeaders($this->publicationCredential['token'], $key))
        ->postJson('/api/v1/it/work-items', $payload)
        ->assertCreated();

    expect(ItTicket::query()->count())->toBe(1)
        ->and(ItTicketEvent::query()->count())->toBeGreaterThan(0)
        ->and(ItApiRequest::query()->count())->toBe(1)
        ->and(ItApiRequest::query()->sole()->execution_state)->toBe('committed')
        ->and(ItApiRequest::query()->sole()->attempt_count)->toBe(1);
});

test('a returned or thrown downstream five hundred rolls back its savepoint and only retries its rolled-back receipt', function (string $failure): void {
    Log::spy();
    $key = (string) fake()->uuid();
    $mode = $failure;
    $payload = apiPublicationPayload($this->publicationSite, 'Controlled command failure');
    $command = function (Request $request) use (&$mode, $payload): Response {
        $ticket = app(ItApiWorkItemService::class)->create(
            $request->attributes->get('it_service_identity'),
            $payload,
            $request->attributes->get('it_api_request'),
        );
        if ($mode === 'throw') {
            throw new RuntimeException('Synthetic downstream failure must not be published');
        }
        if ($mode === 'return') {
            return response()->json([
                'message' => 'Synthetic downstream failure must not be published',
            ], 500);
        }

        return response()->json(['data' => ['id' => $ticket->id]], 201);
    };

    $first = apiPublicationControlledRequest(
        $this->publicationCredential['identity'],
        $key,
        $payload,
        $command,
    );
    $body = apiPublicationResponse($first);
    expect($first->getStatusCode())->toBe(500)
        ->and($body['code'] ?? null)->toBe('command_not_applied')
        ->and((string) ($body['message'] ?? ''))->not->toContain('Synthetic downstream failure')
        ->and(ItTicket::query()->count())->toBe(0)
        ->and(ItTicketEvent::query()->count())->toBe(0)
        ->and(ItApiRequest::query()->count())->toBe(1);

    $receipt = ItApiRequest::query()->sole();
    Log::shouldHaveReceived('error')->once()->with('IT API command rolled back.', [
        'service_identity_id' => (int) $this->publicationCredential['identity']->id,
        'api_request_id' => (int) $receipt->id,
        'attempt_count' => 1,
        'error_category' => $failure === 'return' ? 'command_response_failure' : 'command_exception',
    ]);
    expect($receipt->execution_state)->toBe('rolled_back')
        ->and($receipt->response_status)->toBe(500)
        ->and($receipt->ticket_id)->toBeNull()
        ->and($receipt->attempt_count)->toBe(1)
        ->and($receipt->last_attempt_at)->not->toBeNull();

    $mode = 'success';
    $retry = apiPublicationControlledRequest(
        $this->publicationCredential['identity'],
        $key,
        $payload,
        $command,
    );
    expect($retry->getStatusCode())->toBe(201)
        ->and(ItTicket::query()->count())->toBe(1)
        ->and(ItApiRequest::query()->count())->toBe(1);

    $eventCount = ItTicketEvent::query()->count();
    expect($eventCount)->toBeGreaterThan(0);

    $receipt->refresh();
    expect($receipt->execution_state)->toBe('committed')
        ->and($receipt->attempt_count)->toBe(2)
        ->and($receipt->ticket_id)->toBeInt();

    $replay = apiPublicationControlledRequest(
        $this->publicationCredential['identity'],
        $key,
        $payload,
        $command,
    );
    expect($replay->getStatusCode())->toBe(201)
        ->and($replay->headers->get('X-Idempotent-Replay'))->toBe('true')
        ->and(ItTicket::query()->count())->toBe(1)
        ->and(ItTicketEvent::query()->count())->toBe($eventCount)
        ->and(ItApiRequest::query()->sole()->attempt_count)->toBe(2);
})->with([
    'returned five hundred response' => ['return'],
    'thrown five hundred response' => ['throw'],
]);

test('a malformed success cannot commit a canonical ticket without a usable API result identity', function (string $invalid): void {
    $key = (string) fake()->uuid();
    $payload = apiPublicationPayload($this->publicationSite);
    $response = apiPublicationControlledRequest($this->publicationCredential['identity'], $key, $payload,
        function (Request $request) use ($payload, $invalid): Response {
            $ticket = app(ItApiWorkItemService::class)->create(
                $request->attributes->get('it_service_identity'), $payload,
                $request->attributes->get('it_api_request'),
            );

            return response()->json(['data' => $invalid === 'missing' ? [] : ['id' => (string) $ticket->id]], 201);
        });
    expect($response->getStatusCode())->toBe(500)
        ->and(apiPublicationResponse($response)['code'])->toBe('command_not_applied')
        ->and(ItTicket::query()->count())->toBe(0)
        ->and(ItTicketCommandReceipt::query()->count())->toBe(0)
        ->and(ItEmailDelivery::query()->count())->toBe(0)
        ->and(ItApiRequest::query()->sole()->execution_state)->toBe('rolled_back');
})->with(['missing', 'string']);

test('legacy uncertain receipts never execute again and changed payloads cannot reuse a rolled-back key', function (): void {
    $payload = apiPublicationPayload($this->publicationSite);
    $key = (string) fake()->uuid();
    $identity = $this->publicationCredential['identity'];
    $failure = static fn (): Response => response()->json(['message' => 'Synthetic failure'], 500);
    apiPublicationControlledRequest($identity, $key, $payload, $failure);
    $receipt = ItApiRequest::query()->sole();
    $mustNotRun = static function (): never {
        throw new LogicException('Legacy or conflicting intent was executed');
    };

    $conflict = apiPublicationControlledRequest($identity, $key, [...$payload, 'title' => 'Changed input'], $mustNotRun);
    expect($conflict->getStatusCode())->toBe(409)
        ->and(apiPublicationResponse($conflict)['code'])->toBe('idempotency_conflict')
        ->and($receipt->refresh()->attempt_count)->toBe(1);

    $receipt->update(['execution_state' => null]);
    $legacyFailure = apiPublicationControlledRequest($identity, $key, $payload, $mustNotRun);
    expect($legacyFailure->getStatusCode())->toBe(500)
        ->and($legacyFailure->headers->get('X-Idempotent-Replay'))->toBe('true')
        ->and(apiPublicationResponse($legacyFailure)['code'])->toBe('command_outcome_unknown');
    $receipt->update(['completed_at' => null, 'response_status' => null]);
    $unknown = apiPublicationControlledRequest($identity, $key, $payload, $mustNotRun);
    expect($unknown->getStatusCode())->toBe(409)
        ->and(apiPublicationResponse($unknown)['code'])->toBe('idempotency_in_progress')
        ->and($receipt->refresh()->attempt_count)->toBe(1)
        ->and(ItTicket::query()->count())->toBe(0);
});

test('revoked identity and a changed authenticated token cannot retry a proven rolled-back command', function (): void {
    $payload = apiPublicationPayload($this->publicationSite);
    $key = (string) fake()->uuid();
    $identity = $this->publicationCredential['identity'];
    $failure = static fn (): Response => response()->json(['message' => 'Synthetic failure'], 500);
    apiPublicationControlledRequest($identity, $key, $payload, $failure);
    $mustNotRun = static function (): never {
        throw new LogicException('An unauthorized intent was executed');
    };
    $identity->update(['revoked_at' => now()]);
    $revoked = apiPublicationControlledRequest($identity, $key, $payload, $mustNotRun);
    expect($revoked->getStatusCode())->toBe(401)
        ->and(apiPublicationResponse($revoked)['code'])->toBe('credential_invalid')
        ->and(ItApiRequest::query()->sole()->attempt_count)->toBe(1);

    $identity->update(['revoked_at' => null]);
    $stale = clone $identity;
    $identity->update(['token_hash' => hash('sha256', 'different synthetic secret')]);
    $rotated = apiPublicationControlledRequest($stale, $key, $payload, $mustNotRun);
    expect($rotated->getStatusCode())->toBe(401)
        ->and(ItApiRequest::query()->sole()->attempt_count)->toBe(1)
        ->and(ItTicket::query()->count())->toBe(0);
});
