<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class PrepareTestingSchema extends Command
{
    protected $signature = 'testing:prepare-schema
        {--database=mysql : The database connection to prepare}
        {--seed= : Optional seeder class to run after migrate:fresh before dumping the schema}
        {--output= : Optional repository-relative output path for a disposable schema cache}
        {--from-scratch : Migrate an empty database and dump structure only, instead of building on the current dump}';

    protected $description = 'Prepare a reusable testing schema dump for faster PHPUnit bootstrapping';

    /**
     * By default the dump is brought forward: load the current dump, run the
     * migrations it doesn't have, and dump the structure plus every row the
     * database then holds. That is exactly the database each test process used
     * to build for itself (load the dump, migrate the rest), so refreshing it
     * changes no test's starting rows. A dump from --from-scratch holds no rows
     * besides the migrations table: rows that data migrations insert are gone.
     */
    public function handle(): int
    {
        $database = (string) $this->option('database');
        $seeder = $this->option('seed');
        $fromScratch = (bool) $this->option('from-scratch');
        $schemaPath = $this->schemaPath($database, $this->option('output'));
        $backupPath = is_file($schemaPath) ? $schemaPath.'.bak' : null;

        // db:wipe below drops every table. Without a .env.testing, --env=testing
        // falls back to .env and would name the developer's own database.
        $databaseName = (string) DB::connection($database)->getDatabaseName();
        if (! app()->environment('testing') || ! str_contains($databaseName, 'test')) {
            $this->components->error(sprintf(
                'Refusing to wipe [%s]: run with --env=testing against a database whose name contains "test".',
                $databaseName,
            ));

            return self::FAILURE;
        }

        if (! $fromScratch && $backupPath === null) {
            $this->components->error(sprintf('There is no dump at %s to build on; run with --from-scratch.', $schemaPath));

            return self::FAILURE;
        }

        $this->ensureDatabaseExists(DB::connection($database));
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

        $succeeded = false;

        try {
            if ($this->call('db:wipe', $wipeOptions) !== self::SUCCESS) {
                return self::FAILURE;
            }

            if (! $fromScratch && ! $this->loadDump(DB::connection($database), (string) $backupPath)) {
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

            $dumped = $fromScratch
                ? $this->dumpSchema(DB::connection($database), $schemaPath)
                : $this->dumpSchemaWithRows(DB::connection($database), $schemaPath);

            if (! $dumped) {
                return self::FAILURE;
            }

            $this->removeDefiners($schemaPath);
            $succeeded = true;
        } finally {
            // A failed run puts the previous dump back, over any partial one.
            if ($backupPath && is_file($backupPath)) {
                if ($succeeded) {
                    @unlink($backupPath);
                } else {
                    @unlink($schemaPath);
                    rename($backupPath, $schemaPath);
                }
            }
        }

        $this->components->info(sprintf('Testing schema dump is ready at %s', $schemaPath));

        return self::SUCCESS;
    }

    /**
     * `composer test:prepare` builds in a scratch database of its own, which
     * may not exist yet; db:wipe can't connect to a missing one.
     */
    protected function ensureDatabaseExists(Connection $connection): void
    {
        $config = $connection->getConfig();
        $dsn = ! empty($config['unix_socket'])
            ? sprintf('mysql:unix_socket=%s', $config['unix_socket'])
            : sprintf('mysql:host=%s;port=%s', is_array($config['host']) ? $config['host'][0] : $config['host'], $config['port'] ?? 3306);
        $pdo = new \PDO($dsn, (string) $config['username'], (string) ($config['password'] ?? ''), [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
        ]);

        $pdo->exec(sprintf(
            'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET %s COLLATE %s',
            str_replace('`', '``', $connection->getDatabaseName()),
            $config['charset'] ?? 'utf8mb4',
            $config['collation'] ?? 'utf8mb4_unicode_ci',
        ));
    }

    protected function configureMysqlClientPath(): void
    {
        $currentPath = (string) (getenv('PATH') ?: '');

        foreach ($this->mysqlClientDirectories() as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            if (str_contains(strtolower($currentPath), strtolower($directory))) {
                $this->setPathEnvironment($currentPath);

                return;
            }

            $this->setPathEnvironment(sprintf('%s%s%s', $directory, PATH_SEPARATOR, $currentPath));

            return;
        }
    }

    protected function setPathEnvironment(string $path): void
    {
        putenv('PATH='.$path);
        $_ENV['PATH'] = $path;
        $_SERVER['PATH'] = $path;

        if (DIRECTORY_SEPARATOR === '\\') {
            putenv('Path='.$path);
            $_ENV['Path'] = $path;
            $_SERVER['Path'] = $path;
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

    protected function dumpSchema(Connection $connection, string $schemaPath): bool
    {
        $dumpBinary = $this->resolveMysqlDumpBinary();
        if ($dumpBinary === null) {
            $this->components->error('Unable to locate mysqldump.exe for schema preparation.');

            return false;
        }

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

    /**
     * Structure, then every row, then triggers: loaded in that order, no
     * trigger fires while the rows go in.
     */
    protected function dumpSchemaWithRows(Connection $connection, string $schemaPath): bool
    {
        $dumpBinary = $this->resolveMysqlDumpBinary();
        if ($dumpBinary === null) {
            $this->components->error('Unable to locate mysqldump for schema preparation.');

            return false;
        }

        $this->ensureSchemaDirectoryExists($schemaPath);

        if (! $this->runDumpProcess(
            binary: $dumpBinary,
            connection: $connection,
            path: $schemaPath,
            extraArgs: ['--routines', '--skip-triggers', sprintf('--result-file=%s', $schemaPath), '--no-data'],
        )) {
            return false;
        }

        $this->removeAutoIncrementingState($schemaPath);

        $sections = [
            'rows' => [
                'objects' => $this->tablesWithRows($connection),
                'args' => ['--no-create-info', '--skip-triggers', '--skip-routines', '--compact', '--complete-insert', '--skip-extended-insert', '--order-by-primary'],
                // --compact leaves out the session settings the rows need: UTC
                // timestamps, and any table order despite foreign keys.
                'before' => "-- Rows: the migrations table and the rows data migrations wrote.\n"
                    ."/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;\n/*!40103 SET TIME_ZONE='+00:00' */;\n"
                    ."/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;\n"
                    ."/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;\n",
                'after' => "/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;\n/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;\n"
                    ."/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;\n",
            ],
            'triggers' => [
                'objects' => [],
                'args' => ['--no-create-info', '--no-data', '--triggers', '--skip-routines', '--compact'],
                'before' => "-- Triggers\n",
                'after' => '',
            ],
        ];

        foreach ($sections as $name => $section) {
            if ($name === 'rows' && $section['objects'] === []) {
                continue;
            }

            $sectionPath = $schemaPath.'.'.$name.'.tmp';

            try {
                if (! $this->runDumpProcess(
                    binary: $dumpBinary,
                    connection: $connection,
                    path: $sectionPath,
                    extraArgs: $section['args'],
                    databaseObjects: $section['objects'],
                )) {
                    return false;
                }

                file_put_contents(
                    $schemaPath,
                    PHP_EOL.$section['before'].file_get_contents($sectionPath).$section['after'],
                    FILE_APPEND,
                );
            } finally {
                if (is_file($sectionPath)) {
                    @unlink($sectionPath);
                }
            }
        }

        return true;
    }

    /**
     * @return list<string>
     */
    protected function tablesWithRows(Connection $connection): array
    {
        $tables = array_map(
            static fn (object $row): string => (string) array_values((array) $row)[0],
            $connection->select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"),
        );

        return array_values(array_filter(
            $tables,
            fn (string $table): bool => (int) $connection->selectOne(sprintf(
                'SELECT EXISTS(SELECT 1 FROM `%s`) AS has_rows',
                str_replace('`', '``', $table),
            ))->has_rows === 1,
        ));
    }

    /**
     * Load the current dump into the wiped database with the mysql client:
     * the dump's triggers need its DELIMITER handling.
     */
    protected function loadDump(Connection $connection, string $path): bool
    {
        $binary = $this->resolveClientBinaryFromEnvironment(['MYSQL_BINARY', 'MYSQL_CLIENT_BIN'], DIRECTORY_SEPARATOR === '\\' ? 'mysql.exe' : 'mysql');

        foreach ($binary === null ? $this->mysqlClientDirectories() : [] as $directory) {
            if (is_file($directory.DIRECTORY_SEPARATOR.'mysql.exe')) {
                $binary = $directory.DIRECTORY_SEPARATOR.'mysql.exe';
                break;
            }
        }

        $binary ??= (new ExecutableFinder)->find('mysql');

        if ($binary === null) {
            $this->components->error('Unable to locate the mysql client to load the current dump.');

            return false;
        }

        $config = $connection->getConfig();
        $arguments = [$binary, sprintf('--user=%s', $config['username'])];
        $arguments[] = ! empty($config['unix_socket'])
            ? sprintf('--socket=%s', $config['unix_socket'])
            : sprintf('--host=%s', is_array($config['host']) ? $config['host'][0] : $config['host']);

        if (empty($config['unix_socket'])) {
            $arguments[] = sprintf('--port=%s', $config['port'] ?? 3306);
        }

        $process = new Process([...$arguments, $config['database']], env: ['MYSQL_PWD' => (string) ($config['password'] ?? '')]);
        $handle = fopen($path, 'r');
        $process->setInput($handle);
        $process->setTimeout(null);

        try {
            $process->run();
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }

        if (! $process->isSuccessful()) {
            $this->components->error(sprintf('Loading the current dump failed: %s', trim($process->getErrorOutput())));

            return false;
        }

        return true;
    }

    /**
     * Triggers record the user who created them; without that clause they
     * load as whoever loads the dump, so any test database user can.
     */
    protected function removeDefiners(string $schemaPath): void
    {
        $schema = file_get_contents($schemaPath);

        if ($schema === false) {
            return;
        }

        file_put_contents(
            $schemaPath,
            preg_replace('#/\*!50017 DEFINER=`[^`]*`@`[^`]*`\*/ ?#', '', $schema) ?? $schema,
        );
    }

    protected function schemaPath(string $database, mixed $output): string
    {
        $output = trim((string) $output);
        if ($output !== '') {
            return base_path($output);
        }

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

        // Linux and macOS keep the client on PATH.
        return (new ExecutableFinder)->find('mysqldump');
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
