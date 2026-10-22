<?php

namespace Tests\Unit\SecurityDevices;

use Illuminate\Config\Repository;
use Illuminate\Database\MySqlConnection;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

final class EndpointScriptOutputMigrationTest extends TestCase
{
    public function test_private_output_has_one_retained_receipt_per_delivery_and_expiry_index(): void
    {
        $previous = Facade::getFacadeApplication();
        $app = new Application;
        $app->instance('config', new Repository);
        Facade::setFacadeApplication($app);
        Facade::clearResolvedInstances();
        $connection = new class(fn () => throw new \LogicException('Schema compilation must not connect.'), 'unreachable', '', ['driver' => 'mysql']) extends MySqlConnection
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
            $migration = require dirname(__DIR__, 3).'/database/migrations/2026_10_21_040000_add_endpoint_script_outputs.php';
            $queries = $connection->pretend(fn () => $migration->up());
            self::assertCount(4, $queries);
            $sql = implode("\n", array_column($queries, 'query'));
            self::assertStringContainsString('unique `endpoint_script_outputs_endpoint_delivery_id_unique`(`endpoint_delivery_id`)', $sql);
            self::assertStringContainsString('index `endpoint_script_outputs_expires_at_index`(`expires_at`)', $sql);
            self::assertSame(1, substr_count(strtolower($sql), 'foreign key'));
            self::assertStringNotContainsString('cascade', strtolower($sql));
            preg_match_all('/(?:constraint|index|key) `([^`]+)`/i', $sql, $matches);
            foreach ($matches[1] as $identifier) {
                self::assertLessThanOrEqual(64, strlen($identifier), $identifier);
            }
            $down = $connection->pretend(fn () => $migration->down());
            self::assertSame([
                'drop table if exists `endpoint_script_outputs`',
            ], array_column($down, 'query'));
        } finally {
            Facade::setFacadeApplication($previous);
            Facade::clearResolvedInstances();
        }
    }
}
