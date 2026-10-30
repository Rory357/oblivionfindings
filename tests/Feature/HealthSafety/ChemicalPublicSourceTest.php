<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\ChemicalCommandReceipt;
use App\Models\ChemicalSourceVersion;
use App\Models\HazardousSubstance;
use App\Services\HealthSafety\ChemicalCommandLedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Complete public catalogue lifecycle, integrity and current capture/manage boundaries. */
final class ChemicalPublicSourceTest extends ChemicalCommandTestCase
{
    public function test_every_existing_catalogue_field_is_retained_with_actual_recorded_version_and_minimal_audit(): void
    {
        $facts = ['name' => 'Full recorded chemical', 'common_name' => 'Cleaner', 'un_number' => 'UN1234', 'hsno_approval' => 'Provided approval',
            'hsno_classification' => 'Provided classification', 'hazard_classifications' => ['Recorded classification'], 'ghs_pictograms' => ['corrosion'],
            'signal_word' => 'Danger', 'hazard_statements' => 'Provided hazard statement', 'precautionary_statements' => 'Provided precaution',
            'physical_form' => 'liquid', 'first_aid_measures' => 'Provided first aid', 'firefighting_measures' => 'Provided firefighting',
            'spill_procedures' => 'Provided spill controls', 'handling_precautions' => 'Provided handling', 'storage_requirements' => 'Provided storage',
            'ppe_required' => 'Provided PPE', 'exposure_limit_type' => 'Provided limit type', 'exposure_limit_value' => 'Provided limit value',
            'requires_tracking' => false, 'is_controlled_substance' => true];
        $input = ['idempotency_key' => strtoupper((string) Str::uuid()), ...$facts];
        $r = $this->service()->create($this->manager, $input);
        $root = HazardousSubstance::findOrFail($r['root_id']);
        foreach ($facts as $field => $value) { $this->assertSame($value, $root->$field); }
        $this->assertSame(1, $root->source_revision);
        $this->assertNull($root->current_sds_id);
        $keys = array_keys($r); $expectedKeys = ChemicalCommandLedgerService::RECEIPT_KEYS;
        sort($keys); sort($expectedKeys); $this->assertSame($expectedKeys, $keys);
        $this->assertSame(strtolower($input['idempotency_key']), $r['idempotency_key']);
        $this->assertArrayNotHasKey('replayed', $r); $this->assertArrayNotHasKey('receipt_id', $r);
        $version = ChemicalSourceVersion::findOrFail($r['source_version_id']);
        $this->assertNull($version->content['root_before']);
        $this->assertSame($facts['first_aid_measures'], $version->content['root_after']['first_aid_measures']);
        $audit = AuditLog::where('action', 'chemical.create_catalogue')->sole();
        $this->assertStringNotContainsString('Provided first aid', json_encode($audit->meta, JSON_THROW_ON_ERROR));
    }

    public function test_current_create_only_permission_records_without_enduring_reference_read_or_manage_grant(): void
    {
        $actor = $this->staff(['hazards.create']);
        $r = $this->service()->create($actor, ['idempotency_key' => (string) Str::uuid(), 'name' => 'Captured definition', 'physical_form' => 'solid']);
        $this->assertTrue($r['accepted']);
        $this->assertSame($r, $this->service()->confirmationForViewer($actor, 'catalogue', $r['root_id'], $r['idempotency_key']));
        $this->denial(fn () => $this->service()->update($r['root_id'], $actor, ['idempotency_key' => (string) Str::uuid(), 'expected_version' => 1, 'name' => 'Denied']));
        $this->denial(fn () => $this->service()->downloadProof($r['root_id'], 1, $actor));
    }

    public function test_reasoned_inactive_removed_and_reactivated_lifecycle_retains_prior_provenance(): void
    {
        $root = $this->catalogue();
        foreach (['inactive', 'removed', 'active'] as $status) {
            $r = $this->service()->status($root, $this->manager, $this->command($root, ['status' => $status, 'reason' => 'Recorded operational reason']));
            $this->assertSame($status, $root->fresh()->status);
            $this->assertTrue($r['changed']);
        }
        $this->assertNull($root->fresh()->status_reason);
        $this->assertSame(4, ChemicalSourceVersion::where('root_id', $root->id)->where('root_type', 'catalogue')->count());
    }

