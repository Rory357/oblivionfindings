<?php

namespace Tests\Feature\HealthSafety;

use App\Models\HazardousSubstance;
use App\Models\SafetyDataSheet;
use App\Models\SubstanceStorageLocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ChemicalSourceMigrationTest extends ChemicalCommandTestCase
{
    protected bool $physicalCommits = true;
    private function migration(): object { return require database_path('migrations/2026_10_12_200100_create_chemical_public_source_history.php'); }

    public function test_existing_catalogue_storage_and_sds_keep_real_fields_with_legacy_zero_and_no_governed_pointer(): void
    {
        $root = HazardousSubstance::factory()->create(['name' => 'Retained legacy', 'status' => 'inactive']);
        $sheet = SafetyDataSheet::factory()->create(['hazardous_substance_id' => $root->id, 'document_path' => 'health-safety/sds/legacy.pdf']);
        $storage = SubstanceStorageLocation::factory()->create(['hazardous_substance_id' => $root->id, 'site_id' => $this->site->id,
            'storage_notes' => 'Retained controls', 'current_quantity' => '2.50']);
        foreach ([$root, $sheet, $storage] as $row) { $this->assertSame(0, $row->fresh()->source_schema_version); $this->assertSame(0, $row->fresh()->source_revision); }
        $this->assertNull($root->fresh()->current_sds_id); $this->assertSame('inactive', $root->status);
        $this->assertSame('2.50', $storage->fresh()->current_quantity); $this->assertSame('Retained controls', $storage->storage_notes);
        $this->assertNull($sheet->fresh()->document_sha256);
    }

    public function test_retained_public_acceptance_refuses_down_without_touching_existing_records_or_bytes(): void
    {
        $root = $this->catalogue(); $this->integrity(fn () => $this->migration()->down());
        $this->assertTrue(Schema::hasTable('chemical_command_receipts')); $this->assertSame(1, $root->fresh()->source_revision);
    }

    public function test_governed_marker_without_history_also_refuses_down(): void
    {
        $root = HazardousSubstance::factory()->create();
        DB::table('hazardous_substances')->where('id', $root->id)->update(['source_revision' => 1]);
        $this->integrity(fn () => $this->migration()->down()); $this->assertTrue(Schema::hasColumn('hazardous_substances', 'source_revision'));
    }

    public function test_retained_document_metadata_or_local_alias_refuses_down_even_without_version(): void
    {
        $root = HazardousSubstance::factory()->create(); $sheet = SafetyDataSheet::factory()->create(['hazardous_substance_id' => $root->id]);
        DB::table('safety_data_sheets')->where('id', $sheet->id)->update(['document_disk' => 'local']);
        $this->integrity(fn () => $this->migration()->down());
        DB::table('safety_data_sheets')->where('id', $sheet->id)->update(['document_disk' => 'private', 'document_sha256' => str_repeat('a', 64)]);
        $this->integrity(fn () => $this->migration()->down()); $this->assertTrue(Schema::hasColumn('safety_data_sheets', 'document_disk'));
    }

    public function test_empty_governed_schema_down_and_up_preserves_all_legacy_source_fields(): void
    {
        $root = HazardousSubstance::factory()->create(['name' => 'Down preserved definition', 'first_aid_measures' => 'Retained instructions']);
        $sheet = SafetyDataSheet::factory()->create(['hazardous_substance_id' => $root->id, 'document_path' => 'health-safety/sds/old.pdf']);
        $storage = SubstanceStorageLocation::factory()->create(['hazardous_substance_id' => $root->id, 'site_id' => $this->site->id]);
        $reversed = [];
        try {
            foreach (['2026_10_12_200300_create_chemical_exposure_hs_projections.php' => 'chemical_exposure_hs_projections',
                '2026_10_12_200200_create_chemical_exposure_source_history.php' => 'chemical_exposure_source_versions'] as $file => $table) {
                if (Schema::hasTable($table)) {
                    $dependent = require database_path('migrations/'.$file);
                    $dependent->down(); $reversed[] = $dependent;
                }
            }
            $public = $this->migration(); $public->down(); $reversed[] = $public;
            $this->assertDatabaseHas('hazardous_substances', ['id' => $root->id, 'first_aid_measures' => 'Retained instructions']);
            $this->assertDatabaseHas('safety_data_sheets', ['id' => $sheet->id, 'document_path' => 'health-safety/sds/old.pdf']);
            $this->assertDatabaseHas('substance_storage_locations', ['id' => $storage->id]);
        } finally { foreach (array_reverse($reversed) as $migration) { $migration->up(); } }
        $this->assertSame(0, $root->fresh()->source_revision); $this->assertNull($root->fresh()->current_sds_id);
    }

    public function test_empty_private_dependency_refuses_public_down_before_any_table_is_removed(): void
    {
        $this->assertTrue(Schema::hasTable('chemical_exposure_source_versions'));
        $this->assertSame(0, DB::table('chemical_command_intents')->count());
        $this->integrity(fn () => $this->migration()->down());
        foreach (['chemical_command_intents', 'chemical_command_receipts', 'chemical_source_versions',
            'chemical_evidence_staging', 'chemical_evidence_cleanup'] as $table) { $this->assertTrue(Schema::hasTable($table)); }
        $this->assertTrue(Schema::hasColumn('hazardous_substances', 'current_sds_id'));
    }

    public function test_database_unique_uuid_arbitrates_different_source_families_without_second_intent(): void
    {
        $r = $this->service()->create($this->manager, ['idempotency_key' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'First intent', 'physical_form' => 'solid']);
        $row = DB::table('chemical_command_intents')->where('idempotency_key', $r['idempotency_key'])->first();
        $copy = (array) $row; unset($copy['id']); $copy['source_type'] = 'exposure'; $copy['source_id'] = 999;
        $this->assertSame(0, DB::table('chemical_command_intents')->insertOrIgnore($copy));
        $this->assertSame(1, DB::table('chemical_command_intents')->where('idempotency_key', $r['idempotency_key'])->count());
    }
}
