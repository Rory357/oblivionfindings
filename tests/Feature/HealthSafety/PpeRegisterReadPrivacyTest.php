<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\PpeAllocation;
use App\Models\PpeInventory;
use App\Models\PpeType;
use App\Services\HealthSafety\PpePickerService;
use App\Services\HealthSafety\PpeCommandService;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use App\Services\HealthSafety\PpeRegisterService;

class PpeRegisterReadPrivacyTest extends PpeReadTestCase
{
    public function test_register_counts_catalogue_counts_and_picker_rows_exclude_foreign_site(): void
    {
        $dto = app(PpeRegisterService::class)->index($this->viewer);
        $this->assertSame(1, $dto['inventory']['total']);
        $this->assertSame(1, $dto['tabCounts']['inv_all']);
        $this->assertSame(1, $dto['hero']['clusters']['live']['total']);
        $this->assertSame(1, $dto['types'][0]['inventory_count']);
        $this->assertSame([$this->site->id], array_column($dto['sites'], 'id'));
        $this->assertSame([$this->item->id], array_column($dto['allocatable'], 'id'));
        $this->assertStringNotContainsString('FOREIGN-PRIVATE', json_encode($dto, JSON_THROW_ON_ERROR));
        $this->assertDetached($dto);
    }

    public function test_all_refinements_match_counts_and_export_while_site_overview_stays_separate(): void
    {
        $otherType = PpeType::factory()->create(['category' => 'hand']);
        PpeInventory::factory()->create(['site_id' => $this->site->id, 'ppe_type_id' => $otherType->id]);
        $filters = ['category' => 'respiratory', 'ppe_type_id' => $this->type->id, 'status' => 'available', 'search' => 'OWN-ITEM'];
        $service = app(PpeRegisterService::class);
        $dto = $service->index($this->viewer, $filters);
        $rows = [];
        $count = $service->export($this->viewer, $filters, function ($cells) use (&$rows) { $rows[] = $cells; });
        $this->assertSame(1, $dto['inventory']['total']);
        $this->assertSame(1, $dto['tabCounts']['inv_all']);
        $this->assertSame(2, $dto['site_overview']['tabCounts']['inv_all']);
        $this->assertSame($dto['inventory']['total'], $count);
        $this->assertSame('OWN-ITEM', $rows[0][7]);
        $this->assertCount(13, $rows[0]);
    }

    public function test_active_tab_does_not_narrow_count_base_or_filtered_csv(): void
    {
        PpeInventory::factory()->condemned()->create(['site_id' => $this->site->id, 'ppe_type_id' => $this->type->id]);
        $service = app(PpeRegisterService::class);
        $dto = $service->index($this->viewer, ['tab' => 'inv_available']);
        $this->assertSame(1, $dto['inventory']['total']);
        $this->assertSame(2, $dto['tabCounts']['inv_all']);
        $this->assertSame(1, $dto['tabCounts']['inv_condemned']);
        $this->assertSame(2, $service->export($this->viewer, ['tab' => 'inv_available'], fn ($row) => null));
    }

    public function test_foreign_filter_is_not_treated_as_empty_or_global(): void
    {
        $service = app(PpeRegisterService::class);
        $this->denied(fn () => $service->index($this->viewer, ['site_id' => $this->foreignSite->id]), 404);
        $emitted = false;
        $this->denied(fn () => $service->export($this->viewer, ['site_id' => $this->foreignSite->id], function () use (&$emitted) { $emitted = true; }), 404);
        $this->assertFalse($emitted);
    }

    public function test_allocation_active_and_unacknowledged_filter_counts_preserve_exact_parent_scope(): void
    {
        PpeAllocation::factory()->create(['ppe_inventory_id' => $this->item->id, 'user_id' => $this->worker->id, 'acknowledged' => false]);
        PpeAllocation::factory()->acknowledged()->create(['ppe_inventory_id' => $this->item->id, 'user_id' => $this->worker->id]);
        PpeAllocation::factory()->returned()->create(['ppe_inventory_id' => $this->item->id, 'user_id' => $this->worker->id]);
        PpeAllocation::factory()->create(['ppe_inventory_id' => $this->foreignItem->id, 'user_id' => $this->worker->id]);
        $dto = app(PpeRegisterService::class)->index($this->viewer, ['tab' => 'alloc_unack']);
        $this->assertSame(1, $dto['allocations']['total']);
        $this->assertSame(2, $dto['tabCounts']['alloc_active']);
        $this->assertSame(1, $dto['tabCounts']['alloc_unack']);
        $this->assertSame(2, $dto['hero']['compliance']['rpe_fit_test_due']);
    }

