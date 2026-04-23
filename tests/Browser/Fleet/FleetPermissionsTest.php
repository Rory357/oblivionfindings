<?php

use App\Models\Asset;
use App\Models\Client;
use App\Models\FleetVehicleBooking;
use App\Models\ControlRoomAlert;
use App\Models\FleetOuting;
use App\Models\FleetOutingResident;
use App\Models\Permission;
use App\Models\User;
use Laravel\Dusk\Browser;

function fleetBrowserUser(string $email, string $name, array $permissionKeys): User
{
    $user = User::query()->firstWhere('email', $email);

    if (! $user) {
        $user = User::factory()
            ->withoutTwoFactor()
            ->create([
                'email' => $email,
                'name' => $name,
                'approved_at' => now(),
                'role' => 'qa',
            ]);
    }

    foreach ($permissionKeys as $key) {
        $permission = Permission::firstOrCreate(
            ['key' => $key],
            [
                'description' => str_replace('.', ' ', $key),
                'group' => explode('.', $key)[0],
            ],
        );

        $user->permissionOverrides()->syncWithoutDetaching([
            $permission->id => ['allowed' => true],
        ]);
    }

    return $user->fresh();
}

function fleetBrowserVehicle(): Asset
{
    $vehicle = Asset::query()
        ->whereRaw('LOWER(category) = ?', ['vehicle'])
        ->orderBy('id')
        ->first();

    if ($vehicle) {
        return $vehicle;
    }

    return Asset::factory()->create([
        'category' => 'vehicle',
    ]);
}

test('fleet fuel logging action is hidden from read-only viewers', function () {
    $viewer = fleetBrowserUser('fleet-viewer@test.com', 'Fleet Viewer QA', [
        'fleet.viewAny',
    ]);

    $this->browse(function (Browser $browser) use ($viewer) {
        $browser->loginAs($viewer)
            ->visit('/fleet-assets/fuel')
            ->waitForText('Fuel Management', 10)
            ->assertSee('Fuel Management')
            ->assertDontSee('Log Fuel');
    });
});

test('fleet managers can still open the fuel logging dialog', function () {
    $manager = fleetBrowserUser('fleet-manager@test.com', 'Fleet Manager QA', [
        'fleet.viewAny',
        'fleet.manage',
        'fleet.maintenance.manage',
    ]);

    $this->browse(function (Browser $browser) use ($manager) {
        $browser->loginAs($manager)
            ->visit('/fleet-assets/fuel')
            ->waitForText('Fuel Management', 10)
            ->assertSee('Log Fuel')
            ->press('Log Fuel')
            ->waitForText('Log Fuel Fill-up', 10)
            ->assertSee('Record a fuel purchase for a vehicle.');
    });
});

test('fleet inspection entry points are hidden from read-only viewers', function () {
    $viewer = fleetBrowserUser('fleet-viewer@test.com', 'Fleet Viewer QA', [
        'fleet.viewAny',
    ]);

    $manager = fleetBrowserUser('fleet-manager@test.com', 'Fleet Manager QA', [
        'fleet.viewAny',
        'fleet.manage',
        'fleet.maintenance.manage',
    ]);

    $vehicle = fleetBrowserVehicle();

    $this->browse(function (Browser $viewerBrowser, Browser $managerBrowser) use ($viewer, $manager, $vehicle) {
        $viewerBrowser->loginAs($viewer)
            ->visit('/fleet-assets/mobile/dashboard')
            ->waitFor('@fleet-mobile-dashboard-heading', 10)
            ->assertDontSee('Start Inspection')
            ->assertSee('Daily Vehicle Check');

        $managerBrowser->loginAs($manager)
            ->visit('/fleet-assets/mobile/dashboard')
            ->waitFor('@fleet-mobile-dashboard-heading', 10)
            ->assertSee('Start Inspection');

        $viewerBrowser->visit("/fleet-assets/vehicles/{$vehicle->id}")
            ->waitForText($vehicle->name, 10)
            ->assertDontSee('Start Pre-Trip Inspection')
            ->assertSee('Starting inspections requires fleet maintenance manager access.');

        $managerBrowser->visit("/fleet-assets/vehicles/{$vehicle->id}")
            ->waitForText($vehicle->name, 10)
            ->assertSee('Start Pre-Trip Inspection');
    });
});

