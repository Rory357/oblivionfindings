<?php

namespace App\Http\Middleware;

use App\Support\Database\LockConflictRetry;
use Closure;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\Request;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\Facades\DB;
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
 * application transaction. Saves (POST/PUT/PATCH/DELETE) still refuse at once. A page load that has already written
 * anything is never run again either (31 Oct): a row on any database connection, a queued job, mail, a
 * notification, an outgoing HTTP call or work deferred until after the response. It returns its error once.
 */
final class RetryPageReadOnLockConflict
{
    public const ATTEMPTS = 4;

    /** Events for work a second run would do again, outside the database rows counted per connection. */
    private const SIDE_EFFECTS = [JobQueueing::class, MessageSending::class, NotificationSending::class, RequestSending::class];

    private const COUNTER = self::class.'.side-effects';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethodSafe()) {
            return $next($request);
        }

        for ($attempt = 1; ; $attempt++) {
            $stopWatching = $this->watchForWrites();
            try {
                $response = $next($request);
            } finally {
                $wrote = $stopWatching();
            }
            $error = $response->exception ?? null;
            if ($attempt >= self::ATTEMPTS || $error === null || $wrote || ! LockConflictRetry::isLockConflict($error)
                || LockConflictRetry::insideCallerTransaction()) {
                return $response;
            }
            (LockConflictRetry::$pause ?? static fn (int $n) => usleep(150_000 * $n))($attempt);
        }
    }

    /**
     * Starts watching one attempt. The returned function stops watching and says whether the attempt wrote anything
     * a second run would repeat. Each connection's own "records modified" flag is cleared for the attempt and then
     * restored (kept set if it was set before or the attempt wrote).
     *
     * @return callable(): bool
     */
    private function watchForWrites(): callable
    {
        $before = [];
        foreach (DB::getConnections() as $name => $connection) {
            $before[$name] = $connection->hasModifiedRecords();
            $connection->setRecordModificationState(false);
        }
        $counter = $this->sideEffectCounter();
        $sideEffects = $counter->count;
        $deferred = $this->deferredCount();

        return function () use ($before, $counter, $sideEffects, $deferred): bool {
            $wrote = $counter->count > $sideEffects || $this->deferredCount() > $deferred;
            foreach (DB::getConnections() as $name => $connection) {
                $modified = $connection->hasModifiedRecords();
                $wrote = $wrote || $modified;
                $connection->setRecordModificationState(($before[$name] ?? false) || $modified);
            }

            return $wrote;
        };
    }

    /** One counter per application, listening once; it only ever counts up. */
    private function sideEffectCounter(): object
    {
        $app = app();
        if (! $app->bound(self::COUNTER)) {
            $counter = new class
            {
                public int $count = 0;
            };
            $app->instance(self::COUNTER, $counter);
            $app['events']->listen(self::SIDE_EFFECTS, static function () use ($counter): void {
                $counter->count++;
            });
        }

        return $app->make(self::COUNTER);
    }

    private function deferredCount(): int
    {
        return app()->bound(DeferredCallbackCollection::class) ? count(app(DeferredCallbackCollection::class)) : 0;
    }
}
