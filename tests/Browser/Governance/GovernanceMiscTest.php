<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('governance documents page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/governance/documents')
            ->waitForText('Documents', 10)
            ->assertSee('Documents');
    });
});

test('governance strategy index loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/governance/strategy')
            ->waitForText('Strategy', 10)
            ->assertSee('Strategy');
    });
});

test('governance strategy create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/governance/strategy/create')
            ->waitForText('Strategy', 10)
            ->assertSee('Strategy');
    });
});

test('governance resolutions index loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/governance/resolutions')
            ->waitForText('Resolutions', 10)
            ->assertSee('Resolutions');
    });
});

test('governance resolutions create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/governance/resolutions/create')
            ->waitForText('Resolution', 10)
            ->assertSee('Resolution');
    });
});

test('governance evaluations index loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/governance/evaluations')
            ->waitForText('Evaluations', 10)
            ->assertSee('Evaluations');
    });
});

test('governance evaluations create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/governance/evaluations/create')
            ->waitForText('Evaluation', 10)
            ->assertSee('Evaluation');
    });
});

test('governance performance index loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/governance/performance')
            ->waitForText('Performance', 10)
            ->assertSee('Performance');
    });
});

test('governance performance create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/governance/performance/create')
            ->waitForText('Performance', 10)
            ->assertSee('Performance');
    });
});

test('governance budgets index loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/governance/budgets')
            ->waitForText('Budgets', 10)
            ->assertSee('Budgets');
    });
});

test('governance budgets create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/governance/budgets/create')
            ->waitForText('Budget', 10)
            ->assertSee('Budget');
    });
});

test('governance CEO reports index loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/governance/ceo-reports')
            ->waitForText('CEO Reports', 10)
            ->assertSee('CEO Reports');
    });
});

test('governance CEO reports create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/governance/ceo-reports/create')
            ->waitForText('CEO Report', 10)
            ->assertSee('CEO Report');
    });
});

test('governance interests page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/governance/interests')
            ->waitForText('Interests', 10)
            ->assertSee('Interests');
    });
});

test('governance te tiriti page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/governance/te-tiriti')
            ->waitForText('Te Tiriti', 10)
            ->assertSee('Te Tiriti');
    });
});

test('governance clinical page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/governance/clinical')
            ->waitForText('Clinical', 10)
            ->assertSee('Clinical');
    });
});

test('governance clinical trends page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/governance/clinical/trends')
            ->waitForText('Trends', 10)
            ->assertSee('Trends');
    });
});

test('governance actions page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/governance/actions')
            ->waitForText('Actions', 10)
            ->assertSee('Actions');
    });
});

test('governance admin board members page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/governance/admin/board-members')
            ->waitForText('Board', 10)
            ->assertPathBeginsWith('/governance');
    });
});

test('governance interests mine page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/governance/interests/mine')
            ->waitForText('Interest', 10)
            ->assertPathBeginsWith('/governance');
    });
});

test('governance reports board monthly page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/governance/reports/board-monthly')
            ->waitForText('Board', 10)
            ->assertPathBeginsWith('/governance');
    });
});

test('governance reports compliance status page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/governance/reports/compliance-status')
            ->waitForText('Compliance', 10)
            ->assertPathBeginsWith('/governance');
    });
});

test('governance reports risk narrative page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/governance/reports/risk-narrative')
            ->waitForText('Risk', 10)
            ->assertPathBeginsWith('/governance');
    });
});
