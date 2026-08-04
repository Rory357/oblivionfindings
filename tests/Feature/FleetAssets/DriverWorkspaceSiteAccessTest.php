<?php

use App\Domain\Hr\Models\HrDriverEligibility;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Asset;
use App\Models\FleetDriverSession;
use App\Models\FleetDrivingMetric;
use App\Models\FleetSignal;
use App\Models\FleetTrip;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

it('scopes the driver register assignments sessions and hero totals to current staff at accessible Sites', function () {
    $localSite = Site::factory()->create(['name' => 'Local Fleet Site']);
    $otherSite = Site::factory()->create(['name' => 'Other Fleet Site']);
    [$viewer] = driverWorkspaceStaffAt($localSite, ['fleet.viewAny']);
    [$localDriver] = driverWorkspaceStaffAt($localSite);
    [$otherDriver] = driverWorkspaceStaffAt($otherSite);
    [$endedDriver] = driverWorkspaceStaffAt($localSite, [], [
        'end_date' => now()->subDay()->toDateString(),
    ]);
    driverWorkspaceEligibility($localDriver, ['status' => 'eligible']);
    driverWorkspaceEligibility($otherDriver, ['status' => 'eligible']);
    driverWorkspaceEligibility($endedDriver, ['status' => 'eligible']);

    $localVehicle = driverWorkspaceVehicleAt($localSite, $localDriver, 'Local assigned van');
    $otherVehicle = driverWorkspaceVehicleAt($otherSite, $localDriver, 'Hidden assigned van');
    driverWorkspaceSession($localDriver, $localVehicle);
    driverWorkspaceSession($localDriver, $otherVehicle);
    driverWorkspaceSession($otherDriver, $otherVehicle);

    $this->actingAs($viewer)
        ->get(route('fleet-assets.drivers.index', [], false))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('fleet-assets/drivers/index')
            ->where('hero.total', 1)
            ->where('hero.active', 1)
            ->where('hero.sessions_today', 1)
            ->has('drivers.data', 1)
            ->where('drivers.data.0.id', $localDriver->id)
            ->where('drivers.data.0.assigned_vehicles', fn ($vehicles) => collect($vehicles)->pluck('id')->all() === [$localVehicle->id])
            ->where('drivers.data.0.session_count', 1));
});

it('keeps shared-vehicle aggregates unknown while preserving driver-session trips and signals', function () {
    $localSite = Site::factory()->create(['name' => 'Driver home Site']);
    $otherSite = Site::factory()->create(['name' => 'Inaccessible Fleet Site']);
    [$viewer] = driverWorkspaceStaffAt($localSite, ['fleet.viewAny', 'hr.employees.viewAny']);
    [$driver, $driverProfile] = driverWorkspaceStaffAt($localSite);
    [$sharedVehicleDriver] = driverWorkspaceStaffAt($localSite);
    [$otherDriver] = driverWorkspaceStaffAt($otherSite);
    [$endedDriver] = driverWorkspaceStaffAt($localSite, [], [
        'end_date' => now()->subDay()->toDateString(),
    ]);
    driverWorkspaceEligibility($driver);
    driverWorkspaceEligibility($sharedVehicleDriver);
    driverWorkspaceEligibility($otherDriver);
    driverWorkspaceEligibility($endedDriver);

    $localVehicle = driverWorkspaceVehicleAt($localSite, $driver, 'Visible driver vehicle');
    $otherVehicle = driverWorkspaceVehicleAt($otherSite, $driver, 'Hidden driver vehicle');
    $localSession = driverWorkspaceSession($driver, $localVehicle);
    $sharedVehicleSession = driverWorkspaceSession($sharedVehicleDriver, $localVehicle);
    $otherSession = driverWorkspaceSession($driver, $otherVehicle);
    driverWorkspaceMetric($localVehicle, 84);
    driverWorkspaceMetric($otherVehicle, 11);
    $localSignal = driverWorkspaceSignal($driver, $localVehicle, $localSession, 'harsh_brake');
    driverWorkspaceSignal($sharedVehicleDriver, $localVehicle, $sharedVehicleSession, 'speeding');
    $localTrip = driverWorkspaceTrip($localVehicle, $localSession);
    driverWorkspaceTrip($otherVehicle, $otherSession);

    $this->actingAs($viewer)
        ->get(route('fleet-assets.drivers.show', $driver, false).'?tab=scorecard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('fleet-assets/drivers/show')
            ->where('driver.id', $driver->id)
            ->where('driver.hr_profile_id', $driverProfile->id)
            ->where('driver.hr_profile_href', '/hr/people/'.$driverProfile->id)
            ->where('assigned_vehicles', fn ($vehicles) => collect($vehicles)->pluck('id')->all() === [$localVehicle->id])
            ->where('sessions', fn ($sessions) => collect($sessions)->pluck('id')->all() === [$localSession->id])
            ->has('driving_metrics', 0)
            ->where('recent_trips', fn ($trips) => collect($trips)->pluck('id')->all() === [$localTrip->id])
            ->where('scorecard.score', null)
            ->where('scorecard.previous_score', null)
            ->where('scorecard.fleet_avg_score', null)
            ->where('scorecard.metrics.harsh_brakes', null)
            ->where('scorecard.metrics.hard_accels', null)
            ->where('scorecard.metrics.speeding_events', null)
            ->where('scorecard.metrics.idle_minutes', null)
            ->where('scorecard.recent_events', fn ($events) => collect($events)->pluck('id')->all() === [$localSignal->id]));

    $this->actingAs($viewer)
        ->get(route('fleet-assets.drivers.show', $otherDriver, false))
        ->assertNotFound();
    $this->actingAs($viewer)
        ->get(route('fleet-assets.drivers.scorecard', $otherDriver, false))
        ->assertNotFound();
    $this->actingAs($viewer)
        ->get(route('fleet-assets.drivers.show', $endedDriver, false))
        ->assertNotFound();
});

