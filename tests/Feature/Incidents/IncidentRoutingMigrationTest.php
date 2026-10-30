<?php

namespace Tests\Feature\Incidents;

use App\Models\AppSetting;
use App\Models\IncidentOwnerChange;
use App\Services\Incidents\IncidentOwnershipService;
use App\Services\Incidents\IncidentRoutingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class IncidentRoutingMigrationTest extends IncidentRoutingTestCase
{
    public function test_destructive_down_refuses_retained_configuration_and_missing_invalid_configuration_rows(): void
    {
        $migration = require database_path('migrations/2026_10_12_200700_create_incident_owner_routing.php');
        foreach ([null, [], false] as $value) {
            $row = AppSetting::updateOrCreate(['key' => 'healthSafety.incidents.owner_routing'], ['value' => $value]);
            try { $migration->down(); $this->fail('Expected configuration retention refusal.'); } catch (\RuntimeException $error) { $this->assertStringContainsString('retained', $error->getMessage()); }
            $row->delete();
        }
    }

    public function test_destructive_down_refuses_initial_intent_even_without_successful_route(): void
    {
        $root = $this->incident(); $id = app(IncidentRoutingService::class)->stageForSubmitted($root->id, $this->reporter); $this->assertGreaterThan(0, $id);
        $migration = require database_path('migrations/2026_10_12_200700_create_incident_owner_routing.php'); $this->expectException(\RuntimeException::class); $migration->down();
    }

    public function test_down_preserves_new_manual_ownership_audit_without_routing_settings_or_intent(): void
    {
        app(IncidentOwnershipService::class)->assign($this->manager, $this->incident(), $this->lead->id, 0, (string) Str::uuid(), null);
        $this->assertSame(1, IncidentOwnerChange::count()); $migration = require database_path('migrations/2026_10_12_200700_create_incident_owner_routing.php');
        try { $migration->down(); $this->fail('Expected ownership evidence retention refusal.'); } catch (\RuntimeException) {}
        $this->assertTrue(DB::getSchemaBuilder()->hasTable('incident_routing_intents'));
    }

    public function test_generated_constraint_names_are_explicit_and_bounded_and_no_backfill_exists(): void
    {
        $source = file_get_contents(database_path('migrations/2026_10_12_200700_create_incident_owner_routing.php'));
        preg_match_all('/(?:foreign|index|unique)\([^;\n]*,\s*\x27([^\x27]+)\x27\)/', $source, $matches);
        $this->assertNotEmpty($matches[1]); foreach ($matches[1] as $name) { $this->assertLessThanOrEqual(64, strlen($name)); }
        $this->assertStringNotContainsString('tenant_id', $source); $this->assertStringNotContainsString('updateOrCreate', $source);
    }
}
