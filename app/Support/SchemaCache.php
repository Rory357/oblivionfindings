<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * Cached replacement for Schema::hasTable()/hasColumn() on request-hot
 * paths. Each Schema::has*() call is an uncached information_schema
 * round-trip; with 784 tables and hundreds of call sites the global
 * middleware alone paid 8 of them per request.
 *
 * One table listing (and one column listing per asked-about table) is
 * cached forever under a version-stamped key; MigrationsEnded /
 * SchemaLoaded listeners in AppServiceProvider bump the stamp, which
 * orphans the old keys — so this works identically on the file and
 * redis stores without cache tags. Tables created outside migrations
 * are picked up after `php artisan cache:clear`.
 */
final class SchemaCache
{
    /** @var array<string, true>|null */
    private static ?array $tables = null;

    /** @var array<string, array<string, true>> */
    private static array $columns = [];

    public static function hasTable(string $table): bool
    {
        self::$tables ??= array_fill_keys(array_map('strtolower', Cache::rememberForever(
            'schema-cache:'.self::stamp().':tables',
            fn (): array => Schema::getTableListing(schemaQualified: false),
        )), true);

        return isset(self::$tables[strtolower($table)]);
    }

    public static function hasColumn(string $table, string $column): bool
    {
        if (! self::hasTable($table)) {
            return false;
        }

        self::$columns[$table] ??= array_fill_keys(array_map('strtolower', Cache::rememberForever(
            'schema-cache:'.self::stamp().":columns:{$table}",
            fn (): array => Schema::getColumnListing($table),
        )), true);

        return isset(self::$columns[$table][strtolower($column)]);
    }

    /**
     * @param  string[]  $columns
     */
    public static function hasColumns(string $table, array $columns): bool
    {
        foreach ($columns as $column) {
            if (! self::hasColumn($table, $column)) {
                return false;
            }
        }

        return true;
    }

    public static function flush(): void
    {
        self::$tables = null;
        self::$columns = [];

        // Bumping the stamp orphans the old listing keys instead of
        // needing to enumerate and forget them individually.
        if (Cache::has('schema-cache:stamp')) {
            Cache::increment('schema-cache:stamp');
        }
    }

    private static function stamp(): int
    {
        return (int) Cache::rememberForever('schema-cache:stamp', fn (): int => 1);
    }
}
