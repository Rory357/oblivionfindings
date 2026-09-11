<?php

namespace App\Http\Middleware;

use App\Domain\It\Exceptions\ItTicketCommandConflict;
use App\Domain\It\Exceptions\ItTicketDraftException;
use App\Domain\It\Exceptions\ItTicketVersionConflict;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Private draft/history text must never enter redirect old-input sessions or debug responses. */
final class ProtectItDraftResponses
{
    public static function applies(Request $request): bool
    {
        return $request->is('it/drafts', 'it/drafts/*', 'it/tickets/*/task-candidates/validate', 'it/tickets/*/tasks/*/history', 'it/tickets/*/approval-history')
            || self::isApprovalCommand($request) || self::isControlRoomHandoff($request);
    }

    private static function isControlRoomHandoff(Request $request): bool
    {
        return $request->is('it/control-room/alerts/*/handoff', 'it/control-room/alerts/*/handoff/*');
    }

    private static function isApprovalCommand(Request $request): bool
    {
        if ($request->is('it/tickets/*/approval-commands/*', 'it/tickets/*/approval-candidates/validate')) {
            return true;
        }

        // Keep already-served forms which omit the complete command tuple on
        // their existing Inertia redirect contract. Typed proposals never flash.
        return $request->is('it/tickets/*/approvals', 'it/tickets/*/approvals/*/decide', 'it/approvals/*/decide', 'it/tickets/*/approvals/*/withdraw')
            && ($request->expectsJson() || $request->hasAny(['actor_user_id', 'request_uuid', 'expected_version']));
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! self::applies($request)) {
            return $next($request);
        }

