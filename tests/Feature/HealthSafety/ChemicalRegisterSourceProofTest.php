<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\ChemicalCommandReceipt;
use App\Models\ChemicalSourceVersion;
use App\Models\HazardousSubstance;
use App\Models\SafetyDataSheet;
use App\Models\SubstanceStorageLocation;
use App\Services\HealthSafety\ChemicalAccessService;
use App\Services\HealthSafety\ChemicalRegisterReadService;
use App\Services\HealthSafety\HealthSafetyReadContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Complete real writer→reader proof suite; physical SDS cases need committed fixture authority. */
final class ChemicalRegisterSourceProofTest extends ChemicalCommandTestCase
{
    protected bool $physicalCommits = true;
    protected function setUp(): void { parent::setUp(); Storage::fake('private'); }
    private function read(): ChemicalRegisterReadService { return app(ChemicalRegisterReadService::class); }
    private function upload(HazardousSubstance $root, array $facts = []): SafetyDataSheet
    {
        $receipt = $this->service()->uploadSds($root, $this->manager, UploadedFile::fake()->createWithContent('Printed source.pdf', "%PDF-1.4\n".str_repeat("printed sheet\n", 280)."%%EOF\n"),
            $this->command($root, ['version' => 'Printed 1', 'issue_date' => '2026-10-01', 'review_date' => '2027-10-01', ...$facts]));
        return SafetyDataSheet::findOrFail($receipt['source_id']);
    }

    public function test_writer_created_catalogue_storage_and_sds_read_full_fields_versions_and_exact_current_pointer(): void
    {
        $root = $this->catalogue(['handling_precautions' => 'Printed handling', 'requires_tracking' => true]);
        $location = $this->storage($root, ['storage_notes' => 'Recorded cabinet controls']); $sheet = $this->upload($root);
        $detail = $this->read()->detail($this->manager, $root->id);
        $this->assertSame('valid', $detail['source_integrity']); $this->assertSame(2, $detail['expected_version']);
        $this->assertSame('Printed handling', $detail['handling_precautions']); $this->assertTrue($detail['requires_tracking']);
        $this->assertSame('current', $detail['sds_state']); $this->assertSame($sheet->id, $detail['current_sds_id']);
        $this->assertSame('recorded', $detail['sds_records'][0]['attribution_state']);
        $this->assertSame($location->id, $detail['storage_locations'][0]['id']);
        $this->assertSame('Recorded cabinet controls', $detail['storage_locations'][0]['storage_notes']);
        $this->assertSame(1, $detail['storage_locations'][0]['expected_version']);
        $this->assertSame(2, $detail['storage_locations'][0]['parent_expected_version']);
        $this->assertCount(2, $detail['history']['items']); $this->assertSame(['sds' => 1, 'storage' => 1, 'exposures' => null], $detail['counts']);
        $this->assertStringNotContainsString('document_path', json_encode($detail));
        $this->assertStringNotContainsString('intent_fingerprint', json_encode($detail));
    }

    public function test_current_pointer_is_not_inferred_from_legacy_status_and_legacy_files_remain_honestly_unknown(): void
    {
        $root = HazardousSubstance::factory()->create(); $path = 'health-safety/sds/'.$root->id.'/legacy.pdf';
        Storage::disk('private')->put($path, '%PDF-1.4 retained bytes');
        $sheet = SafetyDataSheet::factory()->create(['hazardous_substance_id' => $root->id, 'document_path' => $path,
            'status' => 'current', 'issue_date' => '2026-01-01', 'review_date' => '2027-01-01']);
        $detail = $this->read()->detail($this->manager, $root->id);
        $this->assertSame('legacy_unknown', $detail['source_integrity']); $this->assertSame('unknown', $detail['sds_state']);
        $this->assertNull($detail['current_sds_id']); $this->assertTrue($detail['sds_records'][0]['available']);
        $this->assertSame('legacy_unknown', $this->read()->sdsForDownload($this->manager, $root->id, $sheet->id)['attribution_state']);
    }

