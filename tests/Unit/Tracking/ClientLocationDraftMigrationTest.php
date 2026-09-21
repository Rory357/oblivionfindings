<?php

namespace Tests\Unit\Tracking;

use Illuminate\Support\Facades\Schema;
use Tests\Support\CommittedDatabaseTestCase;

class ClientLocationDraftMigrationTest extends CommittedDatabaseTestCase
{
    public function test_empty_draft_schema_rolls_back_and_can_be_reinstalled(): void
    {
        // No RefreshDatabase transaction: MySQL DDL commits implicitly.
        $migration = require database_path('migrations/2026_09_21_000001_create_client_geofence_rule_drafts.php');
        $monitoring = require database_path('migrations/2026_09_21_000005_create_client_geofence_monitors.php');
        $monitoring->down();
        $this->assertFalse(Schema::hasTable('client_geofence_monitors'));
        $migration->down();
        $this->assertFalse(Schema::hasTable('client_geofence_rules'));
        $this->assertFalse(Schema::hasTable('client_geofence_rule_versions'));
        $migration->up();
        $monitoring->up();
        $this->assertTrue(Schema::hasTable('client_geofence_rules'));
        $this->assertTrue(Schema::hasColumn('client_geofence_rule_versions', 'geometry_proposal'));
        $this->assertTrue(Schema::hasTable('client_geofence_monitors'));
    }
}
