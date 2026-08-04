<?php

namespace App\Http\Middleware;

use App\Domain\It\Services\ItApiWorkItemService;
use App\Http\Resources\ItApiWorkItemResource;
use App\Models\ItApiRequest;
use App\Models\ItServiceIdentity;
use App\Models\ItTicket;
use App\Models\ItTicketComment;
use App\Services\AuditLogger;
use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class RecordItApiRequest
{
    public function __construct(
        private readonly ItApiWorkItemService $workItems,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $identity = $request->attributes->get('it_service_identity');
        abort_unless($identity instanceof ItServiceIdentity, 401);

        $ability = $this->routeAbility($request);
        if ($ability === null || ! $this->workItems->canUseAbility($identity, $ability)) {
            return $this->abilityDenied();
        }

        $idempotencyKey = $request->header('Idempotency-Key');
        if ($request->isMethod('post')) {
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

        if ($idempotencyKey !== null) {
            $existing = ItApiRequest::query()
                ->where('service_identity_id', $identity->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if ($existing) {
                return $this->existingResponse($request, $existing, $requestHash, $identity, $ability);
            }
        }

        try {
            $apiRequest = ItApiRequest::query()->create([
                'service_identity_id' => $identity->id,
                'method' => strtoupper($request->method()),
                'path' => '/'.$request->path(),
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
            ]);
        } catch (QueryException $exception) {
            $concurrent = $idempotencyKey === null ? null : ItApiRequest::query()
                ->where('service_identity_id', $identity->id)
                ->where('idempotency_key', $idempotencyKey)
                ->first();
            if (! $concurrent) {
                throw $exception;
            }

            return $this->existingResponse($request, $concurrent, $requestHash, $identity, $ability);
        }
        $request->attributes->set('it_api_request', $apiRequest);

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $handler = app(ExceptionHandler::class);
            $handler->report($exception);
            $response = $handler->render($request, $exception);
        }

        $decoded = json_decode((string) $response->getContent(), true);
        $safeBody = is_array($decoded) ? $decoded : ['message' => 'Request completed.'];
        if ($response->getStatusCode() >= 400) {
            $safeBody = Arr::only($safeBody, ['message', 'code', 'errors']);
        } elseif ($request->routeIs('api.v1.it.work-items.comments.store')) {
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
        if (is_numeric($subjectTicketId)) {
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
                $response = response()->json($safeBody, 404);
            }
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
        ])->save();

        AuditLogger::logOrFail('it.api.request', $identity, [
            'actor_id' => $identity->actor_user_id,
            'api_request_id' => $apiRequest->id,
            'method' => $apiRequest->method,
            'path' => $apiRequest->path,
            'response_status' => $apiRequest->response_status,
            'ticket_id' => $apiRequest->ticket_id,
        ], $request);

        return $response;
    }

    private function existingResponse(
        Request $request,
        ItApiRequest $existing,
        string $requestHash,
        ItServiceIdentity $identity,
        string $ability,
    ): Response {
        if (! hash_equals((string) $existing->request_hash, $requestHash)) {
            return response()->json([
                'message' => 'That Idempotency-Key was already used for a different request.',
                'code' => 'idempotency_conflict',
            ], 409);
        }
        if ($existing->completed_at !== null && $existing->response_status !== null) {
            $routeTicketId = $request->route('workItem');
            $ticketId = $existing->ticket_id
                ?? (is_numeric($routeTicketId) ? (int) $routeTicketId : null);
            $ticket = $ticketId === null
                ? null
                : $this->workItems->authorizedTicket(
                    $identity,
                    (int) $ticketId,
                    $ability,
                    $this->routeWorksTicket($request),
                );

            if ($ticketId !== null && ! $ticket) {
                return response()->json([
                    'message' => 'The requested work item was not found.',
                    'code' => 'work_item_not_found',
                ], 404);
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
                $existing->response_body ?? [],
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
