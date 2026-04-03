<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('hr expenses index loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/expenses')
            ->waitForText('Expense', 10)
            ->assertPathIs('/hr/expenses');
    });
});

test('hr expenses create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/expenses/create')
            ->waitForText('Expense', 10)
            ->assertPathIs('/hr/expenses/create');
    });
});
