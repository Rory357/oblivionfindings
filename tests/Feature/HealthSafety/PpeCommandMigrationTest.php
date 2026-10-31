<?php

namespace Tests\Feature\HealthSafety;

use App\Models\PpeInventory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class PpeCommandMigrationTest extends PpeCommandTestCase
{
    protected bool $physicalCommits = true;
    private function migration(): object { return require database_path('migrations/2026_10_12_200000_create_ppe_command_history.php'); }
    public function test_legacy_fields_and_quantities_are_not_backfilled_or_reinterpreted(): void
    {
        // fresh(): a just-created model doesn't hold the columns' database defaults (0) until it's read back.
        $item = PpeInventory::factory()->create(['site_id' => $this->site->id, 'quantity' => 8, 'status' => 'maintenance'])->fresh();
        $this->assertSame(0, $item->source_schema_version); $this->assertSame(0, $item->source_revision);
        $this->assertSame(8, $item->quantity); $this->assertSame('maintenance', $item->status);
        $this->assertSame('legacy_unknown', $this->sourceState($item));
    }
    public function test_destructive_down_refuses_retained_acceptance_and_keeps_all_source_columns(): void
    {
        $item = $this->item();
        try { $this->migration()->down(); $this->fail('Expected retained PPE refusal.'); }
        catch (\RuntimeException $failure) { $this->assertStringContainsString('retained', $failure->getMessage()); }
        $this->assertTrue(Schema::hasTable('ppe_source_versions'));
        $this->assertTrue(Schema::hasColumn('ppe_inventory', 'source_revision'));
        $this->assertSame(1, $item->fresh()->source_revision);
    }
    public function test_marker_without_history_also_refuses_destructive_rollback(): void
    {
        $item = PpeInventory::factory()->create(['site_id' => $this->site->id]);
        DB::table('ppe_inventory')->where('id', $item->id)->update(['source_schema_version' => 1, 'source_revision' => 1]);
        try { $this->migration()->down(); $this->fail('Expected governed marker refusal.'); }
        catch (\RuntimeException $failure) { $this->assertStringContainsString('metadata', $failure->getMessage()); }
        $this->assertTrue(Schema::hasTable('ppe_command_receipts'));
    }
    public function test_empty_governed_schema_can_roll_back_and_reapply_without_removing_legacy_records(): void
    {
        $item = PpeInventory::factory()->create(['site_id' => $this->site->id, 'serial_number' => 'LEGACY-PRESERVED', 'quantity' => 7]);
        $this->migration()->down();
        $this->assertDatabaseHas('ppe_inventory', ['id' => $item->id, 'serial_number' => 'LEGACY-PRESERVED', 'quantity' => 7]);
        $this->migration()->up();
        $this->assertSame(0, $item->fresh()->source_revision);
        $this->assertTrue(Schema::hasTable('ppe_evidence_cleanups'));
    }
}
