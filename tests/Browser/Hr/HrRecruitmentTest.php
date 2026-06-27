<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('hr recruitment index loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/recruitment')
            ->waitForText('Recruitment', 10)
            ->assertPathIs('/hr/recruitment');
    });
});

test('hr candidates index loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/recruitment/candidates')
            ->waitForText('Candidate', 10)
            ->assertPathBeginsWith('/hr/recruitment');
    });
});

test('hr candidates create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/recruitment/candidates/create')
            ->waitForText('Candidate', 10)
            ->assertPathIs('/hr/recruitment/candidates/create');
    });
});

// The standalone jobs/kanban/analytics/kits pages were retired into the unified
// Recruitment hub — their routes now redirect to /hr/recruitment?tab=...
test('retired recruitment tab pages redirect into the hub', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        foreach (['jobs', 'kanban', 'analytics', 'kits'] as $page) {
            $browser->loginAs($user)
                ->visit('/hr/recruitment/'.$page)
                ->waitForText('Recruitment', 10)
                ->assertPathIs('/hr/recruitment');
        }
    });
});
