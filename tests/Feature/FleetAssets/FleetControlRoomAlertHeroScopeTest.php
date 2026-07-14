<?php

namespace Tests\Feature\FleetAssets;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\ControlRoomAlert;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FleetControlRoomAlertHeroScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
    }

    public function test_fleet_hero_alert_counts_are_actionable_site_scoped_and_globally_available_to_managers(): void
    {
        [$localSite, $foreignSite] = [Site::factory()->create(), Site::factory()->create()];
        $user = $this->siteScopedUser($localSite, ['fleet.viewAny', 'assets.viewAny']);
        $manager = $this->siteScopedUser($localSite, ['fleet.viewAny', 'assets.viewAny', 'fleet.manage']);

        ControlRoomAlert::factory()->fromFleet()->open()->critical()->create(['site_id' => $localSite->id]);
        ControlRoomAlert::factory()->fromFleet()->create([
            'site_id' => $localSite->id,
            'status' => ControlRoomAlert::STATUS_DISMISSED,
            'severity' => 'critical',
        ]);
        ControlRoomAlert::factory()->fromFleet()->resolved()->critical()->create(['site_id' => $localSite->id]);
        ControlRoomAlert::factory()->fromFleet()->open()->critical()->create(['site_id' => $foreignSite->id]);

        $this->assertFleetHeroAlertCounts($user, 1);
        $this->assertFleetHeroAlertCounts($manager, 2);
    }

    private function assertFleetHeroAlertCounts(User $user, int $expected): void
    {
        foreach ([
            ['/fleet-assets/vehicles', 'compliance.open_alerts'],
            ['/fleet-assets/vehicles', 'compliance.critical_alerts'],
            ['/fleet-assets/daily-check', 'compliance.open_alerts'],
            ['/fleet-assets/daily-check', 'compliance.critical_alerts'],
            ['/fleet-assets/bookings', 'hero.critical_alerts'],
            ['/fleet-assets/outings', 'hero.critical_alerts'],
        ] as [$uri, $prop]) {
            $this->actingAs($user)
                ->get($uri)
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page->where($prop, $expected));
        }
    }

    /**
     * @param  array<int, string>  $permissionKeys
     */
    private function siteScopedUser(Site $site, array $permissionKeys): User
    {
        $user = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $permissionIds = Permission::query()->whereIn('key', $permissionKeys)->pluck('id');
        $user->permissionOverrides()->sync(
            $permissionIds->mapWithKeys(fn ($id) => [$id => ['allowed' => true]]),
        );
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
        ]);

        return $user;
    }
}
