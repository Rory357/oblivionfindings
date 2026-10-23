<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\EmergencyDrill;
use App\Models\PpeAllocation;
use App\Models\PpeInventory;
use App\Services\HealthSafety\HsDashboardService;
use App\Services\HealthSafety\PpeCommandService;
use App\Services\Sites\Profile\SiteProfileSafetyPresenter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Actual cross-module consumers. Source prepared only; every complete case requires admitted native verification. */
final class PpeCrossModuleProjectionTest extends PpeReadTestCase
{
    public function test_actual_site_presenter_preserves_legacy_facts_and_permanent_site_back(): void
    {
        $this->item->update(['quantity' => 7, 'expiry_date' => '2026-10-20', 'next_inspection_due' => '2026-10-12']);
        $dto = $this->profile();
        $this->assertFalse($dto['locked']);
        $this->assertSame(1, $dto['physical_record_count']);
        $this->assertSame(7, $dto['confirmed_units']);
        $row = $dto['items'][0];
        $this->assertSame('Recorded respirator', $row['name']);
        $this->assertSame(7, $row['quantity']);
        $this->assertSame('legacy_unknown', $row['attribution_state']);
        $this->assertSame('legacy_unknown', $row['source_integrity']);
        $this->assertTrue($row['inspection_due']);
        $this->assertFalse($row['expired']);
        $this->assertSiteBack($row['href'], $this->item->id);
        $this->assertDetached($dto);
    }

    public function test_foreign_site_presenter_and_feed_deny_before_any_ppe_hydration(): void
    {
        $this->foreignItem->update(['next_inspection_due' => '2026-10-11']);
        [$profile, $sql] = $this->queries(fn () => app(SiteProfileSafetyPresenter::class)->ppe($this->viewer, $this->foreignSite));
        $this->assertTrue($profile['locked']);
        $this->assertSame([], $profile['items']);
        $this->assertNull($profile['summary']);
        $this->assertNoPpeSql($sql);
        [$feed, $sql] = $this->queries(fn () => $this->feed($this->foreignSite->id));
        $this->assertSame([], $feed['items']);
        $this->assertSame(0, $feed['ppe']['physical_record_count']);
        $this->assertNoPpeSql($sql);
        $this->assertStringNotContainsString('FOREIGN-PRIVATE', json_encode([$profile, $feed], JSON_THROW_ON_ERROR));
    }

    public function test_warmed_permission_revoke_and_restore_changes_actual_consumers_without_reusing_cached_grants(): void
    {
        $this->item->update(['next_inspection_due' => '2026-10-11']);
        $this->viewer->load(['roles.permissions', 'permissionOverrides']);
        $this->assertFalse($this->profile()['locked']);
        $this->allow($this->viewer, 'hazards.view', false);
        $this->assertTrue($this->viewer->canDo('hazards.view'), 'The caller deliberately retains its old loaded grant.');
        [$profile, $sql] = $this->queries(fn () => $this->profile());
        $this->assertTrue($profile['locked']);
        $this->assertNoPpeSql($sql);
        [$feed, $sql] = $this->queries(fn () => $this->feed());
        $this->assertSame([], $feed['items']);
        $this->assertSame('restricted', $feed['ppe']['source_integrity']);
        $this->assertNull($feed['ppe']['total']);
        $this->assertNoPpeSql($sql);
        $this->viewer->refresh()->load(['roles.permissions', 'permissionOverrides']);
        $this->allow($this->viewer, 'hazards.view', true);
        $this->assertFalse($this->viewer->canDo('hazards.view'), 'Restoration must also beat the old loaded denial.');
        $this->assertFalse($this->profile()['locked']);
        $this->assertSame(1, $this->feed()['ppe']['confirmed_count']);
    }

    public function test_current_hr_withdrawal_and_restore_withholds_ppe_source_queries(): void
    {
        $this->item->update(['expiry_date' => '2026-10-20']);
        $this->viewer->load(['roles.permissions', 'permissionOverrides', 'hrEmployeeProfile']);
        HrEmployeeProfile::where('user_id', $this->viewer->id)->update(['is_active' => false]);
        [$profile, $sql] = $this->queries(fn () => $this->profile());
        $this->assertTrue($profile['locked']);
        $this->assertNoPpeSql($sql);
        $this->assertSame('restricted', $this->feed()['ppe']['source_integrity']);
        HrEmployeeProfile::where('user_id', $this->viewer->id)->update(['is_active' => true]);
        $this->assertFalse($this->profile()['locked']);
        $this->assertSame(1, $this->feed()['ppe']['confirmed_count']);
    }

