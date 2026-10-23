<?php

namespace Tests\Feature\HealthSafety;

use App\Models\AuditLog;
use App\Models\ChemicalExposureEvidenceStaging;
use App\Models\HazardousSubstance;
use App\Models\SubstanceExposureRecord;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Schema mutations are prepared only for the sole runner's guarded physical MySQL test connection. */
final class ChemicalExposureSourceMigrationTest extends ChemicalExposureCommandTestCase
{
    protected bool $physicalCommits = true;
    private function migration(): object { return require database_path('migrations/2026_10_12_200200_create_chemical_exposure_source_history.php'); }
    private function projectionMigration(): object { return require database_path('migrations/2026_10_12_200300_create_chemical_exposure_hs_projections.php'); }
    private function withoutProjection(callable $operation): void
    {
        $dependent = $this->projectionMigration(); $dependent->down();
        try { $operation(); } finally { $dependent->up(); }
    }
    private function legacy(array $facts = []): SubstanceExposureRecord
    {
        return SubstanceExposureRecord::factory()->create(['hazardous_substance_id' => HazardousSubstance::factory()->create()->id,
            'user_id' => $this->worker->id, 'site_id' => $this->site->id, 'created_by' => $this->manager->id,
            'exposed_at' => '2026-10-12 00:00:00', ...$facts]);
    }
    private function refused(callable $operation): void
    {
        try { $operation(); $this->fail('Retained source or dependent schema must refuse destructive rollback.'); }
        catch (\RuntimeException $failure) { $this->assertNotSame('', $failure->getMessage()); }
        foreach (['chemical_exposure_source_versions', 'chemical_exposure_command_receipts', 'chemical_exposure_reviews',
            'chemical_exposure_followups', 'chemical_exposure_evidence', 'chemical_exposure_evidence_staging',
            'chemical_exposure_evidence_cleanups', 'chemical_exposure_source_deliveries'] as $table) {
            $this->assertTrue(Schema::hasTable($table));
        }
        $this->assertTrue(Schema::hasColumn('substance_exposure_records', 'source_revision'));
    }

    public function test_legacy_worker_recorder_incident_and_private_report_fields_remain_verbatim_without_backfill(): void
    {
        $row = $this->legacy(['symptoms' => 'Retained reported fact', 'first_aid_given' => 'Retained treatment',
            'medical_treatment' => 'medical', 'medical_attention_sought' => true, 'exposure_duration' => '5 minutes']);
        $this->assertSame($this->worker->id, $row->user_id); $this->assertSame($this->manager->id, $row->created_by);
        $this->assertSame('Retained reported fact', $row->symptoms); $this->assertSame('Retained treatment', $row->first_aid_given);
        $this->assertSame('medical', $row->medical_treatment); $this->assertTrue($row->medical_attention_sought);
        $this->assertSame(0, $row->source_schema_version); $this->assertSame(0, $row->source_revision);
        $this->assertNull($row->original_context); $this->assertSame('legacy_unknown', $this->proof($row)['attribution_state']);
        $this->assertSame(0, DB::table('chemical_exposure_source_versions')->count());
    }

    public function test_even_empty_downstream_projection_schema_refuses_before_any_private_table_is_removed(): void
    {
        $this->assertSame(0, DB::table('chemical_exposure_hs_projections')->count());
        $this->refused(fn () => $this->migration()->down());
        $this->assertTrue(Schema::hasTable('chemical_exposure_hs_projections'));
        $this->assertTrue(Schema::hasTable('chemical_command_intents'));
    }

    public function test_retained_actual_acceptance_prevents_down_after_dependency_reversed(): void
    {
        $root = $this->exposure(['symptoms' => 'Immutable retained fact']);
        $this->withoutProjection(function () use ($root): void {
            $this->refused(fn () => $this->migration()->down());
            $this->assertSame(1, $root->fresh()->source_revision); $this->assertSame('Immutable retained fact', $root->fresh()->symptoms);
            $this->assertSame(1, DB::table('chemical_exposure_source_versions')->count());
            $this->assertSame(1, DB::table('chemical_exposure_command_receipts')->count());
        });
    }

