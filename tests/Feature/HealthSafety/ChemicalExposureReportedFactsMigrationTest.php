<?php

namespace Tests\Feature\HealthSafety;

use App\Models\ChemicalExposureSourceVersion;
use App\Models\SubstanceExposureRecord;
use Illuminate\Support\Facades\DB;

/** Requires sole-runner admission to the guarded physical MySQL test schema. No migration/test has been executed. */
final class ChemicalExposureReportedFactsMigrationTest extends ChemicalExposureCommandTestCase
{
    protected bool $physicalCommits = true;
    private function migration(): object { return require database_path('migrations/2026_10_12_200600_add_chemical_exposure_reported_facts.php'); }
    private function old(array $facts = []): SubstanceExposureRecord
    {
        return SubstanceExposureRecord::factory()->create(['hazardous_substance_id' => $this->catalogue()->id,
            'user_id' => $this->worker->id, 'site_id' => $this->site->id, 'created_by' => $this->manager->id,
            'source_schema_version' => 0, 'source_revision' => 0, 'related_incident_id' => null,
            'medical_attention_sought' => false, 'incident_reported' => true, ...$facts]);
    }
    private function refused(): void
    {
        DB::enableQueryLog(); DB::flushQueryLog();
        try {
            try { $this->migration()->down(); $this->fail('Meaningful reported evidence must refuse down.'); }
            catch (\RuntimeException $error) { $this->assertNotSame('', $error->getMessage()); }
            $queries = DB::getQueryLog();
        } finally { DB::disableQueryLog(); }
        foreach ($queries as $query) { $this->assertStringNotContainsString('alter table', strtolower($query['query'])); }
    }

    public function test_existing_zero_and_one_values_remain_verbatim_through_nullable_change(): void
    {
        $this->migration()->down();
        try {
            $root = $this->old(); $this->migration()->up();
            $this->assertFalse($root->fresh()->medical_attention_sought); $this->assertTrue($root->fresh()->incident_reported);
            $this->assertSame(0, $root->fresh()->source_revision);
            $this->assertSame(0, ChemicalExposureSourceVersion::where('exposure_id', $root->id)->count());
        } finally { $this->migration()->up(); }
    }

    public function test_future_raw_insert_defaults_are_null_not_manufactured_no(): void
    {
        $catalogue = $this->catalogue();
        $id = DB::table('substance_exposure_records')->insertGetId(['hazardous_substance_id' => $catalogue->id,
            'user_id' => $this->worker->id, 'site_id' => $this->site->id, 'created_by' => $this->manager->id,
            'exposed_at' => '2026-10-12 00:00:00', 'exposure_type' => 'skin_contact', 'created_at' => now(), 'updated_at' => now()]);
        $root = SubstanceExposureRecord::findOrFail($id); $this->assertNull($root->medical_attention_sought); $this->assertNull($root->incident_reported);
        $this->assertSame(0, $root->source_revision);
    }

    public function test_null_answer_including_soft_deleted_source_refuses_before_any_destructive_alter(): void
    {
        $root = $this->old(['medical_attention_sought' => null]); $root->delete(); $this->refused();
        $this->assertNull(SubstanceExposureRecord::withTrashed()->findOrFail($root->id)->medical_attention_sought);
    }

    public function test_nonnull_reported_answers_still_retain_contract_and_refuse_down(): void
    {
        $root = $this->exposure(['medical_attention_sought' => false, 'incident_reported' => false]); $this->refused();
        $this->assertFalse($root->fresh()->medical_attention_sought); $this->assertSame(1, $root->fresh()->source_revision);
        $this->assertSame('recorded', $this->proof($root)['attribution_state']);
    }

    public function test_present_null_or_malformed_contract_is_not_treated_as_absent_legacy_evidence(): void
    {
        $r = $this->capture(['medical_attention_sought' => false, 'incident_reported' => false]);
        $v = ChemicalExposureSourceVersion::findOrFail($r['source_version_id']);
        foreach ([null, [], 'corrupt-contract'] as $corrupt) {
            $content = $v->content; $content['facts']['reported_facts'] = $corrupt;
            // Deliberate retained-state corruption, not a fabricated accepted source fixture.
            DB::table('chemical_exposure_source_versions')->where('id', $v->id)->update(['content' => json_encode($content)]);
            $this->refused();
        }
    }

    public function test_empty_revert_restores_legacy_defaults_and_up_returns_nullable_schema(): void
    {
        $this->migration()->down();
        try {
            $catalogue = $this->catalogue();
            $id = DB::table('substance_exposure_records')->insertGetId(['hazardous_substance_id' => $catalogue->id,
                'user_id' => $this->worker->id, 'site_id' => $this->site->id, 'created_by' => $this->manager->id,
                'exposed_at' => '2026-10-12 00:00:00', 'exposure_type' => 'skin_contact', 'created_at' => now(), 'updated_at' => now()]);
            $root = SubstanceExposureRecord::findOrFail($id); $this->assertFalse($root->medical_attention_sought); $this->assertFalse($root->incident_reported);
        } finally { $this->migration()->up(); }
    }
}
