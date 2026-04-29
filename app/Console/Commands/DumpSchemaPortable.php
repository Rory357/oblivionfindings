<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Portable schema dump that does not depend on `mysqldump`.
 *
 * Laravel's built-in `php artisan schema:dump` shells out to `mysqldump`, which
 * is not bundled with Herd on Windows. This command produces a functionally
 * equivalent `database/schema/{connection}-schema.sql` file using only the PHP
 * MySQL driver, so test boots can short-circuit migrations via Laravel's
 * existing schema-dump-loading logic in `tests/TestCase.php`.
 *
 * Output format mirrors what `schema:dump` would produce:
 *   - `CREATE TABLE` statements (via `SHOW CREATE TABLE`)
 *   - `INSERT INTO migrations` rows so migrate-fresh recognises everything as run
 *
 * Run after structural migration changes:
 *
 *     php artisan rostering:dump-schema-portable
 *     git add database/schema/mysql-schema.sql
 */
class DumpSchemaPortable extends Command
{
    protected $signature = 'rostering:dump-schema-portable'
        .' {--connection= : Connection name (defaults to default).}'
        .' {--path= : Output path (defaults to database/schema/{connection}-schema.sql).}';

    protected $description = 'Dump the database schema to a SQL file without requiring `mysqldump`. Used to short-circuit migration runs in test boots on machines that do not have MySQL Client Tools installed (e.g. Herd on Windows).';

    public function handle(): int
    {
        $connectionName = $this->option('connection') ?: config('database.default');
        $connection = DB::connection($connectionName);
        $driver = $connection->getDriverName();

        if ($driver !== 'mysql') {
            $this->error("Only the mysql driver is supported. Connection '{$connectionName}' uses '{$driver}'.");

            return self::FAILURE;
        }

        $path = $this->option('path') ?: database_path("schema/{$connectionName}-schema.sql");
        File::ensureDirectoryExists(dirname($path));

        $this->info("Dumping schema for connection '{$connectionName}' → {$path}");

        $database = $connection->getDatabaseName();

        // Stream-write to file to avoid OOM on large schemas.
        $handle = fopen($path, 'w');
        if ($handle === false) {
            $this->error("Could not open {$path} for writing.");

            return self::FAILURE;
        }

        try {
            fwrite($handle, $this->header($database, $connectionName));

            // Query information_schema directly so we only get tables from the
            // current database. Schema::getTables() can return cross-database
            // results on shared MySQL/MariaDB instances.
            $tables = collect($connection->select(
                'SELECT TABLE_NAME as name FROM information_schema.TABLES '
                .'WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ? ORDER BY TABLE_NAME',
                [$database, 'BASE TABLE'],
            ))
                ->pluck('name')
                ->values();

            $this->line('Tables: '.$tables->count());

            $bar = $this->output->createProgressBar($tables->count());
            $bar->start();

            foreach ($tables as $table) {
                $createRow = $connection->selectOne("SHOW CREATE TABLE `{$table}`");
                $createSql = $createRow->{'Create Table'} ?? $createRow->{'Create View'} ?? null;

                if ($createSql !== null) {
                    fwrite($handle, "DROP TABLE IF EXISTS `{$table}`;".PHP_EOL);
                    fwrite($handle, $createSql.';'.PHP_EOL.PHP_EOL);
                }

                $bar->advance();
                unset($createRow, $createSql);
            }

            $bar->finish();
            $this->newLine();

            $this->writeMigrationsRows($connection, $handle);
            fwrite($handle, $this->footer());
        } finally {
            fclose($handle);
        }

        $size = number_format(filesize($path));
        $this->info("Wrote {$path} ({$size} bytes).");

        return self::SUCCESS;
    }

    private function header(string $database, string $connectionName): string
    {
        $generatedAt = now()->toDateTimeString();

        return <<<SQL
            -- Schema dump for connection: {$connectionName}
            -- Database: {$database}
            -- Generated: {$generatedAt}
            -- Generator: php artisan rostering:dump-schema-portable (no mysqldump required)

            /*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
            /*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
            /*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
            /*!50503 SET NAMES utf8mb4 */;
            /*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
            /*!40103 SET TIME_ZONE='+00:00' */;
            /*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
            /*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
            /*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
            /*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;


            SQL.PHP_EOL;
    }

    private function footer(): string
    {
        return PHP_EOL.<<<'SQL'
            /*!40103 SET TIME_ZONE=IFNULL(@OLD_TIME_ZONE,'system') */;
            /*!40101 SET SQL_MODE=IFNULL(@OLD_SQL_MODE,'') */;
            /*!40014 SET FOREIGN_KEY_CHECKS=IFNULL(@OLD_FOREIGN_KEY_CHECKS,1) */;
            /*!40014 SET UNIQUE_CHECKS=IFNULL(@OLD_UNIQUE_CHECKS,1) */;
            /*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
            /*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
            /*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
            /*!40111 SET SQL_NOTES=IFNULL(@OLD_SQL_NOTES,1) */;

            SQL.PHP_EOL;
    }

    private function writeMigrationsRows($connection, $handle): void
    {
        if (! Schema::hasTable('migrations')) {
            return;
        }

        $rows = $connection->table('migrations')->orderBy('id')->lazy(500);

        $first = true;
        $count = 0;

        foreach ($rows as $row) {
            if ($first) {
                fwrite($handle, '-- Migration history'.PHP_EOL);
                fwrite($handle, 'INSERT INTO `migrations` (`id`, `migration`, `batch`) VALUES'.PHP_EOL);
                $first = false;
            } else {
                fwrite($handle, ','.PHP_EOL);
            }

            fwrite($handle, sprintf(
                '    (%d, %s, %d)',
                $row->id,
                $connection->getPdo()->quote($row->migration),
                (int) $row->batch,
            ));

            $count++;
        }

        if ($count > 0) {
            fwrite($handle, ';'.PHP_EOL.PHP_EOL);
            $this->line("Migrations rows written: {$count}");
        }
    }
}
