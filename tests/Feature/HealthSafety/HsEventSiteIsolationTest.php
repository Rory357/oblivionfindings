<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
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
            ->get('/health-safety/events?event='.$hidden->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('events.data', 1)
                ->where('events.data.0.id', $visible->id)
                ->where('tabCounts.all', 1)
                ->where('detail', null)
                ->has('sites', 1)
                ->where('sites.0.id', $siteA->id)
            );
    }

    public function test_site_bound_user_cannot_open_another_sites_event_deep_link(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $user = $this->siteBoundUser($siteA, ['hazards.view']);
        $hidden = HsEvent::factory()->create(['site_id' => $siteB->id]);

        $this->actingAs($user)
            ->get('/health-safety/events/'.$hidden->id)
            ->assertForbidden();
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
            ->assertForbidden();

        $this->assertNotSame(HsEvent::STATUS_CLOSED, $hidden->fresh()->status);
    }

    public function test_global_user_retains_access_to_all_events_and_detail(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $globalUser = $this->userWithPermissions(['hazards.view', 'reports.viewAny']);
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

    /**
     * @param  array<int, string>  $permissionKeys
     */
    private function siteBoundUser(Site $site, array $permissionKeys): User
    {
        $user = $this->userWithPermissions($permissionKeys);
        $user->roles()->attach(Role::query()->where('name', 'health_safety_officer')->firstOrFail());

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
