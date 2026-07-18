<?php

namespace App\Http\Middleware;

use App\Models\ItApiRequest;
use App\Models\ItServiceIdentity;
use App\Models\ItTicket;
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
    public function handle(Request $request, Closure $next): Response
    {
        $identity = $request->attributes->get('it_service_identity');
        abort_unless($identity instanceof ItServiceIdentity, 401);

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
                return $this->existingResponse($existing, $requestHash, $identity);
            }
        }

        try {
            $apiRequest = ItApiRequest::query()->create([
                'tenant_id' => $identity->tenant_id,
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

            return $this->existingResponse($concurrent, $requestHash, $identity);
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
        }
        $routeTicketId = $request->route('workItem');
        $ticketId = is_numeric($routeTicketId)
            ? (int) $routeTicketId
            : ($request->routeIs('api.v1.it.work-items.store') ? data_get($safeBody, 'data.id') : null);
        if (is_numeric($ticketId) && ! ItTicket::query()
            ->forTenant((int) $identity->tenant_id)
            ->whereKey((int) $ticketId)
            ->exists()) {
            $ticketId = null;
        }
        $apiRequest->forceFill([
            'ticket_id' => is_numeric($ticketId) ? (int) $ticketId : null,
            'response_status' => $response->getStatusCode(),
            'response_body' => $safeBody,
            'completed_at' => now(),
        ])->save();

        AuditLogger::logOrFail('it.api.request', $identity, [
            'organization_id' => $identity->tenant_id,
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
        ItApiRequest $existing,
        string $requestHash,
        ItServiceIdentity $identity,
    ): Response {
        if (! hash_equals((string) $existing->request_hash, $requestHash)) {
            return response()->json([
                'message' => 'That Idempotency-Key was already used for a different request.',
                'code' => 'idempotency_conflict',
            ], 409);
        }
        if ($existing->completed_at !== null && $existing->response_status !== null) {
            AuditLogger::log('it.api.request.replayed', $identity, [
                'organization_id' => $identity->tenant_id,
                'actor_id' => $identity->actor_user_id,
                'api_request_id' => $existing->id,
            ]);

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
}
