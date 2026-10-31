<?php

namespace Tests\Support;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;

/**
 * Removes rows a RefreshDatabase test commits part-way through (for example so
 * worker processes can see its fixtures). Otherwise RefreshDatabase either
 * rebuilds the whole schema before the next test (minutes here) or, when the
 * test reopens a transaction before it ends, leaks every row it committed.
 *
 * capture() reads the committed baseline through a separate session, so rows
 * the test has not committed yet (setUp fixtures included) are never baseline.
 * restore() deletes rows above each auto-increment high-water mark and
 * key-only rows (pivots, natural and UUID keys) absent from the baseline.
 *
 * Deleting new rows cannot undo changes to baseline rows (a seeder's sync()
 * detaching a migration's role grant, an observer touching a role). So capture()
 * also snapshots every small table (up to SNAPSHOT_ROW_LIMIT rows, generated
 * columns left out), and restore() rewrites a table from its snapshot when its
 * checksum still differs. A table that refuses the rewrite (an append-only
 * guard, say) is left alone; if any checksum still differs, the next test
 * rebuilds, exactly as before.
 */
final class CommittedFixtureCleanup
{
    /** Tables up to this size are snapshotted at capture so changed baseline rows can be put back. */
    private const SNAPSHOT_ROW_LIMIT = 5000;

    /**
     * Whether the running test captured a cleanup. Tests\TestCase then trusts
     * restore()'s own checksum verdict over checking the database again.
     */
    public static bool $captured = false;

    /**
     * @param  array<string, array{string, int}>  $highWaterMarks  table => [auto-increment column, max value]
     * @param  array<string, array{list<string>, array<string, true>}>  $keyedRows  table => [key columns, baseline keys]
     * @param  array<string, string|null>  $checksums  table => CHECKSUM TABLE value
     * @param  array<string, array{list<string>, list<list<mixed>>}>  $snapshots  table => [insertable columns, baseline rows]
     */
    private function __construct(
        private readonly string $database,
        private readonly array $highWaterMarks,
        private readonly array $keyedRows,
        private readonly array $checksums,
        private readonly array $snapshots = [],
    ) {}

