<?php

namespace Tests\Unit\SecurityDevices;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\MySqlConnection;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

final class EndpointScriptCancellationMigrationTest extends TestCase
{
    public function test_cancellation_receipts_and_lease_history_are_unique_and_do_not_cascade(): void
    {
        $previousContainer = Container::getInstance();
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
            $migration = require dirname(__DIR__, 3).'/database/migrations/2026_10_21_030000_add_endpoint_script_cancellations.php';
            $queries = $connection->pretend(fn () => $migration->up());
            self::assertCount(7, $queries);
            $sql = implode("\n", array_column($queries, 'query'));
            self::assertStringContainsString('unique `endpoint_script_cancel_request`(`actor_user_id`, `request_key`)', $sql);
            self::assertStringContainsString('unique `endpoint_script_cancel_lease`(`endpoint_delivery_id`, `sequence`)', $sql);
            self::assertSame(3, substr_count(strtolower($sql), 'foreign key'));
            self::assertStringNotContainsString('cascade', strtolower($sql));
            preg_match_all('/(?:constraint|index|key) `([^`]+)`/i', $sql, $matches);
            foreach ($matches[1] as $identifier) {
                self::assertLessThanOrEqual(64, strlen($identifier), $identifier);
            }
            $down = $connection->pretend(fn () => $migration->down());
            self::assertSame([
                'drop table if exists `endpoint_script_cancel_leases`',
                'drop table if exists `endpoint_script_cancel_receipts`',
            ], array_column($down, 'query'));
        } finally {
            Container::setInstance($previousContainer);
            Facade::setFacadeApplication($previous);
            Facade::clearResolvedInstances();
        }
    }
}
