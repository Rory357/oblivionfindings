<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Http\Controllers\HealthSafety\HealthSafetyDashboardController;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\HsCorrectiveAction;
use App\Models\HsEvent;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Step C (MASTER, 31 Oct): /health-safety shows first and fills its heavy parts from background requests. The first
 * response leaves those parts out; each background request returns its part after the same Site checks and locked read
 * as the whole page used to do, so who sees what is unchanged.
 */
class HealthSafetyOverviewDeferredTest extends TestCase
{
    use RefreshDatabase;

    private const ROLES = ['admin', 'health_safety_officer', 'provider_manager', 'team_lead'];

    public function test_the_first_response_leaves_the_heavy_parts_for_the_background(): void
    {
        [$own] = $this->world();
        foreach (self::ROLES as $role) {
            $response = $this->actingAs($this->viewer($role, $own))->get('/health-safety', $this->inertia())->assertOk();
            $props = $response->json('props');
            foreach (['filters', 'sites', 'worker_participation', 'lens', 'org_name', 'clients', 'staff', 'procedures'] as $key) {
                $this->assertArrayHasKey($key, $props, "{$role}: {$key} belongs in the first response.");
            }
            foreach (HealthSafetyDashboardController::OVERVIEW_DEFERRED as $group => $keys) {
                foreach ($keys as $key) {
                    $this->assertArrayNotHasKey($key, $props, "{$role}: {$key} loads in the background.");
                }
                $this->assertSame($keys, $response->json("deferredProps.{$group}"), "{$role}: group {$group}");
            }
        }
    }

    public function test_each_background_part_loads_with_the_viewers_own_sites_only(): void
    {
        [$own, $other] = $this->world();
        foreach (self::ROLES as $role) {
            $viewer = $this->viewer($role, $own);
            $first = $this->actingAs($viewer)->get('/health-safety', $this->inertia())->assertOk();
            foreach (HealthSafetyDashboardController::OVERVIEW_DEFERRED as $group => $keys) {
                $part = $this->part($viewer, $keys)->assertOk();
                foreach ($keys as $key) {
                    $this->assertArrayHasKey($key, $part->json('props'), "{$role}: {$key} arrives with its part.");
                }
                foreach (['filters', 'sites', 'worker_participation'] as $always) {
                    $this->assertSame($first->json("props.{$always}"), $part->json("props.{$always}"), "{$role}: {$always} comes with every part.");
                }
            }

            $actions = collect($this->part($viewer, ['worklists'])->json('props.worklists.overdue_corrective_actions'))->pluck('reference')->sort()->values()->all();
            $league = collect($this->part($viewer, ['site_league'])->json('props.site_league'))->pluck('id')->sort()->values()->all();
            if ($role === 'admin' || $role === 'health_safety_officer') {
                $this->assertSame(['CA-OTHER', 'CA-OWN'], $actions, "{$role} sees every Site.");
            } else {
                $this->assertSame(['CA-OWN'], $actions, "{$role} sees only their own Site's actions.");
                $this->assertNotContains($other->id, $league, "{$role} must not get another Site in the comparison.");
            }
        }
    }

    public function test_a_background_part_answers_like_the_page_for_someone_refused_it(): void
    {
        [$own] = $this->world();
        $noPermission = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $noPermission->roles()->attach(Role::where('name', 'support_worker')->sole());
        $noSite = User::factory()->create(['role' => 'team_lead', 'approved_at' => now()]);
        $noSite->roles()->attach(Role::where('name', 'team_lead')->sole());

        foreach (['no permission' => $noPermission, 'no Site' => $noSite] as $label => $user) {
            $this->actingAs($user)->get('/health-safety', $this->inertia())->assertForbidden();
            foreach (HealthSafetyDashboardController::OVERVIEW_DEFERRED as $keys) {
                $this->assertSame(403, $this->part($user, $keys)->status(), "{$label}: a background part is refused like the page.");
            }
        }
        $lead = $this->viewer('team_lead', $own);
        $page = $this->actingAs($lead)->get('/health-safety?site=999999', $this->inertia())->status();
        $this->assertGreaterThanOrEqual(400, $page);
        $this->assertSame($page, $this->part($lead, ['kpis'], '?site=999999')->status(),
            'A Site the viewer cannot see is refused for a background part exactly like the page.');
    }

    /** @return array{0: Site, 1: Site} */
    private function world(): array
    {
        $this->seed(RbacSeeder::class);
        $own = Site::factory()->create(['is_active' => true]);
        $other = Site::factory()->create(['is_active' => true]);
        $creator = User::factory()->create();
        foreach (['CA-OWN' => $own, 'CA-OTHER' => $other] as $reference => $site) {
            $event = HsEvent::factory()->create(['site_id' => $site->id, 'event_category' => 'hazard', 'created_by' => $creator->id]);
            HsCorrectiveAction::factory()->create(['hs_event_id' => $event->id, 'reference_number' => $reference, 'status' => HsCorrectiveAction::STATUS_OPEN,
                'priority' => 'medium', 'due_date' => now()->subDays(3)->toDateString(), 'created_by' => $creator->id]);
        }

        return [$own, $other];
    }

    private function viewer(string $role, Site $site): User
    {
        $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        $user->roles()->attach(Role::where('name', $role)->sole());
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'position_role' => $role, 'primary_site_id' => $site->id,
            'secondary_site_ids' => [], 'start_date' => today()->subYear(), 'end_date' => null, 'is_active' => true]);

        return $user->fresh();
    }

    /** @param  array<int, string>  $keys */
    private function part(User $viewer, array $keys, string $query = ''): TestResponse
    {
        return $this->actingAs($viewer)->get('/health-safety'.$query, [...$this->inertia(), 'X-Inertia-Partial-Component' => 'health-safety/dashboard',
            'X-Inertia-Partial-Data' => implode(',', $keys)]);
    }

    /** @return array<string, string> */
    private function inertia(): array
    {
        return ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) app(HandleInertiaRequests::class)->version(request())];
    }
}
