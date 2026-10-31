<?php

namespace Tests\Support;

use Closure;
use Database\Seeders\ClinicalPermissionsSeeder;
use Database\Seeders\GovernancePermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use PDO;
use WeakMap;

/**
 * Runs a permission seeder for real once per process, then replays its rows.
 *
 * RbacSeeder issues a few thousand firstOrCreate and sync queries, about a
 * second per call, and ~9,300 tests call it before every test. What it writes
 * depends only on the rows it reads. So the first call records the tables it
 * read and wrote, the state of those tables before it ran, and the rows it
 * added and removed. A later call that finds those tables in the same state
 * applies the recorded change with a few bulk statements instead. Any other
 * state, such as users a test created before seeding, runs the real seeder.
 *
 * The first call also ends with the replay (the real run is rolled back to a
 * savepoint), so every test sees the same path. SeedReplayParityTest proves
 * the replay leaves every table exactly as the real run did.
 * TEST_SEED_REPLAY=false runs the real seeders throughout.
 */
final class SeedReplay
{
    /** Seeders whose output is a function of the rows they read: no clock, randomness or config. */
    public const SEEDERS = [
        RbacSeeder::class,
        SecurityDevicesPermissionsSeeder::class,
        SeedHrPermissionsSeeder::class,
        ClinicalPermissionsSeeder::class,
        GovernancePermissionsSeeder::class,
    ];

    /** Different starting states recorded per seeder before misses just run it. */
    private const MAX_RECORDINGS_PER_SEEDER = 4;

    /** @var array<string, array{read: list<string>, write: list<string>}|false> false: not replayable. */
    private static array $tables = [];

    /** @var array<string, array<string, array<string, array{removed: list<array<string, mixed>>, added: list<array<string, mixed>>}>>> */
    private static array $recordings = [];

    /** @var array<string, list<string>> */
    private static array $columns = [];

    /** @var array<string, list<string>> */
    private static array $primaryKeys = [];

    /** @var array{connection: string, read: array<string, true>, write: array<string, true>, replayable: bool}|null */
    private static ?array $capture = null;

    private static ?WeakMap $listening = null;

    /**
     * Seed $seeder by replay when possible.
     *
     * @param  Closure(): mixed  $runReal  Runs the real seeder.
     * @return bool false when the caller must run the real seeder itself.
     */
    public static function seed(string $seeder, Closure $runReal): bool
    {
        $seeder = ltrim($seeder, '\\');

        if (! self::enabled() || ! in_array($seeder, self::SEEDERS, true) || (self::$tables[$seeder] ?? null) === false) {
            return false;
        }

        $connection = DB::connection();

        // Recording rolls the real run back to a savepoint inside the test's
        // own transaction; outside one, the real seeder's commits must stand.
        if ($connection->getDriverName() !== 'mysql' || $connection->transactionLevel() < 1) {
            return false;
        }

        if (isset(self::$tables[$seeder])) {
            $before = self::fingerprint($connection, self::$tables[$seeder]['read']);

            if (isset(self::$recordings[$seeder][$before])) {
                self::apply($connection, self::$recordings[$seeder][$before]);

                return true;
            }

            if (count(self::$recordings[$seeder] ?? []) >= self::MAX_RECORDINGS_PER_SEEDER) {
                return false;
            }
        }

        $recording = self::record($connection, $runReal);

        if ($recording === null) {
            self::$tables[$seeder] = false;

            return false;
        }

        // What a seeder reads can depend on the data (users present or not), so
        // the table set only grows. Earlier recordings were fingerprinted over
        // fewer tables and can't be compared any more, so they go when it does.
        $known = self::$tables[$seeder] ?? ['read' => [], 'write' => []];
        $tables = [
            'read' => self::union($known['read'], $recording['read']),
            'write' => self::union($known['write'], array_keys($recording['changes'])),
        ];

        if ($tables !== $known) {
            self::$tables[$seeder] = $tables;
            self::$recordings[$seeder] = [];
        }

        // Rolled back to the starting state, so this is the state a later call must match.
        self::$recordings[$seeder][self::fingerprint($connection, $tables['read'])] = $recording['changes'];
        self::apply($connection, $recording['changes']);

        return true;
    }

    /**
     * Run the real seeder and then its replay from the same starting state.
     *
     * @param  Closure(): mixed  $runReal
     * @return array{real: array<string, list<string>>, replayed: array<string, list<string>>}|null
     *                                                                                              Every non-empty table after each, or null when the seeder isn't replayable.
     */
    public static function compare(Closure $runReal): ?array
    {
        $connection = DB::connection();
        $real = null;
        $recording = self::record($connection, $runReal, function () use ($connection, &$real): void {
            $real = self::snapshot($connection);
        });

        if ($recording === null) {
            return null;
        }

        self::apply($connection, $recording['changes']);

        return ['real' => $real, 'replayed' => self::snapshot($connection)];
    }

