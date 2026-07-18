<?php

namespace Tests;

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use PDO;
use Symfony\Component\Process\Process;

abstract class TestCase extends BaseTestCase
{
    protected static ?string $testDatabaseBaseName = null;

    protected static ?string $isolatedMysqlDatabase = null;

    protected static bool $isolatedMysqlPrepared = false;

    protected static bool $isolatedMysqlCleanupRegistered = false;

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

        if (static::$isolatedMysqlSchemaLoaded && ! static::$pendingMigrationsApplied) {
            $this->runPendingMigrationsAfterSchemaLoad($app);
            RefreshDatabaseState::$migrated = true;
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
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
     */
    protected function registerIsolatedDatabaseCleanup(
        string $host,
        string $port,
        string $username,
        string $password,
        string $database,
    ): void {
        if (static::$isolatedMysqlCleanupRegistered) {
            return;
        }

        static::$isolatedMysqlCleanupRegistered = true;

        register_shutdown_function(static function () use ($host, $port, $username, $password, $database): void {
            try {
                $pdo = new PDO(
                    sprintf('mysql:host=%s;port=%s', $host, $port),
                    $username,
                    $password,
                    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
                );

                $pdo->exec(sprintf('DROP DATABASE IF EXISTS `%s`', $database));
            } catch (\Throwable) {
                // Best-effort teardown — never surface cleanup failures.
            }
        });
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
     * is only a slow-drip backstop for processes that never got to run it.
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

        try {
            $kernel = $app->make(Kernel::class);
            $kernel->call('migrate', ['--force' => true, '--no-interaction' => true]);
        } catch (\Throwable) {
            // Best-effort: if the migrator can't run here we fall back to the
            // schema-dump-only state, which matches the prior behaviour.
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
        $schemaPath = Application::inferBasePath()
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
                sprintf('--password=%s', $password),
                $database,
            ]);

            $process->setInput(file_get_contents($schemaPath));
            $process->setTimeout(300);
            $process->run();

            if ($process->isSuccessful()) {
                return true;
            }
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
     * line (after stripping `--` comments and blank lines). Skips DELIMITER
     * directives because the portable dumper does not emit them.
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
        $insideMultiLineComment = false;

        try {
            while (($line = fgets($handle)) !== false) {
                $trimmed = ltrim($line);

                // Skip blank lines and `--` comments before any statement starts.
                if ($buffer === '' && ($trimmed === '' || $trimmed === "\n" || str_starts_with($trimmed, '--'))) {
                    continue;
                }

                // Skip /*!...*/ that span multiple lines (rare in our dumps).
                if ($insideMultiLineComment) {
                    if (str_contains($line, '*/')) {
                        $insideMultiLineComment = false;
                    }

                    continue;
                }

                if ($buffer === '' && str_starts_with($trimmed, '/*') && ! str_contains($line, '*/')) {
                    $insideMultiLineComment = true;

                    continue;
                }

                $buffer .= $line;

                // A statement ends with `;` at the end of a line (ignoring trailing whitespace).
                if (preg_match('/;\s*$/', rtrim($line))) {
                    $statement = trim($buffer);
                    $buffer = '';

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

        return null;
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