    public function test_current_approved_site_reassignment_changes_presenter_and_feed_then_restores(): void
    {
        $this->item->update(['expiry_date' => '2026-10-20']);
        $this->foreignItem->update(['expiry_date' => '2026-10-21', 'next_inspection_due' => null]);
        $this->viewer->load(['roles.permissions', 'permissionOverrides', 'hrEmployeeProfile']);
        HrEmployeeProfile::where('user_id', $this->viewer->id)->update(['primary_site_id' => $this->foreignSite->id]);
        $this->assertTrue($this->profile()['locked']);
        $this->assertSame([], $this->feed()['items']);
        $allowed = $this->feed($this->foreignSite->id);
        $this->assertSame([$this->foreignItem->id], array_column($allowed['items'], 'inventory_id'));
        HrEmployeeProfile::where('user_id', $this->viewer->id)->update(['primary_site_id' => $this->site->id]);
        $this->assertFalse($this->profile()['locked']);
        $this->assertSame([$this->item->id], array_column($this->feed()['items'], 'inventory_id'));
    }

    public function test_failed_status_and_date_proof_produces_undated_record_notice_and_unknown_http_counts(): void
    {
        $root = $this->typedItem();
        DB::table('ppe_inventory')->where('id', $root->id)->update(['status' => 'disposed', 'expiry_date' => '2099-01-01',
            'next_inspection_due' => '2099-02-01', 'quantity' => 999, 'serial_number' => 'UNPROVED-SERIAL']);
        $profile = $this->profile();
        $row = collect($profile['items'])->firstWhere('id', $root->id);
        $this->assertSame('repair_required', $row['source_integrity']);
        foreach (['quantity', 'condition', 'status', 'expiry_date', 'next_inspection_due', 'inspection_due', 'expired'] as $key) {
            $this->assertNull($row[$key]);
        }
        $this->assertNull($profile['confirmed_units']);
        $this->assertSiteBack($row['href'], $root->id);
        $feed = $this->feed();
        $notice = collect($feed['items'])->firstWhere('inventory_id', $root->id);
        $this->assertSame('ppe_repair', $notice['type']);
        $this->assertNull($notice['due_date']);
        $this->assertNull($notice['days_until']);
        $this->assertSiteBack($notice['register_url'], $root->id);
        $this->assertSame(0, $feed['ppe']['confirmed_count']);
        $this->assertNull($feed['ppe']['total']);
        $props = $this->dashboard();
        $this->assertNull($props['kpis']['ppe_inspections_overdue']);
        $this->assertNull($props['kpis']['ppe_expiring']);
        $this->assertSame('repair_required', $props['ppe_kpi_state']['integrity']['ppe_expiring']);
        $this->assertNull($props['worklists']['ppe_expiring_state']['total']);
        $this->assertStringNotContainsString('UNPROVED-SERIAL', json_encode([$profile, $feed, $props['worklists']], JSON_THROW_ON_ERROR));
    }

    public function test_tampered_acknowledged_returned_child_does_not_hide_unknown_http_count(): void
    {
        $root = $this->typedItem();
        $receipt = app(PpeCommandService::class)->allocate($root, $this->viewer, ['idempotency_key' => (string) Str::uuid(),
            'expected_version' => $root->source_revision, 'user_id' => $this->worker->id]);
        DB::table('ppe_allocations')->where('id', $receipt['source_id'])->update(['acknowledged' => true, 'returned_at' => now()->utc()]);
        $props = $this->dashboard();
        $this->assertNull($props['kpis']['ppe_unacknowledged']);
        $this->assertSame('repair_required', $props['ppe_kpi_state']['integrity']['ppe_unacknowledged']);
        $this->assertSame(1, $props['ppe_kpi_state']['repair_counts']['ppe_unacknowledged']);
        $this->assertSame(1, $props['ppe_kpi_state']['physical_allocation_count']);
        $this->assertSame(0, $props['kpis']['ppe_expiring']);
    }

