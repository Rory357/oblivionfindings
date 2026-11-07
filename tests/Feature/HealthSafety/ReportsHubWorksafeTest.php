<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\HsEvent;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\GovernancePermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * G4-15 (board approved by Stephan 1 Nov 2026): the Reports page's WorkSafe row shows the waiting item the person can
 * already read (WorkSafe: decide or tell at their Sites). Nobody without H&S read gets it, a report view never computes
 * it, and nothing new is granted (B2.11).
 */
class ReportsHubWorksafeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(GovernancePermissionsSeeder::class);
    }

    private function viewer(array $permissions, Site $site): User
    {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $user->roles()->detach();
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $site->id, 'secondary_site_ids' => []]);
        foreach ($permissions as $key) {
            $user->permissionOverrides()->syncWithoutDetaching([Permission::where('key', $key)->firstOrFail()->id => ['allowed' => true]]);
        }

        return $user;
    }

    public function test_the_waiting_worksafe_item_is_only_for_people_who_can_read_it_at_their_sites(): void
    {
        $site = Site::factory()->create();
        $other = Site::factory()->create();
        HsEvent::factory()->create(['site_id' => $site->id, 'worksafe_notifiable' => true, 'status' => 'open']);
        HsEvent::factory()->create(['site_id' => $other->id, 'worksafe_notifiable' => true, 'status' => 'open']);

        $this->actingAs($this->viewer(['hazards.view'], $site))->get('/health-safety/reports')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('health-safety/reports/index')
                ->where('worksafe_waiting.count', 1)
                ->has('worksafe_waiting.first'));

        $this->actingAs($this->viewer(['compliance.view'], $site))->get('/health-safety/reports')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('worksafe_waiting', null));

        $this->actingAs($this->viewer(['hazards.view', 'governance.view'], $site))->get('/health-safety/reports?view=board')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('view', 'board')->where('worksafe_waiting', null));
    }
}
