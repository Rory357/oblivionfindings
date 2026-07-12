<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HsEventSiteIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_site_bound_user_only_receives_events_and_picker_options_for_their_site(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $user = $this->siteBoundUser($siteA, ['hazards.view']);
        $visible = HsEvent::factory()->high()->create(['site_id' => $siteA->id]);
        $hidden = HsEvent::factory()->critical()->create(['site_id' => $siteB->id]);

        $this->actingAs($user)
            ->get('/health-safety/events')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('events.data', 1)
                ->where('events.data.0.id', $visible->id)
                ->where('tabCounts.all', 1)
                ->has('sites', 1)
                ->where('sites.0.id', $siteA->id)
            );

        $this->assertNotSame($visible->id, $hidden->id);
    }

    public function test_site_bound_user_cannot_open_another_sites_event_overlay(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $user = $this->siteBoundUser($siteA, ['hazards.view']);
        $hidden = HsEvent::factory()->create(['site_id' => $siteB->id]);

        $this->actingAs($user)
            ->get('/health-safety/events?event='.$hidden->id)
            ->assertNotFound();

        $this->actingAs($user)
            ->get('/health-safety/events?event=999999')
            ->assertNotFound();
    }

    public function test_site_bound_user_cannot_open_another_sites_event_deep_link(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $user = $this->siteBoundUser($siteA, ['hazards.view']);
        $hidden = HsEvent::factory()->create(['site_id' => $siteB->id]);

        $this->actingAs($user)
            ->get('/health-safety/events/'.$hidden->id)
            ->assertNotFound();

        $this->actingAs($user)
            ->get('/health-safety/events/999999')
            ->assertNotFound();
    }

    public function test_site_bound_manager_cannot_mutate_another_sites_event(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $user = $this->siteBoundUser($siteA, ['hazards.manage']);
        $hidden = HsEvent::factory()->create(['site_id' => $siteB->id]);

        $this->actingAs($user)
            ->post('/health-safety/events/'.$hidden->id.'/close', [
                'closure_summary' => 'This must not be accepted across sites.',
            ])
            ->assertNotFound();

        $this->assertNotSame(HsEvent::STATUS_CLOSED, $hidden->fresh()->status);
    }

    public function test_site_bound_manager_cannot_notify_or_acknowledge_worksafe_for_another_sites_event(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $user = $this->siteBoundUser($siteA, ['hazards.manage']);
        $pending = HsEvent::factory()->worksafeNotifiable()->create(['site_id' => $siteB->id]);
        $notified = HsEvent::factory()->worksafeNotifiable()->create([
            'site_id' => $siteB->id,
            'worksafe_status' => HsEvent::WORKSAFE_NOTIFIED,
            'worksafe_notified_at' => now(),
            'worksafe_method' => 'online',
        ]);

        $this->actingAs($user)
            ->post('/health-safety/events/'.$pending->id.'/worksafe/notify', [
                'notified_at' => now()->toDateString(),
                'method' => 'phone',
            ])
            ->assertNotFound();

        $this->actingAs($user)
            ->post('/health-safety/events/'.$notified->id.'/worksafe/acknowledge', [
                'acknowledged_at' => now()->toDateString(),
            ])
            ->assertNotFound();

        $this->assertSame(HsEvent::WORKSAFE_PENDING, $pending->fresh()->worksafe_status);
        $this->assertSame(HsEvent::WORKSAFE_NOTIFIED, $notified->fresh()->worksafe_status);
        $this->assertNull($notified->fresh()->worksafe_acknowledged_at);
    }

    public function test_site_bound_user_cannot_filter_events_by_an_inaccessible_site(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $user = $this->siteBoundUser($siteA, ['hazards.view']);

        $this->actingAs($user)
            ->get('/health-safety/events?site_id='.$siteB->id)
            ->assertForbidden();

        $this->actingAs($user)
            ->get('/health-safety/corrective-actions?site_id='.$siteB->id)
            ->assertForbidden();
    }

    public function test_site_bound_user_only_receives_corrective_actions_for_their_site(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $user = $this->siteBoundUser($siteA, ['hazards.view']);
        $visibleEvent = HsEvent::factory()->create(['site_id' => $siteA->id]);
        $hiddenEvent = HsEvent::factory()->create(['site_id' => $siteB->id]);
        $visibleAction = HsCorrectiveAction::factory()->create(['hs_event_id' => $visibleEvent->id]);
        HsCorrectiveAction::factory()->create(['hs_event_id' => $hiddenEvent->id]);

        $this->actingAs($user)
            ->get('/health-safety/corrective-actions')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('actions.data', 1)
                ->where('actions.data.0.id', $visibleAction->id)
                ->where('actions.data.0.event.id', $visibleEvent->id)
                ->where('tabCounts.all', 1)
                ->where('tabCounts.open', 1)
                ->where('hero.live.open', 1)
                ->has('sites', 1)
                ->where('sites.0.id', $siteA->id)
            );
    }

    public function test_site_bound_user_cannot_open_another_sites_event_in_the_corrective_actions_overlay(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $user = $this->siteBoundUser($siteA, ['hazards.view']);
        $hidden = HsEvent::factory()->create(['site_id' => $siteB->id]);

        $this->actingAs($user)
            ->get('/health-safety/corrective-actions?event='.$hidden->id)
            ->assertNotFound();

        $this->actingAs($user)
            ->get('/health-safety/corrective-actions?event=999999')
            ->assertNotFound();
    }

    public function test_unassigned_hazards_user_without_global_permission_is_not_treated_as_global(): void
    {
        $site = Site::factory()->create();
        $user = $this->userWithPermissions(['hazards.view']);
        $event = HsEvent::factory()->create(['site_id' => $site->id]);

        $this->actingAs($user)
            ->get('/health-safety/events')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('events.data', 0)
                ->where('tabCounts.all', 0)
                ->has('sites', 0)
            );

        $this->actingAs($user)
            ->get('/health-safety/events/'.$event->id)
            ->assertNotFound();
    }

    public function test_global_user_retains_access_to_all_events_and_detail(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $globalUser = $this->userWithPermissions(['hazards.view', 'healthSafety.viewAllSites']);
        HsEvent::factory()->create(['site_id' => $siteA->id]);
        $target = HsEvent::factory()->create(['site_id' => $siteB->id]);

        $this->actingAs($globalUser)
            ->get('/health-safety/events?event='.$target->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('events.data', 2)
                ->where('tabCounts.all', 2)
                ->where('detail.id', $target->id)
                ->has('sites', 2)
            );
    }

    public function test_health_safety_officer_role_has_explicit_global_hs_visibility(): void
    {
        $role = Role::query()->where('name', 'health_safety_officer')->firstOrFail();

        $this->assertTrue($role->permissions()->where('key', 'healthSafety.viewAllSites')->exists());
    }

    /**
     * @param  array<int, string>  $permissionKeys
     */
    private function siteBoundUser(Site $site, array $permissionKeys): User
    {
        $user = $this->userWithPermissions($permissionKeys);

        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
        ]);

        return $user;
    }

    /**
     * @param  array<int, string>  $permissionKeys
     */
    private function userWithPermissions(array $permissionKeys): User
    {
        $user = User::factory()->create(['approved_at' => now()]);

        $permissions = Permission::query()->whereIn('key', $permissionKeys)->pluck('id');
        $user->permissionOverrides()->sync($permissions->mapWithKeys(fn ($id) => [$id => ['allowed' => true]]));

        return $user;
    }
}
