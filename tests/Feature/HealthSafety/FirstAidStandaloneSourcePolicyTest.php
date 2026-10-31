<?php

namespace Tests\Feature\HealthSafety;

use App\Models\Client;
use App\Models\FirstAidRecord;
use App\Models\HsEvent;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\HsEventAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FirstAidSourceFixtures;
use Tests\TestCase;

/** Direct source policy, using accepted treatment and current approved source staff. */
class FirstAidStandaloneSourcePolicyTest extends TestCase
{
    use FirstAidSourceFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initializeFirstAidFixtures();
        foreach (['healthSafety.viewAllSites', 'sites.viewAll', 'clinical.accessAllSites', 'clients.viewAny'] as $key) {
            $this->permission($this->worker, $key, false);
        }
    }

    public function test_warmed_hs_destination_permission_revoke_and_restore_are_current_without_mutating_the_source(): void
    {
        $source = $this->source();
        $event = $this->review($source);
        $access = app(HsEventAccessService::class);
        $this->assertTrue($access->canView($event, $this->worker));
        $this->worker->load('roles.permissions', 'permissionOverrides', 'hrEmployeeProfile');
        $this->permission($this->worker, 'hazards.view', false);
        $this->assertFalse($access->canView($event, $this->worker));
        $this->permission($this->worker, 'hazards.view', true);
        $this->assertTrue($access->canView($event, $this->worker));
        $this->assertSame($source->id, $event->fresh()->source_id);
        $this->assertDatabaseCount('first_aid_records', 1);
    }

    public function test_actual_site_and_hr_withdrawal_restore_use_fresh_evidence_on_same_policy_and_viewer(): void
    {
        $event = $this->review($this->source());
        $access = app(HsEventAccessService::class);
        $this->assertTrue($access->canView($event, $this->worker));
        $this->worker->load('roles.permissions', 'permissionOverrides', 'hrEmployeeProfile');
        $other = Site::factory()->create(['is_active' => true]);
        $this->worker->hrEmployeeProfile()->update(['primary_site_id' => $other->id, 'secondary_site_ids' => []]);
        $this->assertFalse($access->canView($event, $this->worker));
        $this->worker->hrEmployeeProfile()->update(['primary_site_id' => $this->sourceSite->id]);
        $this->assertTrue($access->canView($event, $this->worker));
        $this->worker->hrEmployeeProfile()->update(['is_active' => false]);
        $this->assertFalse($access->canView($event, $this->worker));
        $this->worker->hrEmployeeProfile()->update(['is_active' => true]);
        $this->assertTrue($access->canView($event, $this->worker));
        $this->sourceSite->update(['is_active' => false]);
        $this->assertFalse($access->canView($event, $this->worker));
        $this->sourceSite->update(['is_active' => true]);
        $this->assertTrue($access->canView($event, $this->worker));
    }

    public function test_primary_client_assignment_and_read_grant_each_remain_binding_on_warmed_caller(): void
    {
        $client = Client::factory()->create(['site_id' => $this->sourceSite->id]);
        $event = $this->review($this->source(['treated_person_type' => 'client', 'treated_person_id' => null, 'client_id' => $client->id]));
        $this->permission($this->worker, 'clients.viewAssigned', true);
        $access = app(HsEventAccessService::class);
        $this->assertFalse($access->canView($event, $this->worker));
        $client->supportWorkers()->attach($this->worker->id);
        $this->assertTrue($access->canView($event, $this->worker));
        $this->worker->load('roles.permissions', 'permissionOverrides', 'assignedClients', 'hrEmployeeProfile');
        $this->permission($this->worker, 'clients.viewAssigned', false);
        $this->assertFalse($access->canView($event, $this->worker));
        $this->permission($this->worker, 'clients.viewAssigned', true);
        $this->assertTrue($access->canView($event, $this->worker));
        $client->supportWorkers()->detach($this->worker->id);
        $this->assertFalse($access->canView($event, $this->worker));
        $client->supportWorkers()->attach($this->worker->id);
        $this->assertTrue($access->canView($event, $this->worker));
        $event->updateQuietly(['event_category' => HsEvent::CATEGORY_INCIDENT]);
        $this->assertFalse($access->canView($event, $this->worker));
    }

    private function permission(User $actor, string $key, bool $allowed): void
    {
        $actor->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->sole()->id => ['allowed' => $allowed]]);
    }

    private function review(FirstAidRecord $source): HsEvent
    {
        return HsEvent::factory()->createQuietly(['source_type' => $source->getMorphClass(), 'source_id' => $source->id,
            'event_category' => HsEvent::CATEGORY_INJURY, 'site_id' => $source->site_id,
            'client_id' => $source->client_id, 'staff_id' => $source->treated_person_id,
            'created_by' => $this->manager->id]);
    }
}
