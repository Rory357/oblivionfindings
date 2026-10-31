<?php

namespace Tests;

use App\Http\Middleware\HandleInertiaRequests;
use App\Support\SecurityPolicy;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionCommitted;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Arr;
use PDO;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Support\CommittedDatabaseTestCase;
use Tests\Support\CommittedFixtureCleanup;
use Tests\Support\FrozenClockTimezone;
use Tests\Support\IsolatedDatabaseCleanup;
use Tests\Support\KeptTestDatabase;
use Tests\Support\SeedReplay;

abstract class TestCase extends BaseTestCase
{
    /** phpunit.xml's DB_DATABASE: only runs on it keep their database. */
    private const KEPT_DATABASE_BASE = 'oblivion_findings_codex_test';

    protected static ?string $testDatabaseBaseName = null;

    protected static ?string $isolatedMysqlDatabase = null;

    protected static bool $isolatedMysqlPrepared = false;

    protected static ?IsolatedDatabaseCleanup $isolatedDatabaseCleanup = null;

    protected static bool $mysqlClientPathConfigured = false;

    protected static bool $isolatedMysqlSchemaLoaded = false;

    protected static bool $pendingMigrationsApplied = false;

    /** This process's kept database slot, when the run keeps its database (see keepsTestingDatabase()). */
    protected static ?KeptTestDatabase $keptDatabase = null;

    /** @var array<string, string> The checkout's migrations, recorded with the kept database once migrated. */
    protected static array $keptDatabaseMigrations = [];

    /** @var array{host: string, port: string, username: string, password: string}|null For rebuilding mid-run. */
    protected static ?array $keptDatabaseServer = null;

    /** Why the kept database may hold a finished test's committed changes; the next test checks it first. */
    protected static ?string $keptDatabaseDirtiedBy = null;

    /** Set once the kept database has served a test, so later RefreshDatabaseState changes are signals. */
    protected static bool $keptDatabaseReady = false;

    /** Whether the running test wrote outside its transaction (see watchForCommittedWrites()). */
    protected static bool $committedWrites = false;

    public function createApplication()
    {
        $this->configureMysqlClientPath();
        $this->clearTestingMaintenanceMode();
        $this->configureIsolatedTestingDatabase();

        if (static::$keptDatabase !== null) {
            // CommittedFixtureCleanup clears RefreshDatabaseState::$migrated
            // when it could not put a test's committed rows back, asking for
            // a fresh database before the next test.
            $dirtiedBy = static::$keptDatabaseDirtiedBy ?? (static::$keptDatabaseReady && ! RefreshDatabaseState::$migrated
                ? 'a test\'s committed rows were not all put back'
                : null);

            if ($dirtiedBy !== null) {
                static::$keptDatabaseDirtiedBy = null;
                $this->bringKeptDatabaseUpToDate(static::$keptDatabase, $dirtiedBy);
            }
        }

        $app = require Application::inferBasePath().'/bootstrap/app.php';

        $this->traitsUsedByTest = array_flip(class_uses_recursive(static::class));

        $app->make(Kernel::class)->bootstrap();

        // The shutdown drop closes this application's connections first.
        static::$isolatedDatabaseCleanup?->track($app['db']);

        if (static::$isolatedMysqlSchemaLoaded && ! static::$pendingMigrationsApplied) {
            $this->runPendingMigrationsAfterSchemaLoad($app);

            // The clean state a kept database is checked against: straight
            // after migrating, before any test has written a row.
            static::$keptDatabase?->recordBaseline(static::$keptDatabaseMigrations);
            RefreshDatabaseState::$migrated = true;
        }

        if (static::$keptDatabase !== null) {
            // A kept database is migrated and repaired above, never by RefreshDatabase.
            RefreshDatabaseState::$migrated = true;
            static::$keptDatabaseReady = true;
        }

        return $app;
    }

