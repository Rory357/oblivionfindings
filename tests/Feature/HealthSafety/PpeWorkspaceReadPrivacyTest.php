<?php

namespace Tests\Feature\HealthSafety;

use App\Models\PpeAllocation;
use App\Models\PpeAllocationAttachment;
use App\Models\PpeAttachment;
use App\Models\PpeInspection;
use App\Models\PpeInspectionAttachment;
use App\Services\HealthSafety\PpePickerService;
use App\Services\HealthSafety\PpeCommandService;
use App\Models\PpeInventory;
use App\Models\PpeSourceVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Services\HealthSafety\PpeWorkspaceService;
use Illuminate\Support\Facades\Storage;

class PpeWorkspaceReadPrivacyTest extends PpeReadTestCase
{
    public function test_item_retains_recorded_fields_and_actual_lifecycle_history(): void
    {
        $this->item->update(['created_by' => $this->viewer->id, 'quantity' => 4, 'condemned_reason' => 'Retained reason']);
        $allocation = PpeAllocation::factory()->returned()->create(['ppe_inventory_id' => $this->item->id, 'user_id' => $this->worker->id,
            'created_by' => $this->viewer->id, 'allocated_at' => '2026-10-10 00:00:00']);
        $inspection = PpeInspection::factory()->create(['ppe_inventory_id' => $this->item->id, 'inspected_by' => $this->viewer->id,
            'findings' => 'Actual observation', 'action_taken' => 'Removed for checking']);
        $dto = app(PpeWorkspaceService::class)->item($this->viewer, $this->item->id);
        $this->assertSame(4, $dto['quantity']);
        $this->assertSame('OWN-ITEM', $dto['serial_number']);
        $this->assertSame('Retained reason', $dto['condemned_reason']);
        $this->assertSame($allocation->id, $dto['allocations'][0]['id']);
        $this->assertSame('Actual observation', $dto['inspections'][0]['findings']);
        $this->assertContains('allocated', array_column($dto['history'], 'type'));
        $this->assertContains('returned', array_column($dto['history'], 'type'));
        $this->assertContains('inspected', array_column($dto['history'], 'type'));
        $this->assertSame('/health-safety/ppe/inventory/'.$this->item->id, $dto['href']);
        $this->assertDetached($dto);
    }

    public function test_multiple_active_allocations_are_explicit_ambiguity_not_arbitrary_active_worker(): void
    {
        PpeAllocation::factory()->count(2)->create(['ppe_inventory_id' => $this->item->id, 'user_id' => $this->worker->id]);
        $dto = app(PpeWorkspaceService::class)->item($this->viewer, $this->item->id);
        $this->assertSame('ambiguous', $dto['active_allocation_state']);
        $this->assertNull($dto['active_allocation']);
        $this->assertCount(2, $dto['allocations']);
        $this->assertFalse($dto['can']['allocate']);
        $this->assertFalse($dto['can']['condemn']);
    }

    public function test_allocation_records_fit_training_and_acknowledgement_without_current_fitness_claim(): void
    {
        $allocation = PpeAllocation::factory()->fitTested()->acknowledged()->create(['ppe_inventory_id' => $this->item->id,
            'user_id' => $this->worker->id, 'training_completed' => true, 'training_date' => '2024-01-01',
            'notes' => 'Recorded issue note', 'acknowledged_by' => $this->viewer->id]);
        $dto = app(PpeWorkspaceService::class)->allocation($this->viewer, $allocation->id);
        $this->assertTrue($dto['fit_test_completed']);
        $this->assertSame('pass', $dto['fit_test_result']);
        $this->assertTrue($dto['training_completed']);
        $this->assertSame('Recorded issue note', $dto['notes']);
        $this->assertNull($dto['fit_test_current']);
        $this->assertNull($dto['training_current']);
        $this->assertNull($dto['fitness_for_work']);
        $this->assertFalse($dto['can']['acknowledge']);
    }

