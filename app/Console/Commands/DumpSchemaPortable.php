<?php

namespace App\Console\Commands;

use App\Support\Database\PortableSchemaUnsupportedObjectInventory;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * Retained as a fail-closed compatibility command.
 *
 * The historic implementation serialized base tables and migration rows only.
 * That format cannot preserve the repository's triggers, views, procedures or
 * functions, and the pure-PDO schema loader is not delimiter-aware. The command
 * must remain disabled until serializer and loader support are added together.
 */
class DumpSchemaPortable extends Command
{
    protected $signature = 'rostering:dump-schema-portable'
        .' {--connection= : Connection name (defaults to default).}'
        .' {--path= : Output path (retained for backwards-compatible refusal only).}';

    protected $description = 'Deprecated fail-closed guard for the unsupported table-only portable schema dump.';

    public function handle(): int
    {
        $connectionName = $this->option('connection') ?: config('database.default');
        $connection = DB::connection($connectionName);
        $driver = $connection->getDriverName();

        if ($driver !== 'mysql') {
            $this->components->error(
                "Portable schema dump is disabled; connection '{$connectionName}' also uses unsupported driver '{$driver}'."
            );

            return self::FAILURE;
        }

        $inventory = new PortableSchemaUnsupportedObjectInventory;
        $migrationTable = $this->migrationTableName();
        $repositoryBlockers = $inventory->migrationRepositoryBlockers(
            migrationTable: $migrationTable,
            tablePrefix: $connection->getTablePrefix(),
            repositoryExists: $this->migrationRepositoryExists($connection, $migrationTable),
        );
        $audit = $inventory->audit($this->migrationSources());
        $blockers = [
            ...$repositoryBlockers,
            ...$audit['blockers'],
            'the audited unsupported schema-object manifest is non-empty ['
                .implode(', ', array_keys($audit['manifest'])).']',
            'the table-only serializer and PDO loader do not support triggers, views, procedures, or functions',
        ];

        $this->components->error(
            'Portable schema dump is deprecated and disabled before any output directory or file is created. '
            .implode('; ', $blockers).'. The checked-in schema dump was not changed.'
        );

        foreach ($audit['manifest'] as $migration => $objects) {
            $this->line(sprintf(
                'Unsupported migration: %s [%s]',
                $migration,
                collect($objects)
                    ->map(fn (int $count, string $type): string => strtoupper($type).'='.$count)
                    ->implode(', '),
            ));
        }

        return self::FAILURE;
    }

    private function migrationTableName(): string
    {
        $migrations = config('database.migrations', 'migrations');
        $table = is_array($migrations)
            ? ($migrations['table'] ?? null)
            : $migrations;

        return is_string($table) ? $table : '';
    }

    private function migrationRepositoryExists(Connection $connection, string $migrationTable): bool
    {
        if ($connection->getTablePrefix() !== ''
            || str_contains($migrationTable, '.')
            || preg_match('/^[A-Za-z0-9_$]+$/D', $migrationTable) !== 1) {
            return false;
        }

        try {
            return $connection->getSchemaBuilder()->hasTable($migrationTable);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, array<int, array{path: string, source: string}>>
     */
    private function migrationSources(): array
    {
        $paths = array_merge(
            [database_path('migrations')],
            app(Migrator::class)->paths(),
        );
        $files = [];

        foreach ($paths as $path) {
            $candidates = str_ends_with($path, '.php')
                ? [$path]
                : (File::glob(rtrim($path, '\\/').DIRECTORY_SEPARATOR.'*_*.php') ?: []);

            foreach ($candidates as $candidate) {
                $resolvedPath = realpath($candidate) ?: $candidate;
                $files[str_replace('\\', '/', $resolvedPath)] = $resolvedPath;
            }
        }

        ksort($files);
        $sources = [];

        foreach ($files as $path) {
            $name = pathinfo($path, PATHINFO_FILENAME);
            $sources[$name][] = [
                'path' => $path,
                'source' => File::get($path),
            ];
        }

        ksort($sources);

        return $sources;
    }
}