    public function test_missing_governed_history_and_downgraded_markers_cannot_become_verified_zero(): void
    {
        $root = $this->typedItem();
        DB::table('ppe_inventory')->where('id', $root->id)->update(['source_schema_version' => 0, 'source_revision' => 0]);
        $props = $this->dashboard();
        $this->assertNull($props['kpis']['ppe_expiring']);
        $this->assertSame('repair_required', $this->profile()['items'][0]['source_integrity']);
        DB::table('ppe_inventory')->where('id', $root->id)->update(['source_schema_version' => 1, 'source_revision' => 1]);
        DB::table('ppe_source_versions')->where('root_type', 'inventory')->where('root_id', $root->id)->delete();
        $this->assertSame('ppe_repair', $this->feed()['items'][0]['type']);
        $this->assertNull($this->dashboard()['kpis']['ppe_expiring']);
    }

    public function test_catalogue_corruption_does_not_publish_forged_name_in_site_feed_or_http_worklist(): void
    {
        $receipt = app(PpeCommandService::class)->createType($this->viewer, ['idempotency_key' => (string) Str::uuid(),
            'name' => 'Immutable eye protection', 'category' => 'eye']);
        $this->item->update(['ppe_type_id' => $receipt['root_id'], 'expiry_date' => '2026-10-20']);
        DB::table('ppe_types')->where('id', $receipt['root_id'])->update(['name' => 'FORGED-CATALOGUE-SECRET']);
        $profile = $this->profile();
        $feed = $this->feed();
        $props = $this->dashboard();
        $this->assertSame('repair_required', $profile['items'][0]['source_integrity']);
        $this->assertSame('ppe_repair', $feed['items'][0]['type']);
        $this->assertNull($props['kpis']['ppe_expiring']);
        $this->assertStringNotContainsString('FORGED-CATALOGUE-SECRET', json_encode([$profile, $feed, $props], JSON_THROW_ON_ERROR));
    }

    public function test_site_hundred_row_preview_proves_all_rows_and_places_repair_before_cap(): void
    {
        PpeInventory::factory()->count(100)->create(['site_id' => $this->site->id, 'ppe_type_id' => $this->type->id,
            'expiry_date' => null, 'next_inspection_due' => null, 'quantity' => 2]);
        $root = $this->typedItem();
        DB::table('ppe_inventory')->where('id', $root->id)->update(['quantity' => 777]);
        $dto = $this->profile();
        $this->assertCount(100, $dto['items']);
        $this->assertSame($root->id, $dto['items'][0]['id']);
        $this->assertSame(102, $dto['physical_record_count']);
        $this->assertSame(100, $dto['displayed_count']);
        $this->assertSame(1, $dto['repair_count']);
        $this->assertTrue($dto['truncated']);
        $this->assertNull($dto['confirmed_units']);
    }

    public function test_global_feed_cap_retains_repair_and_reports_ppe_truncation_from_competing_sources(): void
    {
        $this->item->update(['expiry_date' => '2026-10-20', 'next_inspection_due' => '2026-10-21']);
        EmergencyDrill::factory()->create(['site_id' => $this->site->id, 'created_by' => $this->viewer->id,
            'scheduled_at' => '2026-10-12 00:00:00', 'drill_type' => 'fire_evacuation']);
        $dto = $this->feed(limit: 1);
        $this->assertCount(1, $dto['items']);
        $this->assertSame('drill', $dto['items'][0]['type']);
        $this->assertSame(2, $dto['ppe']['confirmed_count']);
        $this->assertSame(0, $dto['ppe']['displayed_count']);
        $this->assertTrue($dto['ppe']['truncated']);
        $root = $this->typedItem();
        DB::table('ppe_inventory')->where('id', $root->id)->update(['status' => 'disposed']);
        $dto = $this->feed(limit: 1);
        $this->assertSame('ppe_repair', $dto['items'][0]['type']);
        $this->assertNull($dto['items'][0]['due_date']);
        $this->assertSame(1, $dto['ppe']['displayed_count']);
        $this->assertSame(1, $dto['ppe']['repair_count']);
        $this->assertNull($dto['ppe']['total']);
        $this->assertTrue($dto['ppe']['truncated']);
        $this->assertSame($dto['items'], app(HsDashboardService::class)->expiringFeed($this->site->id, 60, 1, $this->viewer));
    }

