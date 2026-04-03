<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('finance bank accounts index loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/bank-accounts')
            ->waitForText('Bank Accounts', 10)
            ->assertSee('Bank Accounts');
    });
});

test('finance bank accounts create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/bank-accounts/create')
            ->waitForText('Bank Account', 10)
            ->assertSee('Bank Account');
    });
});

test('finance bank feeds page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/bank-feeds')
            ->waitForText('Bank Feeds', 10)
            ->assertSee('Bank Feeds');
    });
});

test('finance bank reconciliation page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/bank-reconciliation')
            ->waitForText('Bank Reconciliation', 10)
            ->assertSee('Bank Reconciliation');
    });
});

test('finance bank transactions page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/bank-transactions')
            ->waitForText('Bank Transactions', 10)
            ->assertSee('Bank Transactions');
    });
});

test('finance bank reconciliation create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/bank-reconciliation/create')
            ->waitForText('Reconciliation', 10)
            ->assertPathBeginsWith('/finance');
    });
});
