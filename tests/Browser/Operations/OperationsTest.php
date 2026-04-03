<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('operations index page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations')
            ->waitForText('Operation', 10)
            ->assertSee('Operation');
    });
});

test('operations clients index page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations/clients')
            ->waitForText('Client', 10)
            ->assertSee('Client');
    });
});

test('operations clients create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations/clients/create')
            ->waitForText('Client', 10)
            ->assertSee('Client');
    });
});

test('operations shifts page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations/shifts')
            ->waitForText('Shift', 10)
            ->assertSee('Shift');
    });
});

test('operations shifts create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations/shifts/create')
            ->waitForText('Shift', 10)
            ->assertSee('Shift');
    });
});

test('operations timesheets page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations/timesheets')
            ->waitForText('Timesheet', 10)
            ->assertSee('Timesheet');
    });
});

test('operations care plans page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations/care-plans')
            ->waitForText('Care Plan', 10)
            ->assertSee('Care Plan');
    });
});

test('operations care plans create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations/care-plans/create')
            ->waitForText('Care Plan', 10)
            ->assertSee('Care Plan');
    });
});

test('operations handovers page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations/handovers')
            ->waitForText('Handover', 10)
            ->assertSee('Handover');
    });
});

test('operations forms page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations/forms')
            ->waitForText('Form', 10)
            ->assertSee('Form');
    });
});

test('operations forms create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations/forms/create')
            ->waitForText('Form', 10)
            ->assertSee('Form');
    });
});

test('operations service agreements page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations/service-agreements')
            ->waitForText('Service Agreement', 10)
            ->assertSee('Service Agreement');
    });
});

test('operations service agreements create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations/service-agreements/create')
            ->waitForText('Service Agreement', 10)
            ->assertSee('Service Agreement');
    });
});

test('operations rostering page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations/rostering')
            ->waitForText('Roster', 10)
            ->assertSee('Roster');
    });
});

test('operations rostering templates page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations/rostering/templates')
            ->waitForText('Template', 10)
            ->assertSee('Template');
    });
});

test('operations rostering templates create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations/rostering/templates/create')
            ->waitForText('Template', 10)
            ->assertSee('Template');
    });
});

test('operations progress notes page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations/progress-notes')
            ->waitForText('Progress Note', 10)
            ->assertSee('Progress Note');
    });
});

test('operations shift notes page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations/shift-notes')
            ->waitForText('Shift Note', 10)
            ->assertSee('Shift Note');
    });
});

test('operations billing page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations/billing')
            ->waitForText('Billing', 10)
            ->assertSee('Billing');
    });
});

test('operations funding page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations/funding')
            ->waitForText('Funding', 10)
            ->assertSee('Funding');
    });
});

test('operations funding claims page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations/funding/claims')
            ->waitForText('Claim', 10)
            ->assertSee('Claim');
    });
});

test('operations invoices page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations/invoices')
            ->waitForText('Invoice', 10)
            ->assertSee('Invoice');
    });
});

test('operations quotes page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations/quotes')
            ->waitForText('Quote', 10)
            ->assertSee('Quote');
    });
});

test('operations price books page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations/price-books')
            ->waitForText('Price Book', 10)
            ->assertSee('Price Book');
    });
});

test('operations recurring charges page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations/recurring-charges')
            ->waitForText('Recurring', 10)
            ->assertSee('Recurring');
    });
});

test('operations job board page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations/job-board')
            ->waitForText('Job', 10)
            ->assertSee('Job');
    });
});

test('operations messages page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations/messages')
            ->waitForText('Message', 10)
            ->assertSee('Message');
    });
});

test('operations notifications page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations/notifications')
            ->waitForText('Notification', 10)
            ->assertSee('Notification');
    });
});

test('operations onboarding page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations/onboarding')
            ->waitForText('Onboarding', 10)
            ->assertSee('Onboarding');
    });
});

test('operations mileage page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations/mileage')
            ->waitForText('Mileage', 10)
            ->assertSee('Mileage');
    });
});

test('operations note templates page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations/note-templates')
            ->waitForText('Note Template', 10)
            ->assertSee('Note Template');
    });
});

