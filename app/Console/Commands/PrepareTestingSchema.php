<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

class PrepareTestingSchema extends Command
{
    protected $signature = 'testing:prepare-schema
        {--database=mysql : The database connection to prepare}
        {--seed= : Optional seeder class to run after migrate:fresh before dumping the schema}';

    protected $description = 'Prepare a reusable testing schema dump for faster PHPUnit bootstrapping';

    public function handle(): int
    {
        $database = (string) $this->option('database');
        $seeder = $this->option('seed');
        $schemaPath = $this->schemaPath($database);
        $backupPath = is_file($schemaPath) ? $schemaPath.'.bak' : null;

        $this->configureMysqlClientPath();
        $this->components->info(sprintf('Preparing schema dump for [%s].', $database));

        if ($backupPath) {
            @unlink($backupPath);
            rename($schemaPath, $backupPath);
        }

        $wipeOptions = [
            '--database' => $database,
            '--force' => true,
        ];

        try {
            if ($this->call('db:wipe', $wipeOptions) !== self::SUCCESS) {
                return self::FAILURE;
            }

            if ($this->call('migrate', $wipeOptions) !== self::SUCCESS) {
                return self::FAILURE;
            }

            if (filled($seeder) && $this->call('db:seed', [
                '--database' => $database,
                '--class' => (string) $seeder,
                '--force' => true,
            ]) !== self::SUCCESS) {
                return self::FAILURE;
            }

            if (! $this->dumpSchema(DB::connection($database))) {
                return self::FAILURE;
            }
        } finally {
            if ($backupPath && is_file($backupPath) && ! is_file($schemaPath)) {
                rename($backupPath, $schemaPath);
            } elseif ($backupPath && is_file($backupPath)) {
                @unlink($backupPath);
            }
        }

        $this->components->info('Testing schema dump is ready at database/schema/mysql-schema.sql');

        return self::SUCCESS;
    }

    protected function configureMysqlClientPath(): void
    {
        $currentPath = (string) (getenv('PATH') ?: '');

        foreach ($this->mysqlClientDirectories() as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            if (str_contains(strtolower($currentPath), strtolower($directory))) {
                return;
            }

            putenv(sprintf('PATH=%s%s%s', $directory, PATH_SEPARATOR, $currentPath));
            $_ENV['PATH'] = sprintf('%s%s%s', $directory, PATH_SEPARATOR, $currentPath);
            $_SERVER['PATH'] = sprintf('%s%s%s', $directory, PATH_SEPARATOR, $currentPath);

            return;
        }
    }