    public function test_nz_day_thresholds_include_today_without_expiring_at_start_of_day(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-10-12T23:30:00+13:00'));
        $this->item->update(['expiry_date' => '2026-10-12', 'next_inspection_due' => '2026-10-12']);
        $dto = app(PpeRegisterService::class)->index($this->viewer, ['tab' => 'inv_inspection']);
        $this->assertSame(1, $dto['inventory']['total']);
        $this->assertSame(0, $dto['hero']['compliance']['inspections_overdue']);
        $this->assertSame('in_date', $dto['inventory']['data'][0]['currency']['expiry_state']);
        $this->travelTo(\Carbon\Carbon::parse('2026-10-13T00:01:00+13:00'));
        $dto = app(PpeRegisterService::class)->index($this->viewer);
        $this->assertSame(1, $dto['hero']['compliance']['inspections_overdue']);
        $this->assertSame('expired', $dto['inventory']['data'][0]['currency']['expiry_state']);
    }

    public function test_presence_and_absent_dates_do_not_claim_inventory_compliance_or_current_fit(): void
    {
        PpeInventory::factory()->create(['site_id' => $this->site->id,
            'ppe_type_id' => PpeType::factory()->create(['category' => 'high_visibility'])->id, 'expiry_date' => null]);
        $dto = app(PpeRegisterService::class)->index($this->viewer);
        $this->assertTrue($dto['hero']['compliance']['hi_vis_recorded']);
        foreach (['hi_vis_covered', 'footwear_covered', 'inventory_complete', 'fit_test_current', 'training_current'] as $key) {
            $this->assertNull($dto['hero']['compliance'][$key]);
        }
        $own = collect($dto['inventory']['data'])->firstWhere('id', $this->item->id);
        $this->assertSame('unknown', $own['currency']['expiry_state']);
        $this->assertNull($own['currency']['release_authorized']);
    }

    public function test_inventory_pagination_has_exact_total_and_export_does_not_stop_at_first_page(): void
    {
        PpeInventory::factory()->count(27)->create(['site_id' => $this->site->id, 'ppe_type_id' => $this->type->id]);
        $service = app(PpeRegisterService::class);
        $first = $service->index($this->viewer);
        $second = $service->index($this->viewer, ['page' => 2]);
        $this->assertSame(28, $first['inventory']['total']);
        $this->assertCount(25, $first['inventory']['data']);
        $this->assertCount(3, $second['inventory']['data']);
        $this->assertSame(28, $service->export($this->viewer, [], fn ($row) => null));
    }

    public function test_catalogue_retirement_keeps_history_and_does_not_condemn_inventory(): void
    {
        $this->type->update(['is_active' => false]);
        $dto = app(PpeRegisterService::class)->index($this->viewer);
        $this->assertFalse($dto['types'][0]['is_active']);
        $this->assertSame(1, $dto['inventory']['total']);
        $this->assertSame('available', $dto['inventory']['data'][0]['status']);
        $this->assertSame([], $dto['allocatable']);
        $this->assertSame('available', $this->item->fresh()->status);
    }

    public function test_picker_people_require_current_staff_and_actual_selected_site_membership(): void
    {
        $former = $this->staff([]);
        HrEmployeeProfile::where('user_id', $former->id)->update(['is_active' => false]);
        $foreign = $this->staff([], $this->foreignSite);
        $dto = app(PpePickerService::class)->options($this->viewer, ['site_id' => $this->site->id]);
        $ids = array_column($dto['staff'], 'id');
        $this->assertContains($this->worker->id, $ids);
        $this->assertNotContains($former->id, $ids);
        $this->assertNotContains($foreign->id, $ids);
        $this->assertSame([], app(PpePickerService::class)->options($this->staff(['hazards.view']))['staff']);
    }

