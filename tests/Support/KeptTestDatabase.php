<?php

namespace Tests\Support;

use PDO;
use RuntimeException;
use Throwable;

/**
 * A test database kept between runs instead of rebuilt and dropped each time.
 *
 * Building a database costs minutes (the schema dump, then every migration the
 * dump doesn't have, then a 20-50s DROP at exit), while the tests in a typical
 * single-file run take seconds. So each checkout gets numbered slots,
 * `<base>_k<checkout>_<n>`, kept on the server. A process claims a free slot
 * with a MySQL named lock, which the server frees when the process exits or
 * dies, so two runs never share one.
 *
 * A small meta database records, per slot, the migrations it holds and its
 * clean state, taken straight after migrating: tables, columns and indexes, a
 * checksum of every table's rows, and the rows themselves of the few tables
 * that have any. A slot is reused when both still match and gets only the new
 * migrations when files were added. Rows a test committed are put back from
 * the recorded clean state in seconds (repair); anything else (an edited or
 * removed migration after a branch switch, or a schema change a test left
 * behind) rebuilds the slot from the schema dump.
 */
final class KeptTestDatabase
{
    public const MAX_SLOTS = 32;

    /** A slot whose checkout is gone, or unused this long, is dropped by a later run. */
    public const ABANDONED_AFTER_DAYS = 14;

    private const LOCK_WAIT_TIMEOUT_SECONDS = 30;

    private const PREFERRED_SLOT_WAIT_SECONDS = 5;

    private function __construct(
        private readonly PDO $server,
        private readonly string $name,
        private readonly string $metaDatabase,
        private readonly string $basePath,
    ) {}

    /**
     * Claim the preferred slot, or the first free one after it.
     *
     * The lock lives on $server's session, so $server must stay open for the
     * life of the process.
     */
    public static function claim(PDO $server, string $baseName, string $basePath, int $preferredSlot): self
    {
        $checkout = substr(sha1(strtolower(str_replace('\\', '/', $basePath))), 0, 6);
        $preferredSlot = max(1, min(self::MAX_SLOTS, $preferredSlot));

        foreach (array_unique([$preferredSlot, ...range(1, self::MAX_SLOTS)]) as $slot) {
            $name = sprintf('%s_k%s_%d', $baseName, $checkout, $slot);

            // MySQL caps both database names and lock names at 64 characters.
            if (strlen($name) > 64) {
                throw new RuntimeException(sprintf('Test database base name [%s] is too long for a kept test database.', $baseName));
            }

            // A parallel worker replaced after its batch takes over the slot of
            // the one before it, whose connection may still be closing.
            $wait = $slot === $preferredSlot ? self::PREFERRED_SLOT_WAIT_SECONDS : 0;

            if ((int) $server->query(sprintf('SELECT GET_LOCK(%s, %d)', $server->quote($name), $wait))->fetchColumn() === 1) {
                // A second lock on the same session names the owning process,
                // so a check from any process (OwnedTestDatabase) can prove
                // which PID holds the slot.
                $server->query('SELECT GET_LOCK('.$server->quote(self::ownerLockName($name, getmypid())).', 0)');

                $kept = new self($server, $name, $baseName.'_meta', $basePath);
                $kept->ensureMetaTable();

                return $kept;
            }
        }

        throw new RuntimeException(sprintf('All %d kept test database slots are in use.', self::MAX_SLOTS));
    }

    public static function ownerLockName(string $database, int $pid): string
    {
        return $database.':'.$pid;
    }

    /**
     * @return array<string, string> Migration name => content hash.
     */
    public static function migrationManifest(string $directory): array
    {
        $manifest = [];

        foreach (glob(rtrim($directory, '\\/').DIRECTORY_SEPARATOR.'*.php') ?: [] as $path) {
            $manifest[basename($path, '.php')] = (string) sha1_file($path);
        }

        ksort($manifest, SORT_STRING);

        return $manifest;
    }

    public function name(): string
    {
        return $this->name;
    }