    /**
     * Permission seeders run for real once per process and are replayed after
     * that; see Tests\Support\SeedReplay.
     *
     * @param  array|string  $class
     * @return $this
     */
    public function seed($class = 'Database\\Seeders\\DatabaseSeeder')
    {
        foreach (Arr::wrap($class) as $seeder) {
            if (! SeedReplay::seed($seeder, fn () => parent::seed($seeder))) {
                parent::seed($seeder);
            }
        }

        return $this;
    }

    protected function setUp(): void
    {
        parent::setUp();

        // PROOF_PARITY=1 makes every test also prove that the per-request proven id sets (speed option B, Stephan
        // 30 Oct 2026) admit exactly the rows the original embedded scopes admit; a difference fails the test. It is
        // a sweep switch, not the default: its extra queries would disturb tests that inspect the statements they run.
        // ProvenScopeParityTest compares the two directly on every run.
        \App\Support\HealthSafety\ProofParity::$enabled = filter_var(env('PROOF_PARITY', false), FILTER_VALIDATE_BOOL);

        // SecurityPolicy keeps a static copy of its settings. A session timeout one test
        // sets must not sign the next test's requests out once its rows are rolled back.
        SecurityPolicy::flushCache();

        $this->withoutVite();
        $this->watchForCommittedWrites();
    }

    /**
     * Note a write that commits as it runs: one outside any transaction, or
     * the commit of the test's own RefreshDatabase transaction. Rows such a
     * test does not delete again would otherwise meet the next test.
     * CommittedDatabaseTestCase proves its own restore in tearDown.
     */
    private function watchForCommittedWrites(): void
    {
        static::$committedWrites = false;
        CommittedFixtureCleanup::$captured = false;

        if (static::$keptDatabase === null || $this instanceof CommittedDatabaseTestCase) {
            return;
        }

        $this->app['events']->listen(QueryExecuted::class, static function (QueryExecuted $query): void {
            if ($query->connection->transactionLevel() === 0
                && preg_match('/\A\s*(?:insert|update|delete|replace|create|alter|drop|truncate|rename)\b/i', $query->sql) === 1) {
                static::$committedWrites = true;
            }
        });
        $this->app['events']->listen(TransactionCommitted::class, static function (TransactionCommitted $event): void {
            if ($event->connection->transactionLevel() === 0) {
                static::$committedWrites = true;
            }
        });
    }

    protected function tearDown(): void
    {
        if (static::$keptDatabase !== null && $this->leftCommittedChanges()) {
            // Pest names its classes `P\...` and its tests `__pest_evaluable_<words>`.
            static::$keptDatabaseDirtiedBy = sprintf(
                '%s > "%s" committed changes',
                preg_replace('/\AP\\\\/', '', static::class),
                str_replace('_', ' ', preg_replace('/\A__pest_evaluable_/', '', $this->name())),
            );
        }

        parent::tearDown();
    }

