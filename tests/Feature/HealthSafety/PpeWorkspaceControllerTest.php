<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Http\Controllers\MyTasksController;
use App\Models\PpeAllocation;
use App\Models\PpeCommandReceipt;
use App\Models\PpeInspection;
use App\Models\PpeInventory;
use App\Models\PpeType;
use App\Services\HealthSafety\PpePickerService;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use ReflectionMethod;
use ReflectionProperty;

/** Actual mounted HTTP composition. Physical file publication/cleanup belongs to the separate committed suites. */
final class PpeWorkspaceControllerTest extends PpeReadTestCase
{
    public function test_permanent_inventory_allocation_and_inspection_inertia_pages_use_exact_sources(): void
    {
        $allocation = $this->legacyAllocation();
        $inspection = PpeInspection::factory()->create(['ppe_inventory_id' => $this->item->id,
            'inspected_by' => $this->viewer->id, 'findings' => 'Actual check']);
        $this->actingAs($this->viewer)->get('/health-safety/ppe')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('health-safety/ppe/index')->where('detail', null));
        $this->get('/health-safety/ppe/inventory/'.$this->item->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('health-safety/ppe/show')->where('kind', 'inventory')
                ->where('detail.id', $this->item->id)->where('detail.serial_number', 'OWN-ITEM'));
        $this->get('/health-safety/ppe/allocations/'.$allocation->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('health-safety/ppe/show')->where('kind', 'allocations')
                ->where('detail.id', $allocation->id)->where('detail.inventory_item.id', $this->item->id));
        $this->get('/health-safety/ppe/inspections/'.$inspection->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('health-safety/ppe/show')->where('kind', 'inspections')
                ->where('detail.id', $inspection->id)->where('detail.findings', 'Actual check'));
    }

    public function test_json_detail_preserves_recorded_facts_without_a_current_fitness_or_storage_claim(): void
    {
        $allocation = $this->legacyAllocation(['notes' => 'Retained issue note', 'fit_test_result' => 'Recorded result',
            'fit_test_completed' => true, 'fit_test_date' => '2026-10-10', 'allocated_at' => '2026-10-11 23:30:00']);
        $response = $this->actingAs($this->viewer)->getJson('/health-safety/ppe/allocations/'.$allocation->id)->assertOk()
            ->assertJsonPath('detail.notes', 'Retained issue note')->assertJsonPath('detail.fit_test_result', 'Recorded result')
            ->assertJsonPath('detail.fit_test_date', '2026-10-10')->assertJsonPath('detail.fit_test_current', null)
            ->assertJsonPath('detail.training_current', null)->assertJsonPath('detail.fitness_for_work', null)
            ->assertJsonPath('detail.command_context.root_id', $this->item->id)
            ->assertJsonPath('detail.command_context.source_id', $allocation->id)
            ->assertJsonPath('detail.attribution_state', 'legacy_unknown');
        $this->assertStringEndsWith('+13:00', $response->json('detail.allocated_at'));
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $response->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertDetached($response->json());
    }

    public function test_real_child_pagination_keeps_totals_and_active_issue_outside_loaded_page(): void
    {
        $active = $this->legacyAllocation(['allocated_at' => '2020-01-01 00:00:00']);
        PpeAllocation::factory()->count(26)->returned()->create(['ppe_inventory_id' => $this->item->id, 'user_id' => $this->worker->id]);
        PpeInspection::factory()->count(26)->create(['ppe_inventory_id' => $this->item->id, 'inspected_by' => $this->viewer->id]);
        $this->actingAs($this->viewer)->getJson('/health-safety/ppe/inventory/'.$this->item->id)->assertOk()
            ->assertJsonCount(25, 'detail.allocations')->assertJsonPath('detail.pages.allocations.total', 27)
            ->assertJsonPath('detail.active_allocation.id', $active->id)->assertJsonPath('detail.can.allocate', false);
        $this->getJson('/health-safety/ppe/inventory/'.$this->item->id.'?allocations_page=2&inspections_page=2')->assertOk()
            ->assertJsonCount(2, 'detail.allocations')->assertJsonCount(1, 'detail.inspections')
            ->assertJsonPath('detail.pages.allocations.current_page', 2)->assertJsonPath('detail.pages.inspections.current_page', 2)
            ->assertJsonPath('detail.pages.allocations.total', 27)->assertJsonPath('detail.pages.inspections.total', 26);
        $this->getJson('/health-safety/ppe/inventory/'.$this->item->id.'?history_page=0')->assertUnprocessable();
    }

    public function test_legacy_item_converges_to_permanent_record_with_exact_filtered_back_and_pages(): void
    {
        $response = $this->actingAs($this->viewer)->get('/health-safety/ppe?'.http_build_query([
            'item' => $this->item->id, 'tab' => 'inv_available', 'site_id' => $this->site->id,
            'category' => 'respiratory', 'search' => 'OWN-ITEM', 'page' => 3, 'view' => 'cards',
            'section' => 'inspections', 'inspections_page' => 2, 'history_page' => 4, 'receipt_key' => (string) Str::uuid(),
        ]))->assertRedirect();
        $this->assertSame('/health-safety/ppe/inventory/'.$this->item->id, parse_url($response->headers->get('Location'), PHP_URL_PATH));
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('inspections', $query['section']);
        $this->assertSame('2', $query['inspections_page']);
        $this->assertSame('4', $query['history_page']);
        $this->assertSame('/health-safety/ppe?tab=inv_available&site_id='.$this->site->id.'&category=respiratory&search=OWN-ITEM&page=3&view=cards', $query['return_to']);
        $this->assertArrayNotHasKey('receipt_key', $query);
        $this->getJson($response->headers->get('Location'))->assertOk()->assertJsonPath('return_to', $query['return_to'])
            ->assertJsonPath('detail.id', $this->item->id)->assertJsonPath('detail.pages.inspections.current_page', 2);
    }

    public function test_legacy_allocation_converges_with_its_own_identity_and_filtered_register_origin(): void
    {
        $allocation = $this->legacyAllocation();
        $response = $this->actingAs($this->viewer)->get('/health-safety/ppe?'.http_build_query([
            'allocation' => $allocation->id, 'tab' => 'alloc_unack', 'allocations_page' => 2,
            'section' => 'fit-training', 'attachments_page' => 3, 'action' => 'acknowledge',
        ]))->assertRedirect();
        $this->assertSame('/health-safety/ppe/allocations/'.$allocation->id, parse_url($response->headers->get('Location'), PHP_URL_PATH));
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('fit-training', $query['section']);
        $this->assertSame('3', $query['attachments_page']);
        $this->assertSame('acknowledge', $query['action']);
        $this->assertSame('/health-safety/ppe?tab=alloc_unack&allocations_page=2', $query['return_to']);
        $this->getJson($response->headers->get('Location'))->assertOk()->assertJsonPath('detail.id', $allocation->id);
    }

    public function test_legacy_conflicting_or_malformed_identity_cannot_be_used_as_a_private_lookup(): void
    {
        [$unused, $queries] = $this->queries(function () {
            $this->actingAs($this->viewer)->getJson('/health-safety/ppe?item='.$this->item->id.'&allocation=1')->assertUnprocessable();
            $this->getJson('/health-safety/ppe?item=01')->assertUnprocessable();
            $this->getJson('/health-safety/ppe?allocation[]=1')->assertUnprocessable();
            return null;
        });
        foreach ($queries as $sql) {
            $this->assertDoesNotMatchRegularExpression('/from\s+[`"]?ppe_(?:inventory|allocations|inspections|source_versions)/i', $sql);
        }
        $this->getJson('/health-safety/ppe?item='.$this->foreignItem->id)->assertNotFound();
    }

    public function test_auth_only_own_page_is_narrow_and_does_not_query_private_allocation_content(): void
    {
        $allocation = $this->legacyAllocation(['notes' => 'PRIVATE-ISSUE-NOTE', 'fit_test_result' => 'PRIVATE-FIT-RESULT']);
        [$response, $queries] = $this->queries(fn () => $this->actingAs($this->worker)->getJson('/health-safety/ppe/allocations/'.$allocation->id));
        $response->assertOk()->assertJsonPath('detail.read_scope', 'own_allocation')->assertJsonPath('detail.can.acknowledge_own', true)
            ->assertJsonPath('detail.can.manage', false)->assertJsonPath('detail.can.evidence', false)
            ->assertJsonMissingPath('detail.notes')->assertJsonMissingPath('detail.fit_test_result')
            ->assertJsonMissingPath('detail.attachments')->assertJsonMissingPath('detail.command_history');
        foreach (['PRIVATE-ISSUE-NOTE', 'PRIVATE-FIT-RESULT'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $this->assertNoOwnPrivateSql($queries);
        $this->get('/health-safety/ppe/allocations/'.$allocation->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('health-safety/ppe/show')
                ->where('detail.read_scope', 'own_allocation')->missing('detail.notes')->missing('detail.attachments'));
        $this->getJson('/health-safety/ppe')->assertForbidden();
        $this->getJson('/health-safety/ppe/inventory/'.$this->item->id)->assertForbidden();
    }

    public function test_auth_only_own_page_denies_other_worker_and_foreign_site_even_for_the_same_worker(): void
    {
        $other = $this->legacyAllocation(['user_id' => $this->viewer->id]);
        $foreign = $this->legacyAllocation(['ppe_inventory_id' => $this->foreignItem->id]);
        $this->actingAs($this->worker)->getJson('/health-safety/ppe/allocations/'.$other->id)->assertNotFound();
        $this->getJson('/health-safety/ppe/allocations/'.$foreign->id)->assertNotFound();
        $this->postJson('/health-safety/ppe/allocations/'.$other->id.'/acknowledge-own', $this->command($this->item))->assertNotFound();
        $this->postJson('/health-safety/ppe/allocations/'.$foreign->id.'/acknowledge-own', $this->command($this->foreignItem))->assertNotFound();
        $this->assertFalse($other->fresh()->acknowledged);
        $this->assertFalse($foreign->fresh()->acknowledged);
    }

    public function test_raw_route_identity_is_site_scoped_before_private_children_or_source_history(): void
    {
        $allocation = $this->legacyAllocation(['ppe_inventory_id' => $this->foreignItem->id, 'notes' => 'FOREIGN-ALLOCATION']);
        $inspection = PpeInspection::factory()->create(['ppe_inventory_id' => $this->foreignItem->id, 'findings' => 'FOREIGN-INSPECTION']);
        [$unused, $queries] = $this->queries(function () use ($allocation, $inspection) {
            $this->actingAs($this->viewer)->getJson('/health-safety/ppe/inventory/'.$this->foreignItem->id)->assertNotFound();
            $this->getJson('/health-safety/ppe/allocations/'.$allocation->id)->assertNotFound();
            $this->getJson('/health-safety/ppe/inspections/'.$inspection->id)->assertNotFound();
            return null;
        });
        $sourceQueries = 0;
        foreach ($queries as $sql) {
            $lower = strtolower($sql);
            foreach (['ppe_source_versions', 'ppe_attachments', 'ppe_allocation_attachments', 'ppe_inspection_attachments'] as $privateTable) {
                $this->assertStringNotContainsString($privateTable, $lower);
            }
            if (preg_match('/from\s+[`"]?ppe_(?:inventory|allocations|inspections)/i', $sql)) {
                $sourceQueries++;
                $this->assertStringContainsString('site_id', $lower, 'No implicit unscoped source-model resolution may precede canonical Site admission.');
            }
        }
        $this->assertGreaterThanOrEqual(3, $sourceQueries);
    }

    public function test_warmed_read_and_manage_permissions_are_revoked_and_restored_on_actual_routes(): void
    {
        $this->viewer->load('roles.permissions', 'permissionOverrides');
        $this->actingAs($this->viewer)->getJson('/health-safety/ppe/inventory/'.$this->item->id)->assertOk()->assertJsonPath('detail.can.manage', true);
        $this->allow($this->viewer, 'hazards.manage', false);
        $this->getJson('/health-safety/ppe/inventory/'.$this->item->id)->assertOk()->assertJsonPath('detail.can.manage', false);
        $this->getJson('/health-safety/ppe/options')->assertForbidden();
        $this->putJson('/health-safety/ppe/inventory/'.$this->item->id, $this->command($this->item, ['location' => 'Denied']))->assertForbidden();
        $this->allow($this->viewer, 'hazards.view', false);
        $this->getJson('/health-safety/ppe/inventory/'.$this->item->id)->assertForbidden();
        $this->allow($this->viewer, 'hazards.view', true);
        $this->allow($this->viewer, 'hazards.manage', true);
        $this->getJson('/health-safety/ppe/inventory/'.$this->item->id)->assertOk()->assertJsonPath('detail.can.manage', true);
        $this->getJson('/health-safety/ppe/options')->assertOk();
    }

    public function test_current_hr_and_active_site_loss_deny_then_restore_the_same_record(): void
    {
        $this->actingAs($this->viewer)->getJson('/health-safety/ppe/inventory/'.$this->item->id)->assertOk();
        HrEmployeeProfile::where('user_id', $this->viewer->id)->update(['is_active' => false]);
        $this->getJson('/health-safety/ppe/inventory/'.$this->item->id)->assertForbidden();
        HrEmployeeProfile::where('user_id', $this->viewer->id)->update(['is_active' => true]);
        $this->site->update(['is_active' => false]);
        $this->getJson('/health-safety/ppe/inventory/'.$this->item->id)->assertNotFound();
        $this->site->update(['is_active' => true]);
        $this->getJson('/health-safety/ppe/inventory/'.$this->item->id)->assertOk();
    }

    public function test_own_source_site_movement_is_current_not_a_cached_allocation_grant(): void
    {
        $allocation = $this->legacyAllocation();
        $this->actingAs($this->worker)->getJson('/health-safety/ppe/allocations/'.$allocation->id)->assertOk();
        $this->item->update(['site_id' => $this->foreignSite->id]);
        $this->getJson('/health-safety/ppe/allocations/'.$allocation->id)->assertNotFound();
        $this->item->update(['site_id' => $this->site->id]);
        $this->getJson('/health-safety/ppe/allocations/'.$allocation->id)->assertOk();
    }

    public function test_type_create_update_retire_and_restore_have_only_persisted_minimal_receipts(): void
    {
        $input = ['idempotency_key' => (string) Str::uuid(), 'name' => 'Recorded eye protection', 'category' => 'eye', 'description' => 'CATALOGUE-NARRATIVE'];
        $first = $this->actingAs($this->viewer)->postJson('/health-safety/ppe/types', $input)->assertOk();
        $receipt = $this->receipt($first, 'create_type');
        $this->assertSame($receipt, $this->postJson('/health-safety/ppe/types', $input)->assertOk()->json('ppe_confirmation'));
        $type = PpeType::findOrFail($receipt['root_id']);
        $this->receipt($this->putJson('/health-safety/ppe/types/'.$type->id, $this->command($type,
            ['name' => 'Updated eye protection', 'category' => 'eye'])), 'update_type');
        $this->receipt($this->patchJson('/health-safety/ppe/types/'.$type->id.'/deactivate', $this->command($type)), 'deactivate_type');
        $this->assertFalse($type->fresh()->is_active);
        $this->receipt($this->patchJson('/health-safety/ppe/types/'.$type->id.'/activate', $this->command($type)), 'activate_type');
        $this->assertTrue($type->fresh()->is_active);
        $this->assertStringNotContainsString('CATALOGUE-NARRATIVE', $first->getContent());
        $this->assertSame(1, PpeCommandReceipt::where('idempotency_key', $input['idempotency_key'])->count());
    }

    public function test_inventory_create_exact_retry_and_later_update_do_not_echo_inventory_narratives(): void
    {
        $input = $this->createInput(['location' => 'PRIVATE-RACK']);
        $response = $this->actingAs($this->viewer)->postJson('/health-safety/ppe/inventory', $input)->assertOk();
        $receipt = $this->receipt($response, 'create_inventory');
        $item = PpeInventory::findOrFail($receipt['root_id']);
        $this->assertSame($receipt, $this->postJson('/health-safety/ppe/inventory', $input)->assertOk()->json('ppe_confirmation'));
        $this->assertSame(1, $item->fresh()->source_revision);
        $this->receipt($this->putJson('/health-safety/ppe/inventory/'.$item->id,
            $this->command($item, ['location' => 'Updated private rack'])), 'update_inventory');
        $this->assertSame('Updated private rack', $item->fresh()->location);
        $this->assertStringNotContainsString('PRIVATE-RACK', $response->getContent());
    }

    public function test_unknown_fields_invalid_uuid_and_stale_drafts_never_receive_acceptance(): void
    {
        $item = $this->httpItem();
        $stale = $this->command($item, ['location' => 'Unaccepted stale rack']);
        $this->actingAs($this->viewer)->putJson('/health-safety/ppe/inventory/'.$item->id,
            $this->command($item, ['model' => 'Accepted model']))->assertOk();
        $this->putJson('/health-safety/ppe/inventory/'.$item->id, $stale)->assertUnprocessable()->assertJsonValidationErrors('expected_version');
        $this->putJson('/health-safety/ppe/inventory/'.$item->id, $this->command($item, ['invented_approval' => true]))
            ->assertUnprocessable()->assertJsonValidationErrors('command');
        $this->putJson('/health-safety/ppe/inventory/'.$item->id, $this->command($item, [], 'not-a-uuid'))
            ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->assertNotSame('Unaccepted stale rack', $item->fresh()->location);
        $this->assertDatabaseMissing('ppe_command_receipts', ['idempotency_key' => $stale['idempotency_key']]);
    }

    public function test_exact_uuid_cannot_be_reused_by_another_actor_source_or_payload(): void
    {
        $item = $this->httpItem();
        $otherItem = $this->httpItem();
        $input = $this->command($item, ['location' => 'Accepted rack']);
        $this->actingAs($this->viewer)->putJson('/health-safety/ppe/inventory/'.$item->id, $input)->assertOk();
        $this->putJson('/health-safety/ppe/inventory/'.$item->id, [...$input, 'location' => 'Changed payload'])
            ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->putJson('/health-safety/ppe/inventory/'.$otherItem->id, $input)->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $other = $this->staff(['hazards.view', 'hazards.manage']);
        $this->actingAs($other)->putJson('/health-safety/ppe/inventory/'.$item->id, $input)->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->getJson('/health-safety/ppe/command-confirmation?receipt_key='.$input['idempotency_key'])->assertOk()->assertJsonPath('ppe_confirmation', null);
        $this->assertSame(1, PpeCommandReceipt::where('idempotency_key', $input['idempotency_key'])->count());
    }

    public function test_hold_requires_new_passing_evidence_and_explicit_release_receipt(): void
    {
        $item = $this->httpItem();
        $this->actingAs($this->viewer);
        $this->receipt($this->putJson('/health-safety/ppe/inventory/'.$item->id,
            $this->command($item, ['status' => 'maintenance', 'reason' => 'Actual maintenance hold'])), 'update_inventory');
        $this->putJson('/health-safety/ppe/inventory/'.$item->id,
            $this->command($item, ['status' => 'available', 'reason' => 'Premature release']))->assertUnprocessable()->assertJsonValidationErrors('status');
        $inspection = $this->receipt($this->postJson('/health-safety/ppe/inventory/'.$item->id.'/inspections',
            $this->command($item, ['result' => 'pass', 'condition_after' => 'good', 'findings' => 'Recorded check passed'])), 'inspect');
        $this->assertSame('maintenance', $item->fresh()->status);
        $this->getJson('/health-safety/ppe/inspections/'.$inspection['source_id'])->assertOk()->assertJsonPath('detail.release_authorized', null);
        $this->receipt($this->putJson('/health-safety/ppe/inventory/'.$item->id,
            $this->command($item, ['status' => 'available', 'reason' => 'Released after actual check'])), 'update_inventory');
        $this->assertSame('available', $item->fresh()->status);
    }

    public function test_worker_acknowledgement_is_actor_bound_minimal_and_exactly_replayable_without_module_read(): void
    {
        $item = $this->httpItem();
        $allocation = $this->httpAllocation($item);
        $input = $this->command($item);
        $response = $this->actingAs($this->worker)->postJson('/health-safety/ppe/allocations/'.$allocation->id.'/acknowledge-own', $input)->assertOk();
        $receipt = $this->receipt($response, 'acknowledge_own');
        $this->assertSame($this->worker->id, $receipt['actor_id']);
        $this->assertSame($receipt, $this->postJson('/health-safety/ppe/allocations/'.$allocation->id.'/acknowledge-own', $input)->assertOk()->json('ppe_confirmation'));
        $this->assertSame($this->worker->id, $allocation->fresh()->acknowledged_by);
        $this->getJson('/health-safety/ppe/allocations/'.$allocation->id)->assertOk()->assertJsonPath('detail.can.acknowledge_own', false);
        $this->getJson('/health-safety/ppe/command-confirmation?receipt_key='.strtoupper($input['idempotency_key']))->assertOk()->assertJsonPath('ppe_confirmation', $receipt);
        $this->getJson('/health-safety/ppe/inventory/'.$item->id)->assertForbidden();
    }

    public function test_manager_ack_and_return_preserve_a_maintenance_hold_and_retained_allocation_page(): void
    {
        $item = $this->httpItem();
        $allocation = $this->httpAllocation($item);
        $this->actingAs($this->viewer);
        $this->receipt($this->postJson('/health-safety/ppe/allocations/'.$allocation->id.'/acknowledge', $this->command($item)), 'acknowledge');
        $this->putJson('/health-safety/ppe/inventory/'.$item->id,
            $this->command($item, ['status' => 'maintenance', 'reason' => 'Hold during issue']))->assertOk();
        $this->receipt($this->postJson('/health-safety/ppe/allocations/'.$allocation->id.'/return',
            $this->command($item, ['notes' => 'Actual returned condition'])), 'return');
        $this->assertSame('maintenance', $item->fresh()->status);
        $this->assertNotNull($allocation->fresh()->returned_at);
        $this->actingAs($this->worker)->getJson('/health-safety/ppe/allocations/'.$allocation->id)->assertOk()->assertJsonPath('detail.can.acknowledge_own', false);
        $this->postJson('/health-safety/ppe/allocations/'.$allocation->id.'/acknowledge-own', $this->command($item))->assertUnprocessable();
    }

    public function test_condemnation_and_disposal_are_reasoned_terminal_http_commands_not_edit_shortcuts(): void
    {
        $item = $this->httpItem();
        $this->actingAs($this->viewer);
        $this->receipt($this->postJson('/health-safety/ppe/inventory/'.$item->id.'/condemn',
            $this->command($item, ['reason' => 'Observed damage'])), 'condemn');
        $this->receipt($this->postJson('/health-safety/ppe/inventory/'.$item->id.'/dispose',
            $this->command($item, ['disposal_method' => 'Recorded safe disposal'])), 'dispose');
        $this->getJson('/health-safety/ppe/inventory/'.$item->id)->assertOk()->assertJsonPath('detail.status', 'disposed')
            ->assertJsonPath('detail.condemned_reason', 'Observed damage')->assertJsonPath('detail.can.release', false);
        $this->putJson('/health-safety/ppe/inventory/'.$item->id,
            $this->command($item, ['status' => 'available', 'reason' => 'Forbidden reopening']))->assertUnprocessable();
        $this->assertSame('disposed', $item->fresh()->status);
    }

    public function test_options_exclude_retired_types_inactive_or_foreign_workers_and_scope_search_pages(): void
    {
        $retired = PpeType::factory()->create(['is_active' => false]);
        $inactive = $this->staff([]);
        HrEmployeeProfile::where('user_id', $inactive->id)->update(['is_active' => false]);
        $foreign = $this->staff([], $this->foreignSite);
        $response = $this->actingAs($this->viewer)->getJson('/health-safety/ppe/options?site_id='.$this->site->id)->assertOk();
        $this->assertNotContains($retired->id, array_column($response->json('types'), 'id'));
        $this->assertContains($this->worker->id, array_column($response->json('staff'), 'id'));
        $this->assertNotContains($inactive->id, array_column($response->json('staff'), 'id'));
        $this->assertNotContains($foreign->id, array_column($response->json('staff'), 'id'));
        $this->assertNotContains($this->foreignItem->id, array_column($response->json('allocatable'), 'id'));
        $this->getJson('/health-safety/ppe/options?site_id='.$this->foreignSite->id)->assertNotFound();
        $this->getJson('/health-safety/ppe/options?staff_page=0')->assertUnprocessable();
        $this->getJson('/health-safety/ppe/options?item_search=NO-MATCH&staff_search=NO-MATCH&staff_page=2&item_page=2')->assertOk()
            ->assertJsonCount(0, 'staff')->assertJsonCount(0, 'allocatable')->assertJsonPath('staff_page.current_page', 2)
            ->assertJsonPath('allocatable_page.current_page', 2);
    }

    public function test_new_inventory_denies_retired_catalogue_and_inactive_or_foreign_site_without_a_receipt(): void
    {
        $retired = PpeType::factory()->create(['is_active' => false]);
        $retiredInput = $this->createInput(['ppe_type_id' => $retired->id]);
        $this->actingAs($this->viewer)->postJson('/health-safety/ppe/inventory', $retiredInput)->assertUnprocessable()->assertJsonValidationErrors('ppe_type_id');
        $foreignInput = $this->createInput(['site_id' => $this->foreignSite->id]);
        $this->postJson('/health-safety/ppe/inventory', $foreignInput)->assertNotFound();
        $this->site->update(['is_active' => false]);
        $inactiveInput = $this->createInput();
        $this->postJson('/health-safety/ppe/inventory', $inactiveInput)->assertNotFound();
        foreach ([$retiredInput, $foreignInput, $inactiveInput] as $input) {
            $this->assertDatabaseMissing('ppe_command_receipts', ['idempotency_key' => $input['idempotency_key']]);
        }
    }

    public function test_allocation_denies_inactive_or_foreign_worker_and_retired_type_without_changing_stock(): void
    {
        $item = $this->httpItem();
        $inactive = $this->staff([]);
        HrEmployeeProfile::where('user_id', $inactive->id)->update(['is_active' => false]);
        $foreign = $this->staff([], $this->foreignSite);
        $this->actingAs($this->viewer);
        foreach ([$inactive, $foreign] as $worker) {
            $this->postJson('/health-safety/ppe/inventory/'.$item->id.'/allocate', $this->command($item, ['user_id' => $worker->id]))->assertNotFound();
        }
        $this->patchJson('/health-safety/ppe/types/'.$this->type->id.'/deactivate', $this->command($this->type))->assertOk();
        $this->postJson('/health-safety/ppe/inventory/'.$item->id.'/allocate', $this->command($item, ['user_id' => $this->worker->id]))->assertUnprocessable();
        $this->assertSame('available', $item->fresh()->status);
        $this->assertDatabaseMissing('ppe_allocations', ['ppe_inventory_id' => $item->id]);
    }

    public function test_json_confirmation_recovers_after_later_bookkeeping_but_rechecks_current_actor_permission_and_site(): void
    {
        $item = $this->httpItem();
        $input = $this->command($item, ['location' => 'Accepted rack']);
        $accepted = $this->actingAs($this->viewer)->putJson('/health-safety/ppe/inventory/'.$item->id, $input)->assertOk()->json('ppe_confirmation');
        $this->postJson('/health-safety/ppe/inventory/'.$item->id.'/inspections', $this->command($item, ['result' => 'pass']))->assertOk();
        $url = '/health-safety/ppe/command-confirmation?receipt_key='.strtoupper($input['idempotency_key']);
        $this->getJson($url)->assertOk()->assertJsonPath('ppe_confirmation', $accepted)->assertHeader('Referrer-Policy', 'no-referrer');
        $this->viewer->load('roles.permissions', 'permissionOverrides');
        $this->allow($this->viewer, 'hazards.manage', false);
        $this->getJson($url)->assertForbidden();
        $this->allow($this->viewer, 'hazards.manage', true);
        HrEmployeeProfile::where('user_id', $this->viewer->id)->update(['primary_site_id' => $this->foreignSite->id]);
        $this->getJson($url)->assertNotFound();
        HrEmployeeProfile::where('user_id', $this->viewer->id)->update(['primary_site_id' => $this->site->id]);
        $this->getJson($url)->assertOk()->assertJsonPath('ppe_confirmation', $accepted);
    }

    public function test_session_hint_is_only_a_lookup_key_and_wrong_root_or_actor_never_echoes_as_success(): void
    {
        $item = $this->httpItem();
        $input = $this->command($item, ['location' => 'Accepted rack']);
        $accepted = $this->actingAs($this->viewer)->putJson('/health-safety/ppe/inventory/'.$item->id, $input)->assertOk()->json('ppe_confirmation');
        $hint = [...$accepted, 'root_id' => $this->foreignItem->id, 'notes' => 'FORGED-SESSION-NARRATIVE', 'version' => 999];
        $response = $this->withSession(['ppe_confirmation' => $hint])->getJson('/health-safety/ppe/inventory/'.$item->id)->assertOk()
            ->assertJsonPath('ppe_confirmation', null);
        $this->assertStringNotContainsString('FORGED-SESSION-NARRATIVE', $response->getContent());
        $this->withSession(['ppe_confirmation' => $hint])->get('/health-safety/ppe/inventory/'.$item->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('ppe_confirmation', $accepted));
        $this->get('/health-safety/ppe/inventory/'.$item->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('ppe_confirmation', null));
        $this->withSession(['ppe_confirmation' => $hint])->getJson('/health-safety/ppe/inventory/'.$this->item->id)->assertOk()->assertJsonPath('ppe_confirmation', null);
        $other = $this->staff(['hazards.view', 'hazards.manage']);
        $this->actingAs($other)->withSession(['ppe_confirmation' => $hint])->getJson('/health-safety/ppe/inventory/'.$item->id)->assertOk()->assertJsonPath('ppe_confirmation', null);
        $this->withSession(['ppe_confirmation' => ['idempotency_key' => (string) Str::uuid(), 'accepted' => true]])
            ->getJson('/health-safety/ppe/command-confirmation')->assertOk()->assertJsonPath('ppe_confirmation', null);
    }

    public function test_receipt_keys_are_json_only_and_invalid_keys_do_not_become_private_page_props(): void
    {
        $this->actingAs($this->viewer)->get('/health-safety/ppe/inventory/'.$this->item->id.'?receipt_key='.(string) Str::uuid())->assertNotFound();
        $this->get('/health-safety/ppe/command-confirmation')->assertNotFound();
        $this->getJson('/health-safety/ppe/command-confirmation?receipt_key=bad')->assertUnprocessable();
        $this->getJson('/health-safety/ppe/command-confirmation?receipt_key[]=bad')->assertUnprocessable();
        $this->getJson('/health-safety/ppe/inventory/'.$this->item->id.'?return_to='.rawurlencode('https://outside.example/private'))->assertOk()->assertJsonPath('return_to', null);
    }

    public function test_html_command_redirect_and_flash_contain_only_receipt_and_safe_read_origin(): void
    {
        $item = $this->httpItem();
        $origin = '/health-safety/ppe?tab=inv_available&site_id='.$this->site->id.'&page=2';
        $response = $this->actingAs($this->viewer)->put('/health-safety/ppe/inventory/'.$item->id,
            [...$this->command($item, ['location' => 'PRIVATE-EDIT']), 'return_to' => $origin, 'action' => 'edit'])->assertRedirect()->assertSessionHas('ppe_confirmation');
        $this->assertSame('/health-safety/ppe/inventory/'.$item->id, parse_url($response->headers->get('Location'), PHP_URL_PATH));
        parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame(['return_to' => $origin], $query);
        $receipt = session('ppe_confirmation');
        $this->assertReceiptKeys($receipt);
        $this->assertStringNotContainsString('PRIVATE-EDIT', json_encode($receipt, JSON_THROW_ON_ERROR));
    }

    public function test_my_day_actual_private_mount_uses_own_canonical_attention_without_private_queries(): void
    {
        $own = $this->legacyAllocation(['notes' => 'PRIVATE-MYDAY', 'fit_test_result' => 'PRIVATE-FIT']);
        $this->legacyAllocation(['user_id' => $this->viewer->id]);
        $this->legacyAllocation(['ppe_inventory_id' => $this->foreignItem->id]);
        $this->legacyAllocation(['returned_at' => now()]);
        $controller = app(MyTasksController::class);
        $method = new ReflectionMethod($controller, 'getMyPpe');
        [$rows, $queries] = $this->queries(fn () => $method->invoke($controller, $this->worker));
        $this->assertSame([$own->id], array_column($rows, 'id'));
        $this->assertSame(app(PpePickerService::class)->ownAllocations($this->worker), $rows);
        $this->assertNoOwnPrivateSql($queries);
        $this->assertSame([], (new ReflectionProperty($controller, 'unavailableSections'))->getValue($controller));
        HrEmployeeProfile::where('user_id', $this->worker->id)->update(['is_active' => false]);
        $this->assertSame([], $method->invoke($controller, $this->worker));
        $this->assertContains('PPE', (new ReflectionProperty($controller, 'unavailableSections'))->getValue($controller));
    }

    public function test_my_day_damaged_typed_source_is_an_unavailable_card_not_a_clean_empty_result(): void
    {
        $item = $this->httpItem();
        $allocation = $this->httpAllocation($item);
        $allocation->forceFill(['source_schema_version' => 1, 'source_revision' => 999])->saveQuietly();
        $controller = app(MyTasksController::class);
        $rows = (new ReflectionMethod($controller, 'getMyPpe'))->invoke($controller, $this->worker);
        $this->assertCount(1, $rows);
        $this->assertSame($allocation->id, $rows[0]['id']);
        $this->assertSame('repair_required', $rows[0]['source_integrity']);
        $this->assertNull($rows[0]['type_name']);
        $this->assertFalse($rows[0]['can']['acknowledge_own']);
        $this->actingAs($this->worker)->getJson('/health-safety/ppe/allocations/'.$allocation->id)->assertOk()
            ->assertJsonPath('detail.source_integrity', 'repair_required')->assertJsonMissingPath('detail.notes');
    }

    public function test_initial_html_register_detail_and_recovery_pages_withhold_browser_caching(): void
    {
        $this->actingAs($this->viewer);
        foreach (['/health-safety/ppe', '/health-safety/ppe/inventory/'.$this->item->id, '/health-safety/ppe/recovery'] as $url) {
            $response = $this->get($url)->assertOk()->assertHeader('Referrer-Policy', 'no-referrer');
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
    }

    public function test_inertia_full_and_partial_pages_preserve_private_cache_headers_and_exact_props(): void
    {
        $this->actingAs($this->viewer);
        foreach ([['/health-safety/ppe', 'health-safety/ppe/index', 'filters'],
            ['/health-safety/ppe/inventory/'.$this->item->id, 'health-safety/ppe/show', 'detail'],
            ['/health-safety/ppe/recovery', 'health-safety/ppe/recovery', 'auth']] as [$url, $component, $prop]) {
            $headers = ['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest', 'Accept' => 'text/html'];
            $full = $this->get($url, $headers)->assertOk()->assertHeader('X-Inertia', 'true')->assertHeader('Referrer-Policy', 'no-referrer');
            $this->assertStringContainsString('no-store', $full->headers->get('Cache-Control'));
            $partial = $this->get($url, [...$headers, 'X-Inertia-Partial-Component' => $component, 'X-Inertia-Partial-Data' => $prop])
                ->assertOk()->assertHeader('X-Inertia', 'true')->assertHeader('Referrer-Policy', 'no-referrer')
                ->assertJsonPath('component', $component);
            $this->assertArrayHasKey($prop, $partial->json('props'));
            $this->assertStringContainsString('no-store', $partial->headers->get('Cache-Control'));
            if ($component === 'health-safety/ppe/show') {
                $partial->assertJsonPath('props.detail.id', $this->item->id)->assertJsonMissingPath('props.ppe_confirmation');
            }
        }
    }

    public function test_stale_html_receipt_hint_cannot_turn_a_current_read_only_page_into_acceptance(): void
    {
        $item = $this->httpItem();
        $input = $this->command($item, ['location' => 'Persisted receipt location']);
        $accepted = $this->putJson('/health-safety/ppe/inventory/'.$item->id, $input)->assertOk()->json('ppe_confirmation');
        $this->viewer->load('roles.permissions', 'permissionOverrides');
        $this->allow($this->viewer, 'hazards.manage', false);
        $this->withSession(['ppe_confirmation' => $accepted])->get('/health-safety/ppe/inventory/'.$item->id)->assertOk()
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertInertia(fn (Assert $page) => $page->where('detail.id', $item->id)->where('detail.can.manage', false)->where('ppe_confirmation', null));
        $this->get('/health-safety/ppe/inventory/'.$item->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('ppe_confirmation', null));
        $this->assertDatabaseHas('ppe_command_receipts', ['idempotency_key' => $input['idempotency_key'], 'accepted_result->version' => $accepted['version']]);
        $this->allow($this->viewer, 'hazards.manage', true);
        $this->withSession(['ppe_confirmation' => $accepted])->get('/health-safety/ppe/inventory/'.$item->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('detail.can.manage', true)->where('ppe_confirmation', $accepted));
        $this->get('/health-safety/ppe/inventory/'.$item->id)->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('ppe_confirmation', null));
    }

    private function legacyAllocation(array $facts = []): PpeAllocation
    {
        return PpeAllocation::factory()->create(['ppe_inventory_id' => $this->item->id, 'user_id' => $this->worker->id,
            'created_by' => $this->viewer->id, ...$facts]);
    }

    private function createInput(array $facts = []): array
    {
        return ['idempotency_key' => (string) Str::uuid(), 'ppe_type_id' => $this->type->id, 'site_id' => $this->site->id,
            'condition' => 'good', 'quantity' => 3, 'serial_number' => 'HTTP-RECORDED', 'expiry_date' => '2027-10-12', ...$facts];
    }

    private function command(PpeInventory|PpeType $root, array $facts = [], ?string $key = null): array
    {
        return ['idempotency_key' => $key ?? (string) Str::uuid(), 'expected_version' => (int) $root->fresh()->source_revision, ...$facts];
    }

    private function httpItem(): PpeInventory
    {
        $receipt = $this->receipt($this->actingAs($this->viewer)->postJson('/health-safety/ppe/inventory', $this->createInput()), 'create_inventory');
        return PpeInventory::findOrFail($receipt['root_id']);
    }

    private function httpAllocation(PpeInventory $item): PpeAllocation
    {
        $receipt = $this->receipt($this->actingAs($this->viewer)->postJson('/health-safety/ppe/inventory/'.$item->id.'/allocate',
            $this->command($item, ['user_id' => $this->worker->id, 'notes' => 'PRIVATE-TYPED-ISSUE'])), 'allocate');
        return PpeAllocation::findOrFail($receipt['source_id']);
    }

    private function receipt($response, string $command): array
    {
        $response->assertOk()->assertJsonPath('ppe_confirmation.accepted', true)->assertJsonPath('ppe_confirmation.command', $command);
        $this->assertSame(['ppe_confirmation'], array_keys($response->json()));
        $receipt = $response->json('ppe_confirmation');
        $this->assertReceiptKeys($receipt);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertDatabaseHas('ppe_command_receipts', ['idempotency_key' => $receipt['idempotency_key']]);
        return $receipt;
    }

    private function assertReceiptKeys(array $receipt): void
    {
        $this->assertEqualsCanonicalizing(['accepted', 'actor_id', 'command', 'idempotency_key', 'root_type', 'root_id',
            'source_type', 'source_id', 'expected_version', 'version', 'source_version_id'], array_keys($receipt));
        $this->assertDetached($receipt);
    }

    private function assertNoOwnPrivateSql(array $queries): void
    {
        foreach ($queries as $sql) {
            // An EXISTS probe's "select *" returns no columns; any real wide select still shows.
            $sql = (string) preg_replace('/exists\s*\(\s*select \*/i', 'exists (select 1', $sql);
            $lower = strtolower($sql);
            foreach (['ppe_allocation_attachments', 'ppe_attachments', 'ppe_inspection_attachments', 'ppe_inspections'] as $table) {
                $this->assertStringNotContainsString($table, $lower);
            }
            if (preg_match('/from\s+[`"]?ppe_allocations/i', $sql)) {
                $this->assertDoesNotMatchRegularExpression('/select\s+(?:[`"]?ppe_allocations[`"]?\.)?\*/i', $sql);
                $this->assertDoesNotMatchRegularExpression('/[`"](?:notes|fit_test_result)[`"]/', $sql);
            }
            if (str_contains($lower, 'ppe_source_versions')) {
                $this->assertDoesNotMatchRegularExpression('/[`"]content[`"]|select\s+\*/i', $sql);
            }
        }
    }
}