    /**
     * Decide what this slot needs before the run can use it.
     *
     * @param  array<string, string>  $migrations  The checkout's migration manifest.
     * @return array{0: 'reuse'|'migrate'|'repair'|'rebuild', 1: string} The action and why.
     */
    public function inspect(array $migrations, bool $forceRebuild = false): array
    {
        if ($forceRebuild) {
            return ['rebuild', 'TEST_DB_REBUILD is set'];
        }

        if (! $this->exists()) {
            return ['rebuild', 'it does not exist yet'];
        }

        $record = $this->record();

        if ($record === null) {
            return ['rebuild', 'it has no record of its migrations'];
        }

        $recorded = json_decode((string) $record['migrations'], true);
        $recorded = is_array($recorded) ? $recorded : [];

        foreach ($recorded as $migration => $hash) {
            if (! isset($migrations[$migration])) {
                return ['rebuild', sprintf('migration %s is no longer in this checkout', $migration)];
            }

            if ($migrations[$migration] !== $hash) {
                return ['rebuild', sprintf('migration %s has changed', $migration)];
            }
        }

        $state = $this->state();

        if (! hash_equals((string) $record['fingerprint'], self::fingerprintOf($state))) {
            $baseline = $this->baseline($record);

            if ($baseline === null) {
                return ['rebuild', 'it has no clean state recorded'];
            }

            [$schema, $rows] = self::differences($state, $baseline['state']);

            return ['repair', implode('; ', array_filter([
                $schema === [] ? null : 'a test left schema changes in '.self::list($schema),
                $rows === [] ? null : 'a test left committed rows in '.self::list($rows),
            ]))];
        }

        return $this->afterCleanCheck($migrations);
    }

    /**
     * The verdict for a slot whose recorded migrations and clean state match.
     *
     * @param  array<string, string>  $migrations
     * @return array{0: 'reuse'|'migrate', 1: string}
     */
    public function afterCleanCheck(array $migrations): array
    {
        $recorded = json_decode((string) ($this->record()['migrations'] ?? '[]'), true);
        $pending = count(array_diff_key($migrations, is_array($recorded) ? $recorded : []));

        return $pending > 0
            ? ['migrate', sprintf('%d new migration(s)', $pending)]
            : ['reuse', 'unchanged'];
    }

    /**
     * Put every table that differs from the clean state back as recorded: a
     * table whose schema changed (or that a test created or dropped) is
     * recreated from its recorded definition and triggers, and any table's
     * rows are rewritten from the recorded ones.
     *
     * @return bool false when the slot still differs and needs a rebuild.
     */
    public function repair(): bool
    {
        $record = $this->record();
        $baseline = $record === null ? null : $this->baseline($record);

        if ($baseline === null) {
            return false;
        }

        $state = $this->state();
        [$schema, $rows] = self::differences($state, $baseline['state']);
        $foreignKeyChecks = (int) $this->server->query('SELECT @@FOREIGN_KEY_CHECKS')->fetchColumn();
        $sqlMode = (string) $this->server->query('SELECT @@SESSION.sql_mode')->fetchColumn();

        // Recorded definitions and triggers name their tables unqualified.
        $this->server->exec('USE '.$this->quote($this->name));
        $this->server->exec('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach ($schema as $table) {
                $type = $state['types'][$table] ?? null;
                $this->server->exec(($type === 'VIEW' ? 'DROP VIEW IF EXISTS ' : 'DROP TABLE IF EXISTS ').$this->quote($table));

                if (! isset($baseline['definitions'][$table])) {
                    continue;
                }

                $this->server->exec($baseline['definitions'][$table]);

                foreach ($baseline['triggers'][$table] ?? [] as [$mode, $statement]) {
                    $this->server->exec('SET SESSION sql_mode = '.$this->server->quote($mode));
                    $this->server->exec($statement);
                }

                $this->server->exec('SET SESSION sql_mode = '.$this->server->quote($sqlMode));
            }

            foreach ([...$schema, ...$rows] as $table) {
                if (! isset($baseline['definitions'][$table])) {
                    continue;
                }

                $qualified = $this->quote($table);
                $this->server->exec('DELETE FROM '.$qualified);
                $tableRows = $baseline['rows'][$table] ?? [];

                if ($tableRows === []) {
                    continue;
                }

                $columns = array_keys($tableRows[0]);
                $placeholders = '('.implode(', ', array_fill(0, count($columns), '?')).')';

                // MySQL allows 65,535 placeholders per statement.
                foreach (array_chunk($tableRows, max(1, intdiv(60000, count($columns)))) as $chunk) {
                    $this->server->prepare(
                        'INSERT INTO '.$qualified.' ('.implode(', ', array_map($this->quote(...), $columns)).') VALUES '
                        .implode(', ', array_fill(0, count($chunk), $placeholders))
                    )->execute(array_merge(...array_map(array_values(...), $chunk)));
                }
            }
        } catch (Throwable) {
            return false;
        } finally {
            $this->server->exec('SET SESSION sql_mode = '.$this->server->quote($sqlMode));
            $this->server->exec('SET FOREIGN_KEY_CHECKS = '.$foreignKeyChecks);
        }