it('exports the same filtered sorted Site-visible driver register', function () {
    $localSite = Site::factory()->create(['name' => 'CSV Fleet Site']);
    $otherSite = Site::factory()->create(['name' => 'Hidden CSV Site']);
    [$viewer] = driverWorkspaceStaffAt($localSite, ['fleet.viewAny']);
    [$alpha] = driverWorkspaceStaffAt($localSite);
    [$zulu] = driverWorkspaceStaffAt($localSite);
    [$suspended] = driverWorkspaceStaffAt($localSite);
    [$hidden] = driverWorkspaceStaffAt($otherSite);
    $alpha->update(['name' => 'Export Alpha', 'email' => 'alpha-export@example.test']);
    $zulu->update(['name' => 'Export Zulu', 'email' => 'zulu-export@example.test']);
    $suspended->update(['name' => 'Export Suspended', 'email' => 'suspended-export@example.test']);
    $hidden->update(['name' => 'Export Hidden', 'email' => 'zz-hidden-export@example.test']);
    driverWorkspaceEligibility($alpha, ['status' => 'eligible']);
    driverWorkspaceEligibility($zulu, ['status' => 'eligible']);
    driverWorkspaceEligibility($suspended, ['status' => 'suspended']);
    driverWorkspaceEligibility($hidden, ['status' => 'eligible']);

    $response = $this->actingAs($viewer)->get(
        route('fleet-assets.drivers.index', [], false)
            .'?export=csv&search=Export&status=eligible&sort=email&direction=desc',
    );

    $response->assertOk();
    $content = $response->streamedContent();
    $rows = array_map(
        'str_getcsv',
        preg_split('/\r\n|\r|\n/', trim($content)) ?: [],
    );

    expect($rows)->toHaveCount(3)
        ->and($rows[0])->toBe(['Name', 'Email', 'Licence Class', 'Licence Expires', 'Status', 'Can Drive Clients'])
        ->and(array_slice($rows[1], 0, 2))->toBe(['Export Zulu', 'zulu-export@example.test'])
        ->and(array_slice($rows[2], 0, 2))->toBe(['Export Alpha', 'alpha-export@example.test']);
    expect($content)
        ->not->toContain('Export Suspended')
        ->not->toContain('Export Hidden');
});