test('fleet booking workflow actions stay manager-only', function () {
    $viewer = fleetBrowserUser('fleet-viewer@test.com', 'Fleet Viewer QA', [
        'fleet.viewAny',
    ]);

    $manager = fleetBrowserUser('fleet-manager@test.com', 'Fleet Manager QA', [
        'fleet.viewAny',
        'fleet.manage',
        'fleet.maintenance.manage',
    ]);

    $vehicle = fleetBrowserVehicle();

    $booking = FleetVehicleBooking::factory()->create([
        'asset_id' => $vehicle->id,
        'user_id' => $viewer->id,
        'purpose' => 'QA pending booking',
        'starts_at' => now()->addDay(),
        'ends_at' => now()->addDay()->addHours(3),
        'status' => 'pending',
    ]);

    $this->browse(function (Browser $viewerBrowser, Browser $managerBrowser) use ($viewer, $manager, $booking) {
        $viewerBrowser->loginAs($viewer)
            ->visit("/fleet-assets/bookings/{$booking->id}")
            ->waitForText("Booking #{$booking->id}", 10)
            ->assertSee('Booking workflow actions require fleet manager access.');

        $managerBrowser->loginAs($manager)
            ->visit("/fleet-assets/bookings/{$booking->id}")
            ->waitForText("Booking #{$booking->id}", 10)
            ->assertSee('Awaiting Approval')
            ->assertSee('Approve')
            ->assertSee('Reject');
    });
});

test('fleet vehicle bulk actions stay manager-only', function () {
    $viewer = fleetBrowserUser('fleet-viewer@test.com', 'Fleet Viewer QA', [
        'fleet.viewAny',
    ]);

    $manager = fleetBrowserUser('fleet-manager@test.com', 'Fleet Manager QA', [
        'fleet.viewAny',
        'fleet.manage',
    ]);

    $this->browse(function (Browser $viewerBrowser, Browser $managerBrowser) use ($viewer, $manager) {
        $viewerBrowser->loginAs($viewer)
            ->visit('/fleet-assets/vehicles')
            ->waitForText('Vehicles', 10)
            ->assertSee('Vehicles')
            ->assertDontSee('Select all');

        $managerBrowser->loginAs($manager)
            ->visit('/fleet-assets/vehicles')
            ->waitForText('Vehicles', 10)
            ->assertSee('Vehicles')
            ->assertSee('Select all');
    });
});

test('fleet alert actions stay manager-only', function () {
    $viewer = fleetBrowserUser('fleet-viewer@test.com', 'Fleet Viewer QA', [
        'fleet.viewAny',
        'assets.viewAny',
    ]);

    $manager = fleetBrowserUser('fleet-manager@test.com', 'Fleet Manager QA', [
        'fleet.viewAny',
        'assets.viewAny',
        'fleet.manage',
        'assets.alerts.manage',
    ]);

    $fleetAlert = ControlRoomAlert::factory()->create([
        'source' => 'fleet',
        'status' => 'open',
        'alert_type' => 'Geofence Exit',
    ]);

    $client = Client::query()->orderBy('id')->first() ?? Client::factory()->create();
    $wanderingAlert = ControlRoomAlert::factory()->create([
        'source' => 'resident_tracker',
        'client_id' => $client->id,
        'status' => 'open',
        'alert_type' => 'wandering',
    ]);

    $this->browse(function (Browser $viewerBrowser, Browser $managerBrowser) use ($viewer, $manager, $fleetAlert, $wanderingAlert) {
        $viewerBrowser->loginAs($viewer)
            ->visit('/fleet-assets/alerts')
            ->waitForText('Alerts', 10)
            ->assertSee('Alerts')
            ->assertDontSee('Acknowledge')
            ->assertDontSee('Resolve');

        $managerBrowser->loginAs($manager)
            ->visit('/fleet-assets/alerts')
            ->waitForText('Alerts', 10)
            ->assertSee('Acknowledge')
            ->assertSee('Resolve');

        $viewerBrowser->visit('/fleet-assets/wandering-alerts')
            ->waitForText('Wandering Alerts', 10)
            ->assertSee('Wandering Alerts')
            ->assertDontSee('Ack')
            ->assertDontSee('Resolve');

        $managerBrowser->visit('/fleet-assets/wandering-alerts')
            ->waitForText('Wandering Alerts', 10)
            ->assertSee('Ack')
            ->assertSee('Resolve');
    });
});