        return hash_equals((string) $record['fingerprint'], $this->fingerprint());
    }

    /**
     * @param  array{types: array<string, string>, structures: array<string, string>, checksums: array<string, string>}  $state
     * @param  array{types: array<string, string>, structures: array<string, string>, checksums: array<string, string>}  $baseline
     * @return array{0: list<string>, 1: list<string>} Tables whose schema differs, and tables whose rows alone do.
     */
    private static function differences(array $state, array $baseline): array
    {
        $schema = array_keys(array_diff_assoc($state['structures'], $baseline['structures']) + array_diff_key($baseline['structures'], $state['structures']));
        sort($schema, SORT_STRING);
        $rows = array_values(array_diff(
            array_keys(array_diff_assoc($state['checksums'], $baseline['checksums'])),
            $schema,
        ));

        return [$schema, $rows];
    }

    /**
     * @param  list<string>  $tables
     */
    private static function list(array $tables): string
    {
        return implode(', ', array_slice($tables, 0, 5)).(count($tables) > 5 ? sprintf(' and %d more', count($tables) - 5) : '');
    }

    /**
     * Drop and recreate the slot empty. Its record goes first, so a run that
     * dies mid-build leaves a slot the next run rebuilds.
     */
    public function recreate(): void
    {
        $this->server->prepare(sprintf('DELETE FROM %s.`slots` WHERE `name` = ?', $this->quote($this->metaDatabase)))
            ->execute([$this->name]);
        $this->server->exec(sprintf('SET SESSION lock_wait_timeout = %d', self::LOCK_WAIT_TIMEOUT_SECONDS));
        $this->server->exec(sprintf('DROP DATABASE IF EXISTS %s', $this->quote($this->name)));
        $this->server->exec(sprintf(
            'CREATE DATABASE %s CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $this->quote($this->name),
        ));
    }

    /**
     * Record the migrations and the clean state a later run checks against.
     * Call it straight after migrating, before any test has run.
     *
     * @param  array<string, string>  $migrations
     */
    public function recordBaseline(array $migrations): void
    {
        $state = $this->state();
        $rows = [];
        $definitions = [];
        $triggers = [];

        foreach (array_keys($state['checksums']) as $table) {
            $qualified = $this->quote($this->name).'.'.$this->quote($table);
            $definition = (string) $this->server->query('SHOW CREATE TABLE '.$qualified)->fetch(PDO::FETCH_NUM)[1];
            $definitions[$table] = preg_replace('/\s+AUTO_INCREMENT=\d+/i', '', $definition) ?? $definition;

            // An empty table checksums to 0; only the rest have rows to keep.
            if ($state['checksums'][$table] !== '0') {
                $rows[$table] = $this->server->query('SELECT * FROM '.$qualified)->fetchAll(PDO::FETCH_ASSOC);
            }
        }

        $statement = $this->server->prepare(
            'SELECT EVENT_OBJECT_TABLE, TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = ? ORDER BY EVENT_OBJECT_TABLE, ACTION_ORDER'
        );
        $statement->execute([$this->name]);

        foreach ($statement->fetchAll(PDO::FETCH_NUM) as [$table, $trigger]) {
            $created = $this->server->query('SHOW CREATE TRIGGER '.$this->quote($this->name).'.'.$this->quote($trigger))->fetch(PDO::FETCH_ASSOC);
            // Without its DEFINER a trigger is recreated as whoever repairs it.
            $triggers[$table][] = [
                (string) $created['sql_mode'],
                preg_replace('/\ACREATE\s+DEFINER\s*=\s*\S+\s+TRIGGER/i', 'CREATE TRIGGER', (string) $created['SQL Original Statement']),
            ];
        }

        $statement = $this->server->prepare(sprintf(
            'REPLACE INTO %s.`slots` (`name`, `base_path`, `migrations`, `fingerprint`, `baseline`, `built_at`, `used_at`)
             VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            $this->quote($this->metaDatabase),
        ));
        $statement->bindValue(1, $this->name);
        $statement->bindValue(2, $this->basePath);
        $statement->bindValue(3, json_encode($migrations, JSON_THROW_ON_ERROR));
        $statement->bindValue(4, self::fingerprintOf($state));
        $statement->bindValue(5, gzcompress(serialize([
            'state' => $state,
            'rows' => $rows,
            'definitions' => $definitions,
            'triggers' => $triggers,
        ])), PDO::PARAM_LOB);
        $statement->execute();
    }

    public function touch(): void
    {
        $this->server->prepare(sprintf(
            'UPDATE %s.`slots` SET `used_at` = UTC_TIMESTAMP() WHERE `name` = ?',
            $this->quote($this->metaDatabase),
        ))->execute([$this->name]);
    }

    /**
     * Drop slots whose checkout is gone or that nobody has used for a while.
     * Bounded, and never a slot another process holds.
     */
    public function pruneAbandoned(int $maxDrops = 2): void
    {
        try {
            $statement = $this->server->prepare(sprintf(
                'SELECT `name`, `base_path`, `used_at` < UTC_TIMESTAMP() - INTERVAL %d DAY AS `stale`
                 FROM %s.`slots` WHERE `name` <> ?',
                self::ABANDONED_AFTER_DAYS,
                $this->quote($this->metaDatabase),
            ));
            $statement->execute([$this->name]);
            $dropped = 0;

            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                if ($dropped >= $maxDrops) {
                    break;
                }

                if (! (bool) $row['stale'] && is_dir((string) $row['base_path'])) {
                    continue;
                }

                $lock = $this->server->quote((string) $row['name']);

                if ((int) $this->server->query('SELECT GET_LOCK('.$lock.', 0)')->fetchColumn() !== 1) {
                    continue;
                }

                try {
                    $this->server->exec(sprintf('DROP DATABASE IF EXISTS %s', $this->quote((string) $row['name'])));
                    $this->server->prepare(sprintf('DELETE FROM %s.`slots` WHERE `name` = ?', $this->quote($this->metaDatabase)))
                        ->execute([$row['name']]);
                    $dropped++;
                } finally {
                    $this->server->query('SELECT RELEASE_LOCK('.$lock.')');
                }
            }
        } catch (Throwable) {
            // Pruning is housekeeping; never fail a test run over it.
        }
    }

    /**
     * Tables, columns and indexes, plus a checksum of every table's rows.
     */
    public function fingerprint(): string
    {
        return self::fingerprintOf($this->state());
    }

    /**
     * @param  array{types: array<string, string>, structures: array<string, string>, checksums: array<string, string>}  $state
     */
    private static function fingerprintOf(array $state): string
    {
        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }

    /**
     * Per table: its type, a hash of its columns, indexes, foreign keys and
     * triggers, and a checksum of its rows.
     *
     * @return array{types: array<string, string>, structures: array<string, string>, checksums: array<string, string>}
     */
    private function state(): array
    {
        $schema = $this->server->quote($this->name);
        $types = $this->server->query(
            "SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = {$schema} ORDER BY TABLE_NAME"
        )->fetchAll(PDO::FETCH_KEY_PAIR);
        $parts = [
            'columns' => 'SELECT TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
                FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '.$schema.' ORDER BY TABLE_NAME, ORDINAL_POSITION',
            'indexes' => 'SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
                FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = '.$schema.' ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX',
            'foreign keys' => 'SELECT k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME,
                    k.REFERENCED_COLUMN_NAME, r.UPDATE_RULE, r.DELETE_RULE
                FROM information_schema.KEY_COLUMN_USAGE k
                JOIN information_schema.REFERENTIAL_CONSTRAINTS r
                    ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
                WHERE k.TABLE_SCHEMA = '.$schema.' ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION',
            'triggers' => 'SELECT EVENT_OBJECT_TABLE, TRIGGER_NAME, ACTION_TIMING, EVENT_MANIPULATION, ACTION_ORDER, ACTION_STATEMENT
                FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = '.$schema.' ORDER BY EVENT_OBJECT_TABLE, ACTION_ORDER',
        ];
        $definitions = array_fill_keys(array_keys($types), []);

        foreach ($parts as $part => $sql) {
            foreach ($this->server->query($sql)->fetchAll(PDO::FETCH_NUM) as $row) {
                $definitions[$row[0]][$part][] = $row;
            }
        }

        $structures = [];

        foreach ($definitions as $table => $definition) {
            $structures[$table] = hash('sha256', json_encode([$types[$table] ?? null, $definition], JSON_THROW_ON_ERROR));
        }

        ksort($structures, SORT_STRING);
        $baseTables = array_keys(array_filter($types, static fn (string $type): bool => $type === 'BASE TABLE'));
        $checksums = [];

        // CHECKSUM TABLE sums a checksum of each row, so it changes with any
        // committed row and not with row order. Its rows name the table as
        // `database.table`; keep only the table, so equal contents compare
        // alike under any database name.
        foreach (array_chunk($baseTables, 200) as $chunk) {
            $list = implode(', ', array_map(fn (string $table): string => $this->quote($this->name).'.'.$this->quote($table), $chunk));

            foreach ($this->server->query('CHECKSUM TABLE '.$list)->fetchAll(PDO::FETCH_NUM) as [$table, $checksum]) {
                $checksums[substr((string) $table, strlen($this->name) + 1)] = (string) $checksum;
            }
        }

        ksort($checksums, SORT_STRING);

        return ['types' => $types, 'structures' => $structures, 'checksums' => $checksums];
    }

    private function exists(): bool
    {
        $statement = $this->server->prepare('SELECT 1 FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $statement->execute([$this->name]);

        return $statement->fetchColumn() !== false;
    }

    /**
     * @return array{migrations: string, fingerprint: string, baseline: string}|null
     */
    private function record(): ?array
    {
        $statement = $this->server->prepare(sprintf(
            'SELECT `migrations`, `fingerprint`, `baseline` FROM %s.`slots` WHERE `name` = ?',
            $this->quote($this->metaDatabase),
        ));
        $statement->execute([$this->name]);
        $record = $statement->fetch(PDO::FETCH_ASSOC);

        return is_array($record) ? $record : null;
    }

    /**
     * @param  array{baseline: string}  $record
     * @return array{state: array{types: array<string, string>, structures: array<string, string>, checksums: array<string, string>}, rows: array<string, list<array<string, mixed>>>, definitions: array<string, string>, triggers: array<string, list<array{string, string}>>}|null
     */
    private function baseline(array $record): ?array
    {
        $inflated = @gzuncompress((string) $record['baseline']);
        $baseline = $inflated === false ? false : @unserialize($inflated, ['allowed_classes' => false]);

        // A record in an older layout can't be repaired from; the slot is rebuilt.
        return is_array($baseline)
            && isset($baseline['state']['types'], $baseline['state']['structures'], $baseline['state']['checksums'], $baseline['rows'], $baseline['definitions'], $baseline['triggers'])
            ? $baseline
            : null;
    }

    private function ensureMetaTable(): void
    {
        $meta = $this->quote($this->metaDatabase);
        $this->server->exec("CREATE DATABASE IF NOT EXISTS {$meta} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $this->server->exec(
            "CREATE TABLE IF NOT EXISTS {$meta}.`slots` (
                `name` varchar(64) NOT NULL PRIMARY KEY,
                `base_path` varchar(1024) NOT NULL,
                `migrations` longtext NOT NULL,
                `fingerprint` char(64) NOT NULL,
                `baseline` longblob NOT NULL,
                `built_at` datetime NOT NULL,
                `used_at` datetime NOT NULL
            ) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        );
    }

    private function quote(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }
}
