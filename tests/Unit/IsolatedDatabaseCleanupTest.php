<?php

use Illuminate\Database\Capsule\Manager as Capsule;
use Tests\Support\IsolatedDatabaseCleanup;

/*
 * Tests\TestCase drops each process's isolated MySQL database at shutdown.
 * On 21 Oct 2026 that DROP waited 23+ minutes on a metadata lock held by an
 * idle connection from the same process. The first tests use a fake server
 * connection and need no database. The last two use a tiny scratch database
 * on the test server with a short lock wait, and skip when it is unreachable.
 */

/** @return array{host: string, port: string, username: string, password: string} */
function isolatedCleanupCredentials(): array
{
    return [
        'host' => (string) env('DB_HOST', '127.0.0.1'),
        'port' => (string) env('DB_PORT', '3306'),
        'username' => (string) env('DB_USERNAME', 'root'),
        'password' => (string) env('DB_PASSWORD', ''),
    ];
}

/** A fake server connection that records statements and can fail the DROP. */
function isolatedCleanupFakeServer(?PDOException $dropFailure = null): PDO
{
    return new class($dropFailure) extends PDO
    {
        /** @var list<string> */
        public array $statements = [];

        public function __construct(private readonly ?PDOException $dropFailure) {}

        public function exec(string $statement): int|false
        {
            $this->statements[] = $statement;

            if ($this->dropFailure !== null && str_starts_with($statement, 'DROP DATABASE')) {
                throw $this->dropFailure;
            }

            return 0;
        }
    };
}

/** Lazy MySQL connections: Laravel opens no socket until a query runs. */
function isolatedCleanupLazyManager(): Capsule
{
    $capsule = new Capsule;
    $config = ['driver' => 'mysql', 'host' => '127.0.0.1', 'database' => 'unused'];
    $capsule->addConnection($config);
    $capsule->addConnection($config, 'audit');
    $capsule->getDatabaseManager()->connection();
    $capsule->getDatabaseManager()->connection('audit');

    return $capsule;
}

function isolatedCleanupLockWaitTimeout(): PDOException
{
    $exception = new PDOException('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded; try restarting transaction');
    $exception->errorInfo = ['HY000', 1205, 'Lock wait timeout exceeded; try restarting transaction'];

    return $exception;
}

it('closes every tracked connection before it opens the drop connection', function (): void {
    $capsule = isolatedCleanupLazyManager();
    $server = isolatedCleanupFakeServer();
    $openWhenDropConnected = null;

    $cleanup = new IsolatedDatabaseCleanup(
        'oblivion_findings_codex_test_4242',
        function () use ($capsule, $server, &$openWhenDropConnected): PDO {
            $openWhenDropConnected = array_keys(array_filter(
                $capsule->getDatabaseManager()->getConnections(),
                fn ($connection): bool => $connection->getRawPdo() !== null,
            ));

            return $server;
        },
        fn (string $message) => throw new RuntimeException("Unexpected warning: {$message}"),
    );
    $cleanup->track($capsule->getDatabaseManager());

    expect(array_keys($capsule->getDatabaseManager()->getConnections()))->toBe(['default', 'audit'])
        ->and($cleanup->run())->toBeTrue()
        ->and($openWhenDropConnected)->toBe([])
        ->and($server->statements)->toBe([
            'SET SESSION lock_wait_timeout = 30',
            'DROP DATABASE IF EXISTS `oblivion_findings_codex_test_4242`',
        ]);
});

it('gives up on a lock wait timeout with one warning instead of hanging', function (): void {
    $server = isolatedCleanupFakeServer(isolatedCleanupLockWaitTimeout());
    $warnings = [];

    $cleanup = new IsolatedDatabaseCleanup(
        'oblivion_findings_codex_test_4242',
        fn (): PDO => $server,
        function (string $message) use (&$warnings): void {
            $warnings[] = $message;
        },
    );

    expect($cleanup->run())->toBeFalse()
        ->and($warnings)->toBe([
            '[tests] Left test database `oblivion_findings_codex_test_4242` in place: its DROP waited 30s for a lock '
            .'held by another connection. The dead-PID prune removes it on a later run.',
        ]);
});