it('represents missing safety telemetry as unknown instead of a zero score', function () {
    $site = Site::factory()->create();
    [$viewer] = driverWorkspaceStaffAt($site, ['fleet.viewAny']);
    [$driver] = driverWorkspaceStaffAt($site);
    driverWorkspaceEligibility($driver);

    $this->actingAs($viewer)
        ->get(route('fleet-assets.drivers.show', $driver, false).'?tab=scorecard')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('fleet-assets/drivers/show')
            ->has('driving_metrics', 0)
            ->where('driver.hr_profile_href', null)
            ->where('scorecard.score', null)
            ->where('scorecard.previous_score', null)
            ->where('scorecard.fleet_avg_score', null));
});

/**
 * @param  list<string>  $permissionKeys
 * @param  array<string, mixed>  $profileOverrides
 * @return array{0: User, 1: HrEmployeeProfile}
 */
function driverWorkspaceStaffAt(Site $site, array $permissionKeys = [], array $profileOverrides = []): array
{
    $staff = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $profile = HrEmployeeProfile::factory()->create([
        'user_id' => $staff->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => now()->subYear()->toDateString(),
        'end_date' => null,
        'is_active' => true,
        ...$profileOverrides,
    ]);

    if ($permissionKeys !== []) {
        $role = Role::query()->create([
            'name' => 'driver_workspace_'.str()->uuid(),
            'label' => 'Driver workspace test role',
            'level' => 50,
            'type' => 'custom',
        ]);
        foreach ($permissionKeys as $permissionKey) {
            Permission::query()->firstOrCreate(
                ['key' => $permissionKey],
                ['description' => $permissionKey, 'group' => 'Fleet', 'module' => 'Fleet'],
            );
        }
        $role->permissions()->sync(
            Permission::query()->whereIn('key', $permissionKeys)->pluck('id'),
        );
        $staff->roles()->attach($role->id);
    }

    return [$staff, $profile];
}

/** @param array<string, mixed> $overrides */
function driverWorkspaceEligibility(User $driver, array $overrides = []): HrDriverEligibility
{
    return HrDriverEligibility::query()->create([
        'user_id' => $driver->id,
        'licence_number' => 'DL-'.$driver->id,
        'licence_class' => '1',
        'licence_expires_at' => now()->addYear()->toDateString(),
        'can_drive_clients' => true,
        'status' => 'eligible',
        ...$overrides,
    ]);
}

function driverWorkspaceVehicleAt(Site $site, User $driver, string $name): Asset
{
    return Asset::factory()->vehicle()->create([
        'site_id' => $site->id,
        'home_site_id' => $site->id,
        'primary_driver_user_id' => $driver->id,
        'name' => $name,
    ]);
}

function driverWorkspaceSession(User $driver, Asset $vehicle): FleetDriverSession
{
    return FleetDriverSession::query()->create([
        'asset_id' => $vehicle->id,
        'user_id' => $driver->id,
        'started_at' => now()->subHour(),
        'ended_at' => now()->subMinutes(10),
        'source' => 'manual',
        'status' => 'closed',
    ]);
}

function driverWorkspaceMetric(Asset $vehicle, int $score): FleetDrivingMetric
{
    return FleetDrivingMetric::query()->create([
        'asset_id' => $vehicle->id,
        'period_start' => now()->subDay()->toDateString(),
        'period_end' => now()->toDateString(),
        'harsh_brake_count' => 1,
        'accel_count' => 2,
        'speeding_events' => 1,
        'idle_minutes' => 12,
        'score' => $score,
    ]);
}

function driverWorkspaceTrip(Asset $vehicle, FleetDriverSession $session): FleetTrip
{
    return FleetTrip::factory()->create([
        'asset_id' => $vehicle->id,
        'driver_session_id' => $session->id,
        'started_at' => $session->started_at,
        'ended_at' => $session->ended_at,
        'status' => 'closed',
    ]);
}

function driverWorkspaceSignal(
    User $driver,
    Asset $vehicle,
    FleetDriverSession $session,
    string $type,
): FleetSignal {
    return FleetSignal::query()->create([
        'asset_id' => $vehicle->id,
        'driver_session_id' => $session->id,
        'signal_type' => $type,
        'severity_hint' => 'medium',
        'occurred_at' => now()->subMinutes(5),
        'idempotency_key' => hash('sha256', 'driver-workspace-'.$driver->id.'-'.$type.'-'.str()->uuid()),
        'payload' => [],
    ]);
}
