<?php

namespace Tests\Unit\SecurityDevices;

use Illuminate\Config\Repository;
use Illuminate\Database\MySqlConnection;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

final class EndpointScriptMigrationTest extends TestCase
{
    public function test_mysql_schema_compiles_bounded_identifiers_without_connecting_or_applying_any_migration(): void
    {
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
            $migration = require dirname(__DIR__, 3).'/database/migrations/2026_10_20_120000_add_endpoint_script_catalogue.php';
            $queries = $connection->pretend(fn () => $migration->up());
            self::assertCount(14, $queries);
            $foreign = 0;
            foreach ($queries as $query) {
                preg_match_all('/(?:constraint|index|key) `([^`]+)`/i', $query['query'], $matches);
                foreach ($matches[1] as $identifier) {
                    self::assertLessThanOrEqual(64, strlen($identifier), $identifier);
                }
                $foreign += substr_count(strtolower($query['query']), 'foreign key');
            }
            self::assertSame(6, $foreign);
        } finally {
            Facade::setFacadeApplication($previous);
            Facade::clearResolvedInstances();
        }
    }
}
