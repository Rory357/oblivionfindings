<?php

namespace Tests\Unit\SecurityDevices;

use Illuminate\Config\Repository;
use Illuminate\Database\MySqlConnection;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

final class EndpointScriptReadinessMigrationTest extends TestCase
{
    public function test_readiness_is_an_additive_nullable_observation_with_an_exact_rollback(): void
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
            $migration = require dirname(__DIR__, 3).'/database/migrations/2026_10_21_020000_add_endpoint_script_readiness.php';
            $up = $connection->pretend(fn () => $migration->up());
            self::assertCount(1, $up);
            self::assertSame('alter table `endpoint_agents` add `script_actions` json null', $up[0]['query']);
            $down = $connection->pretend(fn () => $migration->down());
            self::assertCount(1, $down);
            self::assertSame('alter table `endpoint_agents` drop `script_actions`', $down[0]['query']);
        } finally {
            Facade::setFacadeApplication($previous);
            Facade::clearResolvedInstances();
        }
    }
}
