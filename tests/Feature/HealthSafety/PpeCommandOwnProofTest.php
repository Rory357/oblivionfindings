<?php

namespace Tests\Feature\HealthSafety;

use App\Models\PpeAllocation;
use App\Models\PpeInventory;
use App\Models\PpeSourceVersion;
use App\Services\HealthSafety\PpeAccessService;
use Illuminate\Support\Facades\DB;

final class PpeCommandOwnProofTest extends PpeCommandTestCase
{
    private function own(PpeAllocation $allocation): string
    {
        return app(PpeAccessService::class)->withCurrentViewer($this->worker, function ($current, $reads, $sites) use ($allocation) {
            $source = app(PpeAccessService::class)->assertAllocation($allocation, $current, $reads, $sites, 'own_view');
            $root = $reads->query(PpeInventory::query())->select(PpeAccessService::INVENTORY_OWN_COLUMNS)
                ->whereKey($source->ppe_inventory_id)->whereIn('site_id', $sites)->firstOrFail();
            return $this->service()->ownSourceState($root, $source);
        });
    }
    public function test_prospective_own_proof_uses_exact_current_factual_source_without_private_content_query(): void
    {
        $item = $this->item(); $allocation = $this->issue($item);
        DB::enableQueryLog(); DB::flushQueryLog();
        $this->assertSame('recorded', $this->own($allocation));
        $queries = DB::getQueryLog(); DB::disableQueryLog();
        foreach ($queries as $query) {
            $sql = strtolower($query['query']);
            $this->assertStringNotContainsString('ppe_attachments', $sql);
            $this->assertStringNotContainsString('ppe_allocation_attachments', $sql);
            $this->assertStringNotContainsString('ppe_inspection_attachments', $sql);
            if (str_contains($sql, 'ppe_source_versions')) {
                $this->assertDoesNotMatchRegularExpression('/(?:`|\")content(?:`|\")/', $sql);
                $this->assertStringNotContainsString('actor_snapshot', $sql);
                $this->assertStringNotContainsString('idempotency_key', $sql);
            }
            if (str_contains($sql, 'ppe_allocations')) {
                $this->assertStringNotContainsString('fit_test_result', $sql);
                $this->assertStringNotContainsString('notes', $sql);
            }
        }
    }
    public function test_unchanged_child_retains_exact_own_version_after_unrelated_root_fact_advance(): void
    {
        $item = $this->item(); $allocation = $this->issue($item);
        $this->service()->updateInventory($item, $this->manager, $this->command($item, ['location' => 'Moved within same Site']));
        $this->assertSame(2, $allocation->fresh()->source_revision); $this->assertSame(3, $item->fresh()->source_revision);
        $this->assertSame('recorded', $this->own($allocation));
    }
    public function test_missing_typed_own_snapshot_or_digest_never_falls_back_to_legacy(): void
    {
        $item = $this->item(); $allocation = $this->issue($item);
        $version = PpeSourceVersion::where('source_type', 'allocation')->where('source_id', $allocation->id)->sole();
        DB::table('ppe_source_versions')->where('id', $version->id)->update(['own_content' => null, 'own_digest' => null]);
        $this->assertSame('repair_required', $this->own($allocation));
        DB::table('ppe_source_versions')->where('id', $version->id)->update(['own_content' => json_encode($version->own_content), 'own_digest' => str_repeat('0', 64)]);
        $this->assertSame('repair_required', $this->own($allocation));
    }
    public function test_changed_factual_parent_or_child_rejects_own_proof_without_private_reads(): void
    {
        $item = $this->item(); $allocation = $this->issue($item);
        DB::table('ppe_allocations')->where('id', $allocation->id)->update(['training_completed' => true]);
        $this->assertSame('repair_required', $this->own($allocation));
        DB::table('ppe_allocations')->where('id', $allocation->id)->update(['training_completed' => false]);
        DB::table('ppe_inventory')->where('id', $item->id)->update(['condition' => 'poor']);
        $this->assertSame('repair_required', $this->own($allocation));
    }
    public function test_genuine_legacy_own_facts_are_unknown_but_retained_typed_downgrade_requires_repair(): void
    {
        $legacy = PpeInventory::factory()->create(['site_id' => $this->site->id, 'status' => 'allocated']);
        $own = PpeAllocation::factory()->create(['ppe_inventory_id' => $legacy->id, 'user_id' => $this->worker->id]);
        $this->assertSame('legacy_unknown', $this->own($own));
        $item = $this->item(); $allocation = $this->issue($item);
        DB::table('ppe_allocations')->where('id', $allocation->id)->update(['source_schema_version' => 0, 'source_revision' => 0]);
        $this->assertSame('repair_required', $this->own($allocation));
    }
    public function test_missing_root_factual_proof_and_root_marker_downgrade_require_repair(): void
    {
        $item = $this->item(); $allocation = $this->issue($item);
        $version = PpeSourceVersion::where('root_type', 'inventory')->where('root_id', $item->id)->orderByDesc('aggregate_revision')->firstOrFail();
        DB::table('ppe_source_versions')->where('id', $version->id)->update(['factual_content' => null, 'factual_digest' => null]);
        $this->assertSame('repair_required', $this->own($allocation));
        DB::table('ppe_inventory')->where('id', $item->id)->update(['source_schema_version' => 0, 'source_revision' => 0]);
        $this->assertSame('repair_required', $this->own($allocation));
    }
    public function test_another_owner_or_reassigned_current_site_does_not_receive_own_proof(): void
    {
        $item = $this->item(); $allocation = $this->issue($item);
        DB::table('ppe_allocations')->where('id', $allocation->id)->update(['user_id' => $this->manager->id]);
        $this->denial(fn () => $this->own($allocation), 404);
        DB::table('ppe_allocations')->where('id', $allocation->id)->update(['user_id' => $this->worker->id]);
        $foreign = \App\Models\Site::factory()->create(['is_active' => true]);
        $this->worker->hrEmployeeProfile()->update(['primary_site_id' => $foreign->id, 'secondary_site_ids' => []]);
        $this->denial(fn () => $this->own($allocation), 404);
    }
    public function test_noop_does_not_certify_missing_own_history_and_unrelated_other_worker_history_is_not_hydrated(): void
    {
        $legacy = PpeInventory::factory()->create(['site_id' => $this->site->id, 'status' => 'allocated']);
        $own = PpeAllocation::factory()->create(['ppe_inventory_id' => $legacy->id, 'user_id' => $this->worker->id, 'acknowledged' => true]);
        $receipt = $this->service()->acknowledge($own, $this->worker, $this->command($legacy), true);
        $this->assertNull($receipt['source_version_id']); $this->assertSame('legacy_unknown', $this->own($own));
        $item = $this->item(); $allocation = $this->issue($item);
        PpeAllocation::factory()->create(['ppe_inventory_id' => $item->id, 'user_id' => $this->manager->id, 'returned_at' => now(), 'notes' => 'Another worker private note']);
        $this->assertSame('recorded', $this->own($allocation));
    }
    private function ownType(\App\Models\PpeType $type): string
    {
        return app(PpeAccessService::class)->withCurrentViewer($this->worker, function ($current, $reads) use ($type) {
            $fresh = $reads->query(\App\Models\PpeType::query())->whereKey($type->id)
                ->select(['id', 'name', 'category', 'is_active', 'source_schema_version', 'source_revision'])->firstOrFail();
            return $this->service()->ownTypeReferenceState($fresh);
        });
    }
    public function test_prospective_own_catalogue_reference_proves_only_selected_facts_without_private_version_content(): void
    {
        $receipt = $this->service()->createType($this->manager, ['idempotency_key' => (string) \Illuminate\Support\Str::uuid(),
            'name' => 'Actual safe reference', 'category' => 'hand', 'description' => 'Module-only description', 'standards_reference' => 'Retained source reference']);
        $type = \App\Models\PpeType::findOrFail($receipt['root_id']);
        DB::enableQueryLog(); DB::flushQueryLog();
        $this->assertSame('recorded', $this->ownType($type));
        $queries = DB::getQueryLog(); DB::disableQueryLog();
        foreach ($queries as $query) {
            $this->assertStringNotContainsString('description', strtolower($query['query']));
            $this->assertStringNotContainsString('standards_reference', strtolower($query['query']));
            if (str_contains($query['query'], 'ppe_source_versions')) {
                $this->assertDoesNotMatchRegularExpression('/(?:`|\")content(?:`|\")/', $query['query']);
                $this->assertStringNotContainsString('actor_snapshot', $query['query']);
            }
        }
        DB::table('ppe_types')->where('id', $type->id)->update(['name' => 'Externally altered name']);
        $this->assertSame('repair_required', $this->ownType($type));
        DB::table('ppe_types')->where('id', $type->id)->update(['name' => 'Actual safe reference', 'category' => 'eye']);
        $this->assertSame('repair_required', $this->ownType($type));
    }
    public function test_own_catalogue_missing_factual_proof_or_marker_downgrade_never_becomes_genuine_legacy(): void
    {
        $receipt = $this->service()->createType($this->manager, ['idempotency_key' => (string) \Illuminate\Support\Str::uuid(), 'name' => 'Recorded reference', 'category' => 'head']);
        $type = \App\Models\PpeType::findOrFail($receipt['root_id']);
        DB::table('ppe_source_versions')->where('id', $receipt['source_version_id'])->update(['factual_content' => null, 'factual_digest' => null]);
        $this->assertSame('repair_required', $this->ownType($type));
        DB::table('ppe_types')->where('id', $type->id)->update(['source_schema_version' => 0, 'source_revision' => 0]);
        $this->assertSame('repair_required', $this->ownType($type));
        $legacy = \App\Models\PpeType::factory()->create();
        $this->assertSame('legacy_unknown', $this->ownType($legacy));
    }
}
