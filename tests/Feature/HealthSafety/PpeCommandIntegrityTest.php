<?php

namespace Tests\Feature\HealthSafety;

use App\Models\AuditLog;
use App\Models\PpeAllocation;
use App\Models\PpeInventory;
use App\Models\PpeSourceVersion;
use App\Services\HealthSafety\PpeAccessService;
use Illuminate\Support\Facades\DB;

final class PpeCommandIntegrityTest extends PpeCommandTestCase
{
    public function test_healthy_child_and_root_remain_proven_after_another_child_advances_aggregate(): void
    {
        $item = $this->item(); $allocation = $this->issue($item);
        $this->service()->inspect($item, $this->manager, $this->command($item, ['result' => 'pass']));
        $this->assertSame(3, $item->fresh()->source_revision);
        $this->assertSame(2, $allocation->fresh()->source_revision);
        $this->assertSame('recorded', $this->sourceState($item, $allocation));
    }
    public function test_current_fact_digest_metadata_and_missing_version_damage_are_not_certified(): void
    {
        $item = $this->item(); DB::table('ppe_inventory')->where('id', $item->id)->update(['location' => 'External change']);
        $this->assertSame('repair_required', $this->sourceState($item));
        $other = $this->item(); DB::table('ppe_source_versions')->where('root_type', 'inventory')->where('root_id', $other->id)->update(['content_digest' => str_repeat('0', 64)]);
        $this->assertSame('repair_required', $this->sourceState($other));
        $missing = $this->item(); DB::table('ppe_source_versions')->where('root_type', 'inventory')->where('root_id', $missing->id)->delete();
        $this->assertSame('repair_required', $this->sourceState($missing));
    }
    public function test_any_retained_governed_history_blocks_marker_downgrade_even_when_newer_legacy_version_exists(): void
    {
        $item = $this->item(); $allocation = $this->issue($item);
        $older = PpeSourceVersion::where('source_type', 'allocation')->where('source_id', $allocation->id)->sole();
        $shadow = $older->getAttributes(); unset($shadow['id']);
        $shadow['aggregate_revision'] = 3; $shadow['source_schema_version'] = 0;
        DB::table('ppe_source_versions')->insert($shadow);
        DB::table('ppe_allocations')->where('id', $allocation->id)->update(['source_schema_version' => 0, 'source_revision' => 0]);
        $this->assertSame('repair_required', $this->sourceState($item, $allocation));
        DB::table('ppe_inventory')->where('id', $item->id)->update(['source_schema_version' => 0, 'source_revision' => 0]);
        $this->assertSame('repair_required', $this->sourceState($item));
    }
    public function test_legacy_noop_is_accepted_without_new_source_certification(): void
    {
        $item = PpeInventory::factory()->create(['site_id' => $this->site->id]);
        $receipt = $this->service()->updateInventory($item, $this->manager, $this->command($item, ['location' => $item->location]));
        $this->assertSame(0, $receipt['version']); $this->assertNull($receipt['source_version_id']);
        $this->assertSame('legacy_unknown', $this->sourceState($item));
    }
    public function test_refused_source_save_rolls_back_revision_version_receipt_and_audit(): void
    {
        $item = $this->item(); $input = $this->command($item, ['location' => 'Refused location']);
        PpeInventory::saving(fn ($row) => $row->isDirty('location') ? false : null);
        try { $this->integrity(fn () => $this->service()->updateInventory($item, $this->manager, $input)); }
        finally { PpeInventory::flushEventListeners(); }
        $this->assertSame(1, $item->fresh()->source_revision);
        $this->assertDatabaseMissing('ppe_command_receipts', ['idempotency_key' => $input['idempotency_key']]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'ppe.update_inventory']);
    }
    public function test_silent_audit_save_refusal_rolls_back_both_root_and_child(): void
    {
        $item = $this->item(); $input = $this->command($item, ['user_id' => $this->worker->id]);
        AuditLog::creating(fn ($row) => $row->action === 'ppe.allocate' ? false : null);
        try { $this->integrity(fn () => $this->service()->allocate($item, $this->manager, $input)); }
        finally { AuditLog::flushEventListeners(); }
        $this->assertSame('available', $item->fresh()->status); $this->assertSame(1, $item->fresh()->source_revision);
        $this->assertSame(0, $item->allocations()->count());
        $this->assertDatabaseMissing('ppe_command_receipts', ['idempotency_key' => $input['idempotency_key']]);
    }
    public function test_foreign_site_denied_before_private_root_hydration(): void
    {
        $foreign = PpeInventory::factory()->create(); $queries = [];
        DB::listen(function ($event) use (&$queries) { $queries[] = strtolower($event->sql); });
        $this->denial(fn () => $this->service()->updateInventory($foreign, $this->manager, $this->command($foreign, ['location' => 'Forged write'])), 404);
        $this->assertFalse(collect($queries)->contains(fn ($sql) => str_contains($sql, 'from `ppe_source_versions`')));
        $this->assertTrue(collect($queries)->filter(fn ($sql) => str_contains($sql, 'from `ppe_inventory`') && str_contains($sql, 'for update'))->every(fn ($sql) => str_contains($sql, '`site_id` in')));
    }
    public function test_strict_unknown_keys_boolean_integer_real_date_and_merged_date_sequence(): void
    {
        $item = $this->item(['purchase_date' => '2026-10-10']);
        $this->validation(fn () => $this->service()->updateInventory($item, $this->manager, $this->command($item, ['unknown' => true])), 'command');
        $this->validation(fn () => $this->service()->updateInventory($item, $this->manager, $this->command($item, ['quantity' => true])), 'quantity');
        $this->validation(fn () => $this->service()->updateInventory($item, $this->manager, $this->command($item, ['expiry_date' => '2026-02-30'])), 'expiry_date');
        $this->validation(fn () => $this->service()->updateInventory($item, $this->manager, $this->command($item, ['expiry_date' => '2026-10-09'])), 'expiry_date');
    }
    public function test_history_metadata_has_real_bound_actor_and_rejects_tampered_attribution(): void
    {
        $item = $this->item(); $version = PpeSourceVersion::where('root_type', 'inventory')->where('root_id', $item->id)->sole();
        $project = fn () => app(PpeAccessService::class)->withCurrentViewer($this->manager,
            fn ($current, $reads, $sites) => $this->service()->historyProjection(app(PpeAccessService::class)->assertInventory($item, $current, $reads, $sites), $version->fresh()));
        $this->assertSame($this->manager->id, $project()['actor']['id']);
        DB::table('ppe_source_versions')->where('id', $version->id)->update(['actor_snapshot' => json_encode(['id' => $this->manager->id, 'name' => 'Fabricated actor'])]);
        $this->assertSame(['integrity' => 'repair_required'], $project());
    }
    public function test_historical_worker_leaver_can_be_returned_but_new_issue_is_denied(): void
    {
        $item = $this->item(); $allocation = $this->issue($item);
        \App\Domain\Hr\Models\HrEmployeeProfile::where('user_id', $this->worker->id)->update(['is_active' => false]);
        $this->service()->returnAllocation($allocation, $this->manager, $this->command($item));
        $this->assertNotNull($allocation->fresh()->returned_at);
        $this->denial(fn () => $this->service()->allocate($item, $this->manager, $this->command($item, ['user_id' => $this->worker->id])), 404);
    }
    public function test_same_root_identity_proof_accepts_distinct_fresh_model_instances(): void
    {
        $item = $this->item(); $this->issue($item);
        $state = app(\App\Services\HealthSafety\PpeAccessService::class)->withCurrentViewer($this->manager, function ($current, $reads, $sites) use ($item) {
            $root = app(\App\Services\HealthSafety\PpeAccessService::class)->assertInventory($item, $current, $reads, $sites);
            $second = app(\App\Services\HealthSafety\PpeAccessService::class)->assertInventory($item, $current, $reads, $sites);
            return $this->service()->sourceState($root, $second);
        });
        $this->assertSame('recorded', $state);
    }
    public function test_damaged_current_catalogue_type_cannot_create_or_allocate_stock(): void
    {
        $typeReceipt = $this->service()->createType($this->manager, ['idempotency_key' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'Actual type', 'category' => 'head']);
        $type = \App\Models\PpeType::findOrFail($typeReceipt['root_id']);
        $item = $this->item(['ppe_type_id' => $type->id]);
        DB::table('ppe_types')->where('id', $type->id)->update(['standards_reference' => 'Unrecorded change']);
        $this->integrity(fn () => $this->service()->createInventory($this->manager, ['idempotency_key' => (string) \Illuminate\Support\Str::uuid(), 'site_id' => $this->site->id, 'ppe_type_id' => $type->id]));
        $this->validation(fn () => $this->service()->allocate($item, $this->manager, $this->command($item, ['user_id' => $this->worker->id])), 'status');
        $this->assertSame(0, $item->allocations()->count());
    }}