    public function test_picker_paging_and_search_retain_people_beyond_first_fifty(): void
    {
        for ($i = 0; $i < 52; $i++) {
            $this->staff([])->update(['name' => 'Picker '.str_pad((string) $i, 3, '0', STR_PAD_LEFT)]);
        }
        $service = app(PpePickerService::class);
        $first = $service->options($this->viewer);
        $second = $service->options($this->viewer, ['staff_page' => 2]);
        $search = $service->options($this->viewer, ['staff_search' => 'Picker 051']);
        $this->assertSame(54, $first['staff_page']['total']);
        $this->assertCount(50, $first['staff']);
        $this->assertCount(4, $second['staff']);
        $this->assertSame('Picker 051', $search['staff'][0]['name']);
    }

    public function test_manage_only_capture_choices_do_not_imply_register_view(): void
    {
        $manager = $this->staff(['hazards.manage']);
        $this->denied(fn () => app(PpeRegisterService::class)->index($manager), 403);
        $dto = app(PpePickerService::class)->options($manager, [], 'create');
        $this->assertSame($this->site->id, $dto['sites'][0]['id']);
        $this->assertContains($this->item->id, array_column($dto['allocatable'], 'id'));
    }
    public function test_current_create_and_catalogue_capabilities_are_explicit_and_restore_freshly(): void
    {
        $picker = app(PpePickerService::class);
        $this->viewer->load(['roles.permissions', 'permissionOverrides']);
        $this->assertSame(['create' => true, 'catalogue_manage' => true], $picker->options($this->viewer)['can']);
        $this->allow($this->viewer, 'hazards.manage', false);
        $this->assertSame(['create' => false, 'catalogue_manage' => false], $picker->options($this->viewer)['can']);
        $this->allow($this->viewer, 'hazards.manage', true);
        $this->assertTrue($picker->options($this->viewer)['can']['create']);
        $reader = $this->staff(['hazards.view']);
        $this->assertFalse($picker->options($reader)['can']['catalogue_manage']);
    }

    public function test_typed_catalogue_context_is_proved_and_damaged_definition_is_withheld(): void
    {
        $receipt = app(PpeCommandService::class)->createType($this->viewer, ['idempotency_key' => (string) Str::uuid(),
            'name' => 'Accepted catalogue', 'category' => 'head']);
        $service = app(PpeRegisterService::class);
        $row = collect($service->index($this->viewer)['types'])->firstWhere('id', $receipt['root_id']);
        $this->assertSame('intact', $row['source_integrity']);
        $this->assertTrue($row['can']['manage']);
        $this->assertSame(1, $row['command_context']['expected_version']);
        DB::table('ppe_types')->where('id', $receipt['root_id'])->update(['name' => 'CORRUPTED-DEFINITION']);
        $damaged = collect($service->index($this->viewer)['types'])->firstWhere('id', $receipt['root_id']);
        $this->assertSame('repair_required', $damaged['source_integrity']);
        $this->assertFalse($damaged['can']['manage']);
        $this->assertNull($damaged['name']);
        $this->assertNull($damaged['command_context']);
        $options = app(PpePickerService::class)->options($this->viewer);
        $this->assertNotContains($receipt['root_id'], array_column($options['types'], 'id'));
        $this->assertStringNotContainsString('CORRUPTED-DEFINITION', json_encode($options));
    }

    public function test_picker_keeps_status_candidates_but_explains_actual_issue_constraints(): void
    {
        $this->item->update(['expiry_date' => '2026-10-11']);
        $picker = app(PpePickerService::class);
        $expired = collect($picker->options($this->viewer)['allocatable'])->firstWhere('id', $this->item->id);
        $this->assertFalse($expired['can']['allocate']);
        $this->assertNotNull($expired['blocked_reason']);
        $this->item->update(['expiry_date' => null]);
        PpeAllocation::factory()->create(['ppe_inventory_id' => $this->item->id, 'user_id' => $this->worker->id]);
        $duplicate = collect($picker->options($this->viewer)['allocatable'])->firstWhere('id', $this->item->id);
        $this->assertFalse($duplicate['can']['allocate']);
        $this->assertSame(1, $duplicate['quantity']);
        $this->assertNull($duplicate['release_authorized']);
    }
    public function test_damaged_matching_inventory_has_placeholder_physical_counts_and_unknown_fact_totals(): void
    {
        $receipt = app(PpeCommandService::class)->createInventory($this->viewer, ['idempotency_key' => (string) Str::uuid(),
            'site_id' => $this->site->id, 'ppe_type_id' => $this->type->id, 'serial_number' => 'Accepted stock', 'quantity' => 7]);
        DB::table('ppe_inventory')->where('id', $receipt['root_id'])->update(['serial_number' => 'CORRUPTED-STOCK', 'quantity' => 999]);
        $dto = app(PpeRegisterService::class)->index($this->viewer);
        $row = collect($dto['inventory']['data'])->firstWhere('id', $receipt['root_id']);
        $this->assertSame('repair_required', $row['source_integrity']);
        $this->assertNull($row['quantity']);
        $this->assertNull($row['serial_number']);
        $this->assertNull($row['ppe_type']);
        $this->assertSame(2, $dto['physical_tabCounts']['inv_all']);
        $this->assertNull($dto['tabCounts']['inv_all']);
        $this->assertSame(2, $dto['hero']['physical_rows']['inventory']);
        $this->assertNull($dto['hero']['clusters']['live']['units']);
        $type = collect($dto['types'])->firstWhere('id', $this->type->id);
        $this->assertNull($type['inventory_count']);
        $this->assertSame(2, $type['physical_inventory_count']);
        $this->assertStringNotContainsString('CORRUPTED-STOCK', json_encode($dto));
    }

