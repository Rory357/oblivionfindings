<?php

namespace Tests\Feature\HealthSafety;

use App\Models\PpeAllocation;
use App\Models\PpeInspection;
use App\Models\PpeInventory;
use App\Models\PpeSourceVersion;
use App\Models\PpeType;
use Illuminate\Support\Str;

final class PpeCommandLifecycleTest extends PpeCommandTestCase
{
    public function test_catalogue_create_correct_retire_and_reactivate_preserves_history(): void
    {
        $receipt = $this->service()->createType($this->manager, ['idempotency_key' => (string) Str::uuid(), 'name' => 'Helmet', 'category' => 'head']);
        $type = PpeType::findOrFail($receipt['root_id']);
        $this->service()->updateType($type, $this->manager, $this->command($type, ['name' => 'Recorded helmet', 'category' => 'head', 'typical_lifespan_months' => 12]));
        $this->service()->setTypeActive($type, $this->manager, $this->command($type, ['active' => false]));
        $this->service()->setTypeActive($type, $this->manager, $this->command($type, ['active' => true]));
        $this->assertSame(4, $type->fresh()->source_revision);
        $this->assertTrue($type->fresh()->is_active);
        $this->assertSame(4, PpeSourceVersion::where('root_type', 'type')->where('root_id', $type->id)->count());
    }
    public function test_issue_owns_whole_record_quantity_and_records_ack_actor_without_competence_claim(): void
    {
        $item = $this->item();
        $receipt = $this->service()->allocate($item, $this->manager, $this->command($item, ['user_id' => $this->worker->id,
            'acknowledged' => true, 'fit_test_completed' => true, 'fit_test_date' => '2026-10-11', 'fit_test_result' => 'Attributed pass',
            'training_completed' => true, 'training_date' => '2026-10-10']));
        $allocation = PpeAllocation::findOrFail($receipt['source_id']);
        $this->assertSame(3, $item->fresh()->quantity);
        $this->assertSame('allocated', $item->fresh()->status);
        $this->assertSame($this->manager->id, $allocation->acknowledged_by);
        $this->assertSame('recorded', $this->sourceState($item, $allocation));
        $this->assertArrayNotHasKey('competent', $receipt);
    }
    public function test_duplicate_issue_and_zero_stock_are_rejected(): void
    {
        $item = $this->item(); $this->issue($item);
        $this->validation(fn () => $this->service()->allocate($item, $this->manager, $this->command($item, ['user_id' => $this->worker->id])), 'status');
        $empty = $this->item();
        $this->service()->updateInventory($empty, $this->manager, $this->command($empty, ['quantity' => 0]));
        $this->validation(fn () => $this->service()->allocate($empty, $this->manager, $this->command($empty, ['user_id' => $this->worker->id])), 'status');
        $this->assertSame(1, $item->allocations()->count());
    }
    public function test_unknown_fit_and_training_remain_facts_not_verified_currency(): void
    {
        $item = $this->item(); $allocation = $this->issue($item);
        $this->assertFalse($allocation->fit_test_completed);
        $this->assertFalse($allocation->training_completed);
        $this->assertNull($allocation->fit_test_date);
    }
    public function test_completed_facts_require_real_nonfuture_dates(): void
    {
        $item = $this->item();
        $this->validation(fn () => $this->service()->allocate($item, $this->manager, $this->command($item, ['user_id' => $this->worker->id, 'fit_test_completed' => true])), 'fit_test_date');
        $this->validation(fn () => $this->service()->allocate($item, $this->manager, $this->command($item, ['user_id' => $this->worker->id, 'training_completed' => true, 'training_date' => '2026-10-13'])), 'training_date');
        $this->assertSame(0, $item->allocations()->count());
    }
    public function test_failed_and_repair_needed_inspections_quarantine_issued_stock_and_return_keeps_hold(): void
    {
        foreach (['fail', 'needs_repair'] as $result) {
            $item = $this->item(); $allocation = $this->issue($item);
            $this->service()->inspect($item, $this->manager, $this->command($item, ['result' => $result, 'findings' => 'Recorded damage']));
            $this->service()->returnAllocation($allocation, $this->manager, $this->command($item, ['condition' => 'good']));
            $this->assertSame('maintenance', $item->fresh()->status);
            $this->assertNotNull($allocation->fresh()->returned_at);
            $this->assertSame('recorded', $this->sourceState($item));
        }
    }
    public function test_pass_does_not_release_until_explicit_reasoned_command_after_hold(): void
    {
        $item = $this->item();
        $this->service()->updateInventory($item, $this->manager, $this->command($item, ['status' => 'maintenance', 'reason' => 'Physical hold']));
        $this->validation(fn () => $this->service()->updateInventory($item, $this->manager, $this->command($item, ['status' => 'available', 'reason' => 'Proposed release'])), 'status');
        $this->service()->inspect($item, $this->manager, $this->command($item, ['result' => 'pass', 'condition_after' => 'good']));
        $this->assertSame('maintenance', $item->fresh()->status);
        $this->validation(fn () => $this->service()->updateInventory($item, $this->manager, $this->command($item, ['status' => 'available'])), 'reason');
        $this->service()->updateInventory($item, $this->manager, $this->command($item, ['status' => 'available', 'reason' => 'Passing inspection confirmed']));
        $this->assertSame('available', $item->fresh()->status);
    }
    public function test_old_pass_cannot_release_a_later_failed_hold(): void
    {
        $item = $this->item();
        $this->service()->inspect($item, $this->manager, $this->command($item, ['result' => 'pass']));
        $this->service()->inspect($item, $this->manager, $this->command($item, ['result' => 'fail']));
        $this->validation(fn () => $this->service()->updateInventory($item, $this->manager, $this->command($item, ['status' => 'available', 'reason' => 'Old pass'])), 'status');
        $this->assertSame('maintenance', $item->fresh()->status);
    }
    public function test_return_never_releases_condemned_disposed_or_other_active_legacy_issue(): void
    {
        foreach (['condemned', 'disposed', 'maintenance'] as $status) {
            $item = PpeInventory::factory()->create(['site_id' => $this->site->id, 'status' => $status]);
            $allocation = PpeAllocation::factory()->create(['ppe_inventory_id' => $item->id, 'user_id' => $this->worker->id]);
            $this->service()->returnAllocation($allocation, $this->manager, $this->command($item, ['condition' => 'good']));
            $this->assertSame($status, $item->fresh()->status);
        }
        $item = PpeInventory::factory()->allocated()->create(['site_id' => $this->site->id]);
        $first = PpeAllocation::factory()->create(['ppe_inventory_id' => $item->id, 'user_id' => $this->worker->id]);
        $second = PpeAllocation::factory()->create(['ppe_inventory_id' => $item->id, 'user_id' => $this->worker->id]);
        $this->service()->returnAllocation($first, $this->manager, $this->command($item));
        $this->assertSame('allocated', $item->fresh()->status);
        $this->assertNull($second->fresh()->returned_at);
    }
    public function test_terminal_transitions_and_condemnation_reason_are_retained(): void
    {
        $item = $this->item();
        $this->validation(fn () => $this->service()->updateInventory($item, $this->manager, $this->command($item, ['status' => 'disposed'])), 'status');
        $this->service()->condemn($item, $this->manager, $this->command($item, ['reason' => 'Original factual condemnation']));
        $this->service()->dispose($item, $this->manager, $this->command($item, ['disposal_method' => 'Recorded collection', 'reason' => 'Disposal note']));
        $this->assertSame('Original factual condemnation', $item->fresh()->condemned_reason);
        $this->validation(fn () => $this->service()->updateInventory($item, $this->manager, $this->command($item, ['status' => 'available'])), 'status');
    }
    public function test_expired_retired_type_and_foreign_or_ineligible_worker_cannot_issue(): void
    {
        $item = $this->item(['expiry_date' => '2026-10-11']);
        $this->validation(fn () => $this->service()->allocate($item, $this->manager, $this->command($item, ['user_id' => $this->worker->id])), 'status');
        $item = $this->item(); $item->ppeType->update(['is_active' => false]);
        $this->validation(fn () => $this->service()->allocate($item, $this->manager, $this->command($item, ['user_id' => $this->worker->id])), 'status');
        $item = $this->item(); $foreign = $this->staff([], \App\Models\Site::factory()->create());
        $this->denial(fn () => $this->service()->allocate($item, $this->manager, $this->command($item, ['user_id' => $foreign->id])), 404);
    }
    public function test_initial_reported_condemned_condition_is_kept_on_hold_without_invented_condemnation(): void
    {
        $item = $this->item(['condition' => 'condemned']);
        $this->assertSame('maintenance', $item->status);
        $this->assertSame('condemned', $item->condition);
        $this->assertNull($item->condemned_at);
    }
    public function test_legacy_condemned_condition_cannot_be_freed_by_return_without_new_condition_evidence(): void
    {
        $item = PpeInventory::factory()->allocated()->create(['site_id' => $this->site->id, 'condition' => 'condemned']);
        $issue = PpeAllocation::factory()->create(['ppe_inventory_id' => $item->id, 'user_id' => $this->worker->id]);
        $this->service()->returnAllocation($issue, $this->manager, $this->command($item));
        $this->assertSame('maintenance', $item->fresh()->status); $this->assertSame('condemned', $item->fresh()->condition);
        $this->assertNull($item->fresh()->condemned_at); $this->assertNotNull($issue->fresh()->returned_at);
    }}
