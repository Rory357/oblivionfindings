<?php

namespace Tests\Feature\Emar;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * eMAR Lane C8 — the Meds today links only lead where the viewer can go
 * (EA-044, EA-168). Synthetic users only.
 */
class NavigationLaneC8Test extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-10-09 09:30', 'Pacific/Auckland')->utc());
        $this->seed(RbacSeeder::class);
        $this->site = Site::factory()->create(['is_active' => true]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_handover_button_shows_only_for_people_who_can_open_handovers(): void
    {
        // EA-044: a house lead without handover access was sent to a 403.
        $lead = $this->user('team_lead', ['medications.view', 'medications.administer.record'],
            deny: ['handovers.viewAny', 'shifts.viewAny', 'shifts.manageAny', 'shifts.viewAssigned', 'shifts.update', 'handovers.create']);
        $this->actingAs($lead)->get('/meds/today')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('board_extra_can.view_handovers', false));
        $this->actingAs($lead)->get('/emar/handovers')->assertForbidden();

        $handovers = $this->user('team_lead', ['medications.view', 'medications.administer.record', 'shifts.viewAssigned']);
        $this->actingAs($handovers)->get('/meds/today')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('board_extra_can.view_handovers', true));
    }

    public function test_finance_only_users_do_not_get_the_mar_hub_link(): void
    {
        // EA-168: the board's "MAR charts" button opened a hub finance is kept out of.
        $finance = $this->user('finance', []);
        $this->actingAs($finance)->get('/meds/today')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('board_can.view_emar', false));

        $worker = $this->user('support_worker', ['medications.view', 'medications.administer.record']);
        $this->actingAs($worker)->get('/meds/today')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('board_can.view_emar', true));
    }

    /** @param list<string> $allow @param list<string> $deny */
    private function user(string $role, array $allow, array $deny = []): User
    {
        $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        $user->roles()->attach(Role::where('name', $role)->firstOrFail());
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => $this->site->id, 'secondary_site_ids' => [],
            'start_date' => now()->subYear(), 'end_date' => null, 'is_active' => true]);
        $overrides = Permission::whereIn('key', $allow)->pluck('id')->mapWithKeys(fn ($id) => [$id => ['allowed' => true]])
            ->union(Permission::whereIn('key', $deny)->pluck('id')->mapWithKeys(fn ($id) => [$id => ['allowed' => false]]));
        $user->permissionOverrides()->sync($overrides->all());

        return $user->fresh();
    }
}
