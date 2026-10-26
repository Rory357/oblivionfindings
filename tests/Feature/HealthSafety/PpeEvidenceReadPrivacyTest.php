<?php

namespace Tests\Feature\HealthSafety;

use App\Models\PpeAllocationAttachment;
use App\Models\PpeAttachment;
use App\Models\PpeInspection;
use App\Models\PpeInspectionAttachment;
use App\Models\PpeInventory;
use App\Models\PpeType;
use App\Models\Site;
use App\Services\HealthSafety\PpeWorkspaceService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Genuine upload acceptance needs the established guarded physical-commit fixture boundary. */
final class PpeEvidenceReadPrivacyTest extends PpeCommandTestCase
{
    protected bool $physicalCommits = true;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('private');
    }

    private function upload(string $kind, int $parentId, PpeInventory $root, string $bytes = 'Actual private evidence'): array
    {
        return $this->service()->uploadAttachment($kind, $parentId, $this->manager,
            $this->command($root, ['file' => UploadedFile::fake()->createWithContent('evidence.txt', $bytes), 'kind' => 'certificate']));
    }

    public function test_accepted_private_bytes_have_internal_descriptor_and_actual_http_download(): void
    {
        $item = $this->item();
        $file = PpeAttachment::findOrFail($this->upload('inventory', $item->id, $item)['source_id']);
        $descriptor = app(PpeWorkspaceService::class)->evidence($this->manager, 'inventory', $item->id, $file->id);
        $this->assertSame('private', $descriptor['disk']);
        $this->assertSame('recorded', $descriptor['attribution_state']);
        $this->assertSame(hash('sha256', 'Actual private evidence'), $descriptor['digest']);
        $this->assertSame(strlen('Actual private evidence'), $descriptor['size']);
        $detail = app(PpeWorkspaceService::class)->item($this->manager, $item->id);
        $this->assertTrue($detail['attachments'][0]['available']);
        $this->assertSame('recorded', $detail['attachments'][0]['attribution_state']);
        $this->assertArrayNotHasKey('path', $detail['attachments'][0]);
        $this->assertArrayNotHasKey('digest', $detail['attachments'][0]);
        $response = $this->actingAs($this->manager)->get('/health-safety/ppe/inventory/'.$item->id.'/attachments/'.$file->id.'/download');
        $response->assertOk();
        $this->assertSame('Actual private evidence', $response->streamedContent());
    }

    public function test_allocation_and_inspection_downloads_prove_literal_parent_kind_and_child_fk(): void
    {
        $item = $this->item();
        $allocation = $this->issue($item);
        $allocationFile = PpeAllocationAttachment::findOrFail($this->upload('allocation', $allocation->id, $item, 'Issue bytes')['source_id']);
        $inspectionReceipt = $this->service()->inspect($item, $this->manager, $this->command($item, ['result' => 'pass', 'findings' => 'Actual finding']));
        $inspection = PpeInspection::findOrFail($inspectionReceipt['source_id']);
        $inspectionFile = PpeInspectionAttachment::findOrFail($this->upload('inspection', $inspection->id, $item, 'Inspection bytes')['source_id']);
        $service = app(PpeWorkspaceService::class);
        $this->assertSame(hash('sha256', 'Issue bytes'), $service->evidence($this->manager, 'allocations', $allocation->id, $allocationFile->id)['digest']);
        $this->assertSame(hash('sha256', 'Inspection bytes'), $service->evidence($this->manager, 'inspections', $inspection->id, $inspectionFile->id)['digest']);
        $other = $this->item();
        $otherIssue = $this->issue($other);
        $this->denial(fn () => $service->evidence($this->manager, 'allocations', $otherIssue->id, $allocationFile->id), 404);
        $this->denial(fn () => $service->evidence($this->manager, 'inventory', $item->id, $allocationFile->id), 404);
        $this->denial(fn () => $service->evidence($this->manager, 'allocation', $allocation->id, $allocationFile->id), 404);
    }

    public function test_current_view_revoke_restore_and_foreign_site_never_expose_bytes(): void
    {
        $item = $this->item();
        $file = PpeAttachment::findOrFail($this->upload('inventory', $item->id, $item)['source_id']);
        $this->manager->load(['roles.permissions', 'permissionOverrides']);
        $this->revoke($this->manager, 'hazards.view');
        $service = app(PpeWorkspaceService::class);
        $this->denial(fn () => $service->evidence($this->manager, 'inventory', $item->id, $file->id));
        $this->actingAs($this->manager)->get('/health-safety/ppe/inventory/'.$item->id.'/attachments/'.$file->id.'/download')->assertForbidden();
        $permission = \App\Models\Permission::where('key', 'hazards.view')->sole();
        $this->manager->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => true]]);
        $this->assertSame('recorded', $service->evidence($this->manager, 'inventory', $item->id, $file->id)['attribution_state']);
        $foreign = Site::factory()->create(['is_active' => true]);
        $this->manager->hrEmployeeProfile()->update(['primary_site_id' => $foreign->id, 'secondary_site_ids' => []]);
        $this->denial(fn () => $service->evidence($this->manager, 'inventory', $item->id, $file->id), 404);
        $this->actingAs($this->manager)->get('/health-safety/ppe/inventory/'.$item->id.'/attachments/'.$file->id.'/download')->assertNotFound();
    }

    public function test_own_allocation_is_not_module_evidence_read_authority(): void
    {
        $item = $this->item();
        $allocation = $this->issue($item);
        $file = PpeAllocationAttachment::findOrFail($this->upload('allocation', $allocation->id, $item)['source_id']);
        $service = app(PpeWorkspaceService::class);
        $this->assertSame('own_allocation', $service->allocation($this->worker, $allocation->id)['read_scope']);
        $this->denial(fn () => $service->evidence($this->worker, 'allocations', $allocation->id, $file->id));
        $this->actingAs($this->worker)->get('/health-safety/ppe/allocations/'.$allocation->id.'/attachments/'.$file->id.'/download')->assertForbidden();
    }

    public function test_changed_typed_bytes_withhold_filename_link_and_stream(): void
    {
        $item = $this->item();
        $file = PpeAttachment::findOrFail($this->upload('inventory', $item->id, $item)['source_id']);
        Storage::disk('private')->put($file->path, 'Externally changed bytes');
        $service = app(PpeWorkspaceService::class);
        $row = $service->item($this->manager, $item->id)['attachments'][0];
        $this->assertSame('repair_required', $row['attribution_state']);
        $this->assertFalse($row['available']);
        $this->assertNull($row['original_name']);
        $this->assertNull($row['download_url']);
        $this->denial(fn () => $service->evidence($this->manager, 'inventory', $item->id, $file->id), 404);
        $this->actingAs($this->manager)->get('/health-safety/ppe/inventory/'.$item->id.'/attachments/'.$file->id.'/download')->assertNotFound();
    }

    public function test_missing_private_bytes_are_unavailable_and_never_empty_verified_evidence(): void
    {
        $item = $this->item();
        $file = PpeAttachment::findOrFail($this->upload('inventory', $item->id, $item)['source_id']);
        Storage::disk('private')->delete($file->path);
        $service = app(PpeWorkspaceService::class);
        $row = $service->item($this->manager, $item->id)['attachments'][0];
        $this->assertSame('unavailable', $row['attribution_state']);
        $this->assertFalse($row['available']);
        $this->assertNull($row['download_url']);
        $this->denial(fn () => $service->evidence($this->manager, 'inventory', $item->id, $file->id), 404);
    }

    public function test_legacy_private_bytes_remain_unknown_and_public_or_unsafe_paths_fail_closed(): void
    {
        $type = PpeType::factory()->create();
        $item = PpeInventory::factory()->create(['site_id' => $this->site->id, 'ppe_type_id' => $type->id]);
        Storage::disk('private')->put('ppe/legacy.txt', 'Actual legacy bytes');
        $file = PpeAttachment::create(['ppe_inventory_id' => $item->id, 'disk' => 'private', 'path' => 'ppe/legacy.txt', 'original_name' => 'Legacy record']);
        $service = app(PpeWorkspaceService::class);
        $this->assertSame('legacy_unknown', $service->evidence($this->manager, 'inventory', $item->id, $file->id)['attribution_state']);
        foreach ([['public', 'ppe/legacy.txt'], ['unknown', 'ppe/legacy.txt'], ['private', '../outside'],
            ['private', 'C:/secret'], ['private', '/absolute'], ['private', 'ppe'.chr(92).'outside'], ['private', "ppe/\0secret"]] as [$disk, $path]) {
            DB::table('ppe_attachments')->where('id', $file->id)->update(['disk' => $disk, 'path' => $path]);
            $this->denial(fn () => $service->evidence($this->manager, 'inventory', $item->id, $file->id), 404);
        }
    }

    public function test_disk_failure_and_reparented_or_marker_downgraded_typed_evidence_do_not_stream(): void
    {
        $item = $this->item();
        $file = PpeAttachment::findOrFail($this->upload('inventory', $item->id, $item)['source_id']);
        $other = $this->item();
        DB::table('ppe_attachments')->where('id', $file->id)->update(['ppe_inventory_id' => $other->id]);
        $service = app(PpeWorkspaceService::class);
        $this->denial(fn () => $service->evidence($this->manager, 'inventory', $other->id, $file->id), 404);
        DB::table('ppe_attachments')->where('id', $file->id)->update(['ppe_inventory_id' => $item->id, 'source_schema_version' => 0, 'source_revision' => 0]);
        $this->denial(fn () => $service->evidence($this->manager, 'inventory', $item->id, $file->id), 404);
        DB::table('ppe_attachments')->where('id', $file->id)->update(['source_schema_version' => 1, 'source_revision' => 2]);
        $disk = \Mockery::mock(Storage::disk('private'))->makePartial();
        $disk->shouldReceive('readStream')->andThrow(new \RuntimeException('Private disk unavailable'));
        Storage::shouldReceive('disk')->with('private')->andReturn($disk);
        $this->denial(fn () => $service->evidence($this->manager, 'inventory', $item->id, $file->id), 404);
        $this->assertFalse($service->item($this->manager, $item->id)['attachments'][0]['available']);
    }
    public function test_download_rechecks_the_descriptor_digest_before_emitting_changed_same_size_bytes(): void
    {
        $item = $this->item();
        $file = PpeAttachment::findOrFail($this->upload('inventory', $item->id, $item)['source_id']);
        $descriptor = app(PpeWorkspaceService::class)->evidence($this->manager, 'inventory', $item->id, $file->id);
        $real = Storage::disk('private');
        $opens = 0;
        Storage::shouldReceive('disk')->with('private')->andReturnUsing(function () use ($real, $descriptor, $file, &$opens) {
            // One canonical byte read belongs to evidence(); the next disk open belongs to the stream boundary.
            if (++$opens === 2) {
                $real->put($file->path, str_repeat('X', $descriptor['size']));
            }
            return $real;
        });
        $response = $this->actingAs($this->manager)->get('/health-safety/ppe/inventory/'.$item->id.'/attachments/'.$file->id.'/download');
        $response->assertNotFound();
    }

    public function test_legacy_public_relocation_capability_uses_exact_current_manager_and_real_canonical_bytes(): void
    {
        Storage::fake('public');
        $type = PpeType::factory()->create();
        $item = PpeInventory::factory()->create(['site_id' => $this->site->id, 'ppe_type_id' => $type->id]);
        $path = 'ppe_attachments/'.str_repeat('a', 40).'.txt';
        $bytes = 'Retained original bytes';
        Storage::disk('public')->put($path, $bytes);
        $file = PpeAttachment::create(['ppe_inventory_id' => $item->id, 'disk' => 'public', 'path' => $path,
            'original_name' => 'Retained public record', 'size' => strlen($bytes), 'uploaded_by' => $this->manager->id]);
        $service = app(PpeWorkspaceService::class);
        $row = $service->item($this->manager, $item->id)['attachments'][0];
        $this->assertTrue($row['can_relocate']);
        $this->assertNull($row['relocate_blocked_reason']);
        $this->assertSame('legacy_unknown', $row['attribution_state']);
        $this->assertFalse($row['available']);
        $this->assertNull($row['download_url']);
        $this->assertArrayNotHasKey('path', $row);
        $reader = $this->staff(['hazards.view']);
        $this->assertFalse($service->item($reader, $item->id)['attachments'][0]['can_relocate']);
        $this->revoke($this->manager, 'hazards.manage');
        $this->assertFalse($service->item($this->manager, $item->id)['attachments'][0]['can_relocate']);
        $this->manager->permissionOverrides()->syncWithoutDetaching([\App\Models\Permission::where('key', 'hazards.manage')->sole()->id => ['allowed' => true]]);
        $this->assertTrue($service->item($this->manager, $item->id)['attachments'][0]['can_relocate']);
        Storage::disk('public')->delete($path);
        $missing = $service->item($this->manager, $item->id)['attachments'][0];
        $this->assertFalse($missing['can_relocate']);
        $this->assertNotNull($missing['relocate_blocked_reason']);
        Storage::disk('public')->put($path, $bytes);
        $allocation = \App\Models\PpeAllocation::factory()->create(['ppe_inventory_id' => $item->id, 'user_id' => $this->worker->id]);
        $own = $service->allocation($this->worker, $allocation->id);
        $this->assertArrayNotHasKey('attachments', $own);
        $this->assertArrayNotHasKey('can_relocate', $own['can']);
        DB::table('ppe_attachments')->where('id', $file->id)->update(['source_schema_version' => 1, 'source_revision' => 1]);
        $damaged = $service->item($this->manager, $item->id)['attachments'][0];
        $this->assertSame('repair_required', $damaged['attribution_state']);
        $this->assertFalse($damaged['can_relocate']);
        $this->assertNull($damaged['original_name']);
        $this->denial(fn () => $service->evidence($this->manager, 'inventory', $item->id, $file->id), 404);
        $this->assertStringNotContainsString($path, json_encode($damaged));
    }
}
