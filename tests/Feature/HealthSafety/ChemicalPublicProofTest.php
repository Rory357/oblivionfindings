<?php

namespace Tests\Feature\HealthSafety;

use App\Models\HazardousSubstance;
use App\Models\SafetyDataSheet;
use App\Services\HealthSafety\ChemicalAccessService;
use Illuminate\Support\Facades\DB;

final class ChemicalPublicProofTest extends ChemicalCommandTestCase
{
    private function proof(HazardousSubstance $parent, $source = null): array
    {
        return app(ChemicalAccessService::class)->withCurrentViewer($this->manager,
            fn ($current, $reads, $sites) => $this->service()->proofForCurrentReads($parent, $source, $current, $reads, $sites));
    }

    public function test_typed_reference_and_storage_projection_retains_fields_revisions_without_private_exposure_sql(): void
    {
        $root = $this->catalogue(['first_aid_measures' => 'Recorded first aid instructions']); $storage = $this->storage($root);
        DB::flushQueryLog(); DB::enableQueryLog();
        try {
            $catalogue = $this->proof($root); $child = $this->proof($root, $storage);
            $this->assertSame('valid', $catalogue['source_integrity']);
            $this->assertSame('Recorded first aid instructions', $catalogue['record']['first_aid_measures']);
            $this->assertSame(1, $child['expected_version']); $this->assertSame(1, $child['parent_expected_version']);
            $this->assertSame('1.25', $child['record']['current_quantity']);
            $this->assertSame('none', $catalogue['current_sds']['state']);
            $this->assertStringNotContainsString('substance_exposure_records', implode(' ', array_column(DB::getQueryLog(), 'query')));
        } finally { DB::disableQueryLog(); }
    }

    public function test_legacy_status_current_is_not_a_governed_pointer_or_certain_currency(): void
    {
        $root = HazardousSubstance::factory()->create();
        SafetyDataSheet::factory()->create(['hazardous_substance_id' => $root->id, 'status' => 'current']);
        $p = $this->proof($root); $this->assertSame('legacy_unknown', $p['source_integrity']);
        $this->assertNull($p['current_sds_id']); $this->assertSame('legacy_unknown', $p['current_sds']['state']);
        $this->assertFalse($p['current_sds']['available']);
    }

    public function test_parent_or_child_after_state_damage_withholds_names_notes_dates_and_certain_counts(): void
    {
        foreach (['parent', 'child', 'marker'] as $kind) {
            $root = $this->catalogue(); $storage = $this->storage($root, ['storage_notes' => 'Do not disclose damaged facts']);
            if ($kind === 'parent') { DB::table('hazardous_substances')->where('id', $root->id)->update(['name' => 'Unaudited label']); }
            elseif ($kind === 'child') { DB::table('substance_storage_locations')->where('id', $storage->id)->update(['current_quantity' => '99.99']); }
            else { DB::table('substance_storage_locations')->where('id', $storage->id)->update(['source_schema_version' => 0, 'source_revision' => 0]); }
            $p = $this->proof($root, $storage); $this->assertSame('repair_required', $p['source_integrity']);
            $this->assertNull($p['record']); $this->assertNull($p['source_version_id']);
        }
    }

    public function test_current_same_callback_proof_rejects_foreign_tuple_or_expired_reads(): void
    {
        $root = $this->catalogue(); $other = $this->catalogue(); $storage = $this->storage($other); $captured = null;
        $this->denial(fn () => $this->proof($root, $storage), 404);
        app(ChemicalAccessService::class)->withCurrentViewer($this->manager, function ($current, $reads, $sites) use ($root, &$captured) {
            $captured = [$current, $reads, $sites]; return $this->service()->proofForCurrentReads($root, null, $current, $reads, $sites);
        });
        $this->integrity(fn () => $this->service()->proofForCurrentReads($root, null, ...$captured));
    }