    public function test_own_allocation_does_not_query_private_notes_evidence_or_other_workers(): void
    {
        $own = PpeAllocation::factory()->create(['ppe_inventory_id' => $this->item->id, 'user_id' => $this->worker->id,
            'notes' => 'PRIVATE-ISSUE-NOTE', 'fit_test_result' => 'PRIVATE-RESULT']);
        PpeAllocationAttachment::create(['ppe_allocation_id' => $own->id, 'disk' => 'private', 'path' => 'secret', 'original_name' => 'PRIVATE-CERTIFICATE']);
        [$dto, $queries] = $this->queries(fn () => app(PpeWorkspaceService::class)->allocation($this->worker, $own->id));
        $json = json_encode($dto, JSON_THROW_ON_ERROR);
        foreach (['PRIVATE-ISSUE-NOTE', 'PRIVATE-RESULT', 'PRIVATE-CERTIFICATE'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }
        $sql = strtolower(implode("\n", $queries));
        $this->assertStringNotContainsString('ppe_allocation_attachments', $sql);
        foreach ($queries as $query) {
            if (str_contains(strtolower($query), 'from '.chr(96).'ppe_allocations')) {
                $this->assertStringNotContainsString('select *', strtolower($query));
                $this->assertStringNotContainsString(chr(96).'notes'.chr(96), $query);
                $this->assertStringNotContainsString(chr(96).'fit_test_result'.chr(96), $query);
            }
        }
        $this->assertArrayNotHasKey('attachments', $dto);
        $this->assertArrayNotHasKey('notes', $dto);
    }

    public function test_denied_parent_never_loads_private_children_or_evidence(): void
    {
        PpeInspection::factory()->create(['ppe_inventory_id' => $this->foreignItem->id, 'findings' => 'FOREIGN-OBSERVATION']);
        [, $queries] = $this->queries(function () {
            $this->denied(fn () => app(PpeWorkspaceService::class)->item($this->viewer, $this->foreignItem->id), 404);
            return null;
        });
        $sql = strtolower(implode("\n", $queries));
        foreach (['ppe_allocations', 'ppe_inspections', 'ppe_attachments'] as $table) {
            $this->assertStringNotContainsString($table, $sql);
        }
        $this->assertStringContainsString('site_id', $sql);
    }

    public function test_attachment_availability_is_real_and_never_exposes_storage_locator(): void
    {
        Storage::disk('private')->put('ppe/safe.txt', 'Actual evidence');
        PpeAttachment::create(['ppe_inventory_id' => $this->item->id, 'disk' => 'private', 'path' => 'ppe/safe.txt',
            'original_name' => 'safe.txt', 'kind' => 'certificate', 'mime' => 'text/plain', 'size' => 15, 'uploaded_by' => $this->viewer->id]);
        PpeAttachment::create(['ppe_inventory_id' => $this->item->id, 'disk' => 'private', 'path' => 'ppe/missing.txt', 'original_name' => 'missing.txt']);
        PpeAttachment::create(['ppe_inventory_id' => $this->item->id, 'disk' => 'public', 'path' => 'ppe/public.txt', 'original_name' => 'legacy-public.txt']);
        $dto = app(PpeWorkspaceService::class)->item($this->viewer, $this->item->id);
        $files = collect($dto['attachments'])->keyBy('original_name');
        $this->assertTrue($files['safe.txt']['available']);
        $this->assertStringEndsWith('/download', $files['safe.txt']['download_url']);
        $this->assertFalse($files['missing.txt']['available']);
        $this->assertNull($files['missing.txt']['download_url']);
        $this->assertFalse($files['legacy-public.txt']['available']);
        foreach ($files as $file) {
            $this->assertArrayNotHasKey('path', $file);
            $this->assertArrayNotHasKey('disk', $file);
        }
    }

    public function test_inspection_permanent_payload_preserves_exact_parent_evidence_and_does_not_release_stock(): void
    {
        $this->item->update(['status' => 'condemned']);
        $inspection = PpeInspection::factory()->create(['ppe_inventory_id' => $this->item->id, 'result' => 'pass', 'findings' => 'Recorded pass']);
        PpeInspectionAttachment::create(['ppe_inspection_id' => $inspection->id, 'disk' => 'private', 'path' => 'absent', 'original_name' => 'report']);
        $dto = app(PpeWorkspaceService::class)->inspection($this->viewer, $inspection->id);
        $this->assertSame($this->item->id, $dto['ppe_inventory_id']);
        $this->assertSame('pass', $dto['result']);
        $this->assertNull($dto['release_authorized']);
        $this->assertSame('condemned', $this->item->fresh()->status);
        $this->assertSame('/health-safety/ppe/inventory/'.$this->item->id, $dto['inventory_href']);
    }

    public function test_my_day_own_attention_matches_existing_unack_or_missing_respiratory_fit_and_site_scope(): void
    {
        $own = PpeAllocation::factory()->create(['ppe_inventory_id' => $this->item->id, 'user_id' => $this->worker->id]);
        $fitDue = PpeAllocation::factory()->acknowledged()->create(['ppe_inventory_id' => $this->item->id, 'user_id' => $this->worker->id, 'fit_test_completed' => false]);
        PpeAllocation::factory()->fitTested()->acknowledged()->create(['ppe_inventory_id' => $this->item->id, 'user_id' => $this->worker->id]);
        PpeAllocation::factory()->returned()->create(['ppe_inventory_id' => $this->item->id, 'user_id' => $this->worker->id]);
        PpeAllocation::factory()->create(['ppe_inventory_id' => $this->foreignItem->id, 'user_id' => $this->worker->id]);
        PpeAllocation::factory()->create(['ppe_inventory_id' => $this->item->id, 'user_id' => $this->viewer->id]);
        $rows = app(PpePickerService::class)->ownAllocations($this->worker);
        $this->assertEqualsCanonicalizing([$own->id, $fitDue->id], array_column($rows, 'id'));
        $this->assertTrue($rows[0]['fit_test_required']);
        $this->assertNull($rows[0]['fit_test_current']);
        $this->assertDetached($rows);
    }

    public function test_returned_own_allocation_remains_history_but_cannot_self_acknowledge(): void
    {
        $own = PpeAllocation::factory()->returned()->create(['ppe_inventory_id' => $this->item->id, 'user_id' => $this->worker->id]);
        $dto = app(PpeWorkspaceService::class)->allocation($this->worker, $own->id);
        $this->assertNotNull($dto['returned_at']);
        $this->assertFalse($dto['can']['acknowledge_own']);
        $this->assertSame([], app(PpePickerService::class)->ownAllocations($this->worker));
    }

    public function test_nz_timestamps_retain_dst_offset_and_date_only_fields_do_not_shift(): void
    {
        $own = PpeAllocation::factory()->create(['ppe_inventory_id' => $this->item->id, 'user_id' => $this->worker->id,
            'allocated_at' => '2026-10-11 23:30:00', 'fit_test_date' => '2026-10-12']);
        $dto = app(PpeWorkspaceService::class)->allocation($this->viewer, $own->id);
        $this->assertStringEndsWith('+13:00', $dto['allocated_at']);
        $this->assertSame('2026-10-12', $dto['fit_test_date']);
    }
    public function test_child_pages_preserve_full_totals_and_active_issue_outside_loaded_history_page(): void
    {
        $old = PpeAllocation::factory()->create(['ppe_inventory_id' => $this->item->id, 'user_id' => $this->worker->id,
            'allocated_at' => '2020-01-01 00:00:00']);
        PpeAllocation::factory()->count(26)->returned()->create(['ppe_inventory_id' => $this->item->id, 'user_id' => $this->worker->id]);
        PpeInspection::factory()->count(26)->create(['ppe_inventory_id' => $this->item->id]);
        $service = app(PpeWorkspaceService::class);
        $first = $service->item($this->viewer, $this->item->id);
        $second = $service->item($this->viewer, $this->item->id, ['allocations_page' => 2, 'inspections_page' => 2]);
        $this->assertSame(27, $first['pages']['allocations']['total']);
        $this->assertCount(25, $first['allocations']);
        $this->assertCount(2, $second['allocations']);
        $this->assertCount(1, $second['inspections']);
        $this->assertSame($old->id, $first['active_allocation']['id']);
        $this->assertFalse($first['can']['allocate']);
        $this->assertSame('loaded_record_pages', $first['history_scope']);
    }

    public function test_evidence_pages_retain_all_records_and_canonical_parent_scope(): void
    {
        for ($i = 0; $i < 26; $i++) {
            PpeAttachment::create(['ppe_inventory_id' => $this->item->id, 'disk' => 'private',
                'path' => 'missing-'.$i, 'original_name' => 'record-'.$i]);
        }
        $service = app(PpeWorkspaceService::class);
        $first = $service->item($this->viewer, $this->item->id);
        $second = $service->item($this->viewer, $this->item->id, ['attachments_page' => 2]);
        $this->assertSame(26, $first['pages']['attachments']['total']);
        $this->assertCount(25, $first['attachments']);
        $this->assertCount(1, $second['attachments']);
        $this->assertNotContains($second['attachments'][0]['id'], array_column($first['attachments'], 'id'));
    }

    public function test_minimal_command_context_uses_actual_inventory_root_and_exact_child_identity(): void
    {
        $allocation = PpeAllocation::factory()->create(['ppe_inventory_id' => $this->item->id, 'user_id' => $this->worker->id]);
        $inspection = PpeInspection::factory()->create(['ppe_inventory_id' => $this->item->id]);
        $service = app(PpeWorkspaceService::class);
        foreach ([$service->allocation($this->viewer, $allocation->id), $service->allocation($this->worker, $allocation->id),
            $service->inspection($this->viewer, $inspection->id)] as $dto) {
            $context = $dto['command_context'];
            $this->assertSame('inventory', $context['root_kind']);
            $this->assertSame($this->item->id, $context['root_id']);
            $this->assertSame($this->site->id, $context['site_id']);
            $this->assertSame(0, $context['expected_version']);
            $this->assertSame(0, $context['source_schema_version']);
        }
        $this->assertSame('allocations', $service->allocation($this->worker, $allocation->id)['command_context']['source_kind']);
        $this->assertSame('inspections', $service->inspection($this->viewer, $inspection->id)['command_context']['source_kind']);
        $this->assertSame($allocation->id, $service->allocation($this->worker, $allocation->id)['command_context']['source_id']);
    }
    public function test_genuine_accepted_inventory_and_child_commands_have_proved_current_history(): void
    {
        $item = $this->acceptedItem();
        $receipt = app(PpeCommandService::class)->allocate($item, $this->viewer, [
            'idempotency_key' => (string) Str::uuid(), 'expected_version' => 1, 'user_id' => $this->worker->id]);
        $service = app(PpeWorkspaceService::class);
        $dto = $service->item($this->viewer, $item->id);
        $this->assertSame('recorded', $dto['attribution_state']);
        $this->assertSame('intact', $dto['source_integrity']);
        $this->assertSame(2, $dto['command_context']['expected_version']);
        $this->assertSame(['allocate', 'create_inventory'], array_column($dto['command_history'], 'command'));
        $this->assertSame([2, 1], array_column($dto['command_history'], 'version'));
        $this->assertSame('recorded', $dto['command_history_integrity']);
        $this->assertSame('reconstructed_records', $dto['history_attribution']);
        $this->assertSame(2, $dto['pages']['history']['total']);
        $this->assertSame($this->viewer->name, $dto['command_history'][0]['actor']['name']);
        $this->assertStringEndsWith('+13:00', $dto['command_history'][0]['recorded_at']);
        $child = $service->allocation($this->viewer, $receipt['source_id']);
        $this->assertSame('recorded', $child['attribution_state']);
        $this->assertSame($item->id, $child['command_context']['root_id']);
        $this->assertSame(2, $child['pages']['history']['total']);
        $this->assertDetached($dto);
    }

    public function test_damaged_typed_root_withholds_sections_before_private_child_queries(): void
    {
        $item = $this->acceptedItem();
        DB::table('ppe_inventory')->where('id', $item->id)->update(['serial_number' => 'CORRUPTED-SOURCE']);
        [$dto, $queries] = $this->queries(fn () => app(PpeWorkspaceService::class)->item($this->viewer, $item->id));
        $this->assertSame('repair_required', $dto['source_integrity']);
        foreach (['allocations', 'inspections', 'attachments', 'history', 'command_history', 'pages', 'command_context'] as $key) {
            $this->assertNull($dto[$key]);
        }
        $this->assertNotContains(true, $dto['can']);
        $this->assertStringNotContainsString('CORRUPTED-SOURCE', json_encode($dto));
        foreach (['ppe_allocations', 'ppe_inspections', 'ppe_attachments'] as $table) {
            $this->assertStringNotContainsString($table, strtolower(implode("\n", $queries)));
        }
    }

    public function test_damaged_typed_child_is_not_a_verified_or_empty_history_record(): void
    {
        $item = $this->acceptedItem();
        $receipt = app(PpeCommandService::class)->inspect($item, $this->viewer, [
            'idempotency_key' => (string) Str::uuid(), 'expected_version' => 1, 'result' => 'pass', 'findings' => 'Accepted original']);
        DB::table('ppe_inspections')->where('id', $receipt['source_id'])->update(['findings' => 'CORRUPTED-FINDINGS']);
        $service = app(PpeWorkspaceService::class);
        $dto = $service->item($this->viewer, $item->id);
        $this->assertSame('recorded', $dto['attribution_state']);
        $this->assertSame('repair_required', $dto['child_integrity']['inspections']);
        $this->assertSame(1, $dto['pages']['inspections']['total']);
        $this->assertSame('canonical_source_rows', $dto['pages']['inspections']['total_scope']);
        $this->assertSame([], $dto['inspections']);
        $this->assertStringNotContainsString('CORRUPTED-FINDINGS', json_encode($dto));
        $detail = $service->inspection($this->viewer, $receipt['source_id']);
        $this->assertSame('repair_required', $detail['source_integrity']);
        $this->assertNull($detail['attachments']);
        $this->assertArrayNotHasKey('findings', $detail);
    }

    public function test_markers_downgraded_with_retained_typed_history_do_not_become_legacy(): void
    {
        $item = $this->acceptedItem();
        DB::table('ppe_inventory')->where('id', $item->id)->update(['source_schema_version' => 0, 'source_revision' => 0]);
        $dto = app(PpeWorkspaceService::class)->item($this->viewer, $item->id);
        $this->assertSame('repair_required', $dto['attribution_state']);
        $this->assertNull($dto['command_history']);
        $legacy = app(PpeWorkspaceService::class)->item($this->viewer, $this->item->id);
        $this->assertSame('legacy_unknown', $legacy['attribution_state']);
        $this->assertSame('legacy_unknown', $legacy['source_integrity']);
        $this->assertSame([], $legacy['command_history']);
    }

    public function test_command_history_is_complete_through_pages_and_never_copies_corrupted_actor_data(): void
    {
        $item = $this->acceptedItem();
        for ($i = 1; $i <= 26; $i++) {
            app(PpeCommandService::class)->updateInventory($item, $this->viewer, [
                'idempotency_key' => (string) Str::uuid(), 'expected_version' => $i, 'location' => 'Accepted location '.$i]);
        }
        $service = app(PpeWorkspaceService::class);
        $first = $service->item($this->viewer, $item->id);
        $second = $service->item($this->viewer, $item->id, ['history_page' => 2]);
        $this->assertCount(25, $first['command_history']);
        $this->assertCount(2, $second['command_history']);
        $this->assertSame(27, $second['pages']['history']['total']);
        $this->assertSame(1, $second['command_history'][1]['version']);
        $old = PpeSourceVersion::where('root_type', 'inventory')->where('root_id', $item->id)->orderBy('aggregate_revision')->firstOrFail();
        DB::table('ppe_source_versions')->where('id', $old->id)->update(['actor_snapshot' => json_encode(['name' => 'CORRUPTED-ACTOR'])]);
        $damaged = $service->item($this->viewer, $item->id, ['history_page' => 2]);
        $this->assertSame('repair_required', $damaged['command_history_integrity']);
        $this->assertNull($damaged['command_history'][1]['actor']);
        $this->assertNull($damaged['command_history'][1]['command']);
        $this->assertStringNotContainsString('CORRUPTED-ACTOR', json_encode($damaged));
    }

    public function test_typed_own_read_queries_only_factual_proof_metadata_and_no_private_evidence(): void
    {
        $item = $this->acceptedItem();
        $receipt = app(PpeCommandService::class)->allocate($item, $this->viewer, [
            'idempotency_key' => (string) Str::uuid(), 'expected_version' => 1, 'user_id' => $this->worker->id, 'notes' => 'PRIVATE-ISSUE']);
        [$dto, $queries] = $this->queries(fn () => app(PpeWorkspaceService::class)->allocation($this->worker, $receipt['source_id']));
        $this->assertSame('own_allocation', $dto['read_scope']);
        $this->assertSame('intact', $dto['source_integrity']);
        $this->assertTrue($dto['can']['acknowledge_own']);
        $this->assertArrayNotHasKey('command_history', $dto);
        $this->assertArrayNotHasKey('attachments', $dto);
        $this->assertStringNotContainsString('PRIVATE-ISSUE', json_encode($dto));
        $this->assertStringContainsString('ppe_source_versions', strtolower(implode("\n", $queries)));
        foreach ($queries as $query) {
            if (str_contains(strtolower($query), 'ppe_source_versions')) {
                foreach (['content', 'actor_snapshot', 'command', 'idempotency_key', 'user_id'] as $privateColumn) {
                    $this->assertStringNotContainsString(chr(96).$privateColumn.chr(96), strtolower($query));
                }
                $this->assertStringNotContainsString('select *', strtolower($query));
            }
        }
    }

    private function acceptedItem(): PpeInventory
    {
        $receipt = app(PpeCommandService::class)->createInventory($this->viewer, [
            'idempotency_key' => (string) Str::uuid(), 'site_id' => $this->site->id, 'ppe_type_id' => $this->type->id,
            'serial_number' => 'ACCEPTED-SOURCE', 'quantity' => 3, 'expiry_date' => '2028-10-12']);
        return PpeInventory::findOrFail($receipt['root_id']);
    }
    public function test_governed_own_corruption_is_visible_unavailable_even_when_mutated_attention_flags_hide_due_work(): void
    {
        $item = $this->acceptedItem();
        $receipt = app(PpeCommandService::class)->allocate($item, $this->viewer, ['idempotency_key' => (string) Str::uuid(),
            'expected_version' => 1, 'user_id' => $this->worker->id]);
        DB::table('ppe_allocations')->where('id', $receipt['source_id'])->update(['acknowledged' => true, 'fit_test_completed' => true]);
        $rows = app(PpePickerService::class)->ownAllocations($this->worker);
        $this->assertCount(1, $rows);
        $this->assertSame($receipt['source_id'], $rows[0]['id']);
        $this->assertSame('repair_required', $rows[0]['source_integrity']);
        $this->assertNull($rows[0]['serial_number']);
        $this->assertNull($rows[0]['fit_test_completed']);
        $this->assertFalse($rows[0]['can']['acknowledge_own']);
        $dto = app(PpeWorkspaceService::class)->allocation($this->worker, $receipt['source_id']);
        $this->assertSame('repair_required', $dto['source_integrity']);
        $this->assertNull($dto['command_context']);
        $this->assertFalse($dto['can']['acknowledge_own']);
    }

    public function test_missing_own_factual_proof_or_marker_downgrade_never_certifies_legacy_or_queries_private_history(): void
    {
        $item = $this->acceptedItem();
        $receipt = app(PpeCommandService::class)->allocate($item, $this->viewer, ['idempotency_key' => (string) Str::uuid(),
            'expected_version' => 1, 'user_id' => $this->worker->id, 'notes' => 'PRIVATE-NOTE']);
        DB::table('ppe_source_versions')->where('source_type', 'allocation')->where('source_id', $receipt['source_id'])
            ->update(['own_content' => null, 'own_digest' => null]);
        [$dto, $queries] = $this->queries(fn () => app(PpeWorkspaceService::class)->allocation($this->worker, $receipt['source_id']));
        $this->assertSame('repair_required', $dto['source_integrity']);
        $this->assertArrayNotHasKey('notes', $dto);
        foreach ($queries as $query) {
            if (str_contains(strtolower($query), 'ppe_source_versions')) {
                foreach (['content', 'actor_snapshot', 'command', 'idempotency_key', 'user_id'] as $column) {
                    $this->assertStringNotContainsString(chr(96).$column.chr(96), strtolower($query));
                }
                $this->assertStringNotContainsString('select *', strtolower($query));
            }
        }
        DB::table('ppe_allocations')->where('id', $receipt['source_id'])->update(['source_schema_version' => 0, 'source_revision' => 0]);
        $this->assertSame('repair_required', app(PpePickerService::class)->ownAllocations($this->worker)[0]['source_integrity']);
        $legacy = PpeAllocation::factory()->create(['ppe_inventory_id' => $this->item->id, 'user_id' => $this->worker->id]);
        $healthy = app(PpeWorkspaceService::class)->allocation($this->worker, $legacy->id);
        $this->assertSame('legacy_unknown', $healthy['attribution_state']);
        $this->assertTrue($healthy['can']['acknowledge_own']);
    }

    public function test_own_catalogue_reference_uses_only_safe_proof_and_damaged_category_cannot_hide_attention(): void
    {
        $commands = app(PpeCommandService::class);
        $typeReceipt = $commands->createType($this->viewer, ['idempotency_key' => (string) Str::uuid(),
            'name' => 'Actual own reference', 'category' => 'respiratory', 'description' => 'PRIVATE-CATALOGUE-DESCRIPTION']);
        $itemReceipt = $commands->createInventory($this->viewer, ['idempotency_key' => (string) Str::uuid(),
            'site_id' => $this->site->id, 'ppe_type_id' => $typeReceipt['root_id'], 'serial_number' => 'SAFE-OWN-TYPE', 'quantity' => 1]);
        $item = PpeInventory::findOrFail($itemReceipt['root_id']);
        $issue = $commands->allocate($item, $this->viewer, ['idempotency_key' => (string) Str::uuid(),
            'expected_version' => 1, 'user_id' => $this->worker->id]);
        $allocation = PpeAllocation::findOrFail($issue['source_id']);
        $commands->acknowledge($allocation, $this->worker, ['idempotency_key' => (string) Str::uuid(), 'expected_version' => 2], true);
        [$healthy, $queries] = $this->queries(fn () => app(PpeWorkspaceService::class)->allocation($this->worker, $allocation->id));
        $this->assertSame('recorded', $healthy['catalogue_integrity']);
        $this->assertSame('Actual own reference', $healthy['ppe_type']['name']);
        $this->assertStringNotContainsString('PRIVATE-CATALOGUE-DESCRIPTION', json_encode($healthy));
        foreach ($queries as $query) {
            if (str_contains(strtolower($query), 'ppe_types') || str_contains(strtolower($query), 'ppe_source_versions')) {
                foreach (['content', 'description', 'hazards_addressed', 'standards_reference', 'actor_snapshot', 'command', 'idempotency_key'] as $privateColumn) {
                    $this->assertStringNotContainsString(chr(96).$privateColumn.chr(96), strtolower($query));
                }
                $this->assertStringNotContainsString('select *', strtolower($query));
            }
        }
        DB::table('ppe_types')->where('id', $typeReceipt['root_id'])->update(['name' => 'FORGED-CATALOGUE', 'category' => 'head']);
        $detail = app(PpeWorkspaceService::class)->allocation($this->worker, $allocation->id);
        $this->assertSame('intact', $detail['source_integrity']);
        $this->assertSame('repair_required', $detail['catalogue_integrity']);
        $this->assertNull($detail['ppe_type']);
        $rows = app(PpePickerService::class)->ownAllocations($this->worker);
        $this->assertCount(1, $rows);
        $this->assertSame($allocation->id, $rows[0]['id']);
        $this->assertSame('repair_required', $rows[0]['catalogue_integrity']);
        $this->assertNull($rows[0]['type_name']);
        $this->assertNull($rows[0]['fit_test_required']);
        $this->assertStringNotContainsString('FORGED-CATALOGUE', json_encode($rows));
        DB::table('ppe_types')->where('id', $typeReceipt['root_id'])->update(['source_schema_version' => 0, 'source_revision' => 0]);
        $this->assertSame('repair_required', app(PpeWorkspaceService::class)->allocation($this->worker, $allocation->id)['catalogue_integrity']);
        $legacy = PpeAllocation::factory()->create(['ppe_inventory_id' => $this->item->id, 'user_id' => $this->worker->id]);
        $this->assertSame('legacy_unknown', app(PpeWorkspaceService::class)->allocation($this->worker, $legacy->id)['catalogue_integrity']);
    }
}
