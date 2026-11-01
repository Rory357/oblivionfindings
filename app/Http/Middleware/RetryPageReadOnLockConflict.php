<?php

namespace App\Http\Middleware;

use App\Support\Database\LockConflictRetry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A page load (GET or HEAD) that meets a save holding one of the rows its proof locks is loaded again a moment later,
 * instead of showing "500 Server Error".
 *
 * Page reads lock the viewer's user row and permission evidence with FOR SHARE NOWAIT, so they never queue behind a
 * permission change. Any save that briefly holds the viewer's user row (for example Chat's availability setting)
 * makes such a read fail at once with MySQL 3572. HealthSafetyReadContext and the incident register already retry
 * their own read; many H&S reads (injuries, worker participation, Site hazards) locked the same row with no retry,
 * which gave the admin three 500s on the .com on 30 Oct. This retries the whole page instead: the proof and its
 * locks are unchanged, the page simply runs again from the start, so nothing is cached or reused between attempts.
 *
 * Only safe methods are retried, and only when the failure is a lock conflict (1205, 1213, 3572) outside any
 * application transaction. Saves (POST/PUT/PATCH/DELETE) still refuse at once.
 */
final class RetryPageReadOnLockConflict
{
    public const ATTEMPTS = 4;

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethodSafe()) {
            return $next($request);
        }

        for ($attempt = 1; ; $attempt++) {
            $response = $next($request);
            $error = $response->exception ?? null;
            if ($attempt >= self::ATTEMPTS || $error === null || ! LockConflictRetry::isLockConflict($error)
                || LockConflictRetry::insideCallerTransaction()) {
                return $response;
            }
            (LockConflictRetry::$pause ?? static fn (int $n) => usleep(150_000 * $n))($attempt);
        }
    }
}
