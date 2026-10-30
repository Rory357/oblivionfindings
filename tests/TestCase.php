<?php

namespace Tests;

use App\Http\Middleware\HandleInertiaRequests;
use App\Support\SecurityPolicy;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use PDO;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\Support\FrozenClockTimezone;
use Tests\Support\IsolatedDatabaseCleanup;
use Tests\Support\MysqlStatementSplitter;

abstract class TestCase extends BaseTestCase
{
    protected static ?string $testDatabaseBaseName = null;

    protected static ?string $isolatedMysqlDatabase = null;

    protected static bool $isolatedMysqlPrepared = false;

    protected static ?IsolatedDatabaseCleanup $isolatedDatabaseCleanup = null;

    protected static bool $mysqlClientPathConfigured = false;

    protected static bool $isolatedMysqlSchemaLoaded = false;

    protected static bool $pendingMigrationsApplied = false;

    public function createApplication()
    {
        $this->configureMysqlClientPath();
        $this->clearTestingMaintenanceMode();
        $this->configureIsolatedTestingDatabase();

        $app = require Application::inferBasePath().'/bootstrap/app.php';

        $this->traitsUsedByTest = array_flip(class_uses_recursive(static::class));

        $app->make(Kernel::class)->bootstrap();

        // The shutdown drop closes this application's connections first.
        static::$isolatedDatabaseCleanup?->track($app['db']);

        if (static::$isolatedMysqlSchemaLoaded && ! static::$pendingMigrationsApplied) {
            $this->runPendingMigrationsAfterSchemaLoad($app);
            RefreshDatabaseState::$migrated = true;
        }

        return $app;
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
        static::$isolatedMysqlDatabase ??= sprintf(
            '%s_%s',
            static::$testDatabaseBaseName,
            $this->resolveProcessToken(),
        );

        $this->setEnvironmentValue('DB_DATABASE', static::$isolatedMysqlDatabase);

        if (static::$isolatedMysqlPrepared) {
            return;
        }

        $host = (string) ($this->environmentValue('DB_HOST') ?? '127.0.0.1');
        $port = (string) ($this->environmentValue('DB_PORT') ?? '3306');
        $username = (string) ($this->environmentValue('DB_USERNAME') ?? 'root');
        $password = (string) ($this->environmentValue('DB_PASSWORD') ?? '');
        $database = static::$isolatedMysqlDatabase;

        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%s', $host, $port),
            $username,
            $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );

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

        static::$isolatedMysqlPrepared = true;
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
            $alivePids = $this->runningProcessIds();

            if ($alivePids === null) {
                return;
            }

            $pattern = '/^'.preg_quote($baseName, '/').'_(\d+)$/';
            $names = $pdo->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN);

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

    protected function schemaDumpPath(): string
    {
        $configuredSchemaPath = trim((string) ($this->environmentValue('MYSQL_TEST_SCHEMA_PATH') ?? ''));

        return $configuredSchemaPath !== ''
            ? $configuredSchemaPath
            : Application::inferBasePath()
                .DIRECTORY_SEPARATOR.'database'
                .DIRECTORY_SEPARATOR.'schema'
                .DIRECTORY_SEPARATOR.'mysql-schema.sql';
    }

    protected function loadSchemaDumpIntoTestingDatabase(
        string $host,
        string $port,
        string $username,
        string $password,
        string $database,
    ): bool {
        $schemaPath = $this->schemaDumpPath();

        if (! is_file($schemaPath)) {
            return false;
        }

        // Preferred path: shell out to the `mysql` client binary. Fast and
        // handles delimiters, comments, and binary data robustly.
        // MYSQL_TEST_SCHEMA_LOADER=pdo skips it (to prove the fallback).
        $loader = strtolower((string) ($this->environmentValue('MYSQL_TEST_SCHEMA_LOADER') ?? 'auto'));
        $mysqlBinary = $loader === 'pdo' ? null : $this->resolveMysqlBinary();
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

        // Fallback path: pure-PHP PDO loader, for machines without the `mysql`
        // client (e.g. Herd on Windows). It splits the dump the way the client
        // does (DELIMITER blocks, quoted data, comments), so the same mysqldump
        // file loads either way.
        return $this->loadSchemaDumpViaPdo($schemaPath, $host, $port, $username, $password, $database);
    }

    /**
     * Run a mysqldump file statement by statement through PDO.
     */
    protected function loadSchemaDumpViaPdo(
        string $schemaPath,
        string $host,
        string $port,
        string $username,
        string $password,
        string $database,
    ): bool {
        $sql = @file_get_contents($schemaPath);
        if ($sql === false) {
            return false;
        }

        try {
            $pdo = new PDO(
                sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $host, $port, $database),
                $username,
                $password,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
            );

            foreach (MysqlStatementSplitter::split($sql) as $statement) {
                $pdo->exec($statement);
            }
        } catch (\PDOException) {
            return false;
        }

        return true;
    }

    protected function resolveMysqlBinary(): ?string
    {
        $binaryName = DIRECTORY_SEPARATOR === '\\' ? 'mysql.exe' : 'mysql';

        if ($configuredBinary = $this->resolveClientBinaryFromEnvironment(['MYSQL_BINARY', 'MYSQL_CLIENT_BIN'], $binaryName)) {
            return $configuredBinary;
        }

        foreach ($this->mysqlClientDirectories() as $directory) {
            $candidate = $directory.DIRECTORY_SEPARATOR.$binaryName;
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        // Linux and macOS (CI runners) have the client on PATH.
        return DIRECTORY_SEPARATOR === '\\' ? null : (new ExecutableFinder)->find('mysql');
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