    public function test_export_refuses_all_matching_corrupt_facts_before_emitting_any_cell(): void
    {
        $receipt = app(PpeCommandService::class)->createInventory($this->viewer, ['idempotency_key' => (string) Str::uuid(),
            'site_id' => $this->site->id, 'ppe_type_id' => $this->type->id, 'serial_number' => 'Accepted export']);
        DB::table('ppe_inventory')->where('id', $receipt['root_id'])->update(['quantity' => 999]);
        $rows = [];
        try {
            app(PpeRegisterService::class)->export($this->viewer, [], function ($cells) use (&$rows) { $rows[] = $cells; });
            $this->fail('A damaged matching source cannot be exported as verified facts.');
        } catch (\Illuminate\Validation\ValidationException $failure) {
            $this->assertArrayHasKey('export', $failure->errors());
        }
        $this->assertSame([], $rows);
        $this->actingAs($this->viewer)->getJson('/health-safety/ppe/export')->assertUnprocessable()->assertJsonValidationErrors('export');
    }

    public function test_current_refinements_do_not_let_unrelated_damaged_site_overview_poison_healthy_filtered_facts(): void
    {
        $receipt = app(PpeCommandService::class)->createInventory($this->viewer, ['idempotency_key' => (string) Str::uuid(),
            'site_id' => $this->site->id, 'ppe_type_id' => $this->type->id, 'serial_number' => 'Other stock']);
        DB::table('ppe_inventory')->where('id', $receipt['root_id'])->update(['quantity' => 999]);
        $service = app(PpeRegisterService::class);
        $dto = $service->index($this->viewer, ['search' => 'OWN-ITEM']);
        $this->assertSame(1, $dto['tabCounts']['inv_all']);
        $this->assertSame('legacy_unknown', $dto['source_integrity']['inventory']);
        $this->assertSame('repair_required', $dto['site_overview']['source_integrity']['inventory']);
        $this->assertNull($dto['site_overview']['tabCounts']['inv_all']);
        $this->assertSame(2, $dto['site_overview']['physical_tabCounts']['inv_all']);
        $rows = [];
        $this->assertSame(1, $service->export($this->viewer, ['search' => 'OWN-ITEM'], function ($cells) use (&$rows) { $rows[] = $cells; }));
        $this->assertSame('OWN-ITEM', $rows[0][7]);
    }

    public function test_damaged_typed_allocation_cannot_supply_worker_labels_or_private_attention_measures(): void
    {
        $created = app(PpeCommandService::class)->createInventory($this->viewer, ['idempotency_key' => (string) Str::uuid(),
            'site_id' => $this->site->id, 'ppe_type_id' => $this->type->id, 'serial_number' => 'Issue root']);
        $issued = app(PpeCommandService::class)->allocate($created['root_id'], $this->viewer, ['idempotency_key' => (string) Str::uuid(),
            'expected_version' => 1, 'user_id' => $this->worker->id, 'notes' => 'Original issue']);
        DB::table('ppe_allocations')->where('id', $issued['source_id'])->update(['notes' => 'CORRUPTED-ISSUE']);
        $dto = app(PpeRegisterService::class)->index($this->viewer, ['tab' => 'alloc_active']);
        $row = collect($dto['allocations']['data'])->firstWhere('id', $issued['source_id']);
        $this->assertSame('repair_required', $row['source_integrity']);
        $this->assertNull($row['user']);
        $this->assertNull($row['inventory_item']);
        $this->assertSame(1, $dto['physical_tabCounts']['alloc_active']);
        $this->assertNull($dto['tabCounts']['alloc_active']);
        $this->assertNull($dto['hero']['compliance']['rpe_fit_test_due']);
        $this->assertStringNotContainsString('CORRUPTED-ISSUE', json_encode($dto));
    }