    public function test_damaged_root_remains_repair_placeholder_before_search_filter_caps_and_nulls_affected_counts(): void
    {
        $root = $this->catalogue(); $this->storage($root);
        DB::table('hazardous_substances')->where('id', $root->id)->update(['name' => 'UNACCEPTED NAME', 'physical_form' => 'gas']);
        $page = $this->read()->index($this->manager, ['q' => 'No healthy match', 'status' => 'inactive', 'physical_form' => 'liquid']);
        $this->assertSame([$root->id], array_column($page['rows']['data'], 'id'));
        $row = $page['rows']['data'][0]; $this->assertNull($row['name']); $this->assertNull($row['physical_form']);
        $this->assertNull($row['storage_count']); $this->assertFalse($row['can']['manage']);
        $this->assertSame('repair_required', $row['source_integrity']); $this->assertNull($page['tabCounts']['active']);
        $this->assertNull($page['hero']['live']['active']); $this->assertSame(1, $page['physical_record_count']);
        $detail = $this->read()->detail($this->manager, $root->id);
        $this->assertNull($detail['sds_records']); $this->assertNull($detail['storage_locations']); $this->assertNull($detail['history']);
        $this->assertStringNotContainsString('UNACCEPTED NAME', json_encode([$page, $detail]));
    }

    public function test_damaged_storage_never_displays_quantity_control_notes_or_truthful_inventory_count(): void
    {
        $root = $this->catalogue(); $storage = $this->storage($root);
        DB::table('substance_storage_locations')->where('id', $storage->id)->update(['storage_notes' => 'UNACCEPTED CONTROLS', 'current_quantity' => '999.00']);
        $site = $this->read()->storageForSite($this->manager, $this->site->id);
        $this->assertNull($site['recorded_location_count']); $this->assertSame(1, $site['physical_record_count']);
        $this->assertSame(1, $site['repair_count']); $this->assertNull($site['storage_locations'][0]['current_quantity']);
        $this->assertNull($site['storage_locations'][0]['storage_notes']); $this->assertFalse($site['storage_locations'][0]['can']['manage']);
        $page = $this->read()->index($this->manager); $this->assertNull($page['hero']['live']['storage_locations']);
        $this->assertNull($page['rows']['data'][0]['storage_count']); $this->assertStringNotContainsString('UNACCEPTED CONTROLS', json_encode([$site, $page]));
    }

    public function test_missing_audit_version_or_marker_downgrade_cannot_become_displayable_current_source(): void
    {
        foreach (['audit', 'version', 'marker'] as $damage) {
            $root = $this->catalogue(); $receipt = ChemicalCommandReceipt::where('root_type', 'catalogue')->where('root_id', $root->id)->sole();
            if ($damage === 'audit') { DB::table('audit_logs')->where('id', $receipt->audit_log_id)->update(['action' => 'chemical.not_accepted']); }
            elseif ($damage === 'version') { DB::table('chemical_source_versions')->where('id', $receipt->source_version_id)->update(['content_digest' => str_repeat('0', 64)]); }
            else { DB::table('hazardous_substances')->where('id', $root->id)->update(['source_schema_version' => 0, 'source_revision' => 0]); }
            $detail = $this->read()->detail($this->manager, $root->id);
            $this->assertSame('repair_required', $detail['source_integrity']); $this->assertNull($detail['name']); $this->assertNull($detail['expected_version']);
        }
    }

    public function test_wrong_current_sds_parent_is_repair_without_foreign_document_discovery(): void
    {
        $root = $this->catalogue(); $other = $this->catalogue(); $foreign = $this->upload($other);
        DB::table('hazardous_substances')->where('id', $root->id)->update(['current_sds_id' => $foreign->id]);
        $detail = $this->read()->detail($this->manager, $root->id);
        $this->assertSame('repair_required', $detail['source_integrity']); $this->assertNull($detail['current_sds_id']);
        $this->assertStringNotContainsString('Printed source.pdf', json_encode($detail));
        $this->denial(fn () => $this->read()->sdsForDownload($this->manager, $root->id, $foreign->id), 404);
    }