    public static function capture(): self
    {
        self::$captured = true;
        $config = DB::connection()->getConfig();
        $pdo = new PDO(
            sprintf('mysql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['database']),
            $config['username'],
            $config['password'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        // Reads here never need the test's locks; fail fast rather than wait on them.
        $pdo->exec('SET SESSION lock_wait_timeout = 10');
        // Snapshots are read and written back in UTC so TIMESTAMP values round-trip exactly.
        $pdo->exec("SET SESSION time_zone = '+00:00'");
        $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        $generated = self::columnsByTable($pdo, "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND EXTRA LIKE '%GENERATED%'");
        $incrementing = self::columnsByTable($pdo, "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND EXTRA LIKE '%auto_increment%'");
        $primaryKeys = self::columnsByTable($pdo, "SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = DATABASE() AND CONSTRAINT_NAME = 'PRIMARY' ORDER BY TABLE_NAME, ORDINAL_POSITION");
        $allColumns = self::columnsByTable($pdo, 'SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME, ORDINAL_POSITION');
        $tables = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);

        $highWaterMarks = [];
        $keyedRows = [];
        $snapshots = [];
        foreach ($tables as $table) {
            $insertable = array_values(array_diff($allColumns[$table] ?? [], $generated[$table] ?? []));
            if ($insertable !== []) {
                $rows = $pdo->query('SELECT '.implode(', ', array_map(self::quote(...), $insertable)).' FROM '.self::quote($table)
                    .' LIMIT '.(self::SNAPSHOT_ROW_LIMIT + 1))->fetchAll(PDO::FETCH_NUM);
                if (count($rows) <= self::SNAPSHOT_ROW_LIMIT) {
                    $snapshots[$table] = [$insertable, $rows];
                }
            }

            if (isset($incrementing[$table])) {
                $column = $incrementing[$table][0];
                $max = $pdo->query('SELECT MAX('.self::quote($column).') FROM '.self::quote($table))->fetchColumn();
                $highWaterMarks[$table] = [$column, (int) $max];

                continue;
            }

            // Without a primary key, the whole row is its identity.
            $columns = $primaryKeys[$table] ?? $allColumns[$table];
            $keyedRows[$table] = [$columns, array_fill_keys(array_keys(self::keys($pdo, $table, $columns)), true)];
        }

        return new self($database, $highWaterMarks, $keyedRows, self::checksums($pdo, $tables), $snapshots);
    }

    /** Call once RefreshDatabase has closed its transaction, e.g. from beforeApplicationDestroyed(). */
    public function restore(): void
    {
        $connection = DB::connection();
        // RefreshDatabase disconnects when it rolls back.
        $connection->reconnectIfMissingConnection();
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }

        $pdo = $connection->getPdo();
        if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $this->database) {
            throw new RuntimeException('Committed fixtures can only be removed from the schema they were captured in.');
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($this->highWaterMarks as $table => [$column, $max]) {
                $pdo->prepare('DELETE FROM '.self::quote($table).' WHERE '.self::quote($column).' > ?')->execute([$max]);
            }
            foreach ($this->keyedRows as $table => [$columns, $baseline]) {
                $delete = $pdo->prepare('DELETE FROM '.self::quote($table).' WHERE '
                    .implode(' AND ', array_map(fn (string $column): string => self::quote($column).' <=> ?', $columns)));
                foreach (self::keys($pdo, $table, $columns) as $key => $values) {
                    if (! isset($baseline[$key])) {
                        $delete->execute($values);
                    }
                }
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }

        $current = self::checksums($pdo, array_keys($this->checksums));
        $changed = array_keys(array_filter($current, fn (?string $checksum, string $table): bool => $checksum !== ($this->checksums[$table] ?? null), ARRAY_FILTER_USE_BOTH));
        if ($changed !== []) {
            $this->rewriteFromSnapshots($pdo, $changed);
            $current = self::checksums($pdo, array_keys($this->checksums));
        }

        RefreshDatabaseState::$migrated = $current === $this->checksums;
    }

    /**
     * Put changed small tables back to their baseline rows. Each table is rewritten in its own
     * transaction; one that refuses (a delete guard, a type that doesn't round-trip) is rolled
     * back and left for the rebuild.
     *
     * @param  list<string>  $tables
     */
    private function rewriteFromSnapshots(PDO $pdo, array $tables): void
    {
        $session = $pdo->query('SELECT @@SESSION.time_zone, @@SESSION.sql_mode')->fetch(PDO::FETCH_NUM);
        $pdo->exec("SET SESSION time_zone = '+00:00'");
        // Baseline ids of 0 (the default organisation, for example) must stay 0.
        $pdo->exec("SET SESSION sql_mode = CONCAT(@@SESSION.sql_mode, ',NO_AUTO_VALUE_ON_ZERO')");
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($tables as $table) {
                if (! isset($this->snapshots[$table])) {
                    continue;
                }
                [$columns, $rows] = $this->snapshots[$table];
                try {
                    $pdo->beginTransaction();
                    $pdo->exec('DELETE FROM '.self::quote($table));
                    $perRow = '('.implode(', ', array_fill(0, count($columns), '?')).')';
                    $columnList = implode(', ', array_map(self::quote(...), $columns));
                    foreach (array_chunk($rows, max(1, intdiv(60000, count($columns)))) as $chunk) {
                        $pdo->prepare('INSERT INTO '.self::quote($table).' ('.$columnList.') VALUES '
                            .implode(', ', array_fill(0, count($chunk), $perRow)))->execute(array_merge(...$chunk));
                    }
                    $pdo->commit();
                } catch (\Throwable) {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                }
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            $restore = $pdo->prepare('SET SESSION time_zone = ?, SESSION sql_mode = ?');
            $restore->execute([$session[0], $session[1]]);
        }
    }

    /**
     * @param  list<string>  $tables
     * @return array<string, string|null>
     */
    private static function checksums(PDO $pdo, array $tables): array
    {
        $checksums = [];
        foreach (array_chunk($tables, 100) as $chunk) {
            $rows = $pdo->query('CHECKSUM TABLE '.implode(', ', array_map(self::quote(...), $chunk)))->fetchAll(PDO::FETCH_NUM);
            foreach ($rows as [$qualifiedTable, $checksum]) {
                // Results are schema-qualified; key them by the bare table name.
                $checksums[substr((string) $qualifiedTable, strpos((string) $qualifiedTable, '.') + 1)] = $checksum === null ? null : (string) $checksum;
            }
        }
        ksort($checksums);

        return $checksums;
    }

    /** @return array<string, list<string>> */
    private static function columnsByTable(PDO $pdo, string $sql): array
    {
        $columns = [];
        foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_NUM) as [$table, $column]) {
            $columns[$table][] = $column;
        }

        return $columns;
    }

    /**
     * @param  list<string>  $columns
     * @return array<string, list<mixed>>
     */
    private static function keys(PDO $pdo, string $table, array $columns): array
    {
        $keys = [];
        $select = 'SELECT '.implode(', ', array_map(self::quote(...), $columns)).' FROM '.self::quote($table);
        foreach ($pdo->query($select)->fetchAll(PDO::FETCH_NUM) as $values) {
            // Sessions may fetch numbers as int or string; compare as text.
            $values = array_map(fn (mixed $value): ?string => $value === null ? null : (string) $value, $values);
            $keys[serialize($values)] = $values;
        }

        return $keys;
    }

    private static function quote(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }
}