    public function test_export_reproves_the_actual_next_row_after_a_prior_emission_changes_source_facts(): void
    {
        $created = app(PpeCommandService::class)->createInventory($this->viewer, ['idempotency_key' => (string) Str::uuid(),
            'site_id' => $this->site->id, 'ppe_type_id' => $this->type->id, 'serial_number' => 'Proof after preflight', 'quantity' => 2]);
        $rows = [];
        try {
            app(PpeRegisterService::class)->export($this->viewer, [], function ($cells) use (&$rows, $created) {
                $rows[] = $cells;
                DB::table('ppe_inventory')->where('id', $created['root_id'])->update(['quantity' => 999]);
            });
            $this->fail('A prior identity-only scan cannot certify subsequently changed output facts.');
        } catch (\Illuminate\Validation\ValidationException $failure) {
            $this->assertArrayHasKey('export', $failure->errors());
        }
        $this->assertCount(1, $rows);
        $this->assertSame('OWN-ITEM', $rows[0][7]);
        $this->assertStringNotContainsString('999', json_encode($rows));
    }

    public function test_catalogue_counts_prove_all_current_approved_sites_despite_a_narrow_selected_site(): void
    {
        $this->viewer->hrEmployeeProfile()->update(['secondary_site_ids' => [$this->foreignSite->id]]);
        $commands = app(PpeCommandService::class);
        $created = $commands->createInventory($this->viewer, ['idempotency_key' => (string) Str::uuid(),
            'site_id' => $this->foreignSite->id, 'ppe_type_id' => $this->type->id, 'serial_number' => 'SECOND-SITE-STOCK']);
        $service = app(PpeRegisterService::class);
        $healthy = $service->index($this->viewer, ['site_id' => $this->site->id]);
        $this->assertSame(1, $healthy['tabCounts']['inv_all']);
        $this->assertSame(3, $healthy['types'][0]['inventory_count']);
        $this->assertSame('approved_sites', $healthy['types'][0]['count_scope']);
        DB::table('ppe_inventory')->where('id', $created['root_id'])->update(['quantity' => 999]);
        $damaged = $service->index($this->viewer, ['site_id' => $this->site->id]);
        $this->assertSame(1, $damaged['tabCounts']['inv_all']);
        $this->assertSame(3, $damaged['types'][0]['physical_inventory_count']);
        $this->assertNull($damaged['types'][0]['inventory_count']);
        $this->assertSame('repair_required', $damaged['types'][0]['inventory_integrity']);
        $this->viewer->hrEmployeeProfile()->update(['secondary_site_ids' => []]);
        $restoredScope = $service->index($this->viewer, ['site_id' => $this->site->id]);
        $this->assertSame(1, $restoredScope['types'][0]['inventory_count']);
        $this->assertSame(1, $restoredScope['types'][0]['physical_inventory_count']);
    }

    public function test_export_current_materialization_keeps_refinements_after_a_legacy_source_changes(): void
    {
        $this->item->update(['serial_number' => 'MATCH-FIRST']);
        $next = PpeInventory::factory()->create(['site_id' => $this->site->id, 'ppe_type_id' => $this->type->id, 'serial_number' => 'MATCH-NEXT']);
        $rows = [];
        $count = app(PpeRegisterService::class)->export($this->viewer, ['search' => 'MATCH'], function ($cells) use (&$rows, $next) {
            $rows[] = $cells;
            DB::table('ppe_inventory')->where('id', $next->id)->update(['serial_number' => 'OUTSIDE-REFINEMENTS']);
        });
        $this->assertSame(1, $count);
        $this->assertCount(1, $rows);
        $this->assertSame('MATCH-FIRST', $rows[0][7]);
        $this->assertStringNotContainsString('OUTSIDE-REFINEMENTS', json_encode($rows));
    }
}