    public function test_status_reason_and_reviewed_revision_are_required_with_no_generic_status_bypass(): void
    {
        $root = $this->catalogue();
        $this->validation(fn () => $this->service()->status($root, $this->manager, $this->command($root, ['status' => 'inactive', 'reason' => ' '])), 'reason');
        $this->validation(fn () => $this->service()->update($root, $this->manager, $this->command($root, ['status' => 'removed'])), 'command');
        $this->validation(fn () => $this->service()->update($root, $this->manager, ['idempotency_key' => (string) Str::uuid(), 'name' => 'No reviewed revision']), 'expected_version');
        $this->assertSame('active', $root->fresh()->status);
    }

    public function test_real_update_preserves_unchanged_fields_and_exact_no_op_keeps_revision_without_fabricated_version(): void
    {
        $root = $this->catalogue(['first_aid_measures' => 'Retained factual text']);
        $r = $this->service()->update($root, $this->manager, $this->command($root, ['common_name' => 'Recorded alternate name']));
        $this->assertSame(2, $r['version']);
        $this->assertSame('Retained factual text', $root->fresh()->first_aid_measures);
        $noOp = $this->service()->update($root, $this->manager, $this->command($root, ['common_name' => 'Recorded alternate name']));
        $this->assertFalse($noOp['changed']); $this->assertNull($noOp['source_version_id']);
        $this->assertSame(2, $noOp['version']);
        $this->assertSame(2, ChemicalSourceVersion::where('root_id', $root->id)->where('root_type', 'catalogue')->count());
    }

    public function test_legacy_definition_upgrade_records_current_before_without_inventing_a_prior_version_or_sds_pointer(): void
    {
        $root = HazardousSubstance::factory()->create(['name' => 'Legacy definition']);
        $r = $this->service()->update($root, $this->manager, $this->command($root, ['name' => 'Reviewed definition']));
        $this->assertSame(1, $r['version']);
        $version = ChemicalSourceVersion::findOrFail($r['source_version_id']);
        $this->assertSame(0, $version->content['root_before']['source_schema_version']);
        $this->assertNull($root->fresh()->current_sds_id);
        $this->assertSame(1, ChemicalSourceVersion::where('root_id', $root->id)->where('root_type', 'catalogue')->count());
    }

