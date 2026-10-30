<?php

namespace Tests\Support;

use PDO;

/**
 * What a test database holds, in a form two databases can be compared by: a
 * hash of the whole schema (tables, columns, indexes, keys, checks, triggers,
 * views and routines), a checksum per table and every auto-increment counter.
 * scripts/testing/refresh-schema-dump.php uses it to prove a new schema
 * snapshot loads to the same database a full migration run builds.
 *
 * Database names are left out of every value, so copies under different names
 * compare equal.
 */
final class TestDatabaseState
{
    /**
     * @param  array<string, string|null>  $checksums  table => CHECKSUM TABLE value
     * @param  array<string, int>  $autoIncrements  table => next auto-increment value
     */
    public function __construct(
        public readonly string $schemaHash,
        public readonly array $checksums,
        public readonly array $autoIncrements,
    ) {}

    public static function capture(PDO $pdo, string $database): self
    {
        $tables = $pdo->query(sprintf(
            "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME",
            $pdo->quote($database),
        ))->fetchAll(PDO::FETCH_COLUMN);

        return new self(
            self::schemaHash($pdo, $database),
            self::checksums($pdo, $database, $tables),
            self::autoIncrements($pdo, $database),
        );
    }

    /**
     * Tables whose rows or auto-increment counter differ from the other state.
     *
     * @return array{data: list<string>, autoIncrement: list<string>}
     */
    public function differencesFrom(self $other): array
    {
        $data = [];
        foreach ($other->checksums + $this->checksums as $table => $unused) {
            if (($this->checksums[$table] ?? false) !== ($other->checksums[$table] ?? false)) {
                $data[] = $table;
            }
        }

        $autoIncrement = [];
        foreach ($other->autoIncrements + $this->autoIncrements as $table => $unused) {
            if (($this->autoIncrements[$table] ?? null) !== ($other->autoIncrements[$table] ?? null)) {
                $autoIncrement[] = $table;
            }
        }

        return ['data' => $data, 'autoIncrement' => $autoIncrement];
    }

    public function matches(self $other): bool
    {
        $differences = $this->differencesFrom($other);

        return $this->schemaHash === $other->schemaHash
            && $differences['data'] === []
            && $differences['autoIncrement'] === [];
    }

    public static function schemaHash(PDO $pdo, string $database): string
    {
        $schema = $pdo->quote($database);
        $queries = [
            'tables' => "SELECT TABLE_NAME, TABLE_TYPE, ENGINE, TABLE_COLLATION, CREATE_OPTIONS, TABLE_COMMENT
                FROM information_schema.TABLES WHERE TABLE_SCHEMA = $schema",
            'columns' => "SELECT TABLE_NAME, ORDINAL_POSITION, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT,
                    EXTRA, CHARACTER_SET_NAME, COLLATION_NAME, GENERATION_EXPRESSION, COLUMN_COMMENT, SRS_ID
                FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = $schema",
            'indexes' => "SELECT TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME, NON_UNIQUE, SUB_PART, COLLATION,
                    INDEX_TYPE, IS_VISIBLE, EXPRESSION
                FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = $schema",
            'constraints' => "SELECT TABLE_NAME, CONSTRAINT_NAME, CONSTRAINT_TYPE, ENFORCED
                FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = $schema",
            'keys' => "SELECT TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION, COLUMN_NAME,
                    REFERENCED_TABLE_SCHEMA = TABLE_SCHEMA, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
                FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = $schema",
            'references' => "SELECT TABLE_NAME, CONSTRAINT_NAME, UPDATE_RULE, DELETE_RULE, MATCH_OPTION
                FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = $schema",
            'checks' => "SELECT CONSTRAINT_NAME, CHECK_CLAUSE
                FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = $schema",
            'triggers' => "SELECT TRIGGER_NAME, EVENT_MANIPULATION, EVENT_OBJECT_TABLE, ACTION_ORDER, ACTION_TIMING,
                    ACTION_STATEMENT, SQL_MODE, CHARACTER_SET_CLIENT, COLLATION_CONNECTION
                FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = $schema",
            'views' => "SELECT TABLE_NAME, VIEW_DEFINITION, CHECK_OPTION, IS_UPDATABLE, SECURITY_TYPE
                FROM information_schema.VIEWS WHERE TABLE_SCHEMA = $schema",
            'routines' => "SELECT ROUTINE_NAME, ROUTINE_TYPE, DTD_IDENTIFIER, ROUTINE_DEFINITION, IS_DETERMINISTIC,
                    SQL_DATA_ACCESS, SECURITY_TYPE, SQL_MODE
                FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = $schema",
        ];

        $context = hash_init('sha256');
        foreach ($queries as $name => $query) {
            $rows = $pdo->query($query)->fetchAll(PDO::FETCH_NUM);
            $lines = array_map(
                // View bodies name their own schema; leave it out so copies under other names compare equal.
                static fn (array $row): string => str_replace('`'.$database.'`.', '', json_encode($row, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)),
                $rows,
            );
            sort($lines, SORT_STRING);
            hash_update($context, $name."\n".implode("\n", $lines)."\n");
        }

        return hash_final($context);
    }

    /**
     * @param  list<string>  $tables
     * @return array<string, string|null>
     */
    public static function checksums(PDO $pdo, string $database, array $tables): array
    {
        $checksums = [];
        foreach (array_chunk($tables, 200) as $chunk) {
            $list = implode(', ', array_map(static fn (string $table): string => self::quote($database).'.'.self::quote($table), $chunk));
            foreach ($pdo->query('CHECKSUM TABLE '.$list)->fetchAll(PDO::FETCH_NUM) as [$qualified, $checksum]) {
                $table = substr((string) $qualified, strlen($database) + 1);
                $checksums[$table] = $checksum === null ? null : (string) $checksum;
            }
        }
        ksort($checksums, SORT_STRING);

        return $checksums;
    }

    /**
     * Read with information_schema_stats_expiry = 0, so the values are current rather than cached statistics.
     *
     * @return array<string, int>
     */
    public static function autoIncrements(PDO $pdo, string $database): array
    {
        $pdo->exec('SET SESSION information_schema_stats_expiry = 0');
        $values = [];
        $rows = $pdo->query(sprintf(
            'SELECT TABLE_NAME, AUTO_INCREMENT FROM information_schema.TABLES WHERE TABLE_SCHEMA = %s AND AUTO_INCREMENT IS NOT NULL',
            $pdo->quote($database),
        ))->fetchAll(PDO::FETCH_NUM);
        foreach ($rows as [$table, $value]) {
            $values[(string) $table] = (int) $value;
        }
        ksort($values, SORT_STRING);

        return $values;
    }

    public static function quote(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }
}
