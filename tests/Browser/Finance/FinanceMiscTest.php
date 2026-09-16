<?php

use App\Models\User;
use Laravel\Dusk\Browser;

test('finance vendors index loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/vendors')
            ->waitForText('Vendors', 10)
            ->assertSee('Vendors');
    });
});

test('finance vendors create URL redirects to the register', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/vendors/create')
            ->waitForText('Vendors', 10)
            ->assertSee('Vendors');
    });
});

test('finance purchase orders index loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/purchase-orders')
            ->waitForText('Purchase Orders', 10)
            ->assertSee('Purchase Orders');
    });
});

test('finance purchase orders create URL redirects to the register', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/purchase-orders/create')
            ->waitForText('Purchase orders', 10)
            ->assertSee('Purchase orders');
    });
});

test('finance payment runs page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/payment-runs')
            ->waitForText('Payment Runs', 10)
            ->assertSee('Payment Runs');
    });
});

test('finance petty cash page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/petty-cash')
            ->waitForText('Petty Cash', 10)
            ->assertSee('Petty Cash');
    });
});

test('finance cost centres page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/cost-centres')
            ->waitForText('Cost Centres', 10)
            ->assertSee('Cost Centres');
    });
});

test('finance currencies page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/currencies')
            ->waitForText('Currencies', 10)
            ->assertSee('Currencies');
    });
});

test('finance fiscal periods page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/fiscal-periods')
            ->waitForText('Fiscal Periods', 10)
            ->assertSee('Fiscal Periods');
    });
});

test('finance fixed assets page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/fixed-assets')
            ->waitForText('Fixed Assets', 10)
            ->assertSee('Fixed Assets');
    });
});

test('finance GST returns page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/gst-returns')
            ->waitForText('GST Returns', 10)
            ->assertSee('GST Returns');
    });
});

test('finance IRD filings page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/ird-filings')
            ->waitForText('IRD Filings', 10)
            ->assertSee('IRD Filings');
    });
});

test('finance integrations page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/integrations')
            ->waitForText('Integrations', 10)
            ->assertSee('Integrations');
    });
});

test('finance receivables page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/receivables')
            ->waitForText('Receivables', 10)
            ->assertSee('Receivables');
    });
});

test('finance credit notes page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/credit-notes')
            ->waitForText('Credit Notes', 10)
            ->assertSee('Credit Notes');
    });
});

test('finance donor funds page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/donor-funds')
            ->waitForText('Donor Funds', 10)
            ->assertSee('Donor Funds');
    });
});

test('finance payment matching page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/payment-matching')
            ->waitForText('Payment Matching', 10)
            ->assertSee('Payment Matching');
    });
});

test('finance payment allocations page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/payment-allocations')
            ->waitForText('Payment Allocations', 10)
            ->assertSee('Payment Allocations');
    });
});

test('finance match rules page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/match-rules')
            ->waitForText('Match Rules', 10)
            ->assertSee('Match Rules');
    });
});

test('finance EFTPOS terminals page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/eftpos-terminals')
            ->waitForText('EFTPOS Terminals', 10)
            ->assertSee('EFTPOS Terminals');
    });
});

test('finance FX revaluations page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/fx-revaluations')
            ->waitForText('FX Revaluations', 10)
            ->assertSee('FX Revaluations');
    });
});

test('finance cash flow forecast page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/cash-flow-forecast')
            ->waitForText('Cash Flow Forecast', 10)
            ->assertSee('Cash Flow Forecast');
    });
});

test('finance audit exports page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/audit-exports')
            ->waitForText('Audit Exports', 10)
            ->assertSee('Audit Exports');
    });
});

test('finance audit exports create URL redirects to the register', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/audit-exports/create')
            ->waitForText('Audit exports', 10)
            ->assertPathBeginsWith('/finance');
    });
});

test('finance cash flow forecast create URL redirects to the register', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/cash-flow-forecast/create')
            ->waitForText('Cash-flow forecast', 10)
            ->assertPathBeginsWith('/finance');
    });
});

test('finance credit notes create URL redirects to the register', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/credit-notes/create')
            ->waitForText('Credit notes', 10)
            ->assertPathBeginsWith('/finance');
    });
});

test('finance currencies create URL redirects to the register', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/currencies/create')
            ->waitForText('Currencies', 10)
            ->assertPathBeginsWith('/finance');
    });
});

test('finance donor funds create URL redirects to the register', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/donor-funds/create')
            ->waitForText('Donor funds', 10)
            ->assertPathBeginsWith('/finance');
    });
});

test('finance eftpos batches page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/eftpos/batches')
            ->waitForText('EFTPOS', 10)
            ->assertPathBeginsWith('/finance');
    });
});

test('finance fixed assets create URL redirects to the register', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/fixed-assets/create')
            ->waitForText('Fixed assets', 10)
            ->assertPathBeginsWith('/finance');
    });
});

test('finance funding streams page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/funding-streams')
            ->waitForText('Funding', 10)
            ->assertPathBeginsWith('/finance');
    });
});

test('finance FX revaluations create page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/fx-revaluations/create')
            ->waitForText('FX Revaluation', 10)
            ->assertPathBeginsWith('/finance');
    });
});

test('finance GST returns prepare page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/gst-returns/prepare')
            ->waitForText('GST', 10)
            ->assertPathBeginsWith('/finance');
    });
});

test('finance payment runs create URL redirects to the register', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/payment-runs/create')
            ->waitForText('Payment runs', 10)
            ->assertPathBeginsWith('/finance');
    });
});

test('finance petty cash create URL redirects to the register', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/petty-cash/create')
            ->waitForText('Petty cash', 10)
            ->assertPathBeginsWith('/finance');
    });
});

test('finance receivables aging url redirects to the Aged AR view', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/receivables/aging')
            ->waitForText('Aged receivables', 10)
            ->assertPathIs('/finance/receivables');
    });
});

test('finance receivables statements page loads', function () {
    $this->browse(function (Browser $browser) {
        $user = User::where('email', 'admin@test.com')->first();
        $browser->loginAs($user)
            ->visit('/finance/receivables/statements')
            ->waitForText('Statements', 10)
            ->assertPathBeginsWith('/finance');
    });
});