        $request->headers->set('Accept', 'application/json');
        $response = $next($request);
        if ($response->isRedirection()) {
            $response = response()->json([
                'code' => 'session_expired', 'message' => self::isControlRoomHandoff($request)
                    ? 'Sign in again, then check the saved IT handoff.'
                    : (self::isApprovalCommand($request)
                    ? 'Sign in again, then check the saved approval command.'
                    : ($request->is('it/tickets/*/approval-history') ? 'Sign in again, then reload approval history.'
                        : ($request->is('it/tickets/*/tasks/*/history')
                            ? 'Sign in again, then reload this task’s history.' : 'Sign in again, then check this draft before continuing.'))),
            ], 401);
        }
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    public static function render(Throwable $exception, Request $request): ?JsonResponse
    {
        if (! self::applies($request)) {
            return null;
        }

        $status = match (true) {
            $exception instanceof ItTicketDraftException => $exception->status,
            $exception instanceof ItTicketCommandConflict, $exception instanceof ItTicketVersionConflict => 409,
            $exception instanceof ValidationException => 422,
            $exception instanceof AuthenticationException => 401,
            $exception instanceof TokenMismatchException => 419,
            $exception instanceof AuthorizationException => $exception->status() ?? 403,
            $exception instanceof ModelNotFoundException => 404,
            $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
            default => 500,
        };
        if (self::isControlRoomHandoff($request)) {
            return response()->json(match (true) {
                $exception instanceof ValidationException => ['code' => 'handoff_validation_failed', 'message' => 'Review the IT handoff fields.', 'errors' => $exception->errors()],
                $exception instanceof ItTicketVersionConflict => ['code' => 'stale_ticket', 'message' => 'The selected ticket changed. Refresh and review the handoff again.'],
                $exception instanceof ItTicketCommandConflict => ['code' => 'command_conflict', 'message' => 'This request reference belongs to a different handoff. Check its saved result.'],
                $status === 401 || $status === 419 => ['code' => 'session_expired', 'message' => 'Sign in again, then check the saved IT handoff.'],
                $status === 403 => ['code' => 'access_unavailable', 'message' => 'Your access to this IT handoff is no longer available.'],
                $status === 404 => ['code' => 'handoff_unavailable', 'message' => 'This IT handoff is unavailable.'],
                default => ['code' => 'handoff_outcome_unknown', 'message' => 'The IT handoff result could not be confirmed. Check the saved request before retrying.'],
            }, $status, ['Cache-Control' => 'no-store, private']);
        }
        if ($request->is('it/tickets/*/approval-history')) {
            return response()->json(match (true) {
                $exception instanceof ValidationException => ['code' => 'history_validation', 'message' => 'The approval history request could not be checked.', 'errors' => $exception->errors()],
                $status === 401 || $status === 419 => ['code' => 'session_expired', 'message' => 'Sign in again, then reload approval history.'],
                $status === 403 => ['code' => 'access_unavailable', 'message' => 'Your access to approval history is no longer available.'],
                $status === 404 => ['code' => 'history_unavailable', 'message' => 'This approval history is unavailable.'],
                $status === 409 => ['code' => 'history_changed', 'message' => 'The approval history changed. Reload the first page.'],
                default => ['code' => 'history_request_failed', 'message' => 'Approval history could not be loaded. Retry when the service is available.'],
            }, $status, ['Cache-Control' => 'no-store, private']);
        }
        if (self::isApprovalCommand($request)) {
            return response()->json(match (true) {
                $exception instanceof ValidationException => ['code' => 'approval_validation_failed', 'message' => 'Review the approval fields.', 'errors' => $exception->errors()],
                $status === 401 || $status === 419 => ['code' => 'session_expired', 'message' => 'Sign in again, then check the saved approval command.'],
                $status === 403 => ['code' => 'access_unavailable', 'message' => 'Your access to this approval is no longer available.'],
                $status === 404 => ['code' => 'approval_unavailable', 'message' => 'This approval command is unavailable.'],
                default => ['code' => 'approval_outcome_unknown', 'message' => 'The approval result could not be confirmed. Keep your proposal and check the saved request before retrying.'],
            }, $status, ['Cache-Control' => 'no-store, private']);
        }
        if ($request->is('it/tickets/*/tasks/*/history')) {
            return response()->json(match (true) {
                $exception instanceof ValidationException => ['code' => 'history_validation', 'message' => 'The history request could not be checked.', 'errors' => $exception->errors()],
                $status === 401 || $status === 419 => ['code' => 'session_expired', 'message' => 'Sign in again, then reload this task’s history.'],
                $status === 403 => ['code' => 'access_unavailable', 'message' => 'Your access to this task’s history is no longer available.'],
                $status === 404 => ['code' => 'history_unavailable', 'message' => 'This task’s history is unavailable.'],
                default => ['code' => 'history_request_failed', 'message' => 'Task history could not be loaded. Retry when the service is available.'],
            }, $status, ['Cache-Control' => 'no-store, private']);
        }
        $body = match (true) {
            $exception instanceof ItTicketDraftException => [
                'code' => $exception->errorCode, 'message' => $exception->getMessage(), ...$exception->details,
            ],
            $exception instanceof ValidationException => [
                'code' => 'draft_validation', 'message' => 'Review the draft fields.', 'errors' => $exception->errors(),
            ],
            $status === 401 || $status === 419 => ['code' => 'session_expired', 'message' => 'Sign in again, then check this draft before continuing.'],
            $status === 403 => ['code' => 'access_unavailable', 'message' => 'Your access to this draft is no longer available.'],
            $status === 404 => ['code' => 'draft_unavailable', 'message' => 'This draft is no longer available.'],
            default => ['code' => 'draft_request_failed', 'message' => 'The draft result could not be confirmed. Keep your text and check the saved draft before retrying.'],
        };

        return response()->json($body, $status, ['Cache-Control' => 'no-store, private']);
    }

    /** Suppress raw SQL bindings/exception text, including in debug environments. */
    public static function report(Throwable $exception): ?bool
    {
        if (! self::applies(request())) {
            return null;
        }
        Log::error(self::isControlRoomHandoff(request()) ? 'IT Control Room handoff failed' : (self::isApprovalCommand(request()) ? 'IT approval command failed'
            : (request()->is('it/tickets/*/approval-history') ? 'IT approval history request failed'
                : (request()->is('it/tickets/*/tasks/*/history') ? 'IT task history request failed' : 'IT draft request failed'))), [
                    'failure_id' => (string) Str::uuid(), 'exception_type' => $exception::class,
                    'source_file' => $exception->getFile(), 'source_line' => $exception->getLine(),
                ]);

        return false;
    }
}
