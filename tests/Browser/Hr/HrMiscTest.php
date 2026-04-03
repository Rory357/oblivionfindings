<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('hr calendar page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/calendar')
            ->waitForText('Calendar', 10)
            ->assertPathIs('/hr/calendar');
    });
});

test('hr import-export page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/import-export')
            ->waitForText('Import', 10)
            ->assertPathIs('/hr/import-export');
    });
});

test('hr succession page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/succession')
            ->waitForText('Succession', 10)
            ->assertPathIs('/hr/succession');
    });
});

test('hr skills page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/skills')
            ->waitForText('Skill', 10)
            ->assertPathIs('/hr/skills');
    });
});

test('hr skills matrix page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/skills/matrix')
            ->waitForText('Matrix', 10)
            ->assertPathIs('/hr/skills/matrix');
    });
});

test('hr wellbeing page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/wellbeing')
            ->waitForText('Wellbeing', 10)
            ->assertPathIs('/hr/wellbeing');
    });
});

test('hr feedback page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/feedback')
            ->waitForText('Feedback', 10)
            ->assertPathIs('/hr/feedback');
    });
});

test('hr reports page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/reports')
            ->waitForText('Report', 10)
            ->assertPathIs('/hr/reports');
    });
});

test('hr my-profile page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/my/profile')
            ->waitForText('Profile', 10)
            ->assertPathIs('/hr/my/profile');
    });
});

test('hr my-leave page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/my/leave')
            ->waitForText('Leave', 10)
            ->assertPathIs('/hr/my/leave');
    });
});

test('hr my-time page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/my/time')
            ->waitForText('Time', 10)
            ->assertPathIs('/hr/my/time');
    });
});

test('hr my-goals page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/my/goals')
            ->waitForText('Goal', 10)
            ->assertPathIs('/hr/my/goals');
    });
});

test('hr my-training page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/my/training')
            ->waitForText('Training', 10)
            ->assertPathIs('/hr/my/training');
    });
});

test('hr my-reviews page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/my/reviews')
            ->waitForText('Review', 10)
            ->assertPathIs('/hr/my/reviews');
    });
});

test('hr my-payslips page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/my/payslips')
            ->waitForText('Payslip', 10)
            ->assertPathIs('/hr/my/payslips');
    });
});

test('hr my-policies page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/my/policies')
            ->waitForText('Polic', 10)
            ->assertPathIs('/hr/my/policies');
    });
});

test('hr my-surveys page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/my/surveys')
            ->waitForText('Survey', 10)
            ->assertPathIs('/hr/my/surveys');
    });
});

test('hr approvals chains page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/approvals/chains')
            ->waitForText('Approv', 10)
            ->assertPathBeginsWith('/hr');
    });
});

test('hr approvals pending page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/approvals/pending')
            ->waitForText('Pending', 10)
            ->assertPathBeginsWith('/hr');
    });
});

test('hr assets index page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/assets')
            ->waitForText('Asset', 10)
            ->assertPathIs('/hr/assets');
    });
});

test('hr assets create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/assets/create')
            ->waitForText('Asset', 10)
            ->assertPathIs('/hr/assets/create');
    });
});

test('hr calendar time-off page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/calendar/time-off')
            ->waitForText('Time', 10)
            ->assertPathBeginsWith('/hr/calendar');
    });
});

test('hr departments page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/departments')
            ->waitForText('Department', 10)
            ->assertPathIs('/hr/departments');
    });
});

test('hr feedback request page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/feedback/request')
            ->waitForText('Feedback', 10)
            ->assertPathBeginsWith('/hr/feedback');
    });
});

test('hr my page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/my')
            ->waitForText('My', 10)
            ->assertPathBeginsWith('/hr/my');
    });
});

test('hr reports automations page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/reports/automations')
            ->waitForText('Automation', 10)
            ->assertPathBeginsWith('/hr/reports');
    });
});

test('hr reports builder page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/reports/builder')
            ->waitForText('Report', 10)
            ->assertPathBeginsWith('/hr/reports');
    });
});

test('hr reports generate page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/reports/generate')
            ->waitForText('Report', 10)
            ->assertPathBeginsWith('/hr/reports');
    });
});

test('hr reports saved page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/reports/saved')
            ->waitForText('Saved', 10)
            ->assertPathBeginsWith('/hr/reports');
    });
});

test('hr reports webhooks page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/reports/webhooks')
            ->waitForText('Webhook', 10)
            ->assertPathBeginsWith('/hr/reports');
    });
});

test('hr signatures pending page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/signatures/pending')
            ->waitForText('Signature', 10)
            ->assertPathBeginsWith('/hr/signatures');
    });
});

test('hr training catalog page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/training/catalog')
            ->waitForText('Training', 10)
            ->assertPathBeginsWith('/hr/training');
    });
});

test('hr succession create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/succession/create')
            ->waitForText('Succession', 10)
            ->assertPathBeginsWith('/hr/succession');
    });
});

test('hr feedback summary page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/feedback/summary/' . $user->id)
            ->waitForText('Feedback', 10)
            ->assertPathBeginsWith('/hr/feedback');
    });
});