    public function test_current_sds_missing_or_tampered_bytes_is_unavailable_not_green_and_download_denies(): void
    {
        $root = $this->catalogue(); $sheet = $this->upload($root);
        Storage::disk('private')->put($sheet->document_path, 'Wrong bytes');
        $detail = $this->read()->detail($this->manager, $root->id);
        $this->assertSame('unavailable', $detail['sds_state']); $this->assertNull($detail['sds_records'][0]['download_url']);
        $this->denial(fn () => $this->read()->sdsForDownload($this->manager, $root->id, $sheet->id), 404);
        Storage::disk('private')->delete($sheet->document_path);
        $this->assertSame(0, $this->read()->index($this->manager)['hero']['live']['sds_current']);
    }

    public function test_configured_worker_app_and_utc_civil_days_drive_real_current_sds_currency(): void
    {
        $this->travelTo(Carbon::parse('2026-10-12T01:00:00Z'));
        config(['app.worker_timezone' => 'America/Los_Angeles']); $root = $this->catalogue();
        $sheet = $this->upload($root, ['review_date' => '2026-10-11']);
        $this->assertSame('expiring', $this->read()->detail($this->manager, $root->id)['sds_state']);
        config(['app.worker_timezone' => null, 'app.timezone' => 'UTC']);
        $this->assertSame('expired', $this->read()->detail($this->manager, $root->id)['sds_state']);
        config(['app.worker_timezone' => '', 'app.timezone' => null]);
        $this->assertSame('expired', $this->read()->detail($this->manager, $root->id)['sds_state']);
        config(['app.worker_timezone' => 'Invalid/Zone']);
        $this->integrity(fn () => $this->read()->detail($this->manager, $root->id));
    }

    public function test_future_printed_sds_stays_not_effective_and_does_not_displace_the_current_pointer(): void
    {
        config(['app.worker_timezone' => 'America/Los_Angeles']); $root = $this->catalogue(); $current = $this->upload($root);
        $future = $this->upload($root, ['issue_date' => '2026-10-12']); $detail = $this->read()->detail($this->manager, $root->id);
        $this->assertSame($current->id, $detail['current_sds_id']); $this->assertSame('current', $detail['sds_state']);
        $this->assertSame('not_yet_effective', collect($detail['sds_records'])->firstWhere('id', $future->id)['state']);
    }

