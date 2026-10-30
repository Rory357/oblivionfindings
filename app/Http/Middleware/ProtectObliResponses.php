<?php

namespace App\Http\Middleware;

use App\Domain\It\Exceptions\ItTicketCommandConflict;
use App\Domain\Obli\ObliSignIn;
use App\Domain\SecurityDevices\Endpoints\Services\EndpointRemoteSupportSettings;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Native tokens and private support text must not reach debug/error payloads or reports. */
final class ProtectObliResponses
{
    public static function applies(Request $request): bool
    {
        return $request->is('api/obli/*', 'api/endpoints/agent/obli/ticket-computer-context', 'obli/authorize', 'settings/obli', 'settings/obli/*');
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! self::applies($request)) {
            return $next($request);
        }
        if (! $request->is('settings/obli', 'settings/obli/*')) {
            abort_unless(app(EndpointRemoteSupportSettings::class)->obliHelperEnabled(), 404);
        }
        abort_unless($request->isSecure(), 400);
        if (! $request->is('settings/obli', 'settings/obli/*')) {
            abort_unless(strcasecmp($request->getSchemeAndHttpHost(), ObliSignIn::issuer()) === 0, 400);
        }
        abort_if(strlen($request->getContent()) > 131072, 413);
        $response = $next($request);

        return self::headers($response);
    }

    public static function headers(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    public static function render(Throwable $error, Request $request): ?Response
    {
        if (! self::applies($request)) {
            return null;
        }
        // Let Laravel preserve the intended native authorization URL through the
        // normal browser login flow. API requests must never become HTML redirects.
        if ($error instanceof AuthenticationException && $request->is('obli/authorize', 'settings/obli') && $request->isMethod('GET')) {
            return null;
        }
        $status = match (true) {
            $error instanceof ValidationException => 422,
            $error instanceof AuthenticationException => 401,
            $error instanceof AuthorizationException => 403,
            $error instanceof ModelNotFoundException => 404,
            $error instanceof TokenMismatchException => 419,
            $error instanceof ItTicketCommandConflict => 409,
            $error instanceof HttpExceptionInterface => $error->getStatusCode(),
            default => 500,
        };

        return self::headers(response()->json(['message' => match ($status) {
            401, 403 => 'Sign in again or ask your administrator to check your staff access.',
            404 => 'This Obli service or request is unavailable.',
            409 => 'The settings or request details changed. Reload before trying again.',
            410 => 'This private reply has expired.',
            503 => 'AI chat is not available. Ask your administrator to check Obli settings.',
            422 => 'Review the request details before trying again.',
            429 => 'The request limit or configured AI budget has been reached. Try later or contact your administrator.',
            default => 'The request could not be confirmed. Keep your draft and try again.',
        }], $status));
    }

    public static function report(Throwable $error): ?bool
    {
        if (! app()->bound('request') || ! self::applies(request())) {
            return null;
        }
        Log::warning('Obli request did not complete.', ['exception_class' => $error::class]);

        return false;
    }
}