    public function test_warmed_manage_and_current_staff_revoke_and_restore_recheck_actual_command_authority(): void
    {
        $root = $this->catalogue(); $input = $this->command($root, ['common_name' => 'Reviewed']);
        $this->manager->load('roles.permissions', 'permissionOverrides', 'hrEmployeeProfile');
        $this->allow($this->manager, 'hazards.manage', false);
        $this->denial(fn () => $this->service()->update($root, $this->manager, $input));
        $this->allow($this->manager, 'hazards.manage');
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['is_active' => false]);
        $this->denial(fn () => $this->service()->update($root, $this->manager, $input));
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['is_active' => true]);
        $this->assertTrue($this->service()->update($root, $this->manager, $input)['accepted']);
    }

    public function test_suppressed_audit_rolls_back_canonical_source_version_and_receipt(): void
    {
        $root = $this->catalogue(); $before = $root->attributesToArray();
        AuditLog::creating(fn ($row) => $row->action === 'chemical.update_catalogue' ? false : null);
        try { $this->integrity(fn () => $this->service()->update($root, $this->manager, $this->command($root, ['name' => 'Must roll back']))); }
        finally { AuditLog::flushEventListeners(); }
        $this->assertSame($before, $root->fresh()->attributesToArray());
        $this->assertSame(1, ChemicalSourceVersion::where('root_type', 'catalogue')->where('root_id', $root->id)->count());
        $this->assertSame(1, ChemicalCommandReceipt::where('root_type', 'catalogue')->where('root_id', $root->id)->count());
    }

    public function test_suppressed_version_or_receipt_save_cannot_echo_success(): void
    {
        $root = $this->catalogue();
        foreach ([ChemicalSourceVersion::class, ChemicalCommandReceipt::class] as $model) {
            $model::creating(fn () => false);
            try { $this->integrity(fn () => $this->service()->update($root, $this->manager, $this->command($root, ['name' => 'Refused write']))); }
            finally { $model::flushEventListeners(); }
            $this->assertSame('Recorded disinfectant', $root->fresh()->name);
        }
    }

    public function test_tampered_after_digest_tuple_and_downgraded_marker_fail_closed(): void
    {
        foreach (['field', 'digest', 'marker'] as $damage) {
            $root = $this->catalogue();
            if ($damage === 'field') { DB::table('hazardous_substances')->where('id', $root->id)->update(['name' => 'Unaudited']); }
            elseif ($damage === 'digest') { DB::table('chemical_source_versions')->where('root_id', $root->id)->where('root_type', 'catalogue')->update(['content_digest' => str_repeat('0', 64)]); }
            else { DB::table('hazardous_substances')->where('id', $root->id)->update(['source_schema_version' => 0, 'source_revision' => 0]); }
            $this->integrity(fn () => $this->service()->update($root, $this->manager, $this->command($root, ['common_name' => 'Cannot hide repair'])));
        }
    }

    public function test_stale_revision_keeps_prior_record_without_new_version_or_audit(): void
    {
        $root = $this->catalogue(); $stale = $this->command($root, ['name' => 'Stale intent']);
        $this->service()->update($root, $this->manager, $this->command($root, ['name' => 'Newer accepted fact']));
        $this->validation(fn () => $this->service()->update($root, $this->manager, $stale), 'expected_version');
        $this->assertSame('Newer accepted fact', $root->fresh()->name);
        $this->assertSame(2, $root->fresh()->source_revision);
    }

    public function test_first_canonical_parent_read_locks_for_update_without_preceding_shared_upgrade(): void
    {
        $root = $this->catalogue(); $input = $this->command($root, ['common_name' => 'Canonical lock admission']);
        DB::flushQueryLog(); DB::enableQueryLog();
        try {
            $this->service()->update($root, $this->manager, $input);
            $queries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains(strtolower($query['query']), 'from `hazardous_substances`'))->values();
            $this->assertNotEmpty($queries); $this->assertStringContainsString('for update nowait', strtolower($queries[0]['query']));
            $this->assertFalse($queries->contains(fn ($query) => str_contains(strtolower($query['query']), 'for share')));
        } finally { DB::disableQueryLog(); }
    }

    public function test_canonical_lock_mode_never_authorizes_a_foreign_physical_source_or_withdrawn_view(): void
    {
        $root = $this->catalogue(); $configuration = DB::connection()->getConfig(); config(['database.connections.chemical_foreign' => $configuration]);
        DB::purge('chemical_foreign'); $foreign = $root->replicate(); $foreign->id = $root->id; $foreign->setConnection('chemical_foreign');
        try { $this->integrity(fn () => $this->service()->update($foreign, $this->manager, $this->command($root, ['common_name' => 'Foreign connection']))); }
        finally { DB::purge('chemical_foreign'); }
        $this->allow($this->manager, 'hazards.view', false);
        $this->denial(fn () => app(\App\Services\HealthSafety\ChemicalAccessService::class)->withCurrentViewer($this->manager,
            fn ($current, $reads) => app(\App\Services\HealthSafety\ChemicalAccessService::class)->assertCatalogue($root, $current, $reads, 'view', true)));
        $this->assertSame('Recorded disinfectant', $root->fresh()->name);
    }

    public function test_recorded_acceptance_keeps_actual_microseconds_separately_from_source_date_fields(): void
    {
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-12T01:00:00.123456Z'));
        $root = $this->catalogue(); $version = ChemicalSourceVersion::where('root_type', 'catalogue')->where('root_id', $root->id)->sole();
        $this->assertSame('123456', $version->recorded_at->format('u'));
        $this->assertSame('2026-10-12 01:00:00.123456', $version->content['recorded_at']);
        $receipt = ChemicalCommandReceipt::where('root_type', 'catalogue')->where('root_id', $root->id)->sole();
        $this->assertSame('123456', $receipt->created_at->format('u'));
    }
}
