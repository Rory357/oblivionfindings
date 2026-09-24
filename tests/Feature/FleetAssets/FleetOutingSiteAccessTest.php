<?php

namespace Tests\Feature\FleetAssets;

use App\Domain\Hr\Models\HrDriverEligibility;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\Client;
use App\Models\FleetOuting;
use App\Models\FleetOutingResident;
use App\Models\FleetVehicleBooking;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * FA-T01: community outings belong to the viewer's approved Sites. An outing
 * is visible only when its vehicle is inside the Fleet boundary and it
 * carries at least one resident the viewer may see; resident rows, driver
 * names, counts and pickers follow the same boundary, a foreign outing
 * answers exactly like a missing one, and `fleet.manage` is the explicit
 * all-Sites bypass.
 */
class FleetOutingSiteAccessTest extends TestCase
{
    use RefreshDatabase;

    private Site $localSite;

    private Site $otherSite;

    private Asset $localVehicle;

    private Asset $otherVehicle;

    private Client $localResident;

    private Client $otherResident;

    private User $localDriver;

    private User $otherDriver;

    private FleetOuting $localOuting;

    private FleetOuting $mixedOuting;

    private FleetOuting $otherOuting;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->travelTo(Carbon::parse('2026-09-15 12:00:00', config('app.timezone')));

        $this->localSite = $this->house('Harbour House');
        $this->otherSite = $this->house('Forest House');
        $this->localVehicle = $this->vehicleAt($this->localSite, 'Harbour Van');
        $this->otherVehicle = $this->vehicleAt($this->otherSite, 'Forest Van');
        $this->localResident = $this->residentAt($this->localSite, 'Harbour');
        $this->otherResident = $this->residentAt($this->otherSite, 'Forest');
        $this->localDriver = $this->driverAt($this->localSite, 'Harbour Driver');
        $this->otherDriver = $this->driverAt($this->otherSite, 'Forest Driver');

        // Harbour's own planned outing.
        $this->localOuting = $this->outing('Harbour beach walk', $this->localVehicle, $this->localDriver, $this->localDriver, 'planned',
            now()->addDay(), [$this->localResident]);
        // A shared outing in the Harbour van, driven and planned by the Forest
        // driver, carrying one resident from each house.
        $this->mixedOuting = $this->outing('Joint market trip', $this->localVehicle, $this->otherDriver, $this->otherDriver, 'active',
            now()->subHour(), [$this->localResident, $this->otherResident]);
        // Forest's own outing, already past its planned return.
        $this->otherOuting = $this->outing('Forest picnic', $this->otherVehicle, $this->otherDriver, $this->otherDriver, 'active',
            now()->subHours(3), [$this->otherResident]);

