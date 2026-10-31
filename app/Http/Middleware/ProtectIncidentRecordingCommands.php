<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Narrow outer response boundary, including auth, CSRF and routing failures for these NEW commands. */
final class ProtectIncidentRecordingCommands
{
    public static function applies(Request $request): bool
    {
        return preg_match('~\A/incidents/[^/]+/(?:recording-attachments|evidence-windows|recording-attachment-commands|evidence-window-commands)(?:/.*)?\z~D', $request->getPathInfo()) === 1;
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!self::applies($request)) { return $next($request); }
        $request->headers->set('Accept', 'application/json');
        $response = $next($request);
        if ($response->isRedirection()) { $response = self::error(403); }
        return self::privateHeaders($response);
    }

    public static function render(Throwable $error, Request $request): ?JsonResponse
    {
        if (!self::applies($request)) { return null; }
        $status = match (true) {
            $error instanceof ValidationException, $error instanceof \JsonException, $error instanceof \InvalidArgumentException => 400,
            $error instanceof AuthenticationException, $error instanceof AuthorizationException => 403,
            $error instanceof ModelNotFoundException => 404,
            $error instanceof TokenMismatchException => 419,
            $error instanceof HttpExceptionInterface => $error->getStatusCode(),
            default => 500,
        };
        $response = self::error($status);
        if ($error instanceof HttpExceptionInterface) {
            foreach ($error->getHeaders() as $name => $value) {
                // Preserve protocol metadata only; no Location, cookies, arbitrary headers or exception prose.
                if (is_string($name) && strcasecmp($name, 'Retry-After') === 0 && is_int($value) && $value >= 0) { $value = (string) $value; }
                if (!is_string($name) || !is_string($value)) { continue; }
                if (strcasecmp($name, 'Allow') === 0 && preg_match('/\A[A-Z]+(?:[ ,]+[A-Z]+)*\z/D', $value) === 1) {
                    $response->headers->set('Allow', $value);
                }
                if (strcasecmp($name, 'Retry-After') === 0
                    && preg_match('/\A(?:[0-9]+|(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun), [0-9]{2} (?:Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec) [0-9]{4} [0-9]{2}:[0-9]{2}:[0-9]{2} GMT)\z/D', $value) === 1) {
                    $response->headers->set('Retry-After', $value);
                }
            }
        }
        return self::privateHeaders($response);
    }

    private static function error(int $status): JsonResponse
    {
        $code = match ($status) { 400 => 'invalid_request', 401,403 => 'access_unavailable', 404 => 'not_found',
            409 => 'context_changed', 419 => 'session_expired', 405 => 'method_not_allowed', default => 'request_failed' };
        // Never serialize exception messages, validation payloads, source text or submitted purpose/reason.
        return self::privateHeaders(response()->json(['code' => $code], $status));
    }

    private static function privateHeaders(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        return $response;
    }
}