    /** Drop every recording, so the next call of each seeder records again. */
    public static function forget(): void
    {
        self::$tables = [];
        self::$recordings = [];
    }

    public static function enabled(): bool
    {
        return filter_var(getenv('TEST_SEED_REPLAY') === false ? true : getenv('TEST_SEED_REPLAY'), FILTER_VALIDATE_BOOL);
    }

    /**
     * Run the real seeder inside a savepoint, note what it read and changed,
     * then roll it back.
     *
     * @param  Closure(): mixed  $runReal
     * @param  (Closure(): void)|null  $afterRun  Sees the database while the real run's rows are still there.
     * @return array{read: list<string>, changes: array<string, array{removed: list<array<string, mixed>>, added: list<array<string, mixed>>}>}|null
     */
    private static function record(Connection $connection, Closure $runReal, ?Closure $afterRun = null): ?array
    {
        self::listen($connection);
        $after = [];

        $connection->beginTransaction();

        try {
            self::$capture = ['connection' => $connection->getName(), 'read' => [], 'write' => [], 'replayable' => true];

            try {
                $runReal();
            } finally {
                $capture = self::$capture;
                self::$capture = null;
            }

            if (! $capture['replayable'] || $capture['write'] === []) {
                return null;
            }

            $write = array_keys($capture['write']);
            $read = array_keys($capture['read'] + $capture['write']);
            sort($write, SORT_STRING);
            sort($read, SORT_STRING);

            foreach ($write as $table) {
                $after[$table] = self::rows($connection, $table);
            }

            if ($afterRun !== null) {
                $afterRun();
            }
        } finally {
            $connection->rollBack();
        }

        $changes = [];

        foreach ($write as $table) {
            $before = self::keyed(self::rows($connection, $table));
            $afterKeyed = self::keyed($after[$table]);
            $changes[$table] = [
                'removed' => array_values(array_diff_key($before, $afterKeyed)),
                'added' => array_values(array_diff_key($afterKeyed, $before)),
            ];
        }

        return ['read' => $read, 'changes' => $changes];
    }

    /**
     * @param  list<string>  $left
     * @param  list<string>  $right
     * @return list<string>
     */
    private static function union(array $left, array $right): array
    {
        $union = array_values(array_unique([...$left, ...$right]));
        sort($union, SORT_STRING);

        return $union;
    }

    /**
     * Apply recorded changes with the test's own PDO, so the test's query log
     * and listeners see none of it. Removed rows go first, so a changed row
     * (removed with its old values, added with its new ones) ends up updated.
     *
     * @param  array<string, array{removed: list<array<string, mixed>>, added: list<array<string, mixed>>}>  $changes
     */
    private static function apply(Connection $connection, array $changes): void
    {
        $pdo = $connection->getPdo();
        $foreignKeyChecks = (int) $pdo->query('SELECT @@FOREIGN_KEY_CHECKS')->fetchColumn();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach ($changes as $table => $change) {
                $key = self::primaryKey($connection, $table);

                if ($key !== []) {
                    // A sync() can detach hundreds of pivot rows: delete them by
                    // primary key, many per statement.
                    $tuple = '('.implode(', ', array_fill(0, count($key), '?')).')';

                    foreach (array_chunk($change['removed'], max(1, intdiv(60000, count($key)))) as $chunk) {
                        $pdo->prepare(
                            'DELETE FROM '.self::quote($table).' WHERE ('.implode(', ', array_map(self::quote(...), $key)).') IN ('
                            .implode(', ', array_fill(0, count($chunk), $tuple)).')'
                        )->execute(array_merge(...array_map(
                            static fn (array $row): array => array_map(static fn (string $column): mixed => $row[$column], $key),
                            $chunk,
                        )));
                    }
                } else {
                    foreach ($change['removed'] as $row) {
                        $where = implode(' AND ', array_map(fn (string $column): string => self::quote($column).' <=> ?', array_keys($row)));
                        $pdo->prepare('DELETE FROM '.self::quote($table).' WHERE '.$where.' LIMIT 1')->execute(array_values($row));
                    }
                }

                if ($change['added'] === []) {
                    continue;
                }

                $columns = array_keys($change['added'][0]);
                $placeholders = '('.implode(', ', array_fill(0, count($columns), '?')).')';

                // MySQL allows 65,535 placeholders per statement.
                foreach (array_chunk($change['added'], max(1, intdiv(60000, count($columns)))) as $chunk) {
                    $pdo->prepare(
                        'INSERT INTO '.self::quote($table).' ('.implode(', ', array_map(self::quote(...), $columns)).') VALUES '
                        .implode(', ', array_fill(0, count($chunk), $placeholders))
                    )->execute(array_merge(...array_map(array_values(...), $chunk)));
                }
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = '.$foreignKeyChecks);
        }
    }

