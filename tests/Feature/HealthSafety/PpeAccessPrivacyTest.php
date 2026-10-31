<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\PpeAllocation;
use App\Models\PpeAttachment;
use App\Models\PpeInspection;
use App\Models\PpeInspectionAttachment;
use App\Services\HealthSafety\PpeAccessService;
use App\Services\HealthSafety\PpeWorkspaceService;
use App\Services\CurrentAuthorizationReads;
use LogicException;

class PpeAccessPrivacyTest extends PpeReadTestCase
{
    private function check(callable $operation, $viewer = null): mixed
    {
        return app(PpeAccessService::class)->withCurrentViewer($viewer ?? $this->viewer, $operation);
    }

    public function test_loaded_permission_revocation_and_restore_use_current_actor(): void
    {
        $this->viewer->load(['roles.permissions', 'permissionOverrides']);
        $this->assertTrue($this->viewer->canDo('hazards.view'));
        $this->allow($this->viewer, 'hazards.view', false);
        $this->denied(fn () => app(PpeWorkspaceService::class)->item($this->viewer, $this->item->id), 403);
        $this->allow($this->viewer, 'hazards.view', true);
        $this->assertSame($this->item->id, app(PpeWorkspaceService::class)->item($this->viewer, $this->item->id)['id']);
    }

    public function test_foreign_inventory_allocation_inspection_and_missing_records_are_concealed(): void
    {
        $allocation = PpeAllocation::factory()->create(['ppe_inventory_id' => $this->foreignItem->id]);
        $inspection = PpeInspection::factory()->create(['ppe_inventory_id' => $this->foreignItem->id]);
        $service = app(PpeWorkspaceService::class);
        foreach ([fn () => $service->item($this->viewer, $this->foreignItem->id), fn () => $service->allocation($this->viewer, $allocation->id),
            fn () => $service->inspection($this->viewer, $inspection->id), fn () => $service->item($this->viewer, 99999999)] as $read) {
            $this->denied($read, 404);
        }
    }

    public function test_current_primary_secondary_site_and_explicit_bypass_change_reads(): void
    {
        $this->viewer->load('hrEmployeeProfile');
        $profile = HrEmployeeProfile::where('user_id', $this->viewer->id)->sole();
        $profile->update(['primary_site_id' => $this->foreignSite->id, 'secondary_site_ids' => []]);
        $service = app(PpeWorkspaceService::class);
        $this->denied(fn () => $service->item($this->viewer, $this->item->id), 404);
        $profile->update(['secondary_site_ids' => [$this->site->id]]);
        $this->assertSame($this->item->id, $service->item($this->viewer, $this->item->id)['id']);
        $profile->update(['secondary_site_ids' => []]);
        $this->allow($this->viewer, 'healthSafety.viewAllSites', true);
        $this->assertSame($this->item->id, $service->item($this->viewer, $this->item->id)['id']);
        $this->allow($this->viewer, 'healthSafety.viewAllSites', false);
        $this->denied(fn () => $service->item($this->viewer, $this->item->id), 404);
    }

    public function test_current_hr_approval_and_external_actor_are_required(): void
    {
        $profile = HrEmployeeProfile::where('user_id', $this->viewer->id)->sole();
        $profile->update(['is_active' => false]);
        $this->denied(fn () => app(PpeWorkspaceService::class)->item($this->viewer, $this->item->id), 403);
        $profile->update(['is_active' => true]);
        $this->viewer->update(['approved_at' => null]);
        $this->denied(fn () => app(PpeWorkspaceService::class)->item($this->viewer, $this->item->id), 403);
        $external = $this->staff(['hazards.view']);
        $external->update(['role' => 'external_clinician']);
        $this->denied(fn () => app(PpeWorkspaceService::class)->item($external, $this->item->id), 403);
    }

    public function test_own_allocation_requires_exact_current_owner_and_parent_site_without_module_grant(): void
    {
        $allocation = PpeAllocation::factory()->create(['ppe_inventory_id' => $this->item->id, 'user_id' => $this->worker->id]);
        $service = app(PpeWorkspaceService::class);
        $dto = $service->allocation($this->worker, $allocation->id);
        $this->assertSame('own_allocation', $dto['read_scope']);
        $this->assertTrue($dto['can']['acknowledge_own']);
        $other = $this->staff([]);
        $this->denied(fn () => $service->allocation($other, $allocation->id), 404);
        $allocation->update(['ppe_inventory_id' => $this->foreignItem->id]);
        $this->denied(fn () => $service->allocation($this->worker, $allocation->id), 404);
    }

