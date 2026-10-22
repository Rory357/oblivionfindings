<?php

namespace Tests\Unit\SecurityDevices;

use Illuminate\Config\Repository;
use Illuminate\Database\MySqlConnection;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

final class EndpointScriptPolicyMigrationTest extends TestCase
{
    public function test_policy_migration_has_unique_receipts_and_revisions_without_cascading_history_deletion(): void
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
            $migration = require dirname(__DIR__, 3).'/database/migrations/2026_10_21_010000_add_endpoint_script_policy_revisions.php';
            $queries = $connection->pretend(fn () => $migration->up());
            self::assertCount(5, $queries);
            $sql = implode("\n", array_column($queries, 'query'));
            self::assertStringContainsString('unique `endpoint_script_policy_version`(`device_id`, `version`)', $sql);
            self::assertStringContainsString('unique `endpoint_script_policy_request`(`actor_user_id`, `request_key`)', $sql);
            self::assertSame(2, substr_count(strtolower($sql), 'foreign key'));
            self::assertStringNotContainsString('cascade', strtolower($sql));
            preg_match_all('/(?:constraint|index|key) `([^`]+)`/i', $sql, $matches);
            foreach ($matches[1] as $identifier) {
                self::assertLessThanOrEqual(64, strlen($identifier), $identifier);
            }
            $down = $connection->pretend(fn () => $migration->down());
            self::assertCount(1, $down);
            self::assertSame('drop table if exists `endpoint_script_policy_revisions`', $down[0]['query']);
        } finally {
            Facade::setFacadeApplication($previous);
            Facade::clearResolvedInstances();
        }
    }
}
