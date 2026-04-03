<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('finance profit and loss report loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/reports/profit-loss')
            ->waitForText('Profit', 10)
            ->assertSee('Profit');
    });
});

test('finance balance sheet report loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/reports/balance-sheet')
            ->waitForText('Balance Sheet', 10)
            ->assertSee('Balance Sheet');
    });
});

test('finance trial balance report loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/reports/trial-balance')
            ->waitForText('Trial Balance', 10)
            ->assertSee('Trial Balance');
    });
});

test('finance cash flow report loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/reports/cash-flow')
            ->waitForText('Cash Flow', 10)
            ->assertSee('Cash Flow');
    });
});

test('finance aged payables report loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/reports/aged-payables')
            ->waitForText('Aged Payables', 10)
            ->assertSee('Aged Payables');
    });
});

test('finance aged receivables report loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/reports/aged-receivables')
            ->waitForText('Aged Receivables', 10)
            ->assertSee('Aged Receivables');
    });
});

test('finance budget vs actuals report loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/reports/budget-vs-actuals')
            ->waitForText('Budget', 10)
            ->assertSee('Budget');
    });
});

test('finance funding stream summary report loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/reports/funding-stream-summary')
            ->waitForText('Funding', 10)
            ->assertSee('Funding');
    });
});
