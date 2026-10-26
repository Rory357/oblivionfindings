<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\HazardousSubstance;
use App\Models\Permission;
use App\Models\SafetyDataSheet;
use App\Models\Site;
use App\Models\SubstanceExposureRecord;
use App\Models\SubstanceStorageLocation;
use App\Models\User;
use App\Services\HealthSafety\ChemicalAccessService;
use App\Services\HealthSafety\ChemicalRegisterReadService;
use App\Services\HealthSafety\HealthSafetyReadContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

/** Whole nonprivate source suite; exposure policy/commands/controller integration remain separate. */
final class ChemicalCatalogueStoragePrivacyTest extends TestCase
{
    use RefreshDatabase;

    private User $viewer;
    private Site $site;
    private Site $foreignSite;
    private HazardousSubstance $substance;
    private HazardousSubstance $other;
    private SubstanceStorageLocation $local;
    private SubstanceStorageLocation $foreign;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-12T01:00:00Z'));
        $this->seed(RbacSeeder::class);
        Storage::fake('private');
        $this->site = Site::factory()->create(['is_active' => true]);
        $this->foreignSite = Site::factory()->create(['is_active' => true]);
        $this->viewer = $this->staff(['hazards.view']);
        $this->substance = HazardousSubstance::factory()->create(['name' => 'Canonical disinfectant']);
        $this->other = HazardousSubstance::factory()->create(['name' => 'Other reference chemical']);
        $this->local = SubstanceStorageLocation::factory()->create(['hazardous_substance_id' => $this->substance->id,
            'site_id' => $this->site->id, 'storage_notes' => 'Recorded local storage controls']);
        $this->foreign = SubstanceStorageLocation::factory()->create(['hazardous_substance_id' => $this->substance->id,
            'site_id' => $this->foreignSite->id, 'storage_notes' => 'FOREIGN-STORAGE-SECRET']);
    }

    private function staff(array $keys = []): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now(), 'external_clinical_account' => false]);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [], 'is_active' => true, 'start_date' => '2020-01-01', 'end_date' => null]);
        foreach ($keys as $key) {
            $this->allow($user, $key);
        }

        return $user;
    }

    private function allow(User $user, string $key, bool $allowed = true): void
    {
        $user->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->sole()->id => ['allowed' => $allowed]]);
    }

    private function read(): ChemicalRegisterReadService
    {
        return app(ChemicalRegisterReadService::class);
    }

    private function denied(callable $operation, int $status = 403): void
    {
        try {
            $operation();
            $this->fail('Expected current chemical source denial.');
        } catch (HttpExceptionInterface $failure) {
            $this->assertSame($status, $failure->getStatusCode());
        } catch (ModelNotFoundException $failure) {
            $this->assertSame(404, $status);
        }
    }

    private function integrity(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected active same-viewer evidence refusal.');
        } catch (LogicException $failure) {
            $this->assertNotSame('', $failure->getMessage());
        }
    }

    private function sheet(array $facts = [], ?HazardousSubstance $parent = null, bool $bytes = true): SafetyDataSheet
    {
        $parent ??= $this->substance;
        $sheet = SafetyDataSheet::factory()->create(['hazardous_substance_id' => $parent->id, 'issue_date' => '2026-01-01',
            'review_date' => '2027-01-01', 'document_path' => 'health-safety/sds/'.$parent->id.'/'.uniqid().'.pdf', ...$facts]);
        if ($bytes) {
            Storage::disk('private')->put($sheet->document_path, '%PDF-1.4 retained reference');
        }

        return $sheet;
    }

    public function test_reference_catalogue_is_global_but_storage_detail_counts_are_current_site_scoped(): void
    {
        $page = $this->read()->index($this->viewer);
        $this->assertEqualsCanonicalizing([$this->substance->id, $this->other->id], array_column($page['rows']['data'], 'id'));
        $row = collect($page['rows']['data'])->firstWhere('id', $this->substance->id);
        $this->assertSame(1, $row['storage_count']);
        $this->assertSame(1, $page['hero']['live']['storage_locations']);
        $detail = $this->read()->detail($this->viewer, $this->substance->id);
        $this->assertSame([$this->local->id], array_column($detail['storage_locations'], 'id'));
        $this->assertSame('Recorded local storage controls', $detail['storage_locations'][0]['storage_notes']);
        $this->assertStringNotContainsString('FOREIGN-STORAGE-SECRET', json_encode([$page, $detail]));
        $this->assertSame('unknown', $detail['inventory_state']);
    }

    public function test_catalogue_and_storage_reads_never_query_or_echo_private_exposures_even_with_wellbeing_grant(): void
    {
        $worker = $this->staff();
        SubstanceExposureRecord::withoutEvents(fn () => SubstanceExposureRecord::factory()->create([
            'hazardous_substance_id' => $this->substance->id, 'user_id' => $worker->id, 'site_id' => $this->site->id,
            'symptoms' => 'PRIVATE-SYMPTOMS', 'medical_outcome' => 'PRIVATE-OUTCOME']));
        $this->substance->load('exposureRecords.user');
        $this->allow($this->viewer, 'hr.wellbeing.view');
        DB::enableQueryLog();
        DB::flushQueryLog();
        try {
            $page = $this->read()->index($this->viewer);
            $detail = $this->read()->detail($this->viewer, $this->substance->id);
            $storage = $this->read()->storageForSite($this->viewer, $this->site->id);
            $sql = strtolower(implode("\n", array_column(DB::getQueryLog(), 'query')));
        } finally {
            DB::disableQueryLog();
        }
        foreach (['substance_exposure_records', 'hs_events', 'workplace_injuries', 'hr_documents', 'client_incidents', 'audit_logs'] as $private) {
            $this->assertStringNotContainsString($private, $sql);
        }
        $this->assertNull($detail['exposure_records']);
        $this->assertNull($detail['counts']['exposures']);
        $this->assertNull($page['hero']['attention']['exposures']);
        $this->assertNull($page['hero']['attention']['awaiting_review']);
        $this->assertNull($page['badges']['worksafe_awaiting']);
        foreach ([$worker->name, 'PRIVATE-SYMPTOMS', 'PRIVATE-OUTCOME', 'related_incident_id'] as $private) {
            $this->assertStringNotContainsString($private, json_encode([$page, $detail, $storage]));
        }
    }

    public function test_loaded_permission_revoke_and_restore_controls_detail_index_and_download(): void
    {
        $sheet = $this->sheet();
        $this->viewer->load('roles.permissions', 'permissionOverrides');
        $this->assertTrue($this->viewer->canDo('hazards.view'));
        $this->allow($this->viewer, 'hazards.view', false);
        $this->denied(fn () => $this->read()->index($this->viewer));
        $this->denied(fn () => $this->read()->detail($this->viewer, $this->substance->id));
        $this->denied(fn () => $this->read()->sdsForDownload($this->viewer, $this->substance->id, $sheet->id));
        $this->allow($this->viewer, 'hazards.view');
        $this->assertSame($sheet->id, $this->read()->sdsForDownload($this->viewer, $this->substance->id, $sheet->id)['id']);
        $this->assertSame(2, $this->read()->index($this->viewer)['rows']['total']);
    }

    public function test_create_or_manage_only_never_implies_enduring_catalogue_or_sds_read(): void
    {
        $sheet = $this->sheet();
        foreach (['hazards.create', 'hazards.manage', 'hr.wellbeing.view', 'reports.viewAny'] as $key) {
            $actor = $this->staff([$key]);
            $this->denied(fn () => $this->read()->detail($actor, $this->substance->id));
            $this->denied(fn () => $this->read()->sdsForDownload($actor, $this->substance->id, $sheet->id));
        }
    }

    public function test_current_management_flags_are_independent_of_retained_catalogue_read(): void
    {
        $this->allow($this->viewer, 'hazards.manage');
        $this->viewer->load('permissionOverrides');
        $this->assertTrue($this->read()->detail($this->viewer, $this->substance->id)['can']['manage']);
        $this->allow($this->viewer, 'hazards.manage', false);
        $detail = $this->read()->detail($this->viewer, $this->substance->id);
        $this->assertFalse($detail['can']['manage']);
        $this->assertFalse($detail['can']['create']);
        $this->allow($this->viewer, 'hazards.create');
        $this->assertTrue($this->read()->detail($this->viewer, $this->substance->id)['can']['create']);
    }

    public function test_warmed_site_move_and_restore_changes_storage_without_erasing_reference_catalogue(): void
    {
        $this->viewer->load('hrEmployeeProfile');
        $profile = HrEmployeeProfile::where('user_id', $this->viewer->id)->sole();
        $profile->update(['primary_site_id' => $this->foreignSite->id]);
        $detail = $this->read()->detail($this->viewer, $this->substance->id);
        $this->assertSame([$this->foreign->id], array_column($detail['storage_locations'], 'id'));
        $this->denied(fn () => $this->read()->storageForSite($this->viewer, $this->site->id), 404);
        $profile->update(['primary_site_id' => $this->site->id]);
        $this->assertSame([$this->local->id], array_column($this->read()->detail($this->viewer, $this->substance->id)['storage_locations'], 'id'));
        $this->assertSame($this->other->id, $this->read()->detail($this->viewer, $this->other->id)['id']);
    }

    public function test_direct_storage_parent_site_and_current_placement_are_proved_before_projection(): void
    {
        $access = app(ChemicalAccessService::class);
        $check = fn ($parent, $child) => $access->withCurrentViewer($this->viewer,
            fn ($current, $reads, $sites) => ['id' => $access->assertStorage($parent, $child, $current, $reads, $sites)->id]);
        $this->assertSame($this->local->id, $check($this->substance->id, $this->local)['id']);
        $this->denied(fn () => $check($this->other->id, $this->local), 404);
        $this->denied(fn () => $check($this->substance->id, $this->foreign), 404);
        DB::table('substance_storage_locations')->where('id', $this->local->id)->update(['site_id' => $this->foreignSite->id]);
        $this->denied(fn () => $check($this->substance->id, $this->local), 404);
        $this->assertSame([], $this->read()->storageForSite($this->viewer, $this->site->id)['storage_locations']);
    }

    public function test_organisation_wide_site_grant_is_current_and_does_not_reveal_exposures(): void
    {
        $this->allow($this->viewer, 'healthSafety.viewAllSites');
        $this->assertSame(2, $this->read()->detail($this->viewer, $this->substance->id)['counts']['storage']);
        $this->assertNull($this->read()->detail($this->viewer, $this->substance->id)['counts']['exposures']);
        $this->allow($this->viewer, 'healthSafety.viewAllSites', false);
        $this->denied(fn () => $this->read()->index($this->viewer, ['site_id' => $this->foreignSite->id]), 404);
        $this->assertSame(1, $this->read()->detail($this->viewer, $this->substance->id)['counts']['storage']);
    }

    public function test_inactive_archived_sites_inactive_hr_unapproved_and_external_actors_are_denied(): void
    {
        $this->site->update(['is_active' => false]);
        $this->denied(fn () => $this->read()->detail($this->viewer, $this->substance->id));
        $this->site->update(['is_active' => true, 'archived_at' => now()]);
        $this->denied(fn () => $this->read()->index($this->viewer));
        $this->site->update(['archived_at' => null]);
        HrEmployeeProfile::where('user_id', $this->viewer->id)->update(['is_active' => false]);
        $this->denied(fn () => $this->read()->index($this->viewer));
        HrEmployeeProfile::where('user_id', $this->viewer->id)->update(['is_active' => true]);
        $this->viewer->update(['approved_at' => null]);
        $this->denied(fn () => $this->read()->index($this->viewer));
        // The external flag is not mass assignable, so update() would silently drop it and test nothing.
        $this->viewer->forceFill(['approved_at' => now(), 'external_clinical_account' => true])->save();
        $this->denied(fn () => $this->read()->index($this->viewer));
    }

    public function test_removed_inactive_and_archived_reference_states_keep_honest_existing_read_parity(): void
    {
        $this->substance->update(['status' => 'removed', 'status_reason' => 'Recorded removal reason']);
        $detail = $this->read()->detail($this->viewer, $this->substance->id);
        $this->assertSame('removed', $detail['status']);
        $this->assertSame('Recorded removal reason', $detail['status_reason']);
        $this->assertSame(1, $this->read()->index($this->viewer, ['tab' => 'inactive'])['rows']['total']);
        $this->substance->delete();
        $this->denied(fn () => $this->read()->detail($this->viewer, $this->substance->id), 404);
        $this->assertSame([], $this->read()->storageForSite($this->viewer, $this->site->id)['storage_locations']);
    }

    public function test_foreign_sds_parent_archived_sheet_and_missing_bytes_do_not_offer_download(): void
    {
        $sheet = $this->sheet();
        $this->denied(fn () => $this->read()->sdsForDownload($this->viewer, $this->other->id, $sheet->id), 404);
        Storage::disk('private')->delete($sheet->document_path);
        $detail = $this->read()->detail($this->viewer, $this->substance->id);
        $this->assertSame('unknown', $detail['sds_state']);
        $this->assertSame('unavailable', $detail['sds_records'][0]['state']);
        $this->assertSame('legacy_unknown', $detail['current_sds']['state']);
        $this->assertNull($detail['sds_records'][0]['download_url']);
        $this->denied(fn () => $this->read()->sdsForDownload($this->viewer, $this->substance->id, $sheet->id), 404);
        $sheet->delete();
        $this->assertSame('missing', $this->read()->detail($this->viewer, $this->substance->id)['sds_state']);
    }

    public function test_sds_multiple_current_unknown_date_and_superseded_history_are_not_false_current(): void
    {
        $unknown = $this->sheet(['review_date' => null]);
        $this->assertSame('unknown', $this->read()->detail($this->viewer, $this->substance->id)['sds_state']);
        $known = $this->sheet();
        $this->assertSame('unknown', $this->read()->detail($this->viewer, $this->substance->id)['sds_state']);
        $unknown->update(['status' => 'superseded']);
        $detail = $this->read()->detail($this->viewer, $this->substance->id);
        $this->assertSame('unknown', $detail['sds_state']);
        $this->assertNull($detail['current_sds_id']);
        $this->assertSame(2, $detail['counts']['sds']);
        $history = collect($detail['sds_records'])->firstWhere('id', $unknown->id);
        $this->assertSame('superseded', $history['state']);
        $this->assertSame('legacy_unknown', $history['attribution_state']);
        $this->assertSame($unknown->id, $this->read()->sdsForDownload($this->viewer, $this->substance->id, $unknown->id)['id']);
        $this->assertStringNotContainsString('document_path', json_encode($detail));
        $this->assertSame($known->id, collect($detail['sds_records'])->firstWhere('status', 'current')['id']);
    }

    public function test_review_dates_use_new_zealand_day_and_future_or_invalid_recorded_dates_remain_unknown(): void
    {
        $this->travelTo(Carbon::parse('2026-10-11T11:30:00Z'));
        config(['app.worker_timezone' => 'Pacific/Auckland']);
        $sheet = $this->sheet(['review_date' => '2026-10-11']);
        $this->assertSame('expired', $this->read()->detail($this->viewer, $this->substance->id)['sds_records'][0]['state']);
        $sheet->update(['review_date' => '2026-10-12']);
        $this->assertSame('expiring', $this->read()->detail($this->viewer, $this->substance->id)['sds_records'][0]['state']);
        $sheet->update(['issue_date' => '2026-10-13', 'review_date' => '2027-01-01']);
        $this->assertSame('unknown', $this->read()->detail($this->viewer, $this->substance->id)['sds_state']);
        $sheet->update(['issue_date' => '2026-01-01', 'review_date' => '2026-01-01']);
        $this->assertSame('unknown', $this->read()->detail($this->viewer, $this->substance->id)['sds_state']);
    }

    public function test_reference_controls_and_storage_fields_are_retained_without_verification_inference(): void
    {
        $facts = ['hsno_approval' => 'Recorded supplier identifier', 'hazard_statements' => 'Recorded hazard text', 'precautionary_statements' => 'Recorded controls',
            'handling_precautions' => 'Recorded handling', 'first_aid_measures' => 'Supplier first aid', 'firefighting_measures' => 'Supplier fire controls',
            'spill_procedures' => 'Recorded spill controls', 'exposure_limit_type' => 'Recorded limit type', 'exposure_limit_value' => 'Recorded limit value'];
        $this->substance->update($facts);
        $this->local->update(['current_quantity' => null, 'last_audit_date' => null]);
        $detail = $this->read()->detail($this->viewer, $this->substance->id);
        foreach ($facts as $key => $value) {
            $this->assertSame($value, $detail[$key]);
        }
        $this->assertNull($detail['storage_locations'][0]['current_quantity']);
        $this->assertNull($detail['storage_locations'][0]['last_audit_date']);
        $this->assertSame('unknown', $detail['storage_locations'][0]['inventory_state']);
        $this->assertSame('unknown', $this->read()->detail($this->viewer, $this->other->id)['inventory_state']);
    }

    public function test_filtered_counts_list_tabs_pagination_and_site_refinements_share_same_read_scope(): void
    {
        $this->sheet(['review_date' => '2026-10-20']);
        $this->substance->update(['physical_form' => 'liquid', 'is_controlled_substance' => true, 'common_name' => 'Visible chemical search']);
        HazardousSubstance::factory()->count(21)->create(['status' => 'active']);
        $page = $this->read()->index($this->viewer, ['q' => 'Visible chemical', 'physical_form' => 'liquid', 'controlled' => '1',
            'site_id' => $this->site->id, 'tab' => 'all']);
        $this->assertSame(1, $page['rows']['total']);
        $this->assertSame(0, $page['tabCounts']['sds_expiring']);
        $this->assertSame('unknown', $page['rows']['data'][0]['sds_state']);
        $this->assertSame(1, $page['tabCounts']['controlled']);
        $this->assertSame(0, $page['tabCounts']['sds_missing']);
        $this->assertSame([$this->substance->id], array_column($page['rows']['data'], 'id'));
        $unfiltered = $this->read()->index($this->viewer, ['page' => 2]);
        $this->assertSame(23, $unfiltered['rows']['total']);
        $this->assertCount(3, $unfiltered['rows']['data']);
        $this->assertSame(23, $unfiltered['tabCounts']['all']);
    }

    public function test_supplied_context_reuses_connection_and_returns_same_detached_detail(): void
    {
        $expected = $this->read()->detail($this->viewer, $this->substance->id);
        $actual = HealthSafetyReadContext::within($this->viewer, ChemicalAccessService::PERMISSIONS, function ($context): array {
            $connection = DB::connection();
            $level = $connection->transactionLevel();
            $detail = $this->read()->detailInContext($this->viewer, $this->substance->id, $context);
            $this->assertSame($connection, DB::connection());
            $this->assertSame($level, $connection->transactionLevel());

            return $detail;
        });
        $this->assertSame($expected, $actual);
    }

    public function test_wrong_viewer_unprepared_expired_or_foreign_connection_context_never_refreshes_as_fallback(): void
    {
        $otherActor = $this->staff(['hazards.view']);
        $captured = null;
        HealthSafetyReadContext::within($this->viewer, ChemicalAccessService::PERMISSIONS, function ($context) use ($otherActor, &$captured): void {
            $captured = $context;
            $this->integrity(fn () => $this->read()->detailInContext($otherActor, $this->substance->id, $context));
            $original = DB::getDefaultConnection();
            config(['database.connections.chemical_foreign' => config('database.connections.'.$original)]);
            DB::setDefaultConnection('chemical_foreign');
            try {
                $this->integrity(fn () => $this->read()->detailInContext($this->viewer, $this->substance->id, $context));
            } finally {
                DB::setDefaultConnection($original);
                DB::purge('chemical_foreign');
            }
        });
        $this->integrity(fn () => $this->read()->detailInContext($this->viewer, $this->substance->id, $captured));
        HealthSafetyReadContext::within($this->viewer, ['hazards.view'], fn ($context) =>
            $this->integrity(fn () => $this->read()->detailInContext($this->viewer, $this->substance->id, $context)));
    }

    public function test_authority_models_builders_and_foreign_source_models_cannot_escape_current_callback(): void
    {
        $access = app(ChemicalAccessService::class);
        $this->integrity(fn () => $access->withCurrentViewer($this->viewer, fn ($current) => $current));
        $this->integrity(fn () => $access->withCurrentViewer($this->viewer, fn ($current, $reads) => $access->catalogueQuery($current, $reads)));
        $foreign = clone $this->local;
        $original = DB::getDefaultConnection();
        config(['database.connections.chemical_foreign_source' => config('database.connections.'.$original)]);
        $foreign->setConnection('chemical_foreign_source');
        try {
            $this->integrity(fn () => $access->withCurrentViewer($this->viewer,
                fn ($current, $reads, $sites) => $access->assertStorage($this->substance->id, $foreign, $current, $reads, $sites)));
        } finally {
            DB::purge('chemical_foreign_source');
        }
    }
}
