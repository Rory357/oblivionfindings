<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\ChemicalSourceVersion;
use App\Models\HazardousSubstance;
use App\Models\SubstanceStorageLocation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ChemicalStorageCommandTest extends ChemicalCommandTestCase
{
    public function test_new_storage_accepts_null_own_revision_and_rejects_borrowing_catalogue_revision_as_its_own(): void
    {
        $parent = $this->catalogue(); $input = ['idempotency_key' => (string) Str::uuid(), 'expected_version' => null,
            'parent_expected_version' => 1, 'site_id' => $this->site->id, 'location_description' => 'Explicit new storage'];
        $r = $this->service()->createStorage($parent, $this->manager, $input);
        $this->assertNull($r['expected_version']); $this->assertSame(1, $r['parent_expected_version']); $this->assertSame(1, $r['version']);
        $this->assertSame($r, $this->service()->createStorage($parent, $this->manager, array_diff_key($input, ['expected_version' => true])));
        $this->validation(fn () => $this->service()->createStorage($parent, $this->manager,
            [...$input, 'idempotency_key' => (string) Str::uuid(), 'expected_version' => 1]), 'expected_version');
    }

    public function test_new_storage_has_distinct_root_and_reviewed_parent_revisions_and_preserves_all_old_fields(): void
    {
        $parent = $this->catalogue();
        $this->service()->update($parent, $this->manager, $this->command($parent, ['common_name' => 'Recorded parent revision2']));
        $input = ['idempotency_key' => (string) Str::uuid(), 'parent_expected_version' => 2, 'site_id' => $this->site->id,
            'location_description' => 'Fire cabinet', 'current_quantity' => '12.50', 'quantity_unit' => 'L', 'maximum_quantity' => '20.00',
            'container_type' => 'Recorded container', 'properly_labelled' => true, 'segregation_compliant' => false,
            'last_audit_date' => '2026-10-11', 'storage_notes' => 'Recorded controls'];
        $r = $this->service()->createStorage($parent, $this->manager, $input);
        $source = SubstanceStorageLocation::findOrFail($r['source_id']);
        $this->assertNull($r['expected_version']); $this->assertSame(2, $r['parent_expected_version']);
        $this->assertSame(1, $r['version']); $this->assertSame('storage', $r['root_type']);
        $this->assertSame($parent->id, $r['parent_id']);
        $this->assertSame(2, $parent->fresh()->source_revision);
        foreach (['location_description', 'current_quantity', 'quantity_unit', 'maximum_quantity', 'container_type', 'storage_notes', 'properly_labelled', 'segregation_compliant'] as $field) {
            $this->assertSame($input[$field], $source->$field);
        }
        $this->assertSame('2026-10-11', $source->last_audit_date->toDateString());
        $version = ChemicalSourceVersion::findOrFail($r['source_version_id']);
        $this->assertSame($this->site->id, $version->site_id);
        $this->assertNull($version->content['source_before']);
    }

    public function test_storage_update_requires_both_exact_reviewed_revisions_and_never_advances_catalogue(): void
    {
        $parent = $this->catalogue(); $source = $this->storage($parent);
        $this->validation(fn () => $this->service()->updateStorage($parent, $source, $this->manager,
            ['idempotency_key' => (string) Str::uuid(), 'expected_version' => 1, 'location_description' => 'Unreviewed parent']), 'parent_expected_version');
        $stale = $this->storageCommand($parent, $source, ['storage_notes' => 'Stale catalogue facts']);
        $this->service()->update($parent, $this->manager, $this->command($parent, ['handling_precautions' => 'Changed catalogue precautions']));
        $this->validation(fn () => $this->service()->updateStorage($parent, $source, $this->manager, $stale), 'parent_expected_version');
        $r = $this->service()->updateStorage($parent, $source, $this->manager, $this->storageCommand($parent, $source, ['storage_notes' => 'New reviewed controls']));
        $this->assertSame(2, $r['version']); $this->assertSame(2, $parent->fresh()->source_revision);
        $this->assertSame('New reviewed controls', $source->fresh()->storage_notes);
    }

    public function test_inventory_audit_records_actual_flags_date_and_quantity_without_calling_unknown_empty_stock_zero(): void
    {
        $parent = $this->catalogue(); $source = $this->storage($parent);
        $this->validation(fn () => $this->service()->auditInventory($parent, $source, $this->manager, $this->storageCommand($parent, $source,
            ['properly_labelled' => false, 'segregation_compliant' => true, 'last_audit_date' => '2026-10-12'])), 'current_quantity');
        $r = $this->service()->auditInventory($parent, $source, $this->manager, $this->storageCommand($parent, $source,
            ['current_quantity' => null, 'properly_labelled' => false, 'segregation_compliant' => true, 'last_audit_date' => '2026-10-12']));
        $this->assertTrue($r['changed']); $this->assertNull($source->fresh()->current_quantity);
        $this->assertFalse($source->fresh()->properly_labelled); $this->assertTrue($source->fresh()->segregation_compliant);
        $this->service()->auditInventory($parent, $source, $this->manager, $this->storageCommand($parent, $source,
            ['current_quantity' => 0, 'properly_labelled' => true, 'segregation_compliant' => true, 'last_audit_date' => '2026-10-12']));
        $this->assertSame('0.00', $source->fresh()->current_quantity);
    }

    public function test_storage_archive_restore_and_exact_archive_recovery_preserve_source_history(): void
    {
        $parent = $this->catalogue(); $source = $this->storage($parent);
        $input = $this->storageCommand($parent, $source, ['reason' => 'Recorded operational removal']);
        $r = $this->service()->archiveStorage($parent, $source, $this->manager, $input);
        $this->assertNotNull(SubstanceStorageLocation::withTrashed()->findOrFail($source->id)->deleted_at);
        $this->assertSame($r, $this->service()->archiveStorage($parent, $source, $this->manager, $input));
        $this->assertSame($r, $this->service()->confirmationForViewer($this->manager, 'storage', $source->id, $input['idempotency_key']));
        $archived = SubstanceStorageLocation::withTrashed()->findOrFail($source->id);
        $this->service()->restoreStorage($parent, $archived, $this->manager,
            ['idempotency_key' => (string) Str::uuid(), 'expected_version' => 2, 'parent_expected_version' => 1, 'reason' => 'Restored recorded location']);
        $this->assertNotNull(SubstanceStorageLocation::find($source->id));
        $this->assertSame(3, ChemicalSourceVersion::where('root_type', 'storage')->where('root_id', $source->id)->count());
    }

    public function test_foreign_site_parent_and_current_site_loss_conceal_before_child_fields_or_receipt_discovery(): void
    {
        $parent = $this->catalogue(); $source = $this->storage($parent); $other = $this->catalogue();
        $input = $this->storageCommand($parent, $source, ['storage_notes' => 'Denied']);
        $this->denial(fn () => $this->service()->updateStorage($other, $source, $this->manager, $input), 404);
        $this->denial(fn () => $this->service()->createStorage($parent, $this->manager,
            ['idempotency_key' => (string) Str::uuid(), 'parent_expected_version' => 1, 'site_id' => $this->foreignSite->id, 'location_description' => 'Foreign']), 404);
        $this->manager->load('hrEmployeeProfile', 'permissionOverrides', 'roles.permissions');
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['primary_site_id' => $this->foreignSite->id]);
        $this->denial(fn () => $this->service()->updateStorage($parent, $source, $this->manager, $input), 404);
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['primary_site_id' => $this->site->id]);
        $this->assertTrue($this->service()->updateStorage($parent, $source, $this->manager, $input)['accepted']);
    }

    public function test_storage_site_correction_requires_current_old_and_destination_sites_and_retains_original_tuple(): void
    {
        $parent = $this->catalogue(); $source = $this->storage($parent);
        $input = $this->storageCommand($parent, $source, ['site_id' => $this->foreignSite->id]);
        $this->denial(fn () => $this->service()->updateStorage($parent, $source, $this->manager, $input), 404);
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['secondary_site_ids' => [$this->foreignSite->id]]);
        $r = $this->service()->updateStorage($parent, $source, $this->manager, $input);
        $version = ChemicalSourceVersion::findOrFail($r['source_version_id']);
        $this->assertSame($this->site->id, $version->content['source_before']['site_id']);
        $this->assertSame($this->foreignSite->id, $version->content['source_after']['site_id']);
        $this->assertSame($this->foreignSite->id, $version->site_id);
    }

    public function test_scoped_noncompliance_or_is_grouped_and_never_widens_to_another_site(): void
    {
        $parent = $this->catalogue(); $local = $this->storage($parent, ['properly_labelled' => true, 'segregation_compliant' => true]);
        SubstanceStorageLocation::factory()->create(['hazardous_substance_id' => $parent->id, 'site_id' => $this->foreignSite->id,
            'properly_labelled' => true, 'segregation_compliant' => false]);
        $this->assertSame([], SubstanceStorageLocation::where('site_id', $this->site->id)->nonCompliant()->pluck('id')->all());
        $local->forceFill(['segregation_compliant' => false])->saveQuietly();
        $this->assertSame([$local->id], SubstanceStorageLocation::where('site_id', $this->site->id)->nonCompliant()->pluck('id')->all());
    }

    public function test_inventory_actual_day_uses_worker_application_utc_chain_and_refuses_precision_loss(): void
    {
        $parent = $this->catalogue(); $source = $this->storage($parent);
        $this->travelTo(Carbon::parse('2026-10-12T03:30:00Z')->utc());
        foreach ([['America/Los_Angeles', 'UTC'], ['', 'America/Los_Angeles']] as [$worker, $app]) {
            config(['app.worker_timezone' => $worker, 'app.timezone' => $app]);
            $this->validation(fn () => $this->service()->auditInventory($parent, $source, $this->manager,
                $this->storageCommand($parent, $source, ['current_quantity' => '1.00', 'properly_labelled' => true,
                    'segregation_compliant' => true, 'last_audit_date' => '2026-10-12'])), 'last_audit_date');
        }
        config(['app.worker_timezone' => null, 'app.timezone' => null]);
        $this->assertTrue($this->service()->auditInventory($parent, $source, $this->manager, $this->storageCommand($parent, $source,
            ['current_quantity' => '1.00', 'properly_labelled' => true, 'segregation_compliant' => true, 'last_audit_date' => '2026-10-12']))['accepted']);
        $this->validation(fn () => $this->service()->updateStorage($parent, $source, $this->manager,
            $this->storageCommand($parent, $source, ['current_quantity' => '1.001'])), 'current_quantity');
    }

    public function test_storage_downgrade_reparent_or_after_mutation_never_becomes_legacy_or_accepted(): void
    {
        $parent = $this->catalogue();
        foreach (['marker', 'parent', 'after'] as $damage) {
            $source = $this->storage($parent);
            if ($damage === 'marker') { DB::table('substance_storage_locations')->where('id', $source->id)->update(['source_schema_version' => 0, 'source_revision' => 0]); }
            elseif ($damage === 'parent') {
                $other = $this->catalogue(); DB::table('substance_storage_locations')->where('id', $source->id)->update(['hazardous_substance_id' => $other->id]);
                $this->denial(fn () => $this->service()->updateStorage($parent, $source, $this->manager, $this->storageCommand($parent, $source, ['storage_notes' => 'Denied'])), 404);
                $this->integrity(fn () => $this->service()->updateStorage($other, $source, $this->manager, $this->storageCommand($other, $source, ['storage_notes' => 'Denied'])));
                continue;
            } else { DB::table('substance_storage_locations')->where('id', $source->id)->update(['current_quantity' => '999.00']); }
            $this->integrity(fn () => $this->service()->updateStorage($parent, $source, $this->manager, $this->storageCommand($parent, $source, ['storage_notes' => 'Denied'])));
        }
    }

    public function test_inventory_audit_save_veto_keeps_old_measurement_and_private_exposure_table_untouched(): void
    {
        $parent = $this->catalogue(); $source = $this->storage($parent);
        AuditLog::creating(fn ($row) => $row->action === 'chemical.audit_inventory' ? false : null);
        DB::flushQueryLog(); DB::enableQueryLog();
        try {
            $this->integrity(fn () => $this->service()->auditInventory($parent, $source, $this->manager,
                $this->storageCommand($parent, $source, ['current_quantity' => 0, 'properly_labelled' => true,
                    'segregation_compliant' => true, 'last_audit_date' => '2026-10-12'])));
            foreach (DB::getQueryLog() as $query) { $this->assertStringNotContainsString('substance_exposure_records', strtolower($query['query'])); }
        } finally { DB::disableQueryLog(); AuditLog::flushEventListeners(); }
        $this->assertSame('1.25', $source->fresh()->current_quantity);
        $this->assertSame(1, $source->fresh()->source_revision);
    }
}
