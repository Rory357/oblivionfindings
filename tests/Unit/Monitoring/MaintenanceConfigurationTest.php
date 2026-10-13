<?php

namespace Tests\Unit\Monitoring;

use App\Domain\Monitoring\Services\MaintenanceConfiguration;
use PHPUnit\Framework\TestCase;

final class MaintenanceConfigurationTest extends TestCase
{
    public function test_database_json_key_order_does_not_change_snapshot_identity(): void
    {
        self::assertSame(MaintenanceConfiguration::hash(['version' => 2, 'id' => 5, 'site_id' => 8, 'state' => 'active']), MaintenanceConfiguration::hash(['state' => 'active', 'site_id' => 8, 'id' => 5, 'version' => 2]));
    }

    public function test_source_version_type_and_configuration_changes_are_distinct(): void
    {
        $value = ['id' => 5, 'version' => 2, 'monitor_device_id' => 7, 'starts_at' => '2026-10-20T09:00:00+00:00'];
        foreach ([['version' => 3], ['monitor_device_id' => 9], ['id' => '5'], ['starts_at' => '2026-10-20T10:00:00+00:00']] as $patch) {
            self::assertNotSame(MaintenanceConfiguration::hash($value), MaintenanceConfiguration::hash([...$value, ...$patch]));
        }
    }
}