it('stays silent when the connect or the drop fails for another reason', function (string $failAt): void {
    $failure = new PDOException('SQLSTATE[42000]: Syntax error or access violation: 1044 Access denied');
    $failure->errorInfo = ['42000', 1044, 'Access denied'];

    $cleanup = new IsolatedDatabaseCleanup(
        'oblivion_findings_codex_test_4242',
        fn (): PDO => $failAt === 'connect' ? throw $failure : isolatedCleanupFakeServer($failure),
        fn (string $message) => throw new RuntimeException("Unexpected warning: {$message}"),
    );

    expect($cleanup->run())->toBeFalse();
})->with(['connect', 'drop']);

describe('against the test MySQL server', function (): void {
    beforeEach(function (): void {
        $credentials = isolatedCleanupCredentials();
        $this->server = null;
        // Outside the `<base>_<pid>` pattern, so no other process's prune drops it mid-test.
        $this->database = 'oblivion_findings_codex_test_cleanup_probe_'.getmypid();

        try {
            $this->server = new PDO(
                sprintf('mysql:host=%s;port=%s', $credentials['host'], $credentials['port']),
                $credentials['username'],
                $credentials['password'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
        } catch (PDOException $exception) {
            $this->markTestSkipped('The test MySQL server is not reachable: '.$exception->getMessage());
        }

        $this->server->exec(sprintf('DROP DATABASE IF EXISTS `%s`', $this->database));
        $this->server->exec(sprintf('CREATE DATABASE `%s`', $this->database));
        $this->server->exec(sprintf('CREATE TABLE `%s`.probe (id INT PRIMARY KEY) ENGINE=InnoDB', $this->database));

        $this->databaseExists = fn (): bool => $this->server
            ->query(sprintf("SHOW DATABASES LIKE '%s'", $this->database))
            ->fetchColumn() !== false;
        $this->warnings = [];
        $this->cleanup = fn (int $lockWaitTimeout): IsolatedDatabaseCleanup => IsolatedDatabaseCleanup::forServer(
            ...isolatedCleanupCredentials(),
            database: $this->database,
            lockWaitTimeout: $lockWaitTimeout,
            warn: function (string $message): void {
                $this->warnings[] = $message;
            },
        );
    });

    afterEach(function (): void {
        $this->server?->exec(sprintf('DROP DATABASE IF EXISTS `%s`', $this->database));
    });

    it('drops the database after closing a test connection that holds a metadata lock', function (): void {
        $capsule = new Capsule;
        $capsule->addConnection([
            'driver' => 'mysql',
            ...isolatedCleanupCredentials(),
            'database' => $this->database,
        ]);
        $connection = $capsule->getDatabaseManager()->connection();
        $connection->beginTransaction();

        // The open transaction keeps a shared metadata lock on `probe`, like
        // the idle connection that blocked the shutdown DROP on 21 Oct.
        expect($connection->table('probe')->count())->toBe(0);

        $cleanup = ($this->cleanup)(5);
        $cleanup->track($capsule->getDatabaseManager());

        expect($cleanup->run())->toBeTrue()
            ->and($this->warnings)->toBe([])
            ->and($connection->getRawPdo())->toBeNull()
            ->and(($this->databaseExists)())->toBeFalse();
    });

    it('stops waiting on a lock it cannot release and leaves the database for the prune', function (): void {
        // A connection the cleanup has no reference to, holding the same lock.
        $credentials = isolatedCleanupCredentials();
        $holder = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s', $credentials['host'], $credentials['port'], $this->database),
            $credentials['username'],
            $credentials['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $holder->beginTransaction();
        $holder->query('SELECT COUNT(*) FROM probe')->fetchColumn();

        $cleanup = ($this->cleanup)(1);
        $started = microtime(true);
        $dropped = $cleanup->run();
        $waited = microtime(true) - $started;

        expect($dropped)->toBeFalse()
            ->and($waited)->toBeLessThan(15.0)
            ->and($this->warnings)->toHaveCount(1)
            ->and($this->warnings[0])->toContain("`{$this->database}`")->toContain('waited 1s')
            ->and(($this->databaseExists)())->toBeTrue();

        $holder->rollBack();
        $holder = null;

        expect($cleanup->run())->toBeTrue()
            ->and(($this->databaseExists)())->toBeFalse();
    });
});
