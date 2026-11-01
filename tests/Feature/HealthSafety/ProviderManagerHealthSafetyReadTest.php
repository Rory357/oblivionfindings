<?php

namespace Tests\Feature\HealthSafety;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Client;
use App\Models\ClientIncident;
use App\Models\HsEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Services\HealthSafety\HsEventAccessService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Provider managers read the H&S Overview and Safety reviews for their own Sites (Stephan, 30 Oct, option A).
 * Reading only; another Site stays out of reach and incident-linked records still need incident read.
 */
class ProviderManagerHealthSafetyReadTest extends TestCase
{
    use \Tests\Support\LoadsHealthSafetyOverview;

    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
    }

    public function test_a_provider_manager_reads_the_overview_and_safety_reviews_for_their_site_only(): void
    {
        [$manager, $site] = $this->managerAt();
        $own = $this->event($site);
        $other = $this->event(Site::factory()->create(['is_active' => true]));

        $this->actingAs($manager)->getOverview('/health-safety')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('health-safety/dashboard')
                // An empty near-miss tile says why instead of a bare dash: injury figures not shown to this role, or
                // no recordable injuries yet.
                ->where('leading_lagging.leading.near_miss_ratio', null)
                ->where('leading_lagging.leading.near_miss_ratio_reason',
                    $manager->canDo('hr.wellbeing.view') ? 'none' : 'restricted'));

        // Without wellbeing access the reason is "restricted".
        $wellbeing = Permission::where('key', 'hr.wellbeing.view')->sole();
        $manager->permissionOverrides()->syncWithoutDetaching([$wellbeing->id => ['allowed' => false]]);
        $this->actingAs($manager->fresh())->getOverview('/health-safety')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('leading_lagging.leading.near_miss_ratio_reason', 'restricted'));
        $manager->permissionOverrides()->detach($wellbeing->id);
        $manager = $manager->fresh();
        $this->actingAs($manager);

        $this->get('/health-safety/events')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('events.data', fn ($rows) => collect($rows)->pluck('id')->contains($own->id)
                    && collect($rows)->pluck('id')->doesntContain($other->id)));

        $this->get('/health-safety/events/'.$own->id)->assertOk();
        $this->assertContains($this->get('/health-safety/events/'.$other->id)->status(), [403, 404]);
    }

    public function test_the_grant_is_reading_only(): void
    {
        $keys = Role::where('name', 'provider_manager')->sole()->permissions()->pluck('key')->all();
        $this->assertContains('hazards.view', $keys);
        foreach (['healthSafety.viewAllSites', 'hazards.close', 'healthSafety.events.closeAny'] as $wider) {
            $this->assertNotContains($wider, $keys, 'provider_manager must not gain '.$wider);
        }
    }

    public function test_incident_linked_reviews_still_need_incident_read(): void
    {
        [$manager, $site] = $this->managerAt();
        $client = Client::factory()->create(['site_id' => $site->id]);
        $incident = ClientIncident::withoutEvents(fn () => ClientIncident::factory()->create([
            'site_id' => $site->id, 'client_id' => $client->id, 'status' => 'submitted', 'submitted_at' => now(),
            'reported_by' => $manager->id, 'occurred_at' => now(),
        ]));
        $linked = $this->event($site, ['source_type' => ClientIncident::class, 'source_id' => $incident->id, 'client_id' => $client->id, 'event_category' => 'incident']);
        $plain = $this->event($site);

        $access = app(HsEventAccessService::class);
        $this->assertTrue($access->canView($plain, $manager->fresh()));

        // Without incident read the incident-linked review is out of reach; the plain one stays readable.
        $deny = Permission::where('key', 'incidents.viewAny')->sole();
        $manager->permissionOverrides()->syncWithoutDetaching([$deny->id => ['allowed' => false]]);
        $this->assertFalse($access->canView($linked, $manager->fresh()));
        $this->assertTrue($access->canView($plain, $manager->fresh()));
    }

    public function test_the_migration_grants_exactly_hazards_view(): void
    {
        $role = Role::where('name', 'provider_manager')->sole();
        $key = Permission::where('key', 'hazards.view')->value('id');
        DB::table('role_permission')->where('role_id', $role->id)->where('permission_id', $key)->delete();
        $before = DB::table('role_permission')->where('role_id', $role->id)->count();

        (require database_path('migrations/2026_10_30_120000_grant_provider_manager_hazards_view.php'))->up();

        $this->assertSame($before + 1, DB::table('role_permission')->where('role_id', $role->id)->count());
        $this->assertContains('hazards.view', $role->permissions()->pluck('key')->all());
    }

    /** @return array{0: User, 1: Site} */
    private function managerAt(): array
    {
        $site = Site::factory()->create(['is_active' => true]);
        $user = User::factory()->create(['role' => 'provider_manager', 'approved_at' => now()]);
        $user->roles()->attach(Role::where('name', 'provider_manager')->sole());
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'position_role' => 'provider_manager',
            'primary_site_id' => $site->id, 'secondary_site_ids' => [],
            'start_date' => today()->subYear(), 'end_date' => null, 'is_active' => true]);

        return [$user->fresh(), $site];
    }

    private function event(Site $site, array $attributes = []): HsEvent
    {
        return HsEvent::factory()->create(['site_id' => $site->id, 'event_category' => 'hazard', ...$attributes]);
    }
}
