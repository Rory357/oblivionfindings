<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class IncidentReportRequestUuidMigrationTest extends TestCase
{
    private const INDEX = 'client_incidents_report_request_uuid_unique';

    public function test_report_request_uuid_migration_applies_rolls_back_and_reapplies(): void
    {
        $migration = require database_path('migrations/2026_07_13_000100_add_report_request_uuid_to_client_incidents.php');

        try {
            $this->assertUuidColumnAndUniqueIndexExist();

            $migration->down();

            $this->assertFalse(Schema::hasColumn('client_incidents', 'report_request_uuid'));
            $this->assertFalse($this->hasUniqueIndex());

            $migration->up();

            $this->assertUuidColumnAndUniqueIndexExist();
        } finally {
            if (! Schema::hasColumn('client_incidents', 'report_request_uuid')) {
                $migration->up();
            }
        }
    }

    private function assertUuidColumnAndUniqueIndexExist(): void
    {
        $this->assertTrue(Schema::hasColumn('client_incidents', 'report_request_uuid'));
        $this->assertTrue($this->hasUniqueIndex());
    }

    private function hasUniqueIndex(): bool
    {
        return collect(Schema::getIndexes('client_incidents'))
            ->contains(fn (array $index): bool => ($index['name'] ?? null) === self::INDEX
                && ($index['unique'] ?? false) === true);
    }
}
