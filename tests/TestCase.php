<?php

namespace Tests;

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

    protected static bool $mysqlClientPathConfigured = false;

    protected static bool $isolatedMysqlSchemaLoaded = false;

    public function createApplication()
    {
        $this->configureMysqlClientPath();
        $this->clearTestingMaintenanceMode();
        $this->configureIsolatedTestingDatabase();

        $app = require Application::inferBasePath().'/bootstrap/app.php';

        $this->traitsUsedByTest = array_flip(class_uses_recursive(static::class));

        $app->make(Kernel::class)->bootstrap();

        if (static::$isolatedMysqlSchemaLoaded) {
            RefreshDatabaseState::$migrated = true;
        }

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
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
        if (static::$mysqlClientPathConfigured) {
            return;
        }

        $currentPath = (string) ($this->environmentValue('PATH') ?? getenv('PATH') ?: '');

        foreach ($this->mysqlClientDirectories() as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            if (str_contains(strtolower($currentPath), strtolower($directory))) {
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

        static::$isolatedMysqlSchemaLoaded = $this->loadSchemaDumpIntoTestingDatabase(
            host: $host,
            port: $port,
            username: $username,
            password: $password,
            database: $database,
        );

        static::$isolatedMysqlPrepared = true;
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

        $mysqlBinary = $this->resolveMysqlBinary();
        if ($mysqlBinary === null) {
            return false;
        }

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

        return $process->isSuccessful();
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
