<?php

namespace App\Domain\Referrals\Http;

use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/** Public credential submissions never become HTML debug pages or flashed form input. */
final class ProtectDocumentRequestResponses
{
    public static function applies(Request $request): bool
    {
        return $request->is('referrals/documents/*');
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! self::applies($request)) {
            return $next($request);
        }
        if (! $request->isMethod('GET')) {
            $request->headers->set('Accept', 'application/json');
        }
        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            self::safeLog($exception, $request);
            $response = self::render($exception, $request);
        }

        return self::headers($response);
    }

    /** Laravel's routing pipeline renders downstream exceptions before outer middleware catches them. */
    public static function render(Throwable $exception, Request $request): ?JsonResponse
    {
        if (! self::applies($request)) {
            return null;
        }
        $status = match (true) {
            $exception instanceof ValidationException => 422,
            $exception instanceof TokenMismatchException => 419,
            $exception instanceof ModelNotFoundException => 404,
            $exception instanceof HttpExceptionInterface => $exception->getStatusCode(),
            $exception instanceof QueryException && in_array((int) ($exception->errorInfo[1] ?? 0), [1205, 1213, 3572], true) => 409,
            default => 503,
        };
        $message = match ($status) {
            409 => 'The request changed or already received a different document. Reopen it to check the receipt, or contact the team.',
            419 => 'This page has expired. Refresh it and enter your code again.',
            422 => 'Check the code and choose a complete PDF, JPEG or PNG document no larger than 10 MB.',
            429 => 'Too many attempts. Please wait a minute before trying again.',
            500, 503 => 'We could not confirm the result. Keep the same file and try again. Support reference: '.self::incident($request),
            default => 'This request is unavailable. Check the code or contact the team for help.',
        };
        $response = response()->json(['message' => $message, ...($exception instanceof ValidationException ? ['errors' => $exception->errors()] : [])], $status);
        self::headers($response);

        return $response;
    }

    public static function report(Throwable $exception): ?bool
    {
        if (! self::applies(request())) {
            return null;
        }
        self::safeLog($exception, request());

        return false;
    }

    private static function safeLog(Throwable $exception, Request $request): void
    {
        if ($exception instanceof ValidationException || $exception instanceof HttpExceptionInterface) {
            return;
        }
        // No URI, passcode, payload, SQL bindings or raw exception trace.
        Log::warning('Secure referral document request failed.', ['incident' => self::incident($request), 'exception_type' => $exception::class]);
    }

    private static function incident(Request $request): string
    {
        if (! $request->attributes->has('referral_document_incident')) {
            $request->attributes->set('referral_document_incident', (string) Str::uuid());
        }

        return $request->attributes->get('referral_document_incident');
    }

    private static function headers(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
