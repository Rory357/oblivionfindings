<?php

namespace Tests\Support;

use Closure;
use Illuminate\Database\DatabaseManager;
use PDO;
use PDOException;
use Throwable;
use WeakReference;

/**
 * Drops a test process's isolated MySQL database at shutdown without hanging.
 *
 * Laravel's teardown flushes each test application, but static references
 * (Eloquent's connection resolver, the DB facade cache) keep the last
 * application's database manager and its PDO connections open until PHP
 * destroys objects, which happens after shutdown functions run. A connection
 * with an unfinished transaction holds a metadata lock in the database, and
 * MySQL's default lock_wait_timeout is a year: on 21 Oct 2026 a shutdown DROP
 * waited 23+ minutes and blocked every queued test run on the machine.
 *
 * So the cleanup first disconnects every connection of every tracked test
 * application, then drops on a fresh server connection that waits a bounded
 * time for locks. A database it can't drop is left for the dead-PID prune in
 * Tests\TestCase on a later run.
 */
final class IsolatedDatabaseCleanup
{
    public const LOCK_WAIT_TIMEOUT_SECONDS = 30;

    /** MySQL ER_LOCK_WAIT_TIMEOUT. */
    private const LOCK_WAIT_TIMEOUT_ERROR = 1205;

    /** @var array<int, WeakReference<DatabaseManager>> */
    private array $managers = [];

    /**
     * @param  Closure(): PDO  $connect  Opens a server connection with no default database.
     * @param  Closure(string): void  $warn
     */
    public function __construct(
        private readonly string $database,
        private readonly Closure $connect,
        private readonly Closure $warn,
        private readonly int $lockWaitTimeout = self::LOCK_WAIT_TIMEOUT_SECONDS,
    ) {}

    /**
     * @param  (Closure(string): void)|null  $warn  Defaults to one line on STDERR.
     */
    public static function forServer(
        string $host,
        string $port,
        string $username,
        string $password,
        string $database,
        int $lockWaitTimeout = self::LOCK_WAIT_TIMEOUT_SECONDS,
        ?Closure $warn = null,
    ): self {
        return new self(
            $database,
            static fn (): PDO => new PDO(
                sprintf('mysql:host=%s;port=%s', $host, $port),
                $username,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            ),
            $warn ?? static function (string $message): void {
                fwrite(STDERR, $message.PHP_EOL);
            },
            $lockWaitTimeout,
        );
    }

    /**
     * Remember a test application's database manager without keeping it alive.
     */
    public function track(DatabaseManager $manager): void
    {
        $this->managers = array_filter(
            $this->managers,
            static fn (WeakReference $reference): bool => $reference->get() !== null,
        );

        $this->managers[spl_object_id($manager)] = WeakReference::create($manager);
    }

    /**
     * Close this process's connections, then drop the database.
     *
     * @return bool Whether the database was dropped.
     */
    public function run(): bool
    {
        $this->disconnectTrackedConnections();

        return $this->drop();
    }

    private function disconnectTrackedConnections(): void
    {
        foreach ($this->managers as $reference) {
            $manager = $reference->get();

            if ($manager === null) {
                continue;
            }

            foreach (array_keys($manager->getConnections()) as $name) {
                try {
                    $manager->disconnect($name);
                } catch (Throwable) {
                    // Keep closing the rest; the DROP below is still bounded.
                }
            }
        }
    }

    private function drop(): bool
    {
        try {
            $pdo = ($this->connect)();
            $pdo->exec(sprintf('SET SESSION lock_wait_timeout = %d', $this->lockWaitTimeout));
            $pdo->exec(sprintf('DROP DATABASE IF EXISTS `%s`', $this->database));

            return true;
        } catch (PDOException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) === self::LOCK_WAIT_TIMEOUT_ERROR) {
                ($this->warn)(sprintf(
                    '[tests] Left test database `%s` in place: its DROP waited %ds for a lock held by another '
                    .'connection. The dead-PID prune removes it on a later run.',
                    $this->database,
                    $this->lockWaitTimeout,
                ));
            }

            return false;
        } catch (Throwable) {
            // Best-effort teardown — never surface other cleanup failures.
            return false;
        }
    }
}
