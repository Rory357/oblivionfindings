<?php

namespace App\Http\Middleware;

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

/** Contain private commercial/vault values before validation redirects and exception reporting. */
final class ProtectVendorVaultResponses
{
    public static function applies(Request $request): bool
    {
        return $request->is('vendors', 'vendors/*', 'vendor-agreements/*', 'sites/*/credentials', 'sites/*/credentials/*');
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! self::applies($request)) return $next($request);
        \Inertia\Inertia::encryptHistory();
        $json = ! $request->isMethod('GET') || $request->expectsJson();
        if ($json) $request->headers->set('Accept', 'application/json');
        $response = $next($request);
        if ($json && $response->isRedirection()) {
            $response = response()->json(['message' => 'Sign in again, then reopen the record and check its saved state.'], 401);
        }
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        return $response;
    }

    public static function render(Throwable $exception, Request $request): ?JsonResponse
    {
        if (! self::applies($request)) return null;
        $status = match (true) {
            $exception instanceof ValidationException => 422,
            $exception instanceof AuthenticationException => 401,
            $exception instanceof TokenMismatchException => 419,
            $exception instanceof AuthorizationException => $exception->status() ?? 403,
            $exception instanceof ModelNotFoundException => 404,
            $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
            default => 500,
        };
        return response()->json(match (true) {
            $exception instanceof ValidationException => ['message' => 'Review the record fields.', 'errors' => $exception->errors()],
            $status === 401 || $status === 419 => ['message' => 'Your session expired. Sign in and reopen this record.'],
            $status === 403 || $status === 404 => ['message' => 'This record is unavailable with your current access.'],
            $status === 409 => ['message' => 'The record changed or this action is unavailable. Refresh and review the current record.'],
            $status === 429 => ['message' => 'Too many attempts. Wait before trying again.'],
            $status === 503 => ['message' => 'This feature is awaiting its reviewed database setup.'],
            default => ['message' => 'The result could not be confirmed. Check the saved record before retrying.'],
        }, $status, ['Cache-Control' => 'no-store, private', 'X-Content-Type-Options' => 'nosniff']);
    }

    public static function report(Throwable $exception): ?bool
    {
        if (! self::applies(request())) return null;
        Log::error('Protected vendor or vault operation failed', [
            'failure_id' => (string) Str::uuid(), 'exception_type' => $exception::class,
            'source_file' => $exception->getFile(), 'source_line' => $exception->getLine(),
        ]);
        return false;
    }
}