    public function test_warmed_permission_hr_and_site_revoke_restore_are_current_for_actual_reader(): void
    {
        $root = $this->catalogue(); $location = $this->storage($root); $this->manager->load('permissionOverrides', 'hrEmployeeProfile');
        $this->allow($this->manager, 'hazards.view', false); $this->denial(fn () => $this->read()->detail($this->manager, $root->id));
        $this->allow($this->manager, 'hazards.view'); $this->assertSame('valid', $this->read()->detail($this->manager, $root->id)['source_integrity']);
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['is_active' => false]); $this->denial(fn () => $this->read()->index($this->manager));
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['is_active' => true, 'primary_site_id' => $this->foreignSite->id]);
        $this->denial(fn () => $this->read()->storageForSite($this->manager, $this->site->id), 404);
        $this->assertSame([], $this->read()->detail($this->manager, $root->id)['storage_locations']);
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['primary_site_id' => $this->site->id]);
        $this->assertSame([$location->id], array_column($this->read()->detail($this->manager, $root->id)['storage_locations'], 'id'));
    }

    public function test_approved_storage_site_correction_keeps_original_history_private_after_scope_revoke(): void
    {
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['secondary_site_ids' => json_encode([$this->foreignSite->id])]);
        $root = $this->catalogue(); $row = $this->storage($root);
        $this->service()->updateStorage($root, $row, $this->manager, $this->storageCommand($root, $row, ['site_id' => $this->foreignSite->id]));
        $this->assertCount(2, $this->read()->storageForSite($this->manager, $this->foreignSite->id)['storage_locations'][0]['history']['items']);
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['primary_site_id' => $this->foreignSite->id, 'secondary_site_ids' => '[]']);
        $history = $this->read()->storageForSite($this->manager, $this->foreignSite->id)['storage_locations'][0]['history'];
        $this->assertCount(1, $history['items']); $this->assertSame($this->foreignSite->id, $history['items'][0]['site_id']);
    }

    public function test_actual_filters_tabs_counts_and_pagination_match_verified_current_sources(): void
    {
        $root = $this->catalogue(['name' => 'Searchable controls', 'is_controlled_substance' => true]); $this->storage($root);
        $this->upload($root, ['review_date' => '2026-10-20']);
        for ($index = 0; $index < 21; $index++) { $this->catalogue(['name' => 'Other '.$index]); }
        $page = $this->read()->index($this->manager, ['q' => 'Searchable', 'site_id' => $this->site->id, 'tab' => 'sds_expiring', 'controlled' => '1']);
        $this->assertSame(1, $page['rows']['total']); $this->assertSame(1, $page['tabCounts']['sds_expiring']);
        $this->assertSame([$root->id], array_column($page['rows']['data'], 'id'));
        $all = $this->read()->index($this->manager, ['page' => 2]); $this->assertSame(22, $all['rows']['total']); $this->assertCount(2, $all['rows']['data']);
    }

    public function test_detail_caps_only_after_full_proof_and_prioritises_repair_without_false_counts(): void
    {
        $root = $this->catalogue();
        for ($index = 0; $index < 101; $index++) { $row = $this->storage($root, ['location_description' => 'Location '.$index]); }
        DB::table('substance_storage_locations')->where('id', $row->id)->update(['storage_notes' => 'UNACCEPTED LAST ROW']);
        $detail = $this->read()->detail($this->manager, $root->id);
        $this->assertCount(100, $detail['storage_locations']); $this->assertSame($row->id, $detail['storage_locations'][0]['id']);
        $this->assertTrue($detail['truncated']['storage']); $this->assertSame(101, $detail['physical_counts']['storage']);
        $this->assertNull($detail['counts']['storage']); $this->assertSame(1, $detail['repair_counts']['storage']);
        $this->assertStringNotContainsString('UNACCEPTED LAST ROW', json_encode($detail));
    }

    public function test_reader_uses_one_supplied_current_context_and_never_discovers_private_exposure_family(): void
    {
        $root = $this->catalogue(); $this->storage($root); $this->upload($root); $this->allow($this->manager, 'hr.wellbeing.view');
        DB::enableQueryLog(); DB::flushQueryLog();
        try {
            $expected = $this->read()->detail($this->manager, $root->id);
            $actual = HealthSafetyReadContext::within($this->manager, ChemicalAccessService::PERMISSIONS, function ($context) use ($root) {
                $level = DB::transactionLevel(); $result = $this->read()->detailInContext($this->manager, $root->id, $context);
                $this->assertSame($level, DB::transactionLevel()); return $result;
            });
            $sql = implode(' ', array_column(DB::getQueryLog(), 'query'));
        } finally { DB::disableQueryLog(); }
        $this->assertSame($expected, $actual); $this->assertNull($actual['exposure_records']); $this->assertNull($actual['counts']['exposures']);
        foreach (['substance_exposure_records', 'chemical_exposure_', 'hr_documents', 'client_incidents'] as $private) { $this->assertStringNotContainsString($private, $sql); }
    }

    public function test_archive_is_discoverable_with_reviewed_revisions_and_restore_returns_it_to_active_locations(): void
    {
        $root = $this->catalogue(); $active = $this->storage($root); $row = $this->storage($root, ['location_description' => 'Retained archived cabinet']);
        $this->service()->archiveStorage($root, $row, $this->manager, $this->storageCommand($root, $row, ['reason' => 'No longer used']));
        $detail = $this->read()->detail($this->manager, $root->id); $archive = $detail['archived_storage'];
        $this->assertSame([$active->id], array_column($detail['storage_locations'], 'id')); $this->assertSame(1, $detail['counts']['storage']);
        $this->assertSame(1, $archive['recorded_count']); $this->assertSame(1, $archive['physical_record_count']);
        $this->assertSame(0, $archive['repair_count']); $this->assertFalse($archive['truncated']);
        $entry = $archive['items'][0]; $this->assertSame($row->id, $entry['id']); $this->assertNotNull($entry['archived_at']);
        $this->assertSame(2, $entry['expected_version']); $this->assertSame(1, $entry['parent_expected_version']);
        $this->assertTrue($entry['can']['manage']); $this->assertCount(2, $entry['history']['items']);
        $input = ['idempotency_key' => (string) Str::uuid(), 'expected_version' => $entry['expected_version'],
            'parent_expected_version' => $entry['parent_expected_version'], 'reason' => 'Returned to use'];
        $accepted = $this->service()->restoreStorage($root, $row->id, $this->manager, $input);
        $this->assertSame($accepted, $this->service()->restoreStorage($root, $row->id, $this->manager, $input));
        $restored = $this->read()->detail($this->manager, $root->id);
        $this->assertSame([], $restored['archived_storage']['items']); $this->assertSame(0, $restored['archived_storage']['recorded_count']);
        $this->assertSame(2, $restored['counts']['storage']);
    }

    public function test_archived_discovery_rechecks_warmed_view_and_current_site_without_counting_denied_sources(): void
    {
        $root = $this->catalogue(); $row = $this->storage($root, ['storage_notes' => 'Retained archived controls']);
        $this->service()->archiveStorage($root, $row, $this->manager, $this->storageCommand($root, $row, ['reason' => 'Retained']));
        $this->manager->load('permissionOverrides', 'hrEmployeeProfile'); $this->allow($this->manager, 'hazards.view', false);
        $this->denial(fn () => $this->read()->detail($this->manager, $root->id)); $this->allow($this->manager, 'hazards.view');
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['primary_site_id' => $this->foreignSite->id]);
        $archive = $this->read()->detail($this->manager, $root->id)['archived_storage'];
        $this->assertSame([], $archive['items']); $this->assertSame(0, $archive['physical_record_count']);
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['primary_site_id' => $this->site->id]);
        $this->site->update(['is_active' => false]);
        $this->assertSame([], $this->read()->detail($this->manager, $root->id)['archived_storage']['items']);
        $this->site->update(['is_active' => true]);
        $this->assertSame([$row->id], array_column($this->read()->detail($this->manager, $root->id)['archived_storage']['items'], 'id'));
    }

    public function test_moved_archived_storage_needs_original_site_as_well_as_current_site_before_discovery(): void
    {
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['secondary_site_ids' => json_encode([$this->foreignSite->id])]);
        $root = $this->catalogue(); $row = $this->storage($root);
        $this->service()->updateStorage($root, $row, $this->manager, $this->storageCommand($root, $row, ['site_id' => $this->foreignSite->id]));
        $this->service()->archiveStorage($root, $row, $this->manager, $this->storageCommand($root, $row, ['reason' => 'Retained moved location']));
        $this->assertCount(1, $this->read()->detail($this->manager, $root->id)['archived_storage']['items']);
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['primary_site_id' => $this->foreignSite->id, 'secondary_site_ids' => '[]']);
        $archive = $this->read()->detail($this->manager, $root->id)['archived_storage'];
        $this->assertSame([], $archive['items']); $this->assertSame(0, $archive['recorded_count']);
        $this->assertSame(0, $archive['physical_record_count']); $this->assertSame(0, $archive['repair_count']);
        HrEmployeeProfile::where('user_id', $this->manager->id)->update(['secondary_site_ids' => json_encode([$this->site->id])]);
        $this->assertSame(3, count($this->read()->detail($this->manager, $root->id)['archived_storage']['items'][0]['history']['items']));
    }

    public function test_damaged_archived_storage_is_repair_with_no_stale_fields_archive_date_or_restore_capability(): void
    {
        $root = $this->catalogue(); $row = $this->storage($root);
        $this->service()->archiveStorage($root, $row, $this->manager, $this->storageCommand($root, $row, ['reason' => 'Retained']));
        DB::table('substance_storage_locations')->where('id', $row->id)->update(['storage_notes' => 'UNACCEPTED ARCHIVED CONTROLS', 'deleted_at' => '2026-10-11 00:00:00']);
        $archive = $this->read()->detail($this->manager, $root->id)['archived_storage']; $entry = $archive['items'][0];
        $this->assertSame($row->id, $entry['id']); $this->assertSame('repair_required', $entry['source_integrity']);
        foreach (['storage_notes', 'current_quantity', 'location_description', 'archived_at', 'history', 'expected_version'] as $field) { $this->assertNull($entry[$field]); }
        $this->assertFalse($entry['can']['manage']); $this->assertNull($archive['recorded_count']);
        $this->assertSame(1, $archive['physical_record_count']); $this->assertSame(1, $archive['repair_count']);
        $this->assertStringNotContainsString('UNACCEPTED ARCHIVED CONTROLS', json_encode($archive));
        $historyRow = $this->storage($root, ['storage_notes' => 'Retained history-dependent controls']);
        $this->service()->archiveStorage($root, $historyRow, $this->manager, $this->storageCommand($root, $historyRow, ['reason' => 'Retained']));
        $original = ChemicalSourceVersion::where('root_type', 'storage')->where('root_id', $historyRow->id)->orderBy('aggregate_revision')->firstOrFail();
        DB::table('chemical_source_versions')->where('id', $original->id)->update(['content_digest' => str_repeat('0', 64)]);
        $entry = collect($this->read()->detail($this->manager, $root->id)['archived_storage']['items'])->firstWhere('id', $historyRow->id);
        $this->assertSame('repair_required', $entry['source_integrity']); $this->assertNull($entry['storage_notes']);
        $this->assertNull($entry['archived_at']); $this->assertNull($entry['history']); $this->assertFalse($entry['can']['manage']);
    }

    public function test_damaged_parent_never_discovers_archived_children_and_legacy_archive_remains_unknown(): void
    {
        $legacy = HazardousSubstance::factory()->create();
        $legacyStorage = SubstanceStorageLocation::factory()->create(['hazardous_substance_id' => $legacy->id,
            'site_id' => $this->site->id, 'deleted_at' => now()->utc()]);
        $entry = $this->read()->detail($this->manager, $legacy->id)['archived_storage']['items'][0];
        $this->assertSame($legacyStorage->id, $entry['id']); $this->assertSame('legacy_unknown', $entry['attribution_state']);
        $this->assertSame(0, $entry['expected_version']); $this->assertNotNull($entry['archived_at']);
        $root = $this->catalogue(); $row = $this->storage($root);
        $this->service()->archiveStorage($root, $row, $this->manager, $this->storageCommand($root, $row, ['reason' => 'Retained']));
        DB::table('hazardous_substances')->where('id', $root->id)->update(['name' => 'UNACCEPTED ROOT']);
        DB::enableQueryLog(); DB::flushQueryLog();
        try { $detail = $this->read()->detail($this->manager, $root->id); $sql = implode(' ', array_column(DB::getQueryLog(), 'query')); }
        finally { DB::disableQueryLog(); }
        $this->assertSame(['items' => null, 'recorded_count' => null, 'physical_record_count' => null, 'repair_count' => null, 'truncated' => null], $detail['archived_storage']);
        $this->assertStringNotContainsString('substance_storage_locations', $sql);
    }

    public function test_archived_cap_proves_all_admitted_rows_and_prioritises_last_damaged_row_without_active_count_changes(): void
    {
        $root = $this->catalogue();
        for ($index = 0; $index < 101; $index++) {
            $row = $this->storage($root, ['location_description' => 'Archived location '.$index]);
            $this->service()->archiveStorage($root, $row, $this->manager, $this->storageCommand($root, $row, ['reason' => 'Retained']));
        }
        DB::table('substance_storage_locations')->where('id', $row->id)->update(['storage_notes' => 'UNACCEPTED LAST ARCHIVE']);
        $detail = $this->read()->detail($this->manager, $root->id); $archive = $detail['archived_storage'];
        $this->assertCount(100, $archive['items']); $this->assertSame($row->id, $archive['items'][0]['id']);
        $this->assertTrue($archive['truncated']); $this->assertSame(101, $archive['physical_record_count']);
        $this->assertSame(1, $archive['repair_count']); $this->assertNull($archive['recorded_count']);
        $this->assertSame([], $detail['storage_locations']); $this->assertSame(0, $detail['counts']['storage']);
        $this->assertStringNotContainsString('UNACCEPTED LAST ARCHIVE', json_encode($detail));
    }
}