    public function test_nonzero_marker_or_explicit_context_without_history_refuses_rollback(): void
    {
        $row = $this->legacy();
        $this->withoutProjection(function () use ($row): void {
            foreach ([['source_schema_version' => 1], ['source_revision' => 1], ['original_context' => '{}']] as $metadata) {
                DB::table('substance_exposure_records')->where('id', $row->id)->update([
                    'source_schema_version' => 0, 'source_revision' => 0, 'original_context' => null, ...$metadata]);
                $this->refused(fn () => $this->migration()->down());
            }
        });
    }

    public function test_rejected_physical_attempt_and_private_bytes_remain_discoverable_when_down_refused(): void
    {
        Storage::fake('private'); $root = $this->exposure(); $key = (string) Str::uuid();
        AuditLog::creating(fn ($row) => $row->action === 'chemical.exposure.upload_evidence' ? false : null);
        try {
            $this->integrity(fn () => $this->evidenceService()->upload($root, $this->manager,
                UploadedFile::fake()->createWithContent('reported.pdf', "%PDF-1.4\nActual retained rejected bytes\n%%EOF"),
                $this->privateCommand($root, [], $key)));
        } finally { AuditLog::flushEventListeners(); }
        $stage = ChemicalExposureEvidenceStaging::sole(); $before = Storage::disk('private')->get($stage->path);
        $this->withoutProjection(function () use ($stage, $before): void {
            $this->refused(fn () => $this->migration()->down());
            $this->assertNull($stage->fresh()->receipt_id); $this->assertSame('cleanup_pending', $stage->fresh()->state);
            $this->assertSame($before, Storage::disk('private')->get($stage->path));
        });
    }

    public function test_empty_private_schema_reverse_and_reapply_preserves_legacy_and_shared_public_intents(): void
    {
        $row = $this->legacy(['circumstances' => 'Historical occurrence preserved', 'first_aid_given' => 'Historical treatment']);
        $public = $this->catalogue(); $sharedIntentCount = DB::table('chemical_command_intents')->count();
        $reversed = [];
        try {
            $dependent = $this->projectionMigration(); $dependent->down(); $reversed[] = $dependent;
            $private = $this->migration(); $private->down(); $reversed[] = $private;
            $this->assertDatabaseHas('substance_exposure_records', ['id' => $row->id, 'user_id' => $this->worker->id,
                'created_by' => $this->manager->id, 'circumstances' => 'Historical occurrence preserved', 'first_aid_given' => 'Historical treatment']);
            $this->assertSame($sharedIntentCount, DB::table('chemical_command_intents')->count());
            $this->assertDatabaseHas('hazardous_substances', ['id' => $public->id, 'source_revision' => 1]);
        } finally { foreach (array_reverse($reversed) as $migration) { $migration->up(); } }
        $this->assertSame(0, $row->fresh()->source_revision); $this->assertNull($row->fresh()->original_context);
        $this->assertTrue(Schema::hasTable('chemical_exposure_hs_projections'));
    }

    public function test_actual_mysql_constraint_and_index_names_are_bounded_and_private_receipt_anchors_are_present(): void
    {
        $rows = DB::select("SELECT CONSTRAINT_NAME AS name FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'chemical_exposure_%'");
        foreach ($rows as $row) { $this->assertLessThanOrEqual(64, strlen($row->name)); }
        $indexes = DB::select("SELECT INDEX_NAME AS name FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'chemical_exposure_%'");
        foreach ($indexes as $row) { $this->assertLessThanOrEqual(64, strlen($row->name)); }
        foreach (['source_content_digest', 'source_tuple', 'source_tuple_digest'] as $column) {
            $this->assertTrue(Schema::hasColumn('chemical_exposure_command_receipts', $column));
        }
        $this->assertTrue(Schema::hasColumn('chemical_exposure_evidence_staging', 'command_values'));
    }
}