    public function test_http_legacy_counts_are_record_counts_not_batch_quantity_or_fit_certification(): void
    {
        $this->item->update(['quantity' => 12, 'next_inspection_due' => '2026-10-11', 'expiry_date' => '2026-10-20']);
        PpeAllocation::factory()->create(['ppe_inventory_id' => $this->item->id, 'user_id' => $this->worker->id]);
        $props = $this->dashboard();
        foreach (['ppe_inspections_overdue', 'ppe_expiring', 'ppe_unacknowledged'] as $key) {
            $this->assertSame(1, $props['kpis'][$key]);
            $this->assertSame('legacy_unknown', $props['ppe_kpi_state']['integrity'][$key]);
        }
        $this->assertSame(12, $this->profile()['confirmed_units']);
        $this->assertSame(2, $props['worklists']['ppe_expiring_state']['confirmed_count']);
        $this->assertSame(1, $props['ppe_kpi_state']['physical_inventory_count']);
        $this->assertArrayNotHasKey('fit_valid', $props['ppe_kpi_state']);
        $this->assertArrayNotHasKey('inventory_complete', $props['ppe_kpi_state']);
    }

    public function test_http_full_and_partial_json_preserve_ppe_uncertainty_metadata_and_foreign_scope(): void
    {
        $this->item->update(['expiry_date' => '2026-10-20']);
        $this->foreignItem->update(['expiry_date' => '2026-10-21', 'next_inspection_due' => '2026-10-11']);
        $props = $this->dashboard();
        $this->assertSame(1, $props['kpis']['ppe_expiring']);
        $this->assertSame(1, $props['ppe_kpi_state']['physical_inventory_count']);
        $this->assertSame(1, $props['worklists']['ppe_expiring_state']['physical_record_count']);
        $this->assertStringNotContainsString('FOREIGN-PRIVATE', json_encode($props, JSON_THROW_ON_ERROR));
        $partial = $this->actingAs($this->viewer)->get('/health-safety', ['X-Inertia' => 'true', 'Accept' => 'application/json',
            'X-Inertia-Partial-Component' => 'health-safety/dashboard', 'X-Inertia-Partial-Data' => 'kpis,ppe_kpi_state,worklists'])
            ->assertOk()->json('props');
        $this->assertSame($props['kpis']['ppe_expiring'], $partial['kpis']['ppe_expiring']);
        $this->assertSame($props['ppe_kpi_state'], $partial['ppe_kpi_state']);
        $this->assertSame($props['worklists']['ppe_expiring_state'], $partial['worklists']['ppe_expiring_state']);
        $this->assertArrayNotHasKey('sites', $partial);
    }

    public function test_http_private_ppe_measures_are_null_when_current_staff_evidence_is_withdrawn(): void
    {
        // Keep the independent dashboard Site audience valid; PPE still requires current internal staff.
        $this->allow($this->viewer, 'healthSafety.viewAllSites', true);
        $this->item->update(['expiry_date' => '2026-10-20']);
        $this->assertSame(1, $this->dashboard()['kpis']['ppe_expiring']);
        HrEmployeeProfile::where('user_id', $this->viewer->id)->update(['is_active' => false]);
        $props = $this->dashboard();
        foreach (['ppe_inspections_overdue', 'ppe_expiring', 'ppe_unacknowledged'] as $key) {
            $this->assertNull($props['kpis'][$key]);
            $this->assertSame('restricted', $props['ppe_kpi_state']['integrity'][$key]);
        }
        $this->assertNull($props['worklists']['ppe_expiring_state']['total']);
        $this->assertSame('restricted', $props['worklists']['ppe_expiring_state']['source_integrity']);
        $this->assertSame([], array_values(array_filter($props['worklists']['expiring'], fn ($row) => str_starts_with($row['type'], 'ppe_'))));
        HrEmployeeProfile::where('user_id', $this->viewer->id)->update(['is_active' => true]);
        $this->assertSame(1, $this->dashboard()['kpis']['ppe_expiring']);
    }

    public function test_actorless_dashboard_feed_never_queries_ppe_and_marks_measures_restricted(): void
    {
        $this->item->update(['expiry_date' => '2026-10-20']);
        [$dto, $sql] = $this->queries(fn () => app(HsDashboardService::class)->expiringFeedProjection($this->site->id));
        $this->assertSame([], $dto['items']);
        $this->assertSame('restricted', $dto['ppe']['source_integrity']);
        $this->assertNull($dto['ppe']['total']);
        $this->assertNull($dto['ppe']['physical_record_count']);
        $this->assertNoPpeSql($sql);
    }

