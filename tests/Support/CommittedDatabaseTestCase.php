<?php

namespace Tests\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use PDO;
use Tests\TestCase;

/** Real commits for independent PDO/DDL proofs, with bounded fixture restoration. */
abstract class CommittedDatabaseTestCase extends TestCase
{
    private ?PDO $fixturePdo = null;

    private array $fixtureRows = [];

    private int $fixtureForeignKeys = 1;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Mail::fake();
        Notification::fake();
        $this->assertIsolatedTestConnection(DB::connection());
        $this->assertSame(0, DB::transactionLevel());
        $this->fixturePdo = DB::connection()->getPdo();
        $this->fixtureForeignKeys = (int) $this->fixturePdo->query('SELECT @@FOREIGN_KEY_CHECKS')->fetchColumn();
        foreach ($this->fixtureTables() as $table) {
            $this->fixtureRows[$table] = $this->readFixtureRows($table);
        }
    }

    protected function assertIsolatedTestConnection(Connection $connection): void
    {
        $this->assertSame('testing', app()->environment());
        $this->assertTrue(static::$isolatedMysqlPrepared);
        $this->assertNotEmpty(static::$testDatabaseBaseName);
        $expected = static::$testDatabaseBaseName.'_'.$this->resolveProcessToken();
        $this->assertSame($expected, static::$isolatedMysqlDatabase);
        $this->assertNotSame(static::$testDatabaseBaseName, $expected);
        $this->assertSame('mysql', $connection->getDriverName());
        $this->assertSame($expected, $connection->getDatabaseName());
        $this->assertSame($expected, $connection->getPdo()->query('SELECT DATABASE()')->fetchColumn());
    }

    protected function tearDown(): void
    {
        try {
            if ($this->fixturePdo !== null) {
                // Close every test-owned transaction before restoring committed
                // fixtures. Do not touch a foreign connection or schema.
                foreach (DB::getConnections() as $connection) {
                    $this->assertIsolatedTestConnection($connection);
                    $connection->rollBack(0);
                }
                $this->assertSame(static::$isolatedMysqlDatabase, $this->fixturePdo->query('SELECT DATABASE()')->fetchColumn());
                $this->assertSame(array_keys($this->fixtureRows), $this->fixtureTables(), 'DDL proofs must reinstall their schema.');
                $changed = [];
                foreach ($this->fixtureRows as $table => $rows) {
                    if ($this->readFixtureRows($table) !== $rows) {
                        $changed[$table] = $rows;
                    }
                }
                $this->fixturePdo->exec('SET FOREIGN_KEY_CHECKS = 0');
                try {
                    $this->fixturePdo->beginTransaction();
                    foreach ($changed as $table => $rows) {
                        $quoted = $this->quoteIdentifier($table);
                        $this->fixturePdo->exec('DELETE FROM '.$quoted);
                        if ($rows !== []) {
                            $columns = array_keys($rows[0]);
                            $insert = $this->fixturePdo->prepare('INSERT INTO '.$quoted.' ('.implode(',', array_map($this->quoteIdentifier(...), $columns)).') VALUES ('.implode(',', array_fill(0, count($columns), '?')).')');
                            foreach ($rows as $row) {
                                $insert->execute(array_values($row));
                            }
                        }
                    }
                    $this->fixturePdo->commit();
                } finally {
                    if ($this->fixturePdo->inTransaction()) {
                        $this->fixturePdo->rollBack();
                    }
                    $this->fixturePdo->exec('SET FOREIGN_KEY_CHECKS = '.$this->fixtureForeignKeys);
                }
                foreach ($changed as $table => $rows) {
                    $this->assertSame($rows, $this->readFixtureRows($table), 'Committed fixture cleanup: '.$table);
                }
                $this->assertSame($this->fixtureForeignKeys, (int) $this->fixturePdo->query('SELECT @@FOREIGN_KEY_CHECKS')->fetchColumn());
            }
        } catch (\Throwable $error) {
            // PHPUnit retains the original test failure when teardown throws a
            // non-assertion exception. Also report cleanup trouble explicitly.
            fwrite(STDERR, 'Committed fixture cleanup failed: '.$error->getMessage().PHP_EOL);
            throw new \RuntimeException('Committed fixture cleanup failed.', 0, $error);
        } finally {
            $this->fixturePdo = null;
            parent::tearDown();
        }
    }

    private function fixtureTables(): array
    {
        $tables = $this->fixturePdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
        sort($tables, SORT_STRING);

        return $tables;
    }

    private function readFixtureRows(string $table): array
    {
        // Only disposable, process-owned test data is eligible. Fail before a
        // broad cleanup if a future fixture exceeds this explicit size bound.
        $rows = $this->fixturePdo->query('SELECT * FROM '.$this->quoteIdentifier($table).' LIMIT 10001')->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) > 10000) {
            throw new \RuntimeException('Fixture snapshot bound exceeded: '.$table);
        }
        usort($rows, static fn (array $left, array $right): int => strcmp(serialize($left), serialize($right)));

        return $rows;
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }
}
