<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('hr payroll index loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/payroll')
            ->waitForText('Payroll', 10)
            ->assertPathIs('/hr/payroll');
    });
});

test('hr payslips page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/hr/payroll/payslips')
            ->waitForText('Payslip', 10)
            ->assertPathIs('/hr/payroll/payslips');
    });
});
