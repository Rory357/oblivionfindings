<?php

namespace App\Http\Middleware;

use App\Domain\It\Services\ItApiFieldPolicy;
use App\Domain\It\Services\ItApiWorkItemService;
use App\Http\Resources\ItApiWorkItemResource;
use App\Models\ItApiRequest;
use App\Models\ItServiceIdentity;
use App\Models\ItTicket;
use App\Models\ItTicketComment;
use App\Models\User;
use App\Services\AuditLogger;
use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class RecordItApiRequest
{
    public function __construct(
        private readonly ItApiWorkItemService $workItems,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        // This transport has a JSON-only contract, including framework validation
        // and version-conflict exceptions from callers without an Accept header.
        $request->headers->set('Accept', 'application/json');

        // The receipt and the canonical command share one commit. A lost HTTP
        // response can therefore be recovered without executing the command again.
        return DB::transaction(fn (): Response => $this->record($request, $next));
    }

    private function record(Request $request, Closure $next): Response
    {
        $identity = $request->attributes->get('it_service_identity');
        abort_unless($identity instanceof ItServiceIdentity, 401);

        // The same ordered user/identity mutex is used by credential writers.
        // Serializing on the identity also covers an absent idempotency row.
        $actorIds = array_values(array_unique(array_filter([
            (int) $identity->actor_user_id, (int) $identity->created_by_user_id,
        ])));
        User::query()->whereIn('id', $actorIds)->orderBy('id')->lockForUpdate()->get();
        $current = ItServiceIdentity::query()->whereKey($identity->id)->lockForUpdate()->first();
        if (! $current || ! $current->isActive()
            || (int) $current->actor_user_id !== (int) $identity->actor_user_id
            || (int) $current->created_by_user_id !== (int) $identity->created_by_user_id
            || ! hash_equals((string) $current->token_hash, (string) $request->attributes->get('it_authenticated_token_hash', $identity->token_hash))) {
            return response()->json(['message' => 'A valid IT service credential is required.', 'code' => 'credential_invalid'], 401);
        }
        $identity = $current;
        $request->attributes->set('it_service_identity', $identity);

        $ability = $this->routeAbility($request);
        if ($ability === null || ! $this->workItems->canUseAbility($identity, $ability)) {
            return $this->abilityDenied();
        }

        $idempotencyKey = $request->header('Idempotency-Key');
        if (in_array(strtoupper($request->method()), ['POST', 'PATCH', 'PUT', 'DELETE'], true)) {
            if (! is_string($idempotencyKey) || strlen($idempotencyKey) < 8 || strlen($idempotencyKey) > 100) {
                return response()->json([
                    'message' => 'A unique Idempotency-Key between 8 and 100 characters is required.',
                    'code' => 'idempotency_key_required',
                ], 400);
            }
        } else {
            $idempotencyKey = null;
        }

        $requestHash = hash('sha256', implode("\n", [
            strtoupper($request->method()),
            '/'.$request->path(),
            $request->getContent(),
        ]));

        $apiRequest = null;
        if ($idempotencyKey !== null) {
            $existing = ItApiRequest::query()
                ->where('service_identity_id', $identity->id)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                if (! $this->canReattempt($existing, $requestHash)) {
                    return $this->existingResponse($request, $existing, $requestHash, $identity, $ability);
                }
                $apiRequest = $existing;
            }
        }

        if ($apiRequest === null) {
            $apiRequest = ItApiRequest::query()->create([
                'service_identity_id' => $identity->id,
                'method' => strtoupper($request->method()),
                'path' => '/'.$request->path(),
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
            ]);
        }
        $apiRequest->forceFill([
            'attempt_count' => (int) $apiRequest->attempt_count + 1,
            'last_attempt_at' => now(),
        ])->save();
        $request->attributes->set('it_api_request', $apiRequest);

        $publicationConnection = DB::connection();
        $publicationPdo = $publicationConnection->getPdo();
        $publicationLevel = $publicationConnection->transactionLevel();
        try {
            [$response, $safeBody, $subjectTicketId] = DB::transaction(
                fn (): array => $this->execute($request, $next, $identity, $ability),
            );
            if ($publicationConnection->getPdo() !== $publicationPdo
                || $publicationConnection->transactionLevel() !== $publicationLevel
                || ! $publicationPdo->inTransaction()) {
                throw new \RuntimeException('The API publication transaction is no longer available.');
            }
            $executionState = 'committed';
        } catch (Throwable $exception) {
            // A database fault can invalidate the whole transaction (for
            // example an InnoDB deadlock). Do not claim a proven savepoint
            // rollback or attempt to publish a failure on that connection.
            if ($exception instanceof QueryException
                || $publicationConnection->getPdo() !== $publicationPdo
                || $publicationConnection->transactionLevel() !== $publicationLevel
                || ! $publicationPdo->inTransaction()) {
                throw $exception;
            }
            $handler = app(ExceptionHandler::class);
            $response = $exception instanceof HttpResponseException
                ? $exception->getResponse()
                : $handler->render($request, $exception);
            // Rollback completed before this outcome is recorded. Never retain
            // a framework debug response, SQL or arbitrary exception text.
            $safeBody = $response->getStatusCode() >= 500
                ? ['message' => 'The command was not applied. Retry the same request with the same Idempotency-Key.', 'code' => 'command_not_applied']
                : Arr::only((array) json_decode((string) $response->getContent(), true), ['message', 'code', 'errors']);
            $response = response()->json($safeBody, $response->getStatusCode());
            if ($response->getStatusCode() >= 500) {
                Log::error('IT API command rolled back.', [
                    'service_identity_id' => (int) $identity->id,
                    'api_request_id' => (int) $apiRequest->id,
                    'attempt_count' => (int) $apiRequest->attempt_count,
                    'error_category' => $exception instanceof HttpResponseException ? 'command_response_failure' : 'command_exception',
                ]);
            }
            $subjectTicketId = $request->route('workItem');
            $executionState = 'rolled_back';
        }

        $persistedTicketId = is_numeric($subjectTicketId)
            && ItTicket::query()->whereKey((int) $subjectTicketId)->exists()
                ? (int) $subjectTicketId
                : null;
        $apiRequest->forceFill([
            'ticket_id' => $persistedTicketId,
            'response_status' => $response->getStatusCode(),
            'response_body' => $safeBody,
            'completed_at' => now(),
            'execution_state' => $executionState,
        ])->save();

        AuditLogger::logOrFail('it.api.request', $identity, [
            'actor_id' => $identity->actor_user_id,
            'api_request_id' => $apiRequest->id,
            'method' => $apiRequest->method,
            'path' => $apiRequest->path,
            'response_status' => $apiRequest->response_status,
            'ticket_id' => $apiRequest->ticket_id,
            'execution_state' => $executionState,
            'attempt_count' => $apiRequest->attempt_count,
        ], $request);

        return $response;
    }

    private function canReattempt(ItApiRequest $existing, string $requestHash): bool
    {
        return hash_equals((string) $existing->request_hash, $requestHash)
            && $existing->execution_state === 'rolled_back'
            && $existing->completed_at !== null
            && $existing->response_status >= 500;
    }

    /** @return array{Response, array<string, mixed>, mixed} */
    private function execute(Request $request, Closure $next, ItServiceIdentity $identity, string $ability): array
    {
        $response = $next($request);
        if ($response->getStatusCode() >= 400) {
            throw new HttpResponseException($response);
        }

        $decoded = json_decode((string) $response->getContent(), true);
        // A success receipt must bind a real canonical result. A malformed
        // controller response must not commit work with an unusable receipt.
        $resultId = is_array($decoded) ? data_get($decoded, 'data.id') : null;
        if (! is_int($resultId) || $resultId < 1) {
            throw new \RuntimeException('The API command did not return its canonical result identity.');
        }
        $safeBody = $decoded;
        if ($request->routeIs('api.v1.it.work-items.comments.store')) {
            $safeBody = [
                'data' => Arr::only((array) data_get($safeBody, 'data', []), [
                    'id', 'is_internal', 'created_at',
                ]),
            ];
        } elseif ($this->routeReturnsWorkItem($request)) {
            $safeBody = [
                'data' => Arr::only((array) data_get($safeBody, 'data', []), ['id']),
            ];
        }
        $routeTicketId = $request->route('workItem');
        $subjectTicketId = is_numeric($routeTicketId)
            ? (int) $routeTicketId
            : ($request->routeIs('api.v1.it.work-items.store') ? data_get($safeBody, 'data.id') : null);
        if ($this->routeReturnsWorkItem($request) && $resultId !== $subjectTicketId) {
            throw new \RuntimeException('The API result does not match the requested work item.');
        }
        if (is_numeric($subjectTicketId)) {
            if ($request->routeIs('api.v1.it.work-items.relationships.store')
                && ! $this->workItems->authorizedRelatedTickets($identity,
                    (int) $subjectTicketId, (int) $request->input('target_ticket_id'))) {
                throw new HttpResponseException(response()->json([
                    'message' => 'The requested work item was not found.', 'code' => 'work_item_not_found',
                ], 404));
            }
            $authorized = $this->workItems->authorizedTicket(
                $identity,
                (int) $subjectTicketId,
                $ability,
                $this->routeWorksTicket($request),
            );
            if (! $authorized && $response->getStatusCode() < 400) {
                $safeBody = [
                    'message' => 'The requested work item was not found.',
                    'code' => 'work_item_not_found',
                ];
                throw new HttpResponseException(response()->json($safeBody, 404));
            }
        }
        if ($request->routeIs('api.v1.it.work-items.comments.store')
            && ! ItTicketComment::query()->whereKey($resultId)
                ->where('ticket_id', $subjectTicketId)->where('is_internal', false)->exists()) {
            throw new \RuntimeException('The API response did not identify its public comment.');
        }

        return [$response, $safeBody, $subjectTicketId];
    }

    private function existingResponse(
        Request $request,
        ItApiRequest $existing,
        string $requestHash,
        ItServiceIdentity $identity,
        string $ability,
    ): Response {
        $routeTicketId = $request->route('workItem');
        $replayTicketIds = array_values(array_filter([
            $existing->ticket_id, is_numeric($routeTicketId) ? (int) $routeTicketId : null,
            $request->routeIs('api.v1.it.work-items.relationships.store') && is_numeric($request->input('target_ticket_id'))
                ? (int) $request->input('target_ticket_id') : null,
        ]));
        // A key reused on another route can mention three records. Acquire the
        // complete set before either pair authorization or Site evidence.
        ItTicket::query()->whereIn('id', $replayTicketIds)->orderBy('id')->lockForUpdate()->get();
        if ($request->routeIs('api.v1.it.work-items.relationships.store')
            && ! $this->workItems->authorizedRelatedTickets($identity,
                (int) $routeTicketId, (int) $request->input('target_ticket_id'))) {
            return response()->json(['message' => 'The requested work item was not found.', 'code' => 'work_item_not_found'], 404);
        }
        $ticketId = $existing->ticket_id ?? (is_numeric($routeTicketId) ? (int) $routeTicketId : null);
        $ticket = $ticketId === null ? null : $this->workItems->authorizedTicket(
            $identity, (int) $ticketId, $ability, $this->routeWorksTicket($request),
        );
        if (($ticketId !== null && ! $ticket)
            || (is_numeric($routeTicketId) && (int) $routeTicketId !== (int) $ticketId
                && ! $this->workItems->authorizedTicket($identity, (int) $routeTicketId, $ability, $this->routeWorksTicket($request)))) {
            return response()->json(['message' => 'The requested work item was not found.', 'code' => 'work_item_not_found'], 404);
        }
        if (! hash_equals((string) $existing->request_hash, $requestHash)) {
            return response()->json([
                'message' => 'That Idempotency-Key was already used for a different request.',
                'code' => 'idempotency_conflict',
            ], 409);
        }
        if ($existing->completed_at !== null && $existing->response_status !== null) {
            if ($request->routeIs('api.v1.it.work-items.update')) {
                app(ItApiFieldPolicy::class)->validateUpdate($identity, $request->all());
            }

            AuditLogger::log('it.api.request.replayed', $identity, [
                'actor_id' => $identity->actor_user_id,
                'api_request_id' => $existing->id,
            ]);

            if ($ticket && $existing->response_status < 400 && $this->routeReturnsWorkItem($request)) {
                return response()->json(
                    ['data' => (new ItApiWorkItemResource($ticket))->resolve($request)],
                    $existing->response_status,
                    ['X-Idempotent-Replay' => 'true'],
                );
            }
            if ($ticket && $existing->response_status < 400
                && $request->routeIs('api.v1.it.work-items.comments.store')) {
                $commentId = data_get($existing->response_body, 'data.id');
                $comment = is_numeric($commentId)
                    ? ItTicketComment::query()
                        ->whereKey((int) $commentId)
                        ->where('ticket_id', $ticket->id)
                        ->where('is_internal', false)
                        ->first()
                    : null;
                if (! $comment) {
                    return response()->json([
                        'message' => 'The original public comment is no longer available.',
                        'code' => 'comment_not_found',
                    ], 404);
                }

                return response()->json(['data' => [
                    'id' => $comment->id,
                    'is_internal' => false,
                    'created_at' => $comment->created_at?->toIso8601String(),
                ]], $existing->response_status, ['X-Idempotent-Replay' => 'true']);
            }

            return response()->json(
                $existing->response_status >= 500 && $existing->execution_state === null
                    ? ['message' => 'The original command outcome requires reconciliation. Do not submit it with a new key.', 'code' => 'command_outcome_unknown']
                    : ($existing->response_body ?? []),
                $existing->response_status,
                ['X-Idempotent-Replay' => 'true'],
            );
        }

        return response()->json([
            'message' => 'The original request is still being processed.',
            'code' => 'idempotency_in_progress',
        ], 409);
    }

    private function routeAbility(Request $request): ?string
    {
        return match ($request->route()?->getName()) {
            'api.v1.it.work-items.store' => 'work:create',
            'api.v1.it.work-items.show' => 'work:read',
            'api.v1.it.work-items.comments.store' => 'work:comment',
            'api.v1.it.work-items.transitions.store' => 'work:transition',
            'api.v1.it.work-items.update' => 'work:update',
            'api.v1.it.work-items.relationships.store' => 'work:link',
            default => null,
        };
    }

    private function routeWorksTicket(Request $request): bool
    {
        return $request->route()?->getName() !== 'api.v1.it.work-items.show';
    }

    private function routeReturnsWorkItem(Request $request): bool
    {
        return in_array($request->route()?->getName(), [
            'api.v1.it.work-items.store',
            'api.v1.it.work-items.show',
            'api.v1.it.work-items.transitions.store',
            'api.v1.it.work-items.update',
            'api.v1.it.work-items.relationships.store',
        ], true);
    }

    private function abilityDenied(): Response
    {
        return response()->json([
            'message' => 'This service identity is not allowed to perform that operation.',
            'code' => 'ability_denied',
        ], 403);
    }
}