test('fleet outing detail actions stay manager-only', function () {
    $viewer = fleetBrowserUser('fleet-viewer@test.com', 'Fleet Viewer QA', [
        'fleet.viewAny',
        'assets.viewAny',
    ]);

    $manager = fleetBrowserUser('fleet-manager@test.com', 'Fleet Manager QA', [
        'fleet.viewAny',
        'assets.viewAny',
        'fleet.manage',
        'fleet.outings.manage',
    ]);

    $vehicle = fleetBrowserVehicle();

    $plannedOuting = FleetOuting::factory()->create([
        'asset_id' => $vehicle->id,
        'status' => 'planned',
        'title' => 'QA Planned Outing',
        'created_by_user_id' => $manager->id,
    ]);

    $plannedResident = Client::query()->orderBy('id')->first() ?? Client::factory()->create();
    FleetOutingResident::query()->create([
        'outing_id' => $plannedOuting->id,
        'client_id' => $plannedResident->id,
        'pre_check_completed' => true,
        'medication_packed' => true,
    ]);

    $activeOuting = FleetOuting::factory()->create([
        'asset_id' => $vehicle->id,
        'status' => 'active',
        'title' => 'QA Active Outing',
        'actual_departure' => now()->subHour(),
        'created_by_user_id' => $manager->id,
    ]);

    $activeResidents = Client::query()->orderBy('id')->take(2)->get();
    while ($activeResidents->count() < 2) {
        $activeResidents->push(Client::factory()->create());
    }

    foreach ($activeResidents as $resident) {
        FleetOutingResident::query()->create([
            'outing_id' => $activeOuting->id,
            'client_id' => $resident->id,
            'pre_check_completed' => true,
            'medication_packed' => true,
        ]);
    }

    $this->browse(function (Browser $viewerBrowser, Browser $managerBrowser) use ($viewer, $manager, $plannedOuting, $activeOuting) {
        $viewerBrowser->loginAs($viewer)
            ->visit("/fleet-assets/outings/{$plannedOuting->id}")
            ->waitForText('QA Planned Outing', 10)
            ->assertSee('Outing updates are view-only for your account.')
            ->assertDontSee('Start Outing')
            ->assertDontSee('Cancel');

        $managerBrowser->loginAs($manager)
            ->visit("/fleet-assets/outings/{$plannedOuting->id}")
            ->waitForText('QA Planned Outing', 10)
            ->assertSee('Start Outing')
            ->assertSee('Cancel');

        $viewerBrowser->visit("/fleet-assets/outings/{$activeOuting->id}")
            ->waitForText('QA Active Outing', 10)
            ->assertSee('Outing updates are view-only for your account.')
            ->assertDontSee('Return All (2)')
            ->assertDontSee('Mark Returned')
            ->assertDontSee('Complete (0/2 returned)');

        $managerBrowser->visit("/fleet-assets/outings/{$activeOuting->id}")
            ->waitForText('QA Active Outing', 10)
            ->assertSee('Return All (2)')
            ->assertSee('Mark Returned')
            ->assertSee('Complete (0/2 returned)');
    });
});
