<?php

namespace Tests\Feature\ControlRoom;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\ControlRoom\TriageQueue;
use App\Models\ControlRoomAlert;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\ControlRoom\LiveResponseTabCounts;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The live counts on the Live response tabs use each tab's own rule. */
class LiveResponseTabCountsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_counts_follow_each_tab_and_the_viewers_sites(): void
    {
        $site = Site::factory()->create();
        $otherSite = Site::factory()->create();
        $viewer = $this->siteBoundUser($site, ['controlRoom.viewAny']);
        $queue = TriageQueue::create(['name' => 'Tier 2', 'code' => 'tab-count-t2', 'tier' => 2, 'is_active' => true]);
        $off = TriageQueue::create(['name' => 'Old', 'code' => 'tab-count-old', 'tier' => 3, 'is_active' => false]);
        $make = fn (array $attributes) => ControlRoomAlert::factory()->create(array_merge([
            'site_id' => $site->id,
            'status' => ControlRoomAlert::STATUS_OPEN,
            'snoozed_until' => null,
            'assigned_to_user_id' => null,
            'queue_id' => null,
            'created_by_user_id' => $viewer->id,
        ], $attributes));

        $make([]);                                                         // needs action
        $make(['assigned_to_user_id' => $viewer->id]);                     // needs action + mine
        $make(['queue_id' => $queue->id]);                                 // needs action + escalated
        $make(['queue_id' => $off->id]);                                   // needs action only (queue off)
        $make(['snoozed_until' => now()->addHour(), 'assigned_to_user_id' => $viewer->id]); // snoozed + mine
        $make(['status' => ControlRoomAlert::STATUS_RESOLVED, 'assigned_to_user_id' => $viewer->id]); // none
        $make(['site_id' => $otherSite->id, 'assigned_to_user_id' => $viewer->id]); // another Site: none

        $this->assertSame(
            ['needs_action' => 4, 'mine' => 2, 'escalated' => 1, 'snoozed' => 1],
            app(LiveResponseTabCounts::class)->for($viewer),
        );
    }

    public function test_live_response_pages_send_the_counts_and_refresh_them_on_their_own(): void
    {
        $site = Site::factory()->create();
        $viewer = $this->siteBoundUser($site, ['controlRoom.viewAny']);
        ControlRoomAlert::factory()->create([
            'site_id' => $site->id,
            'status' => ControlRoomAlert::STATUS_OPEN,
            'snoozed_until' => null,
            'created_by_user_id' => $viewer->id,
        ]);

        foreach (['/control-room', '/control-room/my-tasks', '/control-room/escalations', '/control-room/alerts?lens=snoozed', '/control-room/incidents'] as $url) {
            $this->actingAs($viewer)->get($url)->assertOk()
                ->assertInertia(fn ($page) => $page->where('tabCounts.needs_action', 1)->has('tabCounts.snoozed'));
        }

        $version = (string) app(HandleInertiaRequests::class)->version(request());
        $this->actingAs($viewer)
            ->get('/control-room', [
                'X-Inertia' => 'true',
                'X-Inertia-Version' => $version,
                'X-Inertia-Partial-Component' => 'control-room/index',
                'X-Inertia-Partial-Data' => 'tabCounts',
            ])
            ->assertOk()
            ->assertJsonPath('props.tabCounts.needs_action', 1)
            ->assertJsonMissingPath('props.worklist');
    }

    private function siteBoundUser(Site $site, array $permissionKeys): User
    {
        $user = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        $user->roles()->syncWithoutDetaching([
            Role::query()->where('name', 'support_worker')->firstOrFail()->id,
        ]);
        $permissionIds = Permission::query()->whereIn('key', $permissionKeys)->pluck('id');
        $user->permissionOverrides()->sync($permissionIds->mapWithKeys(
            fn ($permissionId) => [$permissionId => ['allowed' => true]],
        ));
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'position_role' => 'support_worker',
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        return $user;
    }
}