    /**
     * Record which tables the seeder reads and writes. One listener per
     * application's event dispatcher, idle unless a recording is running.
     */
    private static function listen(Connection $connection): void
    {
        $events = $connection->getEventDispatcher();

        if ($events === null) {
            return;
        }

        self::$listening ??= new WeakMap;

        if (isset(self::$listening[$events])) {
            return;
        }

        self::$listening[$events] = true;
        $events->listen(QueryExecuted::class, static function (QueryExecuted $query): void {
            if (self::$capture !== null) {
                self::note($query);
            }
        });
    }

    private static function note(QueryExecuted $query): void
    {
        // A write on another connection would escape the savepoint.
        if ($query->connectionName !== self::$capture['connection']) {
            self::$capture['replayable'] = false;

            return;
        }

        $sql = ltrim($query->sql);

        if (preg_match('/^(?:insert(?:\s+ignore)?\s+into|replace\s+into|update|delete\s+from)\s+`?([A-Za-z0-9_]+)`?/i', $sql, $match) === 1) {
            self::$capture['write'][$match[1]] = true;
        } elseif (preg_match('/^\(?\s*select\b/i', $sql) !== 1) {
            // DDL, TRUNCATE, LOCK and the like commit or can't be replayed.
            self::$capture['replayable'] = false;
        }

        preg_match_all('/\b(?:from|join|into|update)\s+`?([A-Za-z0-9_]+)`?/i', $sql, $matches);

        foreach ($matches[1] as $table) {
            self::$capture['read'][$table] = true;
        }
    }

    /**
     * Row count and two 64-bit XORs of each row's hash, per table: equal only
     * when the tables hold the same rows, in any order.
     *
     * @param  list<string>  $tables
     */
    private static function fingerprint(Connection $connection, array $tables): string
    {
        $parts = [];

        foreach ($tables as $table) {
            $columns = self::columns($connection, $table);

            // A word after FROM or JOIN that names no table (an alias, say).
            if ($columns === []) {
                continue;
            }

            $parts[] = $connection->getPdo()->query(sprintf(
                'SELECT %1$s, COUNT(*), BIT_XOR(CAST(CONV(SUBSTRING(MD5(%2$s), 1, 16), 16, 10) AS UNSIGNED)),'
                .' BIT_XOR(CAST(CONV(SUBSTRING(MD5(%2$s), 17, 16), 16, 10) AS UNSIGNED)) FROM %3$s',
                $connection->getPdo()->quote($table),
                'JSON_ARRAY('.implode(', ', array_map(self::quote(...), $columns)).')',
                self::quote($table),
            ))->fetch(PDO::FETCH_NUM);
        }

        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function rows(Connection $connection, string $table): array
    {
        return $connection->getPdo()->query('SELECT * FROM '.self::quote($table))->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>>
     */
    private static function keyed(array $rows): array
    {
        $keyed = [];

        foreach ($rows as $row) {
            $keyed[serialize($row)] = $row;
        }

        return $keyed;
    }

    /**
     * Every non-empty base table, each row serialized and sorted.
     *
     * @return array<string, list<string>>
     */
    private static function snapshot(Connection $connection): array
    {
        $snapshot = [];

        foreach ($connection->getPdo()->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN) as $table) {
            $rows = array_map(serialize(...), self::rows($connection, (string) $table));

            if ($rows !== []) {
                sort($rows, SORT_STRING);
                $snapshot[(string) $table] = $rows;
            }
        }

        ksort($snapshot, SORT_STRING);

        return $snapshot;
    }

    /**
     * @return list<string>
     */
    private static function columns(Connection $connection, string $table): array
    {
        return self::$columns[$table] ??= self::informationSchema(
            $connection,
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            $table,
        );
    }

    /**
     * @return list<string>
     */
    private static function primaryKey(Connection $connection, string $table): array
    {
        return self::$primaryKeys[$table] ??= self::informationSchema(
            $connection,
            "SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = 'PRIMARY' ORDER BY ORDINAL_POSITION",
            $table,
        );
    }

    /**
     * @return list<string>
     */
    private static function informationSchema(Connection $connection, string $sql, string $table): array
    {
        $statement = $connection->getPdo()->prepare($sql);
        $statement->execute([$table]);

        return array_map('strval', $statement->fetchAll(PDO::FETCH_COLUMN));
    }

    private static function quote(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }
}