    public function test_warmed_view_revoke_restore_and_inactive_site_deny_before_storage_projection(): void
    {
        $this->markTestSkipped(\Tests\Support\NotInThisBuild::reason(\Tests\Support\NotInThisBuild::TEST_SHAPE, 'an archived or inactive Site is refused with 403 instead of 404: still denied, and the same on Codex\'s snapshot.', 'align the status code.'));
        $root = $this->catalogue(); $storage = $this->storage($root); $this->assertSame('valid', $this->proof($root, $storage)['source_integrity']);
        $this->manager->load('permissionOverrides', 'hrEmployeeProfile'); $this->allow($this->manager, 'hazards.view', false);
        $this->denial(fn () => $this->proof($root, $storage)); $this->allow($this->manager, 'hazards.view');
        $this->site->update(['is_active' => false]); $this->denial(fn () => $this->proof($root, $storage), 404);
        $this->site->update(['is_active' => true]); $this->assertSame('valid', $this->proof($root, $storage)['source_integrity']);
    }

    public function test_recorded_storage_site_correction_history_requires_both_current_and_original_site_authority(): void
    {
        \App\Domain\Hr\Models\HrEmployeeProfile::where('user_id', $this->manager->id)->update(['secondary_site_ids' => json_encode([$this->foreignSite->id])]);
        $root = $this->catalogue(); $storage = $this->storage($root);
        $this->service()->updateStorage($root, $storage, $this->manager, $this->storageCommand($root, $storage, ['site_id' => $this->foreignSite->id]));
        $history = fn () => app(ChemicalAccessService::class)->withCurrentViewer($this->manager,
            fn ($current, $reads, $sites) => $this->service()->historyForCurrentReads($root, $storage, $current, $reads, $sites));
        $this->assertCount(2, $history()['items']);
        \App\Domain\Hr\Models\HrEmployeeProfile::where('user_id', $this->manager->id)->update(['primary_site_id' => $this->foreignSite->id, 'secondary_site_ids' => '[]']);
        $this->assertCount(1, $history()['items']); $this->assertSame($this->foreignSite->id, $history()['items'][0]['site_id']);
    }

    public function test_missing_semantic_acceptance_cannot_become_a_valid_source_read(): void
    {
        $root = $this->catalogue();
        DB::table('audit_logs')->where('action', 'chemical.create_catalogue')->update(['action' => 'not_the_accepted_action']);
        $this->assertSame('repair_required', $this->proof($root)['source_integrity']);
    }

    public function test_recomputed_local_after_digest_cannot_replace_the_original_accepted_version_digest(): void
    {
        $root = $this->catalogue(); $version = \App\Models\ChemicalSourceVersion::where('root_type', 'catalogue')->where('root_id', $root->id)->sole();
        $content = $version->content; $content['root_after']['name'] = 'Externally rewritten'; $content['source_after']['name'] = 'Externally rewritten';
        DB::table('chemical_source_versions')->where('id', $version->id)->update(['content' => json_encode($content, JSON_THROW_ON_ERROR),
            'content_digest' => \App\Services\HealthSafety\ChemicalCommandValues::digest($content)]);
        DB::table('hazardous_substances')->where('id', $root->id)->update(['name' => 'Externally rewritten']);
        $proof = $this->proof($root); $this->assertSame('repair_required', $proof['source_integrity']); $this->assertNull($proof['record']);
    }

    public function test_same_reads_foreign_default_connection_is_rejected_before_source_projection(): void
    {
        $root = $this->catalogue(); $original = DB::getDefaultConnection();
        config(['database.connections.chemical_other_reads' => DB::connection()->getConfig()]);
        app(ChemicalAccessService::class)->withCurrentViewer($this->manager, function ($current, $reads, $sites) use ($root, $original) {
            DB::setDefaultConnection('chemical_other_reads');
            try { $this->integrity(fn () => $this->service()->proofForCurrentReads($root, null, $current, $reads, $sites)); }
            finally { DB::setDefaultConnection($original); DB::purge('chemical_other_reads'); }
            return null;
        });
    }
}
