<?php

namespace Tests\Unit\SecurityDevices;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\MySqlConnection;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HikvisionSourceMigrationTest extends TestCase
{
    public static function migrations(): array
    {
        return [['2026_10_11_120000_add_direct_cctv_sources.php', 1]];
    }

    #[DataProvider('migrations')]
    public function test_mysql_schema_compiles_bounded_identifiers_without_connecting_or_applying_any_migration(string $filename, int $foreignCount): void
    {
        $previousContainer = Container::getInstance();
        $previous = Facade::getFacadeApplication();
        $app = new Application;
        $app->instance('config', new Repository);
        Facade::setFacadeApplication($app);
        Facade::clearResolvedInstances();
        $connection = new class(fn () => throw new \LogicException('This test must never connect to a database.'), 'unreachable', '', ['driver' => 'mysql']) extends MySqlConnection
        {
            public function isMaria()
            {
                return false;
            }

            public function getServerVersion(): string
            {
                return '8.0.44';
            }
        };
        Schema::swap($connection->getSchemaBuilder());
        try {
            $migration = require dirname(__DIR__, 3).'/database/migrations/'.$filename;
            $queries = $connection->pretend(fn () => $migration->up());
            self::assertGreaterThanOrEqual($foreignCount, count($queries));
            $foreign = 0;
            foreach ($queries as $query) {
                self::assertStringNotContainsString('tenant_id', $query['query']);
                self::assertStringNotContainsString('organization_id', $query['query']);
                preg_match_all('/(?:constraint|index|key) `([^`]+)`/i', $query['query'], $matches);
                foreach ($matches[1] as $identifier) {
                    self::assertLessThanOrEqual(64, strlen($identifier), $identifier);
                }
                $foreign += substr_count(strtolower($query['query']), 'foreign key');
            }
            self::assertSame($foreignCount, $foreign);
        } finally {
            Container::setInstance($previousContainer);
            Facade::setFacadeApplication($previous);
            Facade::clearResolvedInstances();
        }
    }
}
