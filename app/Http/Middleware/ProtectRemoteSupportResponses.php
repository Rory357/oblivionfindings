<?php

namespace App\Http\Middleware;

use App\Domain\Obli\ObliSignIn;
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

/** Never send challenges, credentials or support purposes to debug/error reports. */
final class ProtectRemoteSupportResponses
{
    public static function applies(Request $request): bool
    {
        return $request->is('it/remote-support/devices/*', 'it/remote-support/sessions/*', 'api/endpoints/agent/remote/*', 'api/endpoints/agent/commands', 'api/endpoints/agent/commands/*', 'api/endpoints/agent/software', 'api/endpoints/agent/software/*', 'security-devices/devices/*/endpoint/software-policy', 'api/endpoints/agent/restarts', 'api/endpoints/agent/restarts/*', 'security-devices/devices/*/endpoint/restart-policy');
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! self::applies($request)) {
            return $next($request);
        }
        $request->headers->set('Accept', 'application/json');
        abort_unless($request->isSecure(), 400);
        $origin = ObliSignIn::issuer();
        abort_unless(strcasecmp($request->getSchemeAndHttpHost(), $origin) === 0, 400);
        $chat = preg_match('#^it/remote-support/devices/[1-9][0-9]*/obli/chat$#', $request->path());
        $limit = $chat ? 131072 : ($request->is('*/media/offer', '*/media/answer') ? 65536 : 8192);
        abort_if(strlen($request->getContent()) > $limit, 413);

        return ProtectObliResponses::headers($next($request));
    }

    public static function render(Throwable $error, Request $request): ?Response
    {
        if (! self::applies($request)) {
            return null;
        }
        $status = match (true) {
            $error instanceof ValidationException => 422,
            $error instanceof AuthenticationException => 401,
            $error instanceof AuthorizationException => 403,
            $error instanceof ModelNotFoundException => 404,
            $error instanceof TokenMismatchException => 419,
            $error instanceof HttpExceptionInterface => $error->getStatusCode(),
            default => 500,
        };

        return ProtectObliResponses::headers(response()->json(['message' => match ($status) {
            401,403 => 'Your current access does not allow this remote-support action.',
            404 => 'This remote-support request is unavailable.',
            409 => 'The computer, policy or session changed. Refresh before continuing.',
            422 => 'Review the remote-support request details.',
            429 => 'Too many remote-support requests. Try again shortly.',
            default => 'Remote support could not confirm this action.',
        }], $status));
    }

    public static function report(Throwable $error): ?bool
    {
        if (! app()->bound('request') || ! self::applies(request())) {
            return null;
        }
        Log::warning('Remote support request did not complete.', ['exception_class' => $error::class]);

        return false;
    }
}
