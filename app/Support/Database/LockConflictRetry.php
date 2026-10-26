<?php

namespace App\Support\Database;

use Illuminate\Database\DeadlockException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Page reads take no-wait shared locks on the viewer's user row and their permission evidence, so they never queue
 * behind a permission change. A save in progress that holds one of those rows makes the read fail at once (MySQL
 * 3572). A page must not show "500 Server Error" for that: the whole read is tried again a moment later, as the H&S
 * register already does. Only a read that owns its transaction is retried; inside a caller's transaction the caller
 * decides.
 */
final class LockConflictRetry
{
    /** Lock wait timeout, deadlock, no-wait lock not available. */
    public const CODES = [1205, 1213, 3572];

    /** @var (callable(int): void)|null Test hook: replaces the pause between attempts. */
    public static $pause = null;

    /**
     * @template T
     *
     * @param  callable(): T  $read
     * @return T
     */
    public static function read(callable $read, int $attempts = 3): mixed
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $read();
            } catch (Throwable $error) {
                if ($attempt >= $attempts || ! self::isLockConflict($error) || self::insideCallerTransaction()) {
                    throw $error;
                }
                (self::$pause ?? static fn (int $n) => usleep(100_000 * $n))($attempt);
            }
        }
    }

    /**
     * read() for a page: only while serving a routed GET or HEAD request. Commands and console calls run once, so a
     * no-wait clash on a save stays a fast refusal there.
     *
     * @template T
     *
     * @param  callable(): T  $read
     * @return T
     */
    public static function pageRead(callable $read): mixed
    {
        $request = app()->bound('request') ? app('request') : null;
        $page = $request instanceof \Illuminate\Http\Request && $request->route() !== null && $request->isMethodSafe();

        return $page ? self::read($read) : $read();
    }

    public static function isLockConflict(Throwable $error): bool
    {
        if ($error instanceof DeadlockException) {
            return true;
        }

        return $error instanceof QueryException && in_array((int) ($error->errorInfo[1] ?? 0), self::CODES, true);
    }

    /** True inside an application transaction (the test suite's wrapping transaction is not one). */
    private static function insideCallerTransaction(): bool
    {
        return DB::transactionLevel() > 0 && app('db.transactions')->callbackApplicableTransactions()->isNotEmpty();
    }
}
