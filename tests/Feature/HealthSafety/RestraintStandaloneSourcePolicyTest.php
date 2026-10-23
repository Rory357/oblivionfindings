<?php

namespace Tests\Feature\HealthSafety;

use App\Models\HsEvent;
use App\Models\Permission;
use App\Models\RestraintEvent;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\HsEventAccessService;

/** Current exact source policy against accepted restraint records and approved staff fixtures. */
class RestraintStandaloneSourcePolicyTest extends RestraintEventCommandTestCase
{
    public function test_warmed_destination_and_source_grant_revocation_restore_each_require_actual_current_evidence(): void
    {
        $source = $this->event();
        $event = $this->review($source);
        $viewer = $this->staff(['hazards.view', 'restraints.view', 'clients.viewAny']);
        $access = app(HsEventAccessService::class);
        $this->assertTrue($access->canView($event, $viewer));
        $viewer->load('roles.permissions', 'permissionOverrides', 'hrEmployeeProfile');
        foreach (['hazards.view', 'restraints.view'] as $key) {
            $this->permission($viewer, $key, false);
            $this->assertFalse($access->canView($event, $viewer));
            $this->permission($viewer, $key, true);
            $this->assertTrue($access->canView($event, $viewer));
        }
        $this->assertSame($source->id, $event->fresh()->source_id);
        $this->assertDatabaseCount('restraint_events', 1);
    }

    public function test_actual_site_and_hr_withdrawal_restore_use_current_evidence_for_the_same_actor_and_policy(): void
    {
        $event = $this->review($this->event());
        $viewer = $this->staff(['hazards.view', 'restraints.view', 'clients.viewAny']);
        $access = app(HsEventAccessService::class);
        $this->assertTrue($access->canView($event, $viewer));
        $viewer->load('roles.permissions', 'permissionOverrides', 'hrEmployeeProfile');
        $other = Site::factory()->create(['is_active' => true]);
        $viewer->hrEmployeeProfile()->update(['primary_site_id' => $other->id, 'secondary_site_ids' => []]);
        $this->assertFalse($access->canView($event, $viewer));
        $viewer->hrEmployeeProfile()->update(['primary_site_id' => $this->site->id]);
        $this->assertTrue($access->canView($event, $viewer));
        $viewer->hrEmployeeProfile()->update(['is_active' => false]);
        $this->assertFalse($access->canView($event, $viewer));
        $viewer->hrEmployeeProfile()->update(['is_active' => true]);
        $this->assertTrue($access->canView($event, $viewer));
        $this->site->update(['is_active' => false]);
        $this->assertFalse($access->canView($event, $viewer));
        $this->site->update(['is_active' => true]);
        $this->assertTrue($access->canView($event, $viewer));
    }

    public function test_primary_client_assignment_restoration_never_substitutes_for_source_grant_or_exact_category(): void
    {
        $event = $this->review($this->event());
        $viewer = $this->staff(['hazards.view', 'restraints.view', 'clients.viewAssigned']);
        $this->permission($viewer, 'clients.viewAny', false);
        $access = app(HsEventAccessService::class);
        $this->assertFalse($access->canView($event, $viewer));
        $this->client->supportWorkers()->attach($viewer->id);
        $this->assertTrue($access->canView($event, $viewer));
        $viewer->load('roles.permissions', 'permissionOverrides', 'assignedClients', 'hrEmployeeProfile');
        $this->client->supportWorkers()->detach($viewer->id);
        $this->assertFalse($access->canView($event, $viewer));
        $this->client->supportWorkers()->attach($viewer->id);
        $this->assertTrue($access->canView($event, $viewer));
        $this->permission($viewer, 'restraints.view', false);
        $this->assertFalse($access->canView($event, $viewer));
        $this->permission($viewer, 'restraints.view', true);
        $this->assertTrue($access->canView($event, $viewer));
        $event->updateQuietly(['event_category' => HsEvent::CATEGORY_INJURY]);
        $this->assertFalse($access->canView($event, $viewer));
    }

    private function permission(User $viewer, string $key, bool $allowed): void
    {
        $viewer->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->sole()->id => ['allowed' => $allowed]]);
    }

    private function review(RestraintEvent $source): HsEvent
    {
        return HsEvent::factory()->createQuietly(['source_type' => $source->getMorphClass(), 'source_id' => $source->id,
            'event_category' => HsEvent::CATEGORY_RESTRAINT, 'site_id' => $source->site_id,
            'client_id' => $source->client_id, 'staff_id' => $source->created_by, 'created_by' => $source->created_by]);
    }
}