    public function test_catalogue_authority_does_not_grant_global_operational_scope(): void
    {
        HrEmployeeProfile::where('user_id', $this->viewer->id)->update(['primary_site_id' => null, 'secondary_site_ids' => '[]']);
        $id = $this->check(fn ($current, $reads, $sites) => app(PpeAccessService::class)->assertType($this->type, $current, $reads)->id);
        $this->assertSame($this->type->id, $id);
        $this->denied(fn () => app(PpeWorkspaceService::class)->item($this->viewer, $this->item->id), 404);
    }

    public function test_parent_source_is_reloaded_instead_of_trusting_mutated_route_model(): void
    {
        $stale = clone $this->foreignItem;
        $stale->site_id = $this->site->id;
        $this->denied(fn () => $this->check(fn ($current, $reads, $sites) => app(PpeAccessService::class)->assertInventory($stale, $current, $reads, $sites)->id), 404);
    }

    public function test_attachment_requires_exact_kind_parent_fk_and_current_site(): void
    {
        $attachment = PpeAttachment::create(['ppe_inventory_id' => $this->foreignItem->id, 'disk' => 'private', 'path' => 'sentinel', 'original_name' => 'secret']);
        $this->denied(fn () => $this->check(fn ($current, $reads, $sites) => app(PpeAccessService::class)->assertAttachment($attachment, $this->item, $current, $reads, $sites)->id), 404);
        $inspection = PpeInspection::factory()->create(['ppe_inventory_id' => $this->item->id]);
        $this->denied(fn () => $this->check(fn ($current, $reads, $sites) => app(PpeAccessService::class)->assertAttachment($attachment, $inspection, $current, $reads, $sites)->id), 404);
        $file = PpeInspectionAttachment::create(['ppe_inspection_id' => $inspection->id, 'disk' => 'private', 'path' => 'report', 'original_name' => 'report']);
        $this->assertSame($file->id, $this->check(fn ($current, $reads, $sites) => app(PpeAccessService::class)->assertAttachment($file, $inspection, $current, $reads, $sites)->id));
    }

    public function test_historical_allocated_worker_retains_authorised_read_but_new_issue_eligibility_is_current(): void
    {
        $allocation = PpeAllocation::factory()->create(['ppe_inventory_id' => $this->item->id, 'user_id' => $this->worker->id]);
        HrEmployeeProfile::where('user_id', $this->worker->id)->update(['is_active' => false]);
        $this->assertSame($this->worker->id, app(PpeWorkspaceService::class)->allocation($this->viewer, $allocation->id)['user']['id']);
        $this->denied(fn () => $this->check(fn ($current, $reads, $sites) => app(PpeAccessService::class)->currentStaff($this->worker->id, $this->site->id, $current, $reads, $sites)->id), 404);
    }

    public function test_read_context_cannot_return_model_or_reuse_escaped_evidence(): void
    {
        try {
            $this->check(fn ($current, $reads, $sites) => app(PpeAccessService::class)->assertInventory($this->item, $current, $reads, $sites));
            $this->fail('A live model must not escape.');
        } catch (LogicException $error) {
            $this->assertStringContainsString('detached', $error->getMessage());
        }
        $escaped = null;
        $this->check(function ($current, CurrentAuthorizationReads $reads, $sites) use (&$escaped) { $escaped = $reads; return null; });
        $this->expectException(LogicException::class);
        $escaped->assertActive();
    }

    public function test_view_capability_does_not_authorise_commands_or_own_evidence(): void
    {
        $viewer = $this->staff(['hazards.view']);
        $this->denied(fn () => $this->check(fn ($current) => app(PpeAccessService::class)->assertOperation($current, 'allocate'), $viewer), 403);
        $allocation = PpeAllocation::factory()->create(['ppe_inventory_id' => $this->item->id, 'user_id' => $this->worker->id]);
        $this->denied(fn () => $this->check(fn ($current, $reads, $sites) => app(PpeAccessService::class)->assertInventory($this->item, $current, $reads, $sites, 'own_view')->id, $this->worker), 403);
    }
}