    /**
     * Whether this test's changes outlive it. DatabaseTruncation empties every
     * table, and a schema change inside a test makes MySQL commit the test's
     * open transaction without a word: Laravel still counts it as open, and
     * its rollback then undoes nothing. Before kept databases, later tests in
     * the same process ran on those rows; now the next test repairs first.
     */
    private function leftCommittedChanges(): bool
    {
        // A test that committed through CommittedFixtureCleanup gets its
        // restore()'s own checksum verdict (RefreshDatabaseState::$migrated),
        // which createApplication() reads; checking again would cost a full
        // checksum of every table per test.
        if ((static::$committedWrites && ! CommittedFixtureCleanup::$captured)
            || isset($this->traitsUsedByTest[DatabaseTruncation::class])) {
            return true;
        }

        if ($this->app === null || ! $this->app->resolved('db')) {
            return false;
        }

        foreach ($this->app['db']->getConnections() as $connection) {
            $pdo = $connection->getRawPdo();

            if ($pdo instanceof PDO && $connection->getDriverName() === 'mysql'
                && $connection->transactionLevel() > 0 && ! $pdo->inTransaction()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Runs after setUp and Pest's beforeEach, so a clock frozen there fails
     * before the test body can pass or fail because of the skew.
     */
    protected function assertPreConditions(): void
    {
        parent::assertPreConditions();

        $this->assertFrozenClockUsesDefaultTimezone();
    }

    /**
     * Runs before tearDown and Pest's afterEach reset the clock, so a clock
     * frozen in the test body is still visible here.
     */
    protected function assertPostConditions(): void
    {
        $this->assertFrozenClockUsesDefaultTimezone();

        parent::assertPostConditions();
    }

    private function assertFrozenClockUsesDefaultTimezone(): void
    {
        $violation = FrozenClockTimezone::violation();

        if ($violation !== null) {
            $this->fail($violation);
        }
    }

    /**
     * Build a valid Inertia partial-reload header set whether or not frontend
     * assets have already been built in the current worktree.
     *
     * @return array<string, string>
     */
    protected function inertiaPartialHeaders(string $component, string $data): array
    {
        $headers = [
            'X-Inertia' => 'true',
            'X-Inertia-Partial-Component' => $component,
            'X-Inertia-Partial-Data' => $data,
        ];

        $version = app(HandleInertiaRequests::class)->version(request());

        if (is_string($version) && $version !== '') {
            $headers['X-Inertia-Version'] = $version;
        }

        return $headers;
    }

    protected function clearTestingMaintenanceMode(): void
    {
        if (($this->environmentValue('APP_ENV') ?? 'testing') !== 'testing') {
            return;
        }

        foreach ([
            Application::inferBasePath().DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'down',
            Application::inferBasePath().DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'framework'.DIRECTORY_SEPARATOR.'maintenance.php',
        ] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    protected function configureMysqlClientPath(): void
    {
        $currentPath = (string) ($this->environmentValue('PATH') ?? getenv('PATH') ?: '');

        foreach ($this->mysqlClientDirectories() as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            if (str_contains(strtolower($currentPath), strtolower($directory))) {
                // PHPUnit/application teardown may restore one casing of the
                // Windows path variable between tests. Reassert both entries
                // on every application bootstrap even when no prepend is due.
                $this->setEnvironmentValue('PATH', $currentPath);
                static::$mysqlClientPathConfigured = true;

                return;
            }

            $this->setEnvironmentValue('PATH', $directory.PATH_SEPARATOR.$currentPath);
            static::$mysqlClientPathConfigured = true;

            return;
        }
    }

    protected function configureIsolatedTestingDatabase(): void
    {
        if (($this->environmentValue('APP_ENV') ?? 'testing') !== 'testing') {
            return;
        }

        if (($this->environmentValue('DB_CONNECTION') ?? 'mysql') !== 'mysql') {
            return;
        }

        static::$testDatabaseBaseName ??= (string) ($this->environmentValue('DB_DATABASE') ?? 'oblivion_findings_codex_test');

        // This class owns test database isolation, so `artisan test --parallel`
        // must not also switch each worker to a database of Laravel's own.
        $_SERVER['LARAVEL_PARALLEL_TESTING_WITHOUT_DATABASES'] = 1;

        if (! static::$isolatedMysqlPrepared) {
            $this->prepareIsolatedTestingDatabase();
        }

        $this->setEnvironmentValue('DB_DATABASE', static::$isolatedMysqlDatabase);
    }

    /**
     * Ordinary runs keep their database between runs (TEST_DB_KEEP=false turns
     * that off). Wrappers that name their own database (another DB_DATABASE,
     * such as the eMAR and workforce proofs) or pass a named, non-numeric
     * TEST_TOKEN (the concurrency proofs) own a throwaway `<base>_<pid|token>`
     * database, and keep it.
     */
    protected function keepsTestingDatabase(): bool
    {
        if (! filter_var($this->environmentValue('TEST_DB_KEEP') ?? true, FILTER_VALIDATE_BOOL)
            || static::$testDatabaseBaseName !== self::KEPT_DATABASE_BASE) {
            return false;
        }

        foreach (['TEST_TOKEN', 'PARALLEL_PROCESS', 'PROCESS_TOKEN'] as $key) {
            $value = $this->environmentValue($key);

            if ($value !== null && $value !== '' && ! ctype_digit($value)) {
                return false;
            }
        }

        return true;
    }

    private function prepareIsolatedTestingDatabase(): void
    {
        $host = (string) ($this->environmentValue('DB_HOST') ?? '127.0.0.1');
        $port = (string) ($this->environmentValue('DB_PORT') ?? '3306');
        $username = (string) ($this->environmentValue('DB_USERNAME') ?? 'root');
        $password = (string) ($this->environmentValue('DB_PASSWORD') ?? '');

        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%s', $host, $port),
            $username,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

        if ($this->keepsTestingDatabase()) {
            $this->prepareKeptTestingDatabase($pdo, $host, $port, $username, $password);
        } else {
            $this->prepareThrowawayTestingDatabase($pdo, $host, $port, $username, $password);
        }

        static::$isolatedMysqlPrepared = true;
    }

    /**
     * Claim a kept slot and bring it up to date. createApplication() runs any
     * migrations it still needs once the application has booted, then records
     * its clean state for the next run.
     */
    private function prepareKeptTestingDatabase(PDO $pdo, string $host, string $port, string $username, string $password): void
    {
        $token = (string) ($this->environmentValue('TEST_TOKEN') ?? '');
        $kept = KeptTestDatabase::claim(
            $pdo,
            static::$testDatabaseBaseName,
            Application::inferBasePath(),
            ctype_digit($token) ? (int) $token : 1,
        );
        $database = $kept->name();

        static::$keptDatabase = $kept;
        static::$isolatedMysqlDatabase = $database;
        static::$keptDatabaseServer = ['host' => $host, 'port' => $port, 'username' => $username, 'password' => $password];
        static::$keptDatabaseMigrations = KeptTestDatabase::migrationManifest(
            Application::inferBasePath().DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations',
        );

        // Throwaway databases of crashed runs, and slots of deleted checkouts.
        $this->pruneStaleIsolatedDatabases($pdo, static::$testDatabaseBaseName, $database);
        $kept->pruneAbandoned();

        static::$isolatedMysqlSchemaLoaded = true;
        $this->bringKeptDatabaseUpToDate($kept);
    }

    /**
     * Reuse, repair, migrate or rebuild the kept slot: at the start of a run,
     * and after a test left committed changes behind ($dirtiedBy).
     */
    private function bringKeptDatabaseUpToDate(KeptTestDatabase $kept, ?string $dirtiedBy = null): void
    {
        $database = $kept->name();
        $cause = $dirtiedBy === null ? '' : $dirtiedBy.'; ';
        [$action, $reason] = $kept->inspect(
            static::$keptDatabaseMigrations,
            $dirtiedBy === null && filter_var($this->environmentValue('TEST_DB_REBUILD') ?? false, FILTER_VALIDATE_BOOL),
        );

        if ($action === 'repair') {
            if ($kept->repair()) {
                $this->reportTestDatabase(sprintf('%sRepaired test database `%s`: %s.', $cause, $database, $reason));
                [$action, $reason] = $kept->afterCleanCheck(static::$keptDatabaseMigrations);
            } else {
                [$action, $reason] = ['rebuild', 'repairing its rows left differences'];
            }
        }

        if ($action === 'reuse') {
            $kept->touch();
            static::$pendingMigrationsApplied = true;

            return;
        }

        if ($action === 'rebuild') {
            $this->reportTestDatabase(sprintf('%sRebuilding test database `%s`: %s.', $cause, $database, $reason));
            $kept->recreate();

            if (! $this->loadSchemaDumpIntoTestingDatabase(...static::$keptDatabaseServer, database: $database)) {
                // No usable dump: start empty and let migrate build every table.
                $kept->recreate();
            }
        }

        // createApplication() migrates once the application has booted, then
        // records the clean state.
        static::$pendingMigrationsApplied = false;
    }

    /**
     * Say why the run spends time on its database. Always into
     * storage/logs/test-database.log, which composer test:fast prints after a
     * parallel run; on screen only outside one, because the parallel runner
     * aborts the whole run when a worker writes to STDERR.
     */
    private function reportTestDatabase(string $message): void
    {
        $line = '[tests] '.$message;

        @file_put_contents(
            Application::inferBasePath().DIRECTORY_SEPARATOR.'storage'.DIRECTORY_SEPARATOR.'logs'.DIRECTORY_SEPARATOR.'test-database.log',
            sprintf('[%s] %s%s', gmdate('Y-m-d H:i:s'), $line, PHP_EOL),
            FILE_APPEND | LOCK_EX,
        );

        if (($this->environmentValue('TEST_TOKEN') ?? '') === '') {
            fwrite(STDERR, $line.PHP_EOL);
        }
    }

    private function prepareThrowawayTestingDatabase(PDO $pdo, string $host, string $port, string $username, string $password): void
    {
        static::$isolatedMysqlDatabase ??= sprintf(
            '%s_%s',
            static::$testDatabaseBaseName,
            $this->resolveProcessToken(),
        );
        $database = static::$isolatedMysqlDatabase;

        $pdo->exec(sprintf('DROP DATABASE IF EXISTS `%s`', $database));
        $pdo->exec(sprintf('CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci', $database));

        // Backstop for processes killed before their shutdown handler ran:
        // drop sibling databases whose owning PID is no longer alive.
        $this->pruneStaleIsolatedDatabases($pdo, static::$testDatabaseBaseName, $database);

        // Primary safeguard: drop this process's isolated database when the
        // process exits, so it never leaks into the next run.
        $this->registerIsolatedDatabaseCleanup($host, $port, $username, $password, $database);

        static::$isolatedMysqlSchemaLoaded = $this->loadSchemaDumpIntoTestingDatabase(
            host: $host,
            port: $port,
            username: $username,
            password: $password,
            database: $database,
        );
    }

    /**
     * Register a shutdown handler that drops this process's isolated database.
     *
     * The database name is per-process (suffixed with the PID / test token) and
     * is recreated from scratch on the next run, so dropping it at exit costs
     * nothing and stops hundreds of orphaned schemas piling up on the server.
     *
     * The handler first closes every connection of the test applications this
     * process created, then drops on a fresh server connection with a bounded
     * lock wait, so a leftover metadata lock can't hang the run at exit.
     */
    protected function registerIsolatedDatabaseCleanup(
        string $host,
        string $port,
        string $username,
        string $password,
        string $database,
    ): void {
        if (static::$isolatedDatabaseCleanup !== null) {
            return;
        }

        static::$isolatedDatabaseCleanup = IsolatedDatabaseCleanup::forServer($host, $port, $username, $password, $database);

        register_shutdown_function(static::$isolatedDatabaseCleanup->run(...));
    }

    /**
     * Drop orphaned sibling test databases left behind by crashed / killed
     * processes. Only numeric (PID-suffixed) siblings whose owning process is
     * no longer running are removed, so a concurrently running test process is
     * never affected. If liveness can't be determined, nothing is pruned.
     *
     * Deliberately bounded (a handful of drops, capped wall-clock) so a large
     * backlog drains gradually across successive runs instead of stalling any
     * single bootstrap — each of these schemas is expensive to drop (hundreds
     * of tables). The per-process shutdown handler is the primary cleanup; this
     * is only a slow-drip backstop for processes that never got to run it, or
     * whose shutdown drop gave up waiting for a lock.
     */
    protected function pruneStaleIsolatedDatabases(PDO $pdo, string $baseName, string $currentDatabase): void
    {
        $maxDrops = 5;
        $deadline = microtime(true) + 10.0;
        $dropped = 0;

        try {
            $pattern = '/^'.preg_quote($baseName, '/').'_(\d+)$/';
            $names = preg_grep($pattern, $pdo->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN)) ?: [];

            // Listing processes costs most of a second on Windows; skip it
            // when there is nothing to prune, as on most kept-database runs.
            if ($names === [] || ($alivePids = $this->runningProcessIds()) === null) {
                return;
            }

            foreach ($names as $name) {
                if ($dropped >= $maxDrops || microtime(true) >= $deadline) {
                    break;
                }

                $name = (string) $name;

                if ($name === $currentDatabase || $name === $baseName) {
                    continue;
                }

                if (preg_match($pattern, $name, $matches) !== 1) {
                    continue;
                }

                // Owning process still alive → leave it alone.
                if (isset($alivePids[(int) $matches[1]])) {
                    continue;
                }

                $pdo->exec(sprintf('DROP DATABASE IF EXISTS `%s`', $name));
                $dropped++;
            }
        } catch (\Throwable) {
            // Pruning is a best-effort backstop; never break the test run.
        }
    }

    /**
     * Snapshot the set of currently running process IDs.
     *
     * @return array<int, true>|null Map of alive PID => true, or null when the
     *                               set can't be determined (caller must then
     *                               fail safe and prune nothing).
     */
    protected function runningProcessIds(): ?array
    {
        $isWindows = PHP_OS_FAMILY === 'Windows';

        try {
            $process = $isWindows
                ? new Process(['tasklist', '/NH', '/FO', 'CSV'])
                : new Process(['ps', '-A', '-o', 'pid=']);
            $process->setTimeout(30);
            $process->run();
        } catch (\Throwable) {
            return null;
        }

        if (! $process->isSuccessful()) {
            return null;
        }

        $pids = [];

        foreach (preg_split('/\r\n|\r|\n/', $process->getOutput()) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if ($isWindows) {
                // CSV rows look like: "php.exe","12345","Console","1","50,000 K"
                // Pass $escape explicitly ('') — the default is deprecated in PHP 8.4.
                $fields = str_getcsv($line, ',', '"', '');
                $pid = isset($fields[1]) ? (int) preg_replace('/\D+/', '', (string) $fields[1]) : 0;
            } else {
                $pid = (int) $line;
            }

            if ($pid > 0) {
                $pids[$pid] = true;
            }
        }

        return $pids === [] ? null : $pids;
    }

    /**
     * Apply migrations that were added after the committed schema dump.
     *
     * Running on top of the dump means tests keep their fast cold-boot path,
     * but new migrations on a feature branch still get picked up without
     * having to regenerate the (large, MySQL-version-sensitive) dump file.
     */
    protected function runPendingMigrationsAfterSchemaLoad(Application $app): void
    {
        if (static::$pendingMigrationsApplied) {
            return;
        }

        $kernel = $app->make(Kernel::class);
        $exitCode = $kernel->call('migrate', ['--force' => true, '--no-interaction' => true]);

        if ($exitCode !== 0) {
            $output = trim($kernel->output());

            throw new \RuntimeException(sprintf(
                'Pending test database migrations failed with exit code %d.%s',
                $exitCode,
                $output === '' ? '' : PHP_EOL.$output,
            ));
        }

        static::$pendingMigrationsApplied = true;
    }

    protected function resolveProcessToken(): string
    {
        foreach (['TEST_TOKEN', 'PARALLEL_PROCESS', 'PROCESS_TOKEN'] as $key) {
            $value = $this->environmentValue($key);
            if ($value !== null && $value !== '') {
                return preg_replace('/[^A-Za-z0-9_]+/', '_', (string) $value) ?: 'test';
            }
        }

        return (string) getmypid();
    }

    protected function environmentValue(string $key): ?string
    {
        return $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?: null;
    }

    protected function setEnvironmentValue(string $key, string $value): void
    {
        putenv(sprintf('%s=%s', $key, $value));
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;

        // Symfony Process builds a case-preserving Windows environment block.
        // If the inherited variable is named `Path`, adding only `PATH` leaves
        // both entries present and cmd.exe may resolve executables against the
        // stale one. Keep both spellings aligned for schema-load subprocesses.
        if ($key === 'PATH' && DIRECTORY_SEPARATOR === '\\') {
            putenv(sprintf('Path=%s', $value));
            $_ENV['Path'] = $value;
            $_SERVER['Path'] = $value;
        }
    }

    /**
     * @return array<int, string>
     */
    protected function mysqlClientDirectories(): array
    {
        $configuredDirectories = [];

        foreach (['MYSQL_CLIENT_BIN', 'MYSQL_BINARY', 'MYSQLDUMP_BINARY'] as $envKey) {
            $value = $this->environmentValue($envKey);
            if (! $value) {
                continue;
            }

            foreach (preg_split('/[;,]+/', $value) ?: [] as $candidate) {
                $candidate = trim($candidate, " \t\n\r\0\x0B\"'");
                if ($candidate === '') {
                    continue;
                }

                $configuredDirectories[] = is_dir($candidate)
                    ? $candidate
                    : dirname($candidate);
            }
        }

        return array_values(array_unique(array_filter(array_merge($configuredDirectories, [
            'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin',
            'C:\\Program Files\\MySQL\\MySQL Server 8.4\\bin',
            'C:\\Program Files\\MariaDB 11.4\\bin',
            'C:\\Program Files\\MariaDB 11.3\\bin',
        ]))));
    }

    protected function loadSchemaDumpIntoTestingDatabase(
        string $host,
        string $port,
        string $username,
        string $password,
        string $database,
    ): bool {
        $configuredSchemaPath = trim((string) ($this->environmentValue('MYSQL_TEST_SCHEMA_PATH') ?? ''));
        $schemaPath = $configuredSchemaPath !== ''
            ? $configuredSchemaPath
            : Application::inferBasePath()
                .DIRECTORY_SEPARATOR.'database'
                .DIRECTORY_SEPARATOR.'schema'
                .DIRECTORY_SEPARATOR.'mysql-schema.sql';

        if (! is_file($schemaPath)) {
            return false;
        }

        // Preferred path: shell out to the `mysql` client binary. Fast and
        // handles delimiters, comments, and binary data robustly.
        $mysqlBinary = $this->resolveMysqlBinary();
        if ($mysqlBinary !== null) {
            $process = new Process([
                $mysqlBinary,
                sprintf('--host=%s', $host),
                sprintf('--port=%s', $port),
                sprintf('--user=%s', $username),
                $database,
            ], env: ['MYSQL_PWD' => $password]);

            $process->setInput(file_get_contents($schemaPath));
            // The shared local MySQL server may be importing other isolated
            // schemas at the same time. Keep the normal five-minute limit,
            // with an explicit opt-in for suites running under that load.
            $timeout = (int) ($this->environmentValue('MYSQL_TEST_SCHEMA_TIMEOUT') ?? 300);
            $process->setTimeout(max(300, min(1800, $timeout)));
            $process->run();

            if ($process->isSuccessful()) {
                return true;
            }
        }

        // Full native dumps can contain delimiters, triggers and routines that
        // the table-only PDO parser cannot preserve. Explicit callers fail closed.
        if (filter_var($this->environmentValue('MYSQL_TEST_SCHEMA_REQUIRE_NATIVE') ?? false, FILTER_VALIDATE_BOOL)) {
            throw new \RuntimeException('The required native MySQL schema import did not succeed; PDO fallback is disabled.');
        }

        // Fallback path: pure-PHP PDO loader. Slightly slower but works on
        // machines that don't ship the `mysql.exe` client (e.g. Herd on
        // Windows). The dump produced by `php artisan rostering:dump-schema-portable`
        // is intentionally compatible: only `--` line comments, `/*! ... */`
        // version-conditional pragmas, and `;`-terminated statements.
        return $this->loadSchemaDumpViaPdo($schemaPath, $host, $port, $username, $password, $database);
    }

    /**
     * Stream-execute a `*-schema.sql` dump via PDO. Splits on `;` at end of
     * line (after stripping `--` comments and blank lines), or on the
     * delimiter a DELIMITER directive sets: mysqldump wraps trigger bodies in
     * `DELIMITER ;;` ... `DELIMITER ;`.
     */
    protected function loadSchemaDumpViaPdo(
        string $schemaPath,
        string $host,
        string $port,
        string $username,
        string $password,
        string $database,
    ): bool {
        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%s;dbname=%s', $host, $port, $database),
                $username,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );
        } catch (\Throwable) {
            return false;
        }

        $handle = fopen($schemaPath, 'r');
        if ($handle === false) {
            return false;
        }

        $buffer = '';
        $delimiter = ';';
        $insideMultiLineComment = false;

        try {
            while (($line = fgets($handle)) !== false) {
                $trimmed = ltrim($line);

                // Skip blank lines and `--` comments before any statement starts.
                if ($buffer === '' && ($trimmed === '' || $trimmed === "\n" || str_starts_with($trimmed, '--'))) {
                    continue;
                }

                if ($buffer === '' && preg_match('/^DELIMITER\s+(\S+)\s*$/i', $trimmed, $directive) === 1) {
                    $delimiter = $directive[1];

                    continue;
                }

                // Skip /*!...*/ that span multiple lines (rare in our dumps).
                if ($insideMultiLineComment) {
                    if (str_contains($line, '*/')) {
                        $insideMultiLineComment = false;
                    }

                    continue;
                }

                // Inside a DELIMITER block such a comment is a trigger body to run.
                if ($delimiter === ';' && $buffer === '' && str_starts_with($trimmed, '/*') && ! str_contains($line, '*/')) {
                    $insideMultiLineComment = true;

                    continue;
                }

                $buffer .= $line;

                // A statement ends with the delimiter at the end of a line (ignoring trailing whitespace).
                if (str_ends_with(rtrim($line), $delimiter)) {
                    $statement = trim($buffer);
                    $buffer = '';

                    if ($delimiter !== ';') {
                        $statement = trim(substr($statement, 0, -strlen($delimiter)));
                    }

                    if ($statement === '' || $statement === ';') {
                        continue;
                    }

                    try {
                        $pdo->exec($statement);
                    } catch (\PDOException) {
                        return false;
                    }
                }
            }
        } finally {
            fclose($handle);
        }

        // Flush a trailing statement without a final newline.
        $remaining = trim($buffer);
        if ($remaining !== '' && $remaining !== ';') {
            try {
                $pdo->exec($remaining);
            } catch (\PDOException) {
                return false;
            }
        }

        return true;
    }

    protected function resolveMysqlBinary(): ?string
    {
        if ($configuredBinary = $this->resolveClientBinaryFromEnvironment(['MYSQL_BINARY', 'MYSQL_CLIENT_BIN'], 'mysql.exe')) {
            return $configuredBinary;
        }

        foreach ($this->mysqlClientDirectories() as $directory) {
            $candidate = $directory.DIRECTORY_SEPARATOR.'mysql.exe';
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        // Linux and macOS (CI included) keep the client on PATH.
        return (new ExecutableFinder)->find('mysql');
    }

    /**
     * @param  array<int, string>  $envKeys
     */
    protected function resolveClientBinaryFromEnvironment(array $envKeys, string $binaryName): ?string
    {
        foreach ($envKeys as $envKey) {
            $value = $this->environmentValue($envKey);
            if (! $value) {
                continue;
            }

            foreach (preg_split('/[;,]+/', $value) ?: [] as $candidate) {
                $candidate = trim($candidate, " \t\n\r\0\x0B\"'");
                if ($candidate === '') {
                    continue;
                }

                if (is_file($candidate)) {
                    return $candidate;
                }

                $pathCandidate = rtrim($candidate, '\\/').DIRECTORY_SEPARATOR.$binaryName;
                if (is_file($pathCandidate)) {
                    return $pathCandidate;
                }
            }
        }

        return null;
    }
}
