<?php

namespace App\Http\Middleware;

use App\Support\Database\LockConflictRetry;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Outside RetryPageReadOnLockConflict: a page load (GET/HEAD) whose quick retries still met a lock clash (1205, 1213,
 * 3572) waits a little longer and loads twice more, then shows a calm "This page is busy" page (503, Retry-After)
 * instead of "500 Server Error". A scheduled job can hold the rows a page reads for several seconds (the .com, 1 Nov:
 * CheckControlRoomSlaBreaches held a safety review's linked alerts for ~8 s). Nothing is cached between attempts and the
 * page's proof and locks are unchanged. Saves still refuse at once.
 */
final class CalmPageOnLockConflict
{
    public const EXTRA_ATTEMPTS = 2;

    /** Test hook: replaces the pause between the extra attempts. */
    public static ?\Closure $pause = null;

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethodSafe()) {
            return $next($request);
        }

        $response = $next($request);
        for ($extra = 1; $extra <= self::EXTRA_ATTEMPTS && $this->lockClash($response); $extra++) {
            (self::$pause ?? static fn (int $n) => usleep(1_000_000 * $n))($extra);
            $response = $next($request);
        }
        if (! $this->lockClash($response)) {
            return $response;
        }

        report($response->exception);
        $busy = $request->header('X-Inertia') || ! $request->expectsJson()
            ? Inertia::render('errors/page-busy', ['path' => '/'.ltrim($request->path(), '/').($request->getQueryString() ? '?'.$request->getQueryString() : '')])
                ->toResponse($request)
            : response()->json(['message' => 'This page is busy right now. Nothing has been changed. Try again in a moment.'], 503);
        $busy->setStatusCode(503);
        $busy->headers->set('Retry-After', '2');

        return $busy;
    }

    private function lockClash(Response $response): bool
    {
        $error = $response->exception ?? null;

        return $error !== null && LockConflictRetry::isLockConflict($error) && ! LockConflictRetry::insideCallerTransaction();
    }
}
