<?php

namespace Tests\Unit\SecurityDevices;

use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Database\MySqlConnection;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

final class HikvisionSourceRollbackTest extends TestCase
{
    public function test_rollback_retains_direct_camera_evidence_and_refuses_any_ddl_before_data_loss(): void
    {
        $previousContainer = Container::getInstance();
        $previous = Facade::getFacadeApplication();
        $app = new Application;
        $app->instance('config', new Repository);
        Facade::setFacadeApplication($app);
        Facade::clearResolvedInstances();
        $connection = new class(fn () => throw new \LogicException('No real database connection is allowed.'), 'unreachable', '', ['driver' => 'mysql']) extends MySqlConnection
        {
            public array $reads = [];

            public function isMaria()
            {
                return false;
            }

            public function getServerVersion(): string
            {
                return '8.0.44';
            }

            public function select($query, $bindings = [], $useReadPdo = true, array $fetchUsing = [])
            {
                $this->reads[] = ['query' => $query, 'bindings' => $bindings];

                return [(object) ['exists' => 1]];
            }
        };
        DB::swap($connection);
        Schema::swap($connection->getSchemaBuilder());
        try {
            $migration = require dirname(__DIR__, 3).'/database/migrations/2026_10_11_120000_add_direct_cctv_sources.php';
            try {
                $migration->down();
                self::fail('Retained direct sources were discarded during rollback.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('Retained direct camera sources', $exception->getMessage());
            }
            self::assertCount(1, $connection->reads);
            self::assertStringContainsString(chr(96).'provider'.chr(96).' != ?', $connection->reads[0]['query']);
            self::assertStringContainsString(chr(96).'recorder_device_id'.chr(96).' is null', $connection->reads[0]['query']);
            self::assertSame(['unifi'], $connection->reads[0]['bindings']);
        } finally {
            Container::setInstance($previousContainer);
            Facade::setFacadeApplication($previous);
            Facade::clearResolvedInstances();
        }
    }
}
