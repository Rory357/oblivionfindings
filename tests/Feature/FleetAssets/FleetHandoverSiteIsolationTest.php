<?php

namespace Tests\Feature\FleetAssets;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\FleetShiftHandover;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FleetHandoverSiteIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected Site $siteA;

    protected Site $siteB;

    protected Asset $vehicleA;

    protected Asset $vehicleB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\RbacSeeder::class);
        $this->travelTo(Carbon::parse('2026-04-07 10:00:00'));

        if (! Schema::hasTable('fleet_shift_handovers')) {
            Schema::create('fleet_shift_handovers', function (Blueprint $table) {
                $table->id();
                $table->foreignId('asset_id')->constrained('assets');
                $table->foreignId('outgoing_user_id')->constrained('users');
                $table->foreignId('incoming_user_id')->nullable()->constrained('users');
                $table->integer('odometer_km')->nullable();
                $table->string('fuel_level')->nullable();
                $table->string('exterior_condition');
                $table->string('interior_condition');
                $table->boolean('keys_present')->default(true);
                $table->boolean('documents_present')->default(true);
                $table->boolean('first_aid_kit')->default(true);
                $table->boolean('fire_extinguisher')->default(true);
                $table->json('damage_notes')->nullable();
                $table->text('notes')->nullable();
                $table->string('status')->default('pending_acceptance');
                $table->timestamp('handed_over_at');
                $table->timestamp('accepted_at')->nullable();
                $table->timestamps();
            });
        }

        $this->siteA = Site::factory()->create(['name' => 'Harbour House']);
        $this->siteB = Site::factory()->create(['name' => 'Forest House']);

        $this->vehicleA = Asset::factory()->vehicle()->create([
            'site_id' => $this->siteA->id,
            'name' => 'Van Alpha',
            'registration_number' => 'HH-001',
        ]);

        $this->vehicleB = Asset::factory()->vehicle()->create([
            'site_id' => $this->siteB->id,
            'name' => 'Van Bravo',
            'registration_number' => 'FH-002',
        ]);
    }

    public function test_user_sees_only_fleet_handovers_from_accessible_sites(): void
    {
        $user = $this->makeSiteScopedUser([$this->siteA], ['fleet.viewAny']);

        $visibleHandover = FleetShiftHandover::create([
            'asset_id' => $this->vehicleA->id,
            'outgoing_user_id' => $user->id,
            'exterior_condition' => 'good',
            'interior_condition' => 'clean',
            'status' => 'pending_acceptance',
            'handed_over_at' => now(),
        ]);

        FleetShiftHandover::create([
            'asset_id' => $this->vehicleB->id,
            'outgoing_user_id' => $user->id,
            'exterior_condition' => 'good',
            'interior_condition' => 'clean',
            'status' => 'pending_acceptance',
            'handed_over_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->get('/fleet-assets/handovers');

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('fleet-assets/handovers/index')
            ->where('handovers.meta.total', 1)
            ->where('handovers.data.0.id', $visibleHandover->id)
        );
    }

    public function test_user_cannot_view_fleet_handover_from_another_site(): void
    {
        $user = $this->makeSiteScopedUser([$this->siteA], ['fleet.viewAny']);

        $foreignHandover = FleetShiftHandover::create([
            'asset_id' => $this->vehicleB->id,
            'outgoing_user_id' => $user->id,
            'exterior_condition' => 'good',
            'interior_condition' => 'clean',
            'status' => 'pending_acceptance',
            'handed_over_at' => now(),
        ]);

        $this->actingAs($user)
            ->get("/fleet-assets/handovers/{$foreignHandover->id}")
            ->assertForbidden();
    }

    public function test_user_cannot_view_fleet_handover_show_from_another_site(): void
    {
        $user = $this->makeSiteScopedUser([$this->siteA], ['fleet.viewAny', 'assets.viewAny']);

        $foreignHandover = FleetShiftHandover::create([
            'asset_id' => $this->vehicleB->id,
            'outgoing_user_id' => User::factory()->create()->id,
            'incoming_user_id' => $user->id,
            'exterior_condition' => 'good',
            'interior_condition' => 'clean',
            'status' => 'pending_acceptance',
            'handed_over_at' => now(),
        ]);

        $this->actingAs($user)
            ->get("/fleet-assets/handovers/{$foreignHandover->id}")
            ->assertForbidden();
    }

    public function test_fleet_manager_can_access_any_site_handover(): void
    {
        $manager = $this->makeBypassUser(['fleet.viewAny', 'fleet.manage']);

        $handover = FleetShiftHandover::create([
            'asset_id' => $this->vehicleB->id,
            'outgoing_user_id' => $manager->id,
            'incoming_user_id' => $manager->id,
            'exterior_condition' => 'good',
            'interior_condition' => 'clean',
            'status' => 'pending_acceptance',
            'handed_over_at' => now(),
        ]);

        $this->actingAs($manager)
            ->get("/fleet-assets/handovers/{$handover->id}")
            ->assertOk();

        $this->actingAs($manager)
            ->post("/fleet-assets/handovers/{$handover->id}/accept")
            ->assertRedirect();
    }

    public function test_multi_site_user_sees_handovers_from_both_sites(): void
    {
        $user = $this->makeSiteScopedUser([$this->siteA, $this->siteB], ['fleet.viewAny']);

        FleetShiftHandover::create([
            'asset_id' => $this->vehicleA->id,
            'outgoing_user_id' => $user->id,
            'exterior_condition' => 'good',
            'interior_condition' => 'clean',
            'status' => 'pending_acceptance',
            'handed_over_at' => now(),
        ]);

        FleetShiftHandover::create([
            'asset_id' => $this->vehicleB->id,
            'outgoing_user_id' => $user->id,
            'exterior_condition' => 'good',
            'interior_condition' => 'clean',
            'status' => 'pending_acceptance',
            'handed_over_at' => now()->subHour(),
        ]);

        $response = $this->actingAs($user)
            ->get('/fleet-assets/handovers');

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('fleet-assets/handovers/index')
            ->where('handovers.meta.total', 2)
        );
    }

    public function test_fleet_manager_bypasses_site_scope(): void
    {
        $manager = $this->makeBypassUser(['fleet.viewAny', 'fleet.manage']);

        FleetShiftHandover::create([
            'asset_id' => $this->vehicleA->id,
            'outgoing_user_id' => $manager->id,
            'exterior_condition' => 'good',
            'interior_condition' => 'clean',
            'status' => 'pending_acceptance',
            'handed_over_at' => now(),
        ]);

        FleetShiftHandover::create([
            'asset_id' => $this->vehicleB->id,
            'outgoing_user_id' => $manager->id,
            'exterior_condition' => 'good',
            'interior_condition' => 'clean',
            'status' => 'pending_acceptance',
            'handed_over_at' => now()->subHour(),
        ]);

        $response = $this->actingAs($manager)
            ->get('/fleet-assets/handovers');

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('fleet-assets/handovers/index')
            ->where('handovers.meta.total', 2)
        );
    }

    public function test_vehicle_dropdown_is_scoped_to_accessible_sites(): void
    {
        $user = $this->makeSiteScopedUser([$this->siteA], ['fleet.viewAny']);

        $response = $this->actingAs($user)
            ->get('/fleet-assets/handovers');

        $response->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('fleet-assets/handovers/index')
            ->has('vehicles', 1)
            ->where('vehicles.0.id', $this->vehicleA->id)
        );
    }

    public function test_home_site_id_grants_access_when_site_id_is_null(): void
    {
        $vehicleHomeSite = Asset::factory()->vehicle()->create([
            'site_id' => null,
            'home_site_id' => $this->siteA->id,
            'name' => 'Van Charlie',
        ]);

        $user = $this->makeSiteScopedUser([$this->siteA], ['fleet.viewAny']);

        $handover = FleetShiftHandover::create([
            'asset_id' => $vehicleHomeSite->id,
            'outgoing_user_id' => $user->id,
            'exterior_condition' => 'good',
            'interior_condition' => 'clean',
            'status' => 'pending_acceptance',
            'handed_over_at' => now(),
        ]);

        $this->actingAs($user)
            ->get("/fleet-assets/handovers/{$handover->id}")
            ->assertOk();
    }

    /**
     * @param  array<int, Site>  $sites
     * @param  array<int, string>  $permissionKeys
     */
    protected function makeSiteScopedUser(array $sites, array $permissionKeys): User
    {
        $user = User::factory()->create([
            'approved_at' => now(),
            'role' => 'support_worker',
        ]);

        $this->grantPermissions($user, $permissionKeys);

        HrEmployeeProfile::query()->create([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'employee_number' => 'EMP-FH-'.$user->id,
            'work_email' => $user->email,
            'position_title' => 'Support Worker',
            'position_role' => 'support_worker',
            'employment_type' => 'full_time',
            'start_date' => now()->subMonth()->toDateString(),
            'is_active' => true,
            'primary_site_id' => $sites[0]->id ?? null,
            'secondary_site_ids' => collect($sites)->skip(1)->pluck('id')->values()->all(),
        ]);

        return $user;
    }

    /**
     * @param  array<int, string>  $permissionKeys
     */
    protected function makeBypassUser(array $permissionKeys): User
    {
        $user = User::factory()->create([
            'approved_at' => now(),
            'role' => 'admin',
        ]);

        $this->grantPermissions($user, $permissionKeys);

        return $user;
    }

    /**
     * @param  array<int, string>  $permissionKeys
     */
    protected function grantPermissions(User $user, array $permissionKeys): void
    {
        $permissionIds = collect($permissionKeys)
            ->map(function (string $key) {
                $module = str($key)->before('.')->value() ?: 'fleet';

                return Permission::query()->firstOrCreate(
                    ['key' => $key],
                    [
                        'description' => $key,
                        'group' => $module,
                        'module' => $module,
                    ],
                )->id;
            })
            ->all();

        $permissionMap = collect($permissionIds)
            ->mapWithKeys(fn ($id) => [(int) $id => ['allowed' => true]])
            ->all();

        $user->permissionOverrides()->syncWithoutDetaching($permissionMap);
    }
}