        FleetVehicleBooking::factory()->create([
            'asset_id' => $this->otherVehicle->id,
            'user_id' => $this->otherDriver->id,
            'status' => 'checked_out',
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subDay(),
        ]);
    }

    public function test_site_viewer_lists_counts_and_picks_only_their_sites(): void
    {
        $viewer = $this->staffAt($this->localSite, ['fleet.viewAny']);

        $this->actingAs($viewer)
            ->get('/fleet-assets/outings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('fleet-assets/outings/index')
                ->where('outings.meta.total', 2)
                ->where('outings.data', fn ($rows): bool => collect($rows)->pluck('id')->all()
                    === [$this->localOuting->id, $this->mixedOuting->id])
                ->where('outings.data.0.driver.name', 'Harbour Driver')
                ->where('outings.data.0.resident_count', 1)
                // The Forest driver is not staff this viewer can see, and the
                // Forest resident is neither named nor counted.
                ->where('outings.data.1.driver', null)
                ->where('outings.data.1.resident_count', 1)
                ->where('stats.outings_this_week', 2)
                ->where('stats.residents_this_week', 2)
                ->where('stats.upcoming', 1)
                ->where('hero.planned_today', 0)
                ->where('hero.active_now', 1)
                ->where('hero.residents_out_now', 1)
                ->where('hero.past_return', 0)
                ->where('hero.overdue_returns', 0)
                ->where('chart_data', fn ($days): bool => collect($days)->sum('value') === 2)
                ->where('clients', fn ($clients): bool => collect($clients)->pluck('id')->all() === [$this->localResident->id])
                ->where('vehicles', fn ($vehicles): bool => collect($vehicles)->pluck('id')->all() === [$this->localVehicle->id])
                ->where('drivers', fn ($drivers): bool => collect($drivers)->pluck('name')->all() === ['Harbour Driver']));
    }

    public function test_site_viewer_detail_shows_only_visible_residents_and_staff(): void
    {
        $viewer = $this->staffAt($this->localSite, ['fleet.viewAny']);

        $this->actingAs($viewer)
            ->get("/fleet-assets/outings/{$this->mixedOuting->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('fleet-assets/outings/show')
                ->where('outing.id', $this->mixedOuting->id)
                ->has('outing.residents', 1)
                ->where('outing.residents.0.client_id', $this->localResident->id)
                ->where('outing.driver', null)
                ->where('outing.created_by', null));

        $this->actingAs($viewer)
            ->get("/fleet-assets/outings/{$this->localOuting->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('outing.driver.name', 'Harbour Driver')
                ->where('outing.created_by.name', 'Harbour Driver'));

        foreach ($this->hiddenOutingIds() as $hiddenId) {
            $this->actingAs($viewer)
                ->get("/fleet-assets/outings/{$hiddenId}")
                ->assertNotFound();
        }
    }

    public function test_foreign_outing_mutations_answer_like_missing_ones_and_change_nothing(): void
    {
        $manager = $this->staffAt($this->localSite, ['fleet.viewAny', 'fleet.outings.manage']);
        $foreignRow = $this->residentRow($this->otherOuting, $this->otherResident);

        foreach ($this->hiddenOutingIds() as $hiddenId) {
            foreach (['start', 'complete', 'cancel', 'residents/return-all', "residents/{$foreignRow->id}/return"] as $action) {
                $this->actingAs($manager)
                    ->post("/fleet-assets/outings/{$hiddenId}/{$action}")
                    ->assertNotFound();
            }
        }

        $this->assertSame('active', $this->otherOuting->fresh()->status);
        $this->assertNull($foreignRow->fresh()->returned_at);

        // On a shared outing the Forest resident's row is as hidden as a
        // foreign outing: it cannot be returned, and return-all skips it.
        $mixedForeignRow = $this->residentRow($this->mixedOuting, $this->otherResident);
        $mixedLocalRow = $this->residentRow($this->mixedOuting, $this->localResident);

        $this->actingAs($manager)
            ->post("/fleet-assets/outings/{$this->mixedOuting->id}/residents/{$mixedForeignRow->id}/return")
            ->assertNotFound();

        $this->actingAs($manager)
            ->post("/fleet-assets/outings/{$this->mixedOuting->id}/residents/return-all")
            ->assertRedirect();

        $this->assertNotNull($mixedLocalRow->fresh()->returned_at);
        $this->assertNull($mixedForeignRow->fresh()->returned_at);

        // Everyone must be back before completion, without naming or counting
        // the resident this viewer cannot see.
        $this->actingAs($manager)
            ->post("/fleet-assets/outings/{$this->mixedOuting->id}/complete")
            ->assertRedirect()
            ->assertSessionHas('error', 'Cannot complete outing: residents from another Site have not been marked as returned yet.');
        $this->assertSame('active', $this->mixedOuting->fresh()->status);

        $this->actingAs($manager)
            ->post("/fleet-assets/outings/{$this->localOuting->id}/cancel")
            ->assertRedirect()
            ->assertSessionHas('success');
        $this->assertSame('cancelled', $this->localOuting->fresh()->status);
    }

    public function test_store_naming_a_foreign_vehicle_resident_or_driver_fails_with_zero_writes(): void
    {
        $manager = $this->staffAt($this->localSite, ['fleet.viewAny', 'fleet.outings.manage']);
        $missingVehicleId = (int) Asset::query()->max('id') + 1000;
        $missingClientId = (int) Client::query()->max('id') + 1000;
        $missingUserId = (int) User::query()->max('id') + 1000;
        $before = $this->writeCounts();

        foreach ([$this->otherVehicle->id, $missingVehicleId] as $vehicleId) {
            $this->actingAs($manager)
                ->from('/fleet-assets/outings')
                ->post('/fleet-assets/outings', $this->outingPayload(['asset_id' => $vehicleId]))
                ->assertSessionHasErrors(['asset_id' => 'The selected vehicle is not available.']);
        }

        foreach ([$this->otherResident->id, $missingClientId] as $clientId) {
            $this->actingAs($manager)
                ->from('/fleet-assets/outings')
                ->post('/fleet-assets/outings', $this->outingPayload(['resident_ids' => [$this->localResident->id, $clientId]]))
                ->assertSessionHasErrors(['resident_ids.1' => 'A selected resident is not available.']);
        }

        foreach ([$this->otherDriver->id, $missingUserId] as $driverId) {
            $this->actingAs($manager)
                ->from('/fleet-assets/outings')
                ->post('/fleet-assets/outings', $this->outingPayload(['driver_user_id' => $driverId]))
                ->assertSessionHasErrors(['driver_user_id' => 'The selected driver is not available.']);
        }

        // An outing with no vehicle or no residents would be outside every
        // viewer's boundary, so both are required.
        $this->actingAs($manager)
            ->post('/fleet-assets/outings', $this->outingPayload(['asset_id' => null]))
            ->assertSessionHasErrors(['asset_id' => 'Choose a vehicle for the outing.']);
        $this->actingAs($manager)
            ->post('/fleet-assets/outings', $this->outingPayload(['resident_ids' => []]))
            ->assertSessionHasErrors(['resident_ids' => 'Choose at least one resident for the outing.']);

        $this->assertSame($before, $this->writeCounts());

        $response = $this->actingAs($manager)
            ->post('/fleet-assets/outings', $this->outingPayload(['driver_user_id' => $this->localDriver->id]))
            ->assertSessionHasNoErrors();

        $created = FleetOuting::query()->where('title', 'Harbour library visit')->sole();
        $response->assertRedirect(route('fleet-assets.outings.show', $created));
        $this->assertSame($this->localVehicle->id, (int) $created->asset_id);
        $this->assertSame([$this->localResident->id], $created->residents()->pluck('client_id')->map(fn ($id) => (int) $id)->all());
        $this->assertNotNull($created->booking_id);

        $this->actingAs($manager)
            ->get("/fleet-assets/outings/{$created->id}")
            ->assertOk();
    }

    public function test_viewer_without_a_current_site_sees_nothing(): void
    {
        // Without a current HR Site there is nothing to show or plan: the boundary fails closed.
        $unplaced = User::factory()->create(['approved_at' => now()]);
        $this->grant($unplaced, ['fleet.viewAny', 'fleet.outings.manage']);
        $before = $this->writeCounts();

        $this->actingAs($unplaced)
            ->get('/fleet-assets/outings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('outings.meta.total', 0)
                ->has('outings.data', 0)
                ->where('stats.outings_this_week', 0)
                ->where('stats.residents_this_week', 0)
                ->where('stats.upcoming', 0)
                ->where('hero.active_now', 0)
                ->where('hero.residents_out_now', 0)
                ->where('hero.past_return', 0)
                ->where('hero.overdue_returns', 0)
                ->has('clients', 0)
                ->has('vehicles', 0)
                ->has('drivers', 0));

        foreach ([$this->localOuting, $this->mixedOuting, $this->otherOuting] as $outing) {
            $this->actingAs($unplaced)
                ->get("/fleet-assets/outings/{$outing->id}")
                ->assertNotFound();
            $this->actingAs($unplaced)
                ->post("/fleet-assets/outings/{$outing->id}/cancel")
                ->assertNotFound();
        }

        $this->actingAs($unplaced)
            ->post('/fleet-assets/outings', $this->outingPayload())
            ->assertSessionHasErrors(['asset_id', 'resident_ids.0']);

        $this->assertSame($before, $this->writeCounts());
    }

    public function test_fleet_manage_is_the_explicit_all_sites_bypass(): void
    {
        $fleetManager = $this->staffAt($this->localSite, ['fleet.viewAny', 'fleet.manage']);

        $this->actingAs($fleetManager)
            ->get('/fleet-assets/outings')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('outings.meta.total', 3)
                ->where('outings.data.1.id', $this->mixedOuting->id)
                ->where('outings.data.1.driver.name', 'Forest Driver')
                ->where('outings.data.1.resident_count', 2)
                ->where('stats.residents_this_week', 4)
                ->where('hero.active_now', 2)
                ->where('hero.residents_out_now', 3)
                ->where('hero.past_return', 1)
                ->where('hero.overdue_returns', 1)
                ->where('clients', fn ($clients): bool => collect($clients)->pluck('id')->sort()->values()->all()
                    === collect([$this->localResident->id, $this->otherResident->id])->sort()->values()->all())
                ->where('vehicles', fn ($vehicles): bool => collect($vehicles)->pluck('id')->sort()->values()->all()
                    === collect([$this->localVehicle->id, $this->otherVehicle->id])->sort()->values()->all())
                ->where('drivers', fn ($drivers): bool => collect($drivers)->pluck('name')->all()
                    === ['Forest Driver', 'Harbour Driver']));

        $this->actingAs($fleetManager)
            ->get("/fleet-assets/outings/{$this->otherOuting->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('outing.residents', 1)
                ->where('outing.driver.name', 'Forest Driver'));

        $this->actingAs($fleetManager)
            ->get("/fleet-assets/outings/{$this->mixedOuting->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('outing.residents', 2));

        $this->actingAs($fleetManager)
            ->post('/fleet-assets/outings', $this->outingPayload([
                'title' => 'Forest pool visit',
                'asset_id' => $this->otherVehicle->id,
                'resident_ids' => [$this->otherResident->id],
                'driver_user_id' => $this->otherDriver->id,
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertTrue(FleetOuting::query()->where('title', 'Forest pool visit')->exists());
    }

    /** @return list<int> */
    private function hiddenOutingIds(): array
    {
        return [$this->otherOuting->id, (int) FleetOuting::query()->withTrashed()->max('id') + 1000];
    }

    /** @return array<string, int> */
    private function writeCounts(): array
    {
        return [
            'outings' => FleetOuting::query()->withTrashed()->count(),
            'residents' => FleetOutingResident::query()->count(),
            'bookings' => FleetVehicleBooking::query()->count(),
        ];
    }

    /** @param array<string, mixed> $overrides */
    private function outingPayload(array $overrides = []): array
    {
        return [
            'title' => 'Harbour library visit',
            'destination' => 'Harbour library',
            'purpose' => 'community',
            'planned_departure' => now()->addDays(2)->toDateTimeString(),
            'planned_return' => now()->addDays(2)->addHours(2)->toDateTimeString(),
            'asset_id' => $this->localVehicle->id,
            'resident_ids' => [$this->localResident->id],
            ...$overrides,
        ];
    }

    /** @param list<Client> $residents */
    private function outing(string $title, Asset $vehicle, User $driver, User $createdBy, string $status, Carbon $departure, array $residents): FleetOuting
    {
        $outing = FleetOuting::query()->create([
            'title' => $title,
            'destination' => "{$title} destination",
            'purpose' => 'community',
            'planned_departure' => $departure,
            'planned_return' => $departure->copy()->addHours(2),
            'actual_departure' => $status === 'active' ? $departure : null,
            'asset_id' => $vehicle->id,
            'driver_user_id' => $driver->id,
            'status' => $status,
            'created_by_user_id' => $createdBy->id,
        ]);

        foreach ($residents as $resident) {
            FleetOutingResident::query()->create([
                'outing_id' => $outing->id,
                'client_id' => $resident->id,
                'pre_check_completed' => true,
                'medication_packed' => true,
            ]);
        }

        return $outing;
    }

    private function residentRow(FleetOuting $outing, Client $resident): FleetOutingResident
    {
        return FleetOutingResident::query()
            ->where('outing_id', $outing->id)
            ->where('client_id', $resident->id)
            ->sole();
    }

    private function driverAt(Site $site, string $name): User
    {
        $driver = $this->staffAt($site, [], $name);
        HrDriverEligibility::query()->create([
            'user_id' => $driver->id,
            'licence_number' => 'DL-'.$driver->id,
            'licence_class' => '1',
            'licence_expires_at' => now()->addYear()->toDateString(),
            'can_drive_clients' => true,
            'status' => 'eligible',
        ]);

        return $driver;
    }

    /** @param list<string> $permissionKeys */
    private function staffAt(Site $site, array $permissionKeys = [], ?string $name = null): User
    {
        $user = User::factory()->create([
            ...($name ? ['name' => $name] : []),
            'role' => 'support_worker',
            'approved_at' => now(),
        ]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subMonth(),
            'end_date' => null,
            'is_active' => true,
        ]);
        $this->grant($user, $permissionKeys);

        return $user;
    }

    /** @param list<string> $permissionKeys */
    private function grant(User $user, array $permissionKeys): void
    {
        foreach ($permissionKeys as $permissionKey) {
            $permission = Permission::query()->firstOrCreate(
                ['key' => $permissionKey],
                ['description' => $permissionKey, 'group' => 'fleet', 'module' => 'Fleet'],
            );

            $user->permissionOverrides()->syncWithoutDetaching([
                $permission->id => ['allowed' => true],
            ]);
        }
    }

    private function house(string $name): Site
    {
        return Site::factory()->create([
            'name' => $name,
            'type' => 'house',
            'is_active' => true,
            'archived' => false,
            'archived_at' => null,
        ]);
    }

    private function vehicleAt(Site $site, string $name): Asset
    {
        return Asset::factory()->vehicle()->create([
            'site_id' => $site->id,
            'home_site_id' => $site->id,
            'client_id' => null,
            'category' => 'vehicle',
            'status' => 'active',
            'name' => $name,
        ]);
    }

    private function residentAt(Site $site, string $firstName): Client
    {
        return Client::factory()->create([
            'site_id' => $site->id,
            'status' => 'active',
            'first_name' => $firstName,
            'last_name' => 'Resident',
        ]);
    }
}