    /**
     * @return array<int, string>
     */
    protected function mysqlClientDirectories(): array
    {
        $configuredDirectories = [];

        foreach (['MYSQL_CLIENT_BIN', 'MYSQL_BINARY', 'MYSQLDUMP_BINARY'] as $envKey) {
            $value = getenv($envKey) ?: ($_ENV[$envKey] ?? $_SERVER[$envKey] ?? null);
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

    protected function dumpSchema(Connection $connection): bool
    {
        $dumpBinary = $this->resolveMysqlDumpBinary();
        if ($dumpBinary === null) {
            $this->components->error('Unable to locate mysqldump.exe for schema preparation.');

            return false;
        }

        $schemaPath = database_path(sprintf('schema/%s-schema.sql', $connection->getName()));
        $this->ensureSchemaDirectoryExists($schemaPath);

        if (! $this->dumpStructure($dumpBinary, $connection, $schemaPath)) {
            return false;
        }

        $this->removeAutoIncrementingState($schemaPath);

        if (! $this->appendMigrationData($dumpBinary, $connection, $schemaPath)) {
            return false;
        }

        return true;
    }

    protected function schemaPath(string $database): string
    {
        return database_path(sprintf('schema/%s-schema.sql', $database));
    }

    protected function dumpStructure(string $dumpBinary, Connection $connection, string $schemaPath): bool
    {
        return $this->runDumpProcess(
            binary: $dumpBinary,
            connection: $connection,
            path: $schemaPath,
            extraArgs: ['--routines', sprintf('--result-file=%s', $schemaPath), '--no-data']
        );
    }

    protected function appendMigrationData(string $dumpBinary, Connection $connection, string $schemaPath): bool
    {
        $migrationTable = $this->migrationTableName();

        if (! DB::connection($connection->getName())->getSchemaBuilder()->hasTable($migrationTable)) {
            return true;
        }

        $outputPath = $schemaPath.'.migrations.tmp';

        try {
            if (! $this->runDumpProcess(
                binary: $dumpBinary,
                connection: $connection,
                path: $outputPath,
                extraArgs: [
                    '--no-create-info',
                    '--skip-extended-insert',
                    '--skip-routines',
                    '--compact',
                    '--complete-insert',
                ],
                databaseObjects: [$migrationTable]
            )) {
                return false;
            }

            file_put_contents($schemaPath, PHP_EOL.file_get_contents($outputPath), FILE_APPEND);

            return true;
        } finally {
            if (is_file($outputPath)) {
                @unlink($outputPath);
            }
        }
    }

    /**
     * @param  array<int, string>  $extraArgs
     * @param  array<int, string>  $databaseObjects
     */
    protected function runDumpProcess(
        string $binary,
        Connection $connection,
        string $path,
        array $extraArgs,
        array $databaseObjects = [],
    ): bool {
        $extraArgs = collect($extraArgs)->contains(fn (string $argument) => str_starts_with($argument, '--result-file='))
            ? $extraArgs
            : array_merge([sprintf('--result-file=%s', $path)], $extraArgs);

        $config = $connection->getConfig();
        $commonArgs = [
            sprintf('--user=%s', $config['username']),
            sprintf('--password=%s', (string) ($config['password'] ?? '')),
        ];

        if (! empty($config['unix_socket'])) {
            $commonArgs[] = sprintf('--socket=%s', $config['unix_socket']);
        } else {
            $commonArgs[] = sprintf('--host=%s', is_array($config['host']) ? $config['host'][0] : $config['host']);
            $commonArgs[] = sprintf('--port=%s', $config['port'] ?? '');
        }

        $attempts = [
            array_merge(
                [$binary],
                $commonArgs,
                [
                    '--no-tablespaces',
                    '--skip-add-locks',
                    '--skip-comments',
                    '--skip-set-charset',
                    '--tz-utc',
                    '--column-statistics=0',
                ],
                $connection->isMaria() ? [] : ['--set-gtid-purged=OFF'],
                $extraArgs,
                [$config['database']],
                $databaseObjects
            ),
        ];

        if (! $connection->isMaria()) {
            $attempts[] = array_values(array_filter(
                $attempts[0],
                fn (string $argument) => $argument !== '--column-statistics=0'
            ));
            $attempts[] = array_values(array_filter(
                $attempts[1],
                fn (string $argument) => $argument !== '--set-gtid-purged=OFF'
            ));
        }

        foreach ($attempts as $arguments) {
            $process = new Process($arguments);
            $process->setTimeout(null);
            $process->run();

            if ($process->isSuccessful()) {
                return true;
            }
        }

        $this->components->error(sprintf('Schema dump failed: %s', trim($process->getErrorOutput() ?: $process->getOutput())));

        return false;
    }

    protected function resolveMysqlDumpBinary(): ?string
    {
        if ($configuredBinary = $this->resolveClientBinaryFromEnvironment(['MYSQLDUMP_BINARY', 'MYSQL_CLIENT_BIN'], 'mysqldump.exe')) {
            return $configuredBinary;
        }

        foreach ($this->mysqlClientDirectories() as $directory) {
            $candidate = $directory.DIRECTORY_SEPARATOR.'mysqldump.exe';
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
            $value = getenv($envKey) ?: ($_ENV[$envKey] ?? $_SERVER[$envKey] ?? null);
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

    protected function ensureSchemaDirectoryExists(string $schemaPath): void
    {
        $directory = dirname($schemaPath);

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
    }

    protected function removeAutoIncrementingState(string $schemaPath): void
    {
        $schema = file_get_contents($schemaPath);

        if ($schema === false) {
            return;
        }

        file_put_contents(
            $schemaPath,
            preg_replace('/\s+AUTO_INCREMENT=[0-9]+/iu', '', $schema) ?? $schema
        );
    }

    protected function migrationTableName(): string
    {
        $migrations = config('database.migrations', 'migrations');

        return is_array($migrations)
            ? ($migrations['table'] ?? 'migrations')
            : $migrations;
    }
}