    public function test_actual_feed_keeps_nz_inclusive_dates_and_exact_filtered_register_return(): void
    {
        $this->item->update(['expiry_date' => '2026-12-11', 'next_inspection_due' => '2026-10-12']);
        $dto = $this->feed();
        $this->assertSame(2, $dto['ppe']['confirmed_count']);
        $this->assertSame(2, $dto['ppe']['total']);
        $byType = collect($dto['items'])->keyBy('type');
        $this->assertSame(0, $byType['ppe_inspection']['days_until']);
        $this->assertSame(60, $byType['ppe_expiry']['days_until']);
        foreach (['ppe_inspection' => 'inv_inspection', 'ppe_expiry' => 'inv_expiring'] as $kind => $tab) {
            $row = $byType[$kind];
            $this->assertSame('/health-safety/ppe/inventory/'.$this->item->id, parse_url($row['register_url'], PHP_URL_PATH));
            parse_str(parse_url($row['register_url'], PHP_URL_QUERY), $query);
            $this->assertSame('/health-safety/ppe', parse_url($query['return_to'], PHP_URL_PATH));
            parse_str(parse_url($query['return_to'], PHP_URL_QUERY), $back);
            $this->assertSame(['tab' => $tab, 'site_id' => (string) $this->site->id], $back);
            $this->assertSame('legacy_unknown', $row['attribution_state']);
        }
        $this->assertSame($dto['items'], app(HsDashboardService::class)->expiringFeed($this->site->id, 60, 20, $this->viewer));
        $this->assertSame(0, $this->dashboard()['kpis']['ppe_inspections_overdue'], 'Today is due on the profile, but not strictly overdue.');
    }

    public function test_governed_site_reparent_is_withheld_then_repaired_under_actual_site_bypass(): void
    {
        $root = $this->typedItem();
        DB::table('ppe_inventory')->where('id', $root->id)->update(['site_id' => $this->foreignSite->id, 'serial_number' => 'DAMAGED-FOREIGN-FACT']);
        $this->assertSame(1, $this->profile()['physical_record_count']);
        $this->assertSame([], $this->feed()['items']);
        $this->allow($this->viewer, 'healthSafety.viewAllSites', true);
        $dto = app(HsDashboardService::class)->expiringFeedProjection($this->foreignSite->id, 60, 20, $this->viewer);
        $this->assertSame('ppe_repair', $dto['items'][0]['type']);
        $this->assertSame($root->id, $dto['items'][0]['inventory_id']);
        $this->assertNull($dto['ppe']['total']);
        $this->assertStringNotContainsString('DAMAGED-FOREIGN-FACT', json_encode($dto, JSON_THROW_ON_ERROR));
        DB::table('ppe_inventory')->where('id', $root->id)->update(['site_id' => $this->site->id, 'serial_number' => 'GOVERNED-ITEM']);
        $this->assertNotSame('repair_required', collect($this->profile()['items'])->firstWhere('id', $root->id)['source_integrity']);
    }

    private function profile(): array
    {
        return app(SiteProfileSafetyPresenter::class)->ppe($this->viewer, $this->site);
    }

    private function feed(?int $siteId = null, int $limit = 20): array
    {
        return app(HsDashboardService::class)->expiringFeedProjection($siteId ?? $this->site->id, 60, $limit, $this->viewer);
    }

    private function dashboard(): array
    {
        return $this->actingAs($this->viewer)->get('/health-safety', ['X-Inertia' => 'true', 'Accept' => 'application/json'])
            ->assertOk()->json('props');
    }

    private function typedItem(): PpeInventory
    {
        $receipt = app(PpeCommandService::class)->createInventory($this->viewer, ['idempotency_key' => (string) Str::uuid(),
            'ppe_type_id' => $this->type->id, 'site_id' => $this->site->id, 'serial_number' => 'GOVERNED-ITEM',
            'condition' => 'good', 'quantity' => 1, 'expiry_date' => null, 'next_inspection_due' => null]);

        return PpeInventory::findOrFail($receipt['root_id']);
    }

    private function assertSiteBack(string $href, int $itemId): void
    {
        $this->assertSame('/health-safety/ppe/inventory/'.$itemId, parse_url($href, PHP_URL_PATH));
        parse_str(parse_url($href, PHP_URL_QUERY), $query);
        $this->assertSame(['return_to' => '/sites/'.$this->site->id.'?tab=ppe'], $query);
    }

    private function assertNoPpeSql(array $queries): void
    {
        foreach ($queries as $sql) {
            $this->assertDoesNotMatchRegularExpression('/\b(?:from|join)\s+[`"]?ppe_(?:inventory|types|allocations|source_versions)[`"]?\b/i', $sql);
        }
    }
}
