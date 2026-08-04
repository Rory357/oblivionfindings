<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('fleet assets index page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/fleet-assets')
            ->waitForText('Fleet', 10)
            ->assertSee('Fleet');
    });
});

test('fleet management page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/fleet-management')
            ->waitForText('Fleet', 10)
            ->assertSee('Fleet');
    });
});

test('fleet vehicles page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/fleet-assets/vehicles')
            ->waitForText('Vehicle', 10)
            ->assertSee('Vehicle');
    });
});

test('fleet bookings page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/fleet-assets/bookings')
            ->waitForText('Booking', 10)
            ->assertSee('Booking');
    });
});

test('fleet bookings create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/fleet-assets/bookings/create')
            ->waitForText('Booking', 10)
            ->assertSee('Booking');
    });
});

test('fleet compliance page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/fleet-assets/compliance')
            ->waitForText('Compliance', 10)
            ->assertSee('Compliance');
    });
});

test('fleet daily check page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/fleet-assets/daily-check')
            ->waitForText('Daily', 10)
            ->assertSee('Daily');
    });
});

test('fleet devices page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/fleet-assets/devices')
            ->waitForText('Device', 10)
            ->assertSee('Device');
    });
});

test('fleet drivers page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/fleet-assets/drivers')
            ->waitForText('Driver', 10)
            ->assertSee('Driver');
    });
});

test('fleet fuel page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/fleet-assets/fuel')
            ->waitForText('Fuel', 10)
            ->assertSee('Fuel');
    });
});

test('fleet geofences page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/fleet-assets/geofences')
            ->waitForText('Geofence', 10)
            ->assertSee('Geofence');
    });
});

test('fleet geofences create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/fleet-assets/geofences/create')
            ->waitForText('Geofence', 10)
            ->assertSee('Geofence');
    });
});

test('fleet handovers page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/fleet-assets/handovers')
            ->waitForText('Handover', 10)
            ->assertSee('Handover');
    });
});

test('fleet handovers create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/fleet-assets/handovers/create')
            ->waitForText('Handover', 10)
            ->assertSee('Handover');
    });
});

test('fleet incidents page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/fleet-assets/incidents')
            ->waitForText('Incident', 10)
            ->assertSee('Incident');
    });
});

test('fleet incidents create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/fleet-assets/incidents/create')
            ->waitForText('Incident', 10)
            ->assertSee('Incident');
    });
});

test('fleet inspections page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/fleet-assets/inspections')
            ->waitForText('Inspection', 10)
            ->assertSee('Inspection');
    });
});

test('fleet inspections create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/fleet-assets/inspections/create')
            ->waitForText('Inspection', 10)
            ->assertSee('Inspection');
    });
});

test('fleet keys page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/fleet-assets/keys')
            ->waitForText('Key', 10)
            ->assertSee('Key');
    });
});

test('fleet maintenance dashboard page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/fleet-assets/maintenance/dashboard')
            ->waitForText('Maintenance', 10)
            ->assertSee('Maintenance');
    });
});

test('fleet maintenance work orders page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/fleet-assets/maintenance/work-orders')
            ->waitForText('Work Order', 10)
            ->assertSee('Work Order');
    });
});

test('fleet maintenance checklists page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/fleet-assets/maintenance/checklists')
            ->waitForText('Checklist', 10)
            ->assertSee('Checklist');
    });
});

test('fleet maintenance schedules page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/fleet-assets/maintenance/schedules')
            ->waitForText('Schedule', 10)
            ->assertSee('Schedule');
    });
});

test('fleet map page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/fleet-assets/map')
            ->waitForText('Map', 10)
            ->assertSee('Map');
    });
});

test('fleet mileage page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/fleet-assets/mileage')
            ->waitForText('Mileage', 10)
            ->assertSee('Mileage');
    });
});

test('fleet mileage create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/fleet-assets/mileage/create')
            ->waitForText('Mileage', 10)
            ->assertSee('Mileage');
    });
});

test('fleet outings page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/fleet-assets/outings')
            ->waitForText('Outing', 10)
            ->assertSee('Outing');
    });
});

test('fleet outings create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/fleet-assets/outings/create')
            ->waitForText('Outing', 10)
            ->assertSee('Outing');
    });
});

test('fleet reports page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/fleet-assets/reports')
            ->waitForText('Report', 10)
            ->assertSee('Report');
    });
});

test('fleet transports page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/fleet-assets/transports')
            ->waitForText('Transport', 10)
            ->assertSee('Transport');
    });
});

test('fleet transports create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/fleet-assets/transports/create')
            ->waitForText('Transport', 10)
            ->assertSee('Transport');
    });
});

test('fleet alerts page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/fleet-assets/alerts')
            ->waitForText('Alert', 10)
            ->assertSee('Alert');
    });
});

test('fleet wandering alerts page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/fleet-assets/wandering-alerts')
            ->waitForText('Wandering', 10)
            ->assertSee('Wandering');
    });
});

test('fleet resident tracking page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/fleet-assets/resident-tracking')
            ->waitForText('Resident', 10)
            ->assertSee('Resident');
    });
});

test('legacy fleet mobile dashboard redirects to the desktop web workspace', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/fleet-assets/mobile/dashboard')
            ->waitForText('Fleet & Assets', 10)
            ->assertPathIs('/fleet-assets')
            ->assertDontSee('Mobile Dashboard');
    });
});

test('fleet trips page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/fleet-assets/trips')
            ->waitForText('Trip', 10)
            ->assertSee('Trip');
    });
});

test('fleet settings notifications page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/fleet-assets/settings/notifications')
            ->waitForText('Notification', 10)
            ->assertSee('Notification');
    });
});

test('fleet assets list page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/fleet-assets/assets')
            ->waitForText('Asset', 10)
            ->assertPathBeginsWith('/fleet');
    });
});

test('fleet assets create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/fleet-assets/assets/create')
            ->waitForText('Asset', 10)
            ->assertPathBeginsWith('/fleet');
    });
});

test('fleet reports by house page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/fleet-assets/reports/by-house')
            ->waitForText('House', 10)
            ->assertPathBeginsWith('/fleet');
    });
});

test('fleet reports community access page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/fleet-assets/reports/community-access')
            ->waitForText('Community', 10)
            ->assertPathBeginsWith('/fleet');
    });
});

test('fleet reports cost allocation page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/fleet-assets/reports/cost-allocation')
            ->waitForText('Cost', 10)
            ->assertPathBeginsWith('/fleet');
    });
});

test('fleet reports reimbursement page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/fleet-assets/reports/reimbursement')
            ->waitForText('Reimbursement', 10)
            ->assertPathBeginsWith('/fleet');
    });
});

test('fleet transports medications page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/fleet-assets/transports/medications')
            ->waitForText('Medication', 10)
            ->assertPathBeginsWith('/fleet');
    });
});

test('fleet resident tracking assign page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/fleet-assets/resident-tracking/assign')
            ->waitForText('Assign', 10)
            ->assertPathBeginsWith('/fleet');
    });
});

test('fleet management maps usage page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/fleet-management/maps-usage')
            ->waitForText('Map', 10)
            ->assertPathBeginsWith('/fleet');
    });
});

test('fleet fuel index page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/fleet/fuel')
            ->waitForText('Fuel', 10)
            ->assertPathBeginsWith('/fleet');
    });
});

test('fleet reports index page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/fleet/reports')
            ->waitForText('Report', 10)
            ->assertPathBeginsWith('/fleet');
    });
});