test('operations summaries page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations/summaries')
            ->waitForText('Summar', 10)
            ->assertSee('Summar');
    });
});

test('operations timeline page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations/timeline')
            ->waitForText('Timeline', 10)
            ->assertSee('Timeline');
    });
});

test('operations availability page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations/availability')
            ->waitForText('Availability', 10)
            ->assertSee('Availability');
    });
});

test('operations calendar sync page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations/calendar-sync')
            ->waitForText('Calendar', 10)
            ->assertSee('Calendar');
    });
});

test('operations evv page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations/evv')
            ->waitForText('EVV', 10)
            ->assertSee('EVV');
    });
});

test('operations family portal page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations/family-portal')
            ->waitForText('Family', 10)
            ->assertSee('Family');
    });
});

test('operations geofences page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations/geofences')
            ->waitForText('Geofence', 10)
            ->assertSee('Geofence');
    });
});

test('operations payroll export page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations/payroll-export')
            ->waitForText('Payroll', 10)
            ->assertSee('Payroll');
    });
});

test('operations qualifications page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations/qualifications')
            ->waitForText('Qualification', 10)
            ->assertSee('Qualification');
    });
});

test('operations activity page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations/activity')
            ->waitForText('Activit', 10)
            ->assertPathIs('/operations/activity');
    });
});

test('operations billing entries page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations/billing/entries')
            ->waitForText('Entr', 10)
            ->assertPathIs('/operations/billing/entries');
    });
});

test('operations calendar sync create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations/calendar-sync/create')
            ->waitForText('Calendar', 10)
            ->assertPathIs('/operations/calendar-sync/create');
    });
});

test('operations client funds page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations/client-funds')
            ->waitForText('Fund', 10)
            ->assertPathIs('/operations/client-funds');
    });
});

test('operations client funds create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations/client-funds/create')
            ->waitForText('Fund', 10)
            ->assertPathIs('/operations/client-funds/create');
    });
});

test('operations geofences create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations/geofences/create')
            ->waitForText('Geofence', 10)
            ->assertPathIs('/operations/geofences/create');
    });
});

test('operations invoices create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations/invoices/create')
            ->waitForText('Invoice', 10)
            ->assertPathIs('/operations/invoices/create');
    });
});

test('operations mileage create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations/mileage/create')
            ->waitForText('Mileage', 10)
            ->assertPathIs('/operations/mileage/create');
    });
});

test('operations note templates create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations/note-templates/create')
            ->waitForText('Note Template', 10)
            ->assertPathIs('/operations/note-templates/create');
    });
});

test('operations onboarding create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations/onboarding/create')
            ->waitForText('Onboarding', 10)
            ->assertPathIs('/operations/onboarding/create');
    });
});

test('operations payroll export create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations/payroll-export/create')
            ->waitForText('Payroll', 10)
            ->assertPathIs('/operations/payroll-export/create');
    });
});

test('operations price books create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations/price-books/create')
            ->waitForText('Price Book', 10)
            ->assertPathIs('/operations/price-books/create');
    });
});

test('operations quotes create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations/quotes/create')
            ->waitForText('Quote', 10)
            ->assertPathIs('/operations/quotes/create');
    });
});

test('operations recurring charges create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations/recurring-charges/create')
            ->waitForText('Recurring', 10)
            ->assertPathIs('/operations/recurring-charges/create');
    });
});

test('operations reports page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations/reports')
            ->waitForText('Report', 10)
            ->assertPathIs('/operations/reports');
    });
});

test('operations rostering conflicts page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations/rostering/conflicts')
            ->waitForText('Conflict', 10)
            ->assertPathIs('/operations/rostering/conflicts');
    });
});

test('operations timesheets approvals page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations/timesheets/approvals')
            ->waitForText('Approval', 10)
            ->assertPathIs('/operations/timesheets/approvals');
    });
});

test('operations timesheets create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/operations/timesheets/create')
            ->waitForText('Timesheet', 10)
            ->assertPathIs('/operations/timesheets/create');
    });
});
