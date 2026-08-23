<?php

use App\Domain\Finance\Http\Controllers\AccountingIntegrationController;
use App\Domain\Finance\Http\Controllers\AccountsReceivableController;
use App\Domain\Finance\Http\Controllers\AuditExportController;
use App\Domain\Finance\Http\Controllers\BankAccountController;
use App\Domain\Finance\Http\Controllers\BankFeedController;
use App\Domain\Finance\Http\Controllers\BankingController;
use App\Domain\Finance\Http\Controllers\BankReconciliationController;
use App\Domain\Finance\Http\Controllers\BankTransactionController;
use App\Domain\Finance\Http\Controllers\BillController;
use App\Domain\Finance\Http\Controllers\BillingController;
use App\Domain\Finance\Http\Controllers\BudgetActualsController;
use App\Domain\Finance\Http\Controllers\BudgetForecastApiController;
use App\Domain\Finance\Http\Controllers\CashFlowForecastController;
use App\Domain\Finance\Http\Controllers\CashPositionController;
use App\Domain\Finance\Http\Controllers\ChartOfAccountsController;
use App\Domain\Finance\Http\Controllers\ClientFinancialsController;
use App\Domain\Finance\Http\Controllers\ConsolidationController;
use App\Domain\Finance\Http\Controllers\CostCentreController;
use App\Domain\Finance\Http\Controllers\CreditNoteController;
use App\Domain\Finance\Http\Controllers\CurrencyController;
use App\Domain\Finance\Http\Controllers\DonorFundController;
use App\Domain\Finance\Http\Controllers\EftposController;
use App\Domain\Finance\Http\Controllers\ExecutiveFinancialDashboardController;
use App\Domain\Finance\Http\Controllers\FinanceCalendarController;
use App\Domain\Finance\Http\Controllers\FinanceDashboardController;
use App\Domain\Finance\Http\Controllers\FinancialInsightsApiController;
use App\Domain\Finance\Http\Controllers\FinancialReportController;
use App\Domain\Finance\Http\Controllers\FiscalPeriodController;
use App\Domain\Finance\Http\Controllers\FixedAssetController;
use App\Domain\Finance\Http\Controllers\FundingStreamController;
use App\Domain\Finance\Http\Controllers\FxRevaluationController;
use App\Domain\Finance\Http\Controllers\GstReturnController;
use App\Domain\Finance\Http\Controllers\IntercompanyController;
use App\Domain\Finance\Http\Controllers\InvoiceController;
use App\Domain\Finance\Http\Controllers\IrdFilingController;
use App\Domain\Finance\Http\Controllers\JournalController;
use App\Domain\Finance\Http\Controllers\LedgerController;
use App\Domain\Finance\Http\Controllers\MatchRuleController;
use App\Domain\Finance\Http\Controllers\PayablesController;
use App\Domain\Finance\Http\Controllers\PaymentAllocationController;
use App\Domain\Finance\Http\Controllers\PaymentMatchController;
use App\Domain\Finance\Http\Controllers\PaymentRunController;
use App\Domain\Finance\Http\Controllers\PettyCashController;
use App\Domain\Finance\Http\Controllers\PriceBookController;
use App\Domain\Finance\Http\Controllers\PurchaseOrderController;
use App\Domain\Finance\Http\Controllers\QuoteController;
use App\Domain\Finance\Http\Controllers\RecurringChargeController;
use App\Domain\Finance\Http\Controllers\ReportsController;
use App\Domain\Finance\Http\Controllers\SettingsController;
use App\Domain\Finance\Http\Controllers\SiteFinancialDashboardController;
use App\Domain\Finance\Http\Controllers\SitesFinancialOverviewController;
use App\Domain\Finance\Http\Controllers\TaxController;
use App\Domain\Finance\Http\Controllers\VendorController;
use App\Domain\Finance\Http\Middleware\RejectUnsupportedConsolidation;
use Illuminate\Support\Facades\Route;

/**
 * Finance Module Routes
 */
Route::middleware(['auth'])->prefix('finance')->name('finance.')->group(function () {

    // Overview hub — the module home. Summary lives at /finance itself; the
    // other Overview tabs (executive, by-site, cash position) are sibling
    // routes sharing the OverviewTabsFooter. The old /finance/dashboard URL
    // redirects (route NAME `finance.dashboard` is kept on the hub so every
    // existing route('finance.dashboard') caller now lands on /finance).
    Route::get('/', [FinanceDashboardController::class, 'index'])
        ->name('dashboard')
        ->middleware('permission:finance.dashboard');
    Route::redirect('/dashboard', '/finance');

    // Executive Financial Dashboard (Overview hub tab)
    Route::get('/executive-dashboard', [ExecutiveFinancialDashboardController::class, 'index'])
        ->name('executive-dashboard')
        ->middleware('permission:finance.dashboard');

    // Cash position (Overview hub tab) — live balances + 30-day obligations.
    Route::get('/cash-position', [CashPositionController::class, 'index'])
        ->name('cash-position')
        ->middleware('permission:finance.dashboard');

    // Finance obligation calendar — page shell + JSON event feed (invoice/bill
    // due dates, payment runs, GST deadlines).
    Route::get('/calendar', [FinanceCalendarController::class, 'index'])
        ->name('calendar.index')
        ->middleware('permission:finance.dashboard');
    Route::get('/calendar/events', [FinanceCalendarController::class, 'events'])
        ->name('calendar.events')
        ->middleware('permission:finance.dashboard');

    // All-Sites Financial Overview
    Route::get('/sites', [SitesFinancialOverviewController::class, 'index'])
        ->name('sites.overview')
        ->middleware('permission:finance.dashboard');

    // Site Financial Dashboard
    Route::get('/sites/{site}/financial-dashboard', [SiteFinancialDashboardController::class, 'show'])
        ->name('sites.financial-dashboard')
        ->whereNumber('site')
        ->middleware('permission:finance.dashboard');

    // Client Financials
    Route::get('/clients/{client}/financials', [ClientFinancialsController::class, 'show'])
        ->name('clients.financials')
        ->whereNumber('client')
        ->middleware('permission:finance.dashboard');

    // ── CSV list exports (C3d) ───────────────────────────────────────────
    // Registered BEFORE the resource routes so "/x/export" is matched ahead of
    // "/x/{param}" (Laravel matches in registration order). Each streams the
    // filtered list via the controller's export() + SanitizesCsvOutput trait.
    // Permission mirrors each list's index route. (invoices.export lives inline
    // in the AR block — the reference implementation.)
    Route::get('/bills/export', [BillController::class, 'export'])->name('bills.export')->middleware('permission:finance.ap.view');
    Route::get('/purchase-orders/export', [PurchaseOrderController::class, 'export'])->name('purchase-orders.export')->middleware('permission:finance.ap.view');
    Route::get('/payment-runs/export', [PaymentRunController::class, 'export'])->name('payment-runs.export')->middleware('permission:finance.ap.view');
    Route::get('/vendors/export', [VendorController::class, 'export'])->name('vendors.export')->middleware('permission:finance.ap.view');
    Route::get('/credit-notes/export', [CreditNoteController::class, 'export'])->name('credit-notes.export')->middleware('permission:finance.ap.view');
    Route::get('/quotes/export', [QuoteController::class, 'export'])->name('quotes.export')->middleware('permission:finance.ar.view');
    Route::get('/journals/export', [JournalController::class, 'export'])->name('journals.export')->middleware('permission:finance.ledger.view');
    Route::get('/accounts/export', [ChartOfAccountsController::class, 'export'])->name('accounts.export')->middleware('permission:finance.ledger.view');
    Route::get('/fixed-assets/export', [FixedAssetController::class, 'export'])->name('fixed-assets.export')->middleware('permission:finance.assets.view');
    Route::get('/gst-returns/export', [GstReturnController::class, 'export'])->name('gst-returns.export')->middleware('permission:finance.tax.view');
    Route::get('/ird-filings/export', [IrdFilingController::class, 'export'])->name('ird-filings.export')->middleware('permission:finance.tax.manage');
    Route::get('/donor-funds/export', [DonorFundController::class, 'export'])->name('donor-funds.export')->middleware('permission:finance.reports.view');
    Route::get('/bank-transactions/export', [BankTransactionController::class, 'export'])->name('bank-transactions.export')->middleware('permission:finance.bank.view');
    Route::get('/petty-cash/export', [PettyCashController::class, 'export'])->name('petty-cash.export')->middleware('permission:finance.petty_cash.view');

    // ── General Ledger hub ──────────────────────────────────────────────
    // /finance/ledger is the hub entry point; it redirects to the first ledger
    // tab the user can open. The tabs themselves are the routes below.
    Route::get('/ledger', [LedgerController::class, 'index'])->name('ledger.index');

    // ── Chart of Accounts ───────────────────────────────────────────────
    Route::get('/accounts', [ChartOfAccountsController::class, 'index'])
        ->name('accounts.index')
        ->middleware('permission:finance.ledger.view');
    Route::get('/accounts/create', [ChartOfAccountsController::class, 'create'])
        ->name('accounts.create')
        ->middleware('permission:finance.ledger.manage');
    Route::post('/accounts', [ChartOfAccountsController::class, 'store'])
        ->name('accounts.store')
        ->middleware('permission:finance.ledger.manage');
    Route::get('/accounts/{account}', [ChartOfAccountsController::class, 'show'])
        ->name('accounts.show')
        ->middleware('permission:finance.ledger.view');
    Route::get('/accounts/{account}/edit', [ChartOfAccountsController::class, 'edit'])
        ->name('accounts.edit')
        ->middleware('permission:finance.ledger.manage');
    Route::put('/accounts/{account}', [ChartOfAccountsController::class, 'update'])
        ->name('accounts.update')
        ->middleware('permission:finance.ledger.manage');
    Route::delete('/accounts/{account}', [ChartOfAccountsController::class, 'destroy'])
        ->name('accounts.destroy')
        ->middleware('permission:finance.ledger.manage');

    // ── Journals ────────────────────────────────────────────────────────
    Route::get('/journals', [JournalController::class, 'index'])
        ->name('journals.index')
        ->middleware('permission:finance.ledger.view');
    Route::get('/journals/create', [JournalController::class, 'create'])
        ->name('journals.create')
        ->middleware('permission:finance.ledger.manage');
    Route::post('/journals', [JournalController::class, 'store'])
        ->name('journals.store')
        ->middleware('permission:finance.ledger.manage');
    Route::get('/journals/{journal}', [JournalController::class, 'show'])
        ->name('journals.show')
        ->middleware('permission:finance.ledger.view');
    Route::post('/journals/{journal}/post', [JournalController::class, 'post'])
        ->name('journals.post')
        ->middleware('permission:finance.ledger.manage');
    Route::post('/journals/{journal}/reverse', [JournalController::class, 'reverse'])
        ->name('journals.reverse')
        ->middleware('permission:finance.ledger.manage');

    // ── Fiscal Periods ──────────────────────────────────────────────────
    Route::middleware('permission:finance.admin')->group(function () {
        Route::get('/fiscal-periods', [FiscalPeriodController::class, 'index'])->name('fiscal-periods.index');
        Route::post('/fiscal-periods', [FiscalPeriodController::class, 'store'])->name('fiscal-periods.store');
        Route::put('/fiscal-periods/{period}', [FiscalPeriodController::class, 'update'])->name('fiscal-periods.update');
        Route::post('/fiscal-periods/{period}/close', [FiscalPeriodController::class, 'close'])->name('fiscal-periods.close');
    });

    // ── Cost Centres ────────────────────────────────────────────────────
    Route::middleware('permission:finance.admin')->group(function () {
        Route::get('/cost-centres', [CostCentreController::class, 'index'])->name('cost-centres.index');
        Route::post('/cost-centres', [CostCentreController::class, 'store'])->name('cost-centres.store');
        Route::put('/cost-centres/{costCentre}', [CostCentreController::class, 'update'])->name('cost-centres.update');
        Route::delete('/cost-centres/{costCentre}', [CostCentreController::class, 'destroy'])->name('cost-centres.destroy');
    });

    // ── Funding Streams ─────────────────────────────────────────────────
    Route::middleware('permission:finance.admin')->group(function () {
        Route::get('/funding-streams', [FundingStreamController::class, 'index'])->name('funding-streams.index');
        Route::post('/funding-streams', [FundingStreamController::class, 'store'])->name('funding-streams.store');
        Route::put('/funding-streams/{fundingStream}', [FundingStreamController::class, 'update'])->name('funding-streams.update');
        Route::delete('/funding-streams/{fundingStream}', [FundingStreamController::class, 'destroy'])->name('funding-streams.destroy');
    });

    // ── Currencies ───────────────────────────────────────────────────────
    Route::middleware('permission:finance.admin')->group(function () {
        Route::resource('currencies', CurrencyController::class)->except(['show', 'edit']);
    });

    // ── Purchases & Payables hub ────────────────────────────────────────
    // /finance/payables is the hub entry point; it redirects to the first AP tab
    // the user can open (bills · purchase orders · vendors · credit notes ·
    // payment runs). The tabs themselves are the routes below.
    Route::get('/payables', [PayablesController::class, 'index'])->name('payables.index');

    // ── Vendors ─────────────────────────────────────────────────────────
    Route::get('/vendors', [VendorController::class, 'index'])
        ->name('vendors.index')
        ->middleware('permission:finance.ap.view');
    Route::get('/vendors/create', [VendorController::class, 'create'])
        ->name('vendors.create')
        ->middleware('permission:finance.ap.manage');
    Route::post('/vendors', [VendorController::class, 'store'])
        ->name('vendors.store')
        ->middleware('permission:finance.ap.manage');
    Route::get('/vendors/{vendor}', [VendorController::class, 'show'])
        ->name('vendors.show')
        ->middleware('permission:finance.ap.view');
    Route::get('/vendors/{vendor}/edit', [VendorController::class, 'edit'])
        ->name('vendors.edit')
        ->middleware('permission:finance.ap.manage');
    Route::put('/vendors/{vendor}', [VendorController::class, 'update'])
        ->name('vendors.update')
        ->middleware('permission:finance.ap.manage');

    // ── Purchase Orders ─────────────────────────────────────────────────
    Route::get('/purchase-orders', [PurchaseOrderController::class, 'index'])
        ->name('purchase-orders.index')
        ->middleware('permission:finance.ap.view');
    Route::get('/purchase-orders/create', [PurchaseOrderController::class, 'create'])
        ->name('purchase-orders.create')
        ->middleware('permission:finance.ap.manage');
    Route::post('/purchase-orders', [PurchaseOrderController::class, 'store'])
        ->name('purchase-orders.store')
        ->middleware('permission:finance.ap.manage');
    Route::get('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'show'])
        ->name('purchase-orders.show')
        ->middleware('permission:finance.ap.view');
    Route::get('/purchase-orders/{purchaseOrder}/edit', [PurchaseOrderController::class, 'edit'])
        ->name('purchase-orders.edit')
        ->middleware('permission:finance.ap.manage');
    Route::put('/purchase-orders/{purchaseOrder}', [PurchaseOrderController::class, 'update'])
        ->name('purchase-orders.update')
        ->middleware('permission:finance.ap.manage');
    Route::post('/purchase-orders/{purchaseOrder}/approve', [PurchaseOrderController::class, 'approve'])
        ->name('purchase-orders.approve')
        ->middleware('permission:finance.ap.manage');
    Route::post('/purchase-orders/{purchaseOrder}/convert-to-bill', [PurchaseOrderController::class, 'convertToBill'])
        ->name('purchase-orders.convert-to-bill')
        ->middleware('permission:finance.ap.manage');

    // ── Bills ───────────────────────────────────────────────────────────
    Route::get('/bills', [BillController::class, 'index'])
        ->name('bills.index')
        ->middleware('permission:finance.ap.view');
    Route::get('/bills/create', [BillController::class, 'create'])
        ->name('bills.create')
        ->middleware('permission:finance.ap.manage');
    Route::post('/bills', [BillController::class, 'store'])
        ->name('bills.store')
        ->middleware('permission:finance.ap.manage');
    Route::get('/bills/{bill}', [BillController::class, 'show'])
        ->name('bills.show')
        ->middleware('permission:finance.ap.view');
    Route::get('/bills/{bill}/edit', [BillController::class, 'edit'])
        ->name('bills.edit')
        ->middleware('permission:finance.ap.manage');
    Route::put('/bills/{bill}', [BillController::class, 'update'])
        ->name('bills.update')
        ->middleware('permission:finance.ap.manage');
    Route::post('/bills/{bill}/approve', [BillController::class, 'approve'])
        ->name('bills.approve')
        ->middleware('permission:finance.ap.manage');
    Route::post('/bills/{bill}/cancel', [BillController::class, 'cancel'])
        ->name('bills.cancel')
        ->middleware('permission:finance.ap.manage');

    // ── Credit Notes ────────────────────────────────────────────────────
    // Create is a WizardShell modal on the index page; the retired full-page URL
    // redirects to the list. (Must stay registered BEFORE /credit-notes/{creditNote}.)
    Route::get('/credit-notes', [CreditNoteController::class, 'index'])
        ->name('credit-notes.index')
        ->middleware('permission:finance.ap.view');
    Route::redirect('/credit-notes/create', '/finance/credit-notes')->name('credit-notes.create');
    Route::post('/credit-notes', [CreditNoteController::class, 'store'])
        ->name('credit-notes.store')
        ->middleware('permission:finance.ap.manage');
    Route::get('/credit-notes/{creditNote}', [CreditNoteController::class, 'show'])
        ->name('credit-notes.show')
        ->middleware('permission:finance.ap.view');
    Route::post('/credit-notes/{creditNote}/approve', [CreditNoteController::class, 'approve'])
        ->name('credit-notes.approve')
        ->middleware('permission:finance.ap.manage');

    // ── Payment Runs ────────────────────────────────────────────────────
    Route::get('/payment-runs', [PaymentRunController::class, 'index'])
        ->name('payment-runs.index')
        ->middleware('permission:finance.ap.view');
    Route::get('/payment-runs/create', [PaymentRunController::class, 'create'])
        ->name('payment-runs.create')
        ->middleware('permission:finance.ap.manage');
    Route::post('/payment-runs', [PaymentRunController::class, 'store'])
        ->name('payment-runs.store')
        ->middleware('permission:finance.ap.manage');
    Route::get('/payment-runs/{paymentRun}', [PaymentRunController::class, 'show'])
        ->name('payment-runs.show')
        ->middleware('permission:finance.ap.view');
    Route::post('/payment-runs/{paymentRunId}/approve', [PaymentRunController::class, 'approve'])
        ->name('payment-runs.approve')
        ->middleware('permission:finance.ap.manage');
    Route::post('/payment-runs/{paymentRun}/process', [PaymentRunController::class, 'process'])
        ->name('payment-runs.process')
        ->middleware('permission:finance.ap.manage');
    Route::get('/payment-runs/{paymentRun}/download', [PaymentRunController::class, 'download'])
        ->name('payment-runs.download')
        ->middleware('permission:finance.ap.manage');
    Route::post('/payment-runs/{paymentRun}/accept', [PaymentRunController::class, 'accept'])
        ->name('payment-runs.accept')
        ->middleware('permission:finance.ap.manage');
    Route::post('/payment-runs/{paymentRun}/reject', [PaymentRunController::class, 'reject'])
        ->name('payment-runs.reject')
        ->middleware('permission:finance.ap.manage');
    Route::post('/payment-runs/{paymentRun}/settle', [PaymentRunController::class, 'settle'])
        ->name('payment-runs.settle')
        ->middleware('permission:finance.ap.manage');
    Route::post('/payment-runs/{paymentRun}/reconcile', [PaymentRunController::class, 'reconcile'])
        ->name('payment-runs.reconcile')
        ->middleware('permission:finance.ap.manage');

    // ── Payment Allocations ─────────────────────────────────────────────
    Route::get('/payment-allocations', [PaymentAllocationController::class, 'index'])
        ->name('payment-allocations.index')
        ->middleware('permission:finance.ar.view|finance.ap.view');
    // Allocation history is intentionally read-only. New receipts and bill
    // settlements belong to the canonical AR allocation and AP matching/run
    // workflows below; there is no generic polymorphic allocation write route.

    // ── Accounts Receivable ─────────────────────────────────────────────
    Route::middleware('permission:finance.ar.view')->group(function () {
        Route::get('/receivables', [AccountsReceivableController::class, 'index'])->name('receivables.index');
        Route::get('/receivables/aging', [AccountsReceivableController::class, 'aging'])->name('receivables.aging');
        Route::get('/receivables/statements', [AccountsReceivableController::class, 'statements'])->name('receivables.statements');
    });

    Route::post('/receivables/allocate', [AccountsReceivableController::class, 'allocate'])
        ->name('receivables.allocate')
        ->middleware('permission:finance.ar.manage');

    // ── Billing ────────────────────────────────────────────────────────
    Route::middleware('permission:finance.ar.view')->group(function () {
        Route::get('/billing', [BillingController::class, 'index'])->name('billing.index');
        Route::get('/billing/entries', [BillingController::class, 'entries'])->name('billing.entries');
    });

    // ── Price Books ────────────────────────────────────────────────────
    // Create/edit are WizardShell modals on the index/show pages; the retired
    // full-page URLs redirect to the list. (The create redirect must stay
    // registered BEFORE /price-books/{priceBook} so it isn't captured as a param.)
    Route::redirect('/price-books/create', '/finance/price-books')->name('price_books.create');
    Route::redirect('/price-books/{priceBook}/edit', '/finance/price-books')->name('price_books.edit');
    Route::middleware('permission:finance.ar.manage')->group(function () {
        Route::post('/price-books', [PriceBookController::class, 'store'])->name('price_books.store');
    });
    Route::middleware('permission:finance.ar.view')->group(function () {
        Route::get('/price-books', [PriceBookController::class, 'index'])->name('price_books.index');
        Route::get('/price-books/{priceBook}', [PriceBookController::class, 'show'])->name('price_books.show');
    });
    Route::middleware('permission:finance.ar.manage')->group(function () {
        Route::put('/price-books/{priceBook}', [PriceBookController::class, 'update'])->name('price_books.update');
        Route::post('/price-books/{priceBook}/items', [PriceBookController::class, 'storeItem'])->name('price_books.items.store');
        Route::put('/price-books/{priceBook}/items/{item}', [PriceBookController::class, 'updateItem'])->name('price_books.items.update');
        Route::delete('/price-books/{priceBook}/items/{item}', [PriceBookController::class, 'destroyItem'])->name('price_books.items.destroy');
    });

    // ── Quotes ─────────────────────────────────────────────────────────
    // Create/edit are WizardShell modals on the index page; the retired full-page
    // URLs redirect to the list. (The create redirect must stay registered BEFORE
    // /quotes/{quote} so it isn't captured as a param. Edit is draft-only.)
    Route::redirect('/quotes/create', '/finance/quotes')->name('quotes.create');
    Route::middleware('permission:finance.ar.manage')->group(function () {
        Route::post('/quotes', [QuoteController::class, 'store'])->name('quotes.store');
    });
    Route::middleware('permission:finance.ar.view')->group(function () {
        Route::get('/quotes', [QuoteController::class, 'index'])->name('quotes.index');
        Route::get('/quotes/{quote}', [QuoteController::class, 'show'])->name('quotes.show');
    });
    Route::redirect('/quotes/{quote}/edit', '/finance/quotes')->name('quotes.edit');
    Route::middleware('permission:finance.ar.manage')->group(function () {
        Route::put('/quotes/{quote}', [QuoteController::class, 'update'])->name('quotes.update');
        Route::post('/quotes/{quote}/send', [QuoteController::class, 'send'])->name('quotes.send');
        Route::post('/quotes/{quote}/accept', [QuoteController::class, 'accept'])->name('quotes.accept');
        Route::post('/quotes/{quote}/convert', [QuoteController::class, 'convertToAgreement'])->name('quotes.convert');
        Route::post('/quotes/{quote}/convert-to-invoice', [QuoteController::class, 'convertToInvoice'])->name('quotes.convert-to-invoice');
    });

    // ── Recurring Charges ──────────────────────────────────────────────
    Route::get('/recurring-charges', [RecurringChargeController::class, 'index'])
        ->name('recurring_charges.index')
        ->middleware('permission:finance.ar.view');
    // Create/edit are WizardShell modals on the index page; the retired
    // full-page URLs redirect to the list.
    Route::redirect('/recurring-charges/create', '/finance/recurring-charges')->name('recurring_charges.create');
    Route::redirect('/recurring-charges/{charge}/edit', '/finance/recurring-charges')->name('recurring_charges.edit');
    Route::middleware('permission:finance.ar.manage')->group(function () {
        Route::post('/recurring-charges', [RecurringChargeController::class, 'store'])->name('recurring_charges.store');
        Route::put('/recurring-charges/{charge}', [RecurringChargeController::class, 'update'])->name('recurring_charges.update');
        Route::delete('/recurring-charges/{charge}', [RecurringChargeController::class, 'destroy'])->name('recurring_charges.destroy');
    });

    // ── Banking & Cash hub ──────────────────────────────────────────────
    // /finance/banking is the hub entry point; it redirects to the first banking
    // tab the user can open (accounts · transactions · reconciliation · matching ·
    // feeds · EFTPOS · petty cash · match rules). The tabs are the routes below.
    Route::get('/banking', [BankingController::class, 'index'])->name('banking.index');

    // ── Bank Accounts ───────────────────────────────────────────────────
    Route::get('/bank-accounts', [BankAccountController::class, 'index'])
        ->name('bank-accounts.index')
        ->middleware('permission:finance.bank.view');
    // Create/edit are WizardShell modals on the index/show pages; the retired
    // full-page URLs redirect to the list. (The create redirect must stay
    // registered BEFORE /bank-accounts/{bankAccount} so it isn't captured.)
    Route::redirect('/bank-accounts/create', '/finance/bank-accounts')->name('bank-accounts.create');
    Route::post('/bank-accounts', [BankAccountController::class, 'store'])
        ->name('bank-accounts.store')
        ->middleware('permission:finance.bank.manage');
    Route::get('/bank-accounts/{bankAccount}', [BankAccountController::class, 'show'])
        ->name('bank-accounts.show')
        ->middleware('permission:finance.bank.view');
    Route::redirect('/bank-accounts/{bankAccount}/edit', '/finance/bank-accounts')->name('bank-accounts.edit');
    Route::put('/bank-accounts/{bankAccount}', [BankAccountController::class, 'update'])
        ->name('bank-accounts.update')
        ->middleware('permission:finance.bank.manage');

    // ── Bank Transactions ───────────────────────────────────────────────
    Route::get('/bank-transactions', [BankTransactionController::class, 'index'])
        ->name('bank-transactions.index')
        ->middleware('permission:finance.bank.view');

    Route::middleware('permission:finance.bank.manage')->group(function () {
        Route::post('/bank-transactions', [BankTransactionController::class, 'store'])->name('bank-transactions.store');
        Route::post('/bank-transactions/import', [BankTransactionController::class, 'import'])->name('bank-transactions.import');
    });

    // ── Bank Feeds ─────────────────────────────────────────────────────
    Route::middleware('permission:finance.bank.manage')->group(function () {
        Route::get('/bank-feeds', [BankFeedController::class, 'index'])->name('bank-feeds.index');
        Route::post('/bank-feeds', [BankFeedController::class, 'store'])->name('bank-feeds.store');
        Route::post('/bank-feeds/{feed}/sync', [BankFeedController::class, 'sync'])->name('bank-feeds.sync');
        Route::post('/bank-feeds/sync-all', [BankFeedController::class, 'syncAll'])->name('bank-feeds.sync-all');
        Route::delete('/bank-feeds/{feed}', [BankFeedController::class, 'destroy'])->name('bank-feeds.destroy');
        Route::get('/bank-feeds/{feed}/logs', [BankFeedController::class, 'logs'])->name('bank-feeds.logs');
    });

    // ── Payment Matching ─────────────────────────────────────────────────
    Route::get('/payment-matching', [PaymentMatchController::class, 'index'])
        ->name('payment-matching.index')
        ->middleware('permission:finance.bank.view');
    Route::post('/payment-matching/suggest/{transaction}', [PaymentMatchController::class, 'suggest'])
        ->name('payment-matching.suggest')
        ->middleware('permission:finance.bank.manage');
    Route::post('/payment-matching/match-all', [PaymentMatchController::class, 'matchAll'])
        ->name('payment-matching.match-all')
        ->middleware('permission:finance.bank.manage');
    Route::post('/payment-matching/{match}/confirm', [PaymentMatchController::class, 'confirm'])
        ->name('payment-matching.confirm')
        ->middleware('permission:finance.bank.manage');
    Route::post('/payment-matching/{match}/reject', [PaymentMatchController::class, 'reject'])
        ->name('payment-matching.reject')
        ->middleware('permission:finance.bank.manage');

    // ── Match Rules ──────────────────────────────────────────────────────
    Route::get('/match-rules', [MatchRuleController::class, 'index'])
        ->name('match-rules.index')
        ->middleware('permission:finance.bank.manage');
    Route::post('/match-rules', [MatchRuleController::class, 'store'])
        ->name('match-rules.store')
        ->middleware('permission:finance.bank.manage');
    Route::put('/match-rules/{rule}', [MatchRuleController::class, 'update'])
        ->name('match-rules.update')
        ->middleware('permission:finance.bank.manage');
    Route::delete('/match-rules/{rule}', [MatchRuleController::class, 'destroy'])
        ->name('match-rules.destroy')
        ->middleware('permission:finance.bank.manage');

    // ── Bank Reconciliation ─────────────────────────────────────────────
    Route::get('/bank-reconciliation', [BankReconciliationController::class, 'index'])
        ->name('bank-reconciliation.index')
        ->middleware('permission:finance.bank.view');
    Route::get('/bank-reconciliation/create', [BankReconciliationController::class, 'create'])
        ->name('bank-reconciliation.create')
        ->middleware('permission:finance.bank.manage');
    Route::post('/bank-reconciliation', [BankReconciliationController::class, 'store'])
        ->name('bank-reconciliation.store')
        ->middleware('permission:finance.bank.manage');
    Route::get('/bank-reconciliation/{reconciliation}', [BankReconciliationController::class, 'show'])
        ->name('bank-reconciliation.show')
        ->middleware('permission:finance.bank.view');
    Route::post('/bank-reconciliation/{reconciliation}/match', [BankReconciliationController::class, 'match'])
        ->name('bank-reconciliation.match')
        ->middleware('permission:finance.bank.manage');
    Route::post('/bank-reconciliation/{reconciliation}/unmatch', [BankReconciliationController::class, 'unmatch'])
        ->name('bank-reconciliation.unmatch')
        ->middleware('permission:finance.bank.manage');
    Route::post('/bank-reconciliation/{reconciliation}/complete', [BankReconciliationController::class, 'complete'])
        ->name('bank-reconciliation.complete')
        ->middleware('permission:finance.bank.manage');
    Route::post('/bank-reconciliation/{reconciliation}/amend', [BankReconciliationController::class, 'amend'])
        ->name('bank-reconciliation.amend')
        ->middleware('permission:finance.bank.manage');

    // ── Tax & Compliance hub ────────────────────────────────────────────
    // /finance/tax is the hub entry point; it redirects to the first tax tab the
    // user can open (GST returns · IRD filings · audit exports).
    Route::get('/tax', [TaxController::class, 'index'])->name('tax.index');

    // Settings hub entry — redirects to the first openable admin tab
    // (integrations · funding streams). Mirrors the tax hub.
    Route::get('/settings', [SettingsController::class, 'index'])->name('settings.index');

    // ── GST Returns ─────────────────────────────────────────────────────
    Route::get('/gst-returns', [GstReturnController::class, 'index'])
        ->name('gst-returns.index')
        ->middleware('permission:finance.tax.view');
    Route::get('/gst-returns/prepare', [GstReturnController::class, 'prepare'])
        ->name('gst-returns.prepare')
        ->middleware('permission:finance.tax.manage');
    Route::post('/gst-returns', [GstReturnController::class, 'store'])
        ->name('gst-returns.store')
        ->middleware('permission:finance.tax.manage');
    Route::get('/gst-returns/{gstReturn}', [GstReturnController::class, 'show'])
        ->name('gst-returns.show')
        ->middleware('permission:finance.tax.view');
    Route::post('/gst-returns/{gstReturn}/file', [GstReturnController::class, 'file'])
        ->name('gst-returns.file')
        ->middleware('permission:finance.tax.manage');
    Route::post('/gst-returns/{gstReturn}/amend', [GstReturnController::class, 'amend'])
        ->name('gst-returns.amend')
        ->middleware('permission:finance.tax.manage');

    // ── Fixed Assets ────────────────────────────────────────────────────
    // Create/edit are WizardShell modals on the index/show pages; the retired
    // full-page URLs redirect to the list. (The create redirect must stay
    // registered BEFORE /fixed-assets/{fixedAsset} so it isn't captured.)
    Route::get('/fixed-assets', [FixedAssetController::class, 'index'])
        ->name('fixed-assets.index')
        ->middleware('permission:finance.assets.view');
    Route::redirect('/fixed-assets/create', '/finance/fixed-assets')->name('fixed-assets.create');
    Route::post('/fixed-assets', [FixedAssetController::class, 'store'])
        ->name('fixed-assets.store')
        ->middleware('permission:finance.assets.manage');
    Route::post('/fixed-assets/run-depreciation', [FixedAssetController::class, 'runDepreciation'])
        ->name('fixed-assets.run-depreciation')
        ->middleware('permission:finance.assets.manage');
    Route::get('/fixed-assets/{fixedAsset}', [FixedAssetController::class, 'show'])
        ->name('fixed-assets.show')
        ->middleware('permission:finance.assets.view');
    Route::redirect('/fixed-assets/{fixedAsset}/edit', '/finance/fixed-assets')->name('fixed-assets.edit');
    Route::put('/fixed-assets/{fixedAsset}', [FixedAssetController::class, 'update'])
        ->name('fixed-assets.update')
        ->middleware('permission:finance.assets.manage');
    Route::post('/fixed-assets/{fixedAsset}/dispose', [FixedAssetController::class, 'dispose'])
        ->name('fixed-assets.dispose')
        ->middleware('permission:finance.assets.manage');
    Route::post('/fixed-assets/{fixedAsset}/capitalise', [FixedAssetController::class, 'capitalise'])
        ->name('fixed-assets.capitalise')
        ->middleware('permission:finance.assets.manage');

    // ── Petty Cash ──────────────────────────────────────────────────────
    Route::get('/petty-cash', [PettyCashController::class, 'index'])
        ->name('petty-cash.index')
        ->middleware('permission:finance.petty_cash.view');
    // Create is a WizardShell modal on the index page; the retired full-page
    // URL redirects to the list. (Must stay registered BEFORE /petty-cash/{fund}.)
    Route::redirect('/petty-cash/create', '/finance/petty-cash')->name('petty-cash.create');
    Route::post('/petty-cash', [PettyCashController::class, 'store'])
        ->name('petty-cash.store')
        ->middleware('permission:finance.petty_cash.manage');
    Route::get('/petty-cash/{fund}', [PettyCashController::class, 'show'])
        ->name('petty-cash.show')
        ->middleware('permission:finance.petty_cash.view');
    Route::post('/petty-cash/{fund}/transaction', [PettyCashController::class, 'storeTransaction'])
        ->name('petty-cash.transaction')
        ->middleware('permission:finance.petty_cash.manage');

    // ── FX Revaluations ──────────────────────────────────────────────────
    Route::middleware('permission:finance.ledger.manage')->group(function () {
        Route::get('/fx-revaluations', [FxRevaluationController::class, 'index'])->name('fx-revaluations.index');
        Route::get('/fx-revaluations/create', [FxRevaluationController::class, 'create'])->name('fx-revaluations.create');
        Route::post('/fx-revaluations', [FxRevaluationController::class, 'store'])->name('fx-revaluations.store');
        Route::post('/fx-revaluations/{revaluation}/post', [FxRevaluationController::class, 'post'])->name('fx-revaluations.post');
    });

    // ── Accounting Integrations (Xero / MYOB) ─────────────────────────
    Route::middleware('permission:finance.admin')->group(function () {
        Route::get('/integrations', [AccountingIntegrationController::class, 'index'])->name('integrations.index');
        Route::post('/integrations', [AccountingIntegrationController::class, 'store'])->name('integrations.store');
        Route::put('/integrations/{integration}', [AccountingIntegrationController::class, 'update'])->name('integrations.update');
        Route::post('/integrations/{integration}/sync', [AccountingIntegrationController::class, 'sync'])->name('integrations.sync');
        Route::post('/integrations/{integration}/test', [AccountingIntegrationController::class, 'testConnection'])->name('integrations.test');
        Route::delete('/integrations/{integration}', [AccountingIntegrationController::class, 'destroy'])->name('integrations.destroy');
        Route::get('/integrations/{integration}/mapping', [AccountingIntegrationController::class, 'mapping'])->name('integrations.mapping');
        Route::put('/integrations/{integration}/mapping', [AccountingIntegrationController::class, 'updateMapping'])->name('integrations.mapping.update');
    });

    // ── Reports & Planning hub ──────────────────────────────────────────
    // /finance/reports is the hub entry point; it redirects to the first report tab
    // (P&L). The report routes themselves are the tabs below.
    Route::get('/reports', [ReportsController::class, 'index'])->name('reports.index');

    // ── Financial Reports ───────────────────────────────────────────────
    Route::middleware('permission:finance.reports.view')->group(function () {
        Route::get('/reports/trial-balance', [FinancialReportController::class, 'trialBalance'])->name('reports.trial-balance');
        Route::get('/reports/profit-loss', [FinancialReportController::class, 'profitAndLoss'])->name('reports.profit-loss');
        Route::get('/reports/balance-sheet', [FinancialReportController::class, 'balanceSheet'])->name('reports.balance-sheet');
        Route::get('/reports/cash-flow', [FinancialReportController::class, 'cashFlow'])->name('reports.cash-flow');
        Route::get('/reports/aged-payables', [FinancialReportController::class, 'agedPayables'])->name('reports.aged-payables');
        Route::get('/reports/aged-receivables', [FinancialReportController::class, 'agedReceivables'])->name('reports.aged-receivables');
        Route::get('/reports/funding-stream-summary', [FinancialReportController::class, 'fundingStreamSummary'])->name('reports.funding-stream-summary');
        Route::get('/reports/budget-vs-actuals', [BudgetActualsController::class, 'index'])->name('reports.budget-vs-actuals');
        Route::post('/reports/budget-vs-actuals/sync', [BudgetActualsController::class, 'sync'])->name('reports.budget-vs-actuals.sync');
    });

    // ── Cash Flow Forecast ───────────────────────────────────────────────
    // Create is a WizardShell modal on the index page; the retired full-page
    // URL redirects to the list. (Must stay registered BEFORE the {forecast} show.)
    Route::redirect('/cash-flow-forecast/create', '/finance/cash-flow-forecast')->name('cash-flow-forecast.create');
    Route::middleware('permission:finance.reports.view')->group(function () {
        Route::get('/cash-flow-forecast', [CashFlowForecastController::class, 'index'])->name('cash-flow-forecast.index');
        Route::post('/cash-flow-forecast', [CashFlowForecastController::class, 'store'])->name('cash-flow-forecast.store');
        Route::get('/cash-flow-forecast/{forecast}', [CashFlowForecastController::class, 'show'])->name('cash-flow-forecast.show');
        Route::delete('/cash-flow-forecast/{forecast}', [CashFlowForecastController::class, 'destroy'])->name('cash-flow-forecast.destroy');
    });

    // ── IRD E-Filing ─────────────────────────────────────────────────────
    Route::middleware('permission:finance.tax.manage')->group(function () {
        Route::get('/ird-filings', [IrdFilingController::class, 'index'])->name('ird-filings.index');
        Route::post('/ird-filings/from-gst/{gstReturn}', [IrdFilingController::class, 'createFromGst'])->name('ird-filings.from-gst');
        Route::post('/ird-filings/from-payroll/{run}', [IrdFilingController::class, 'createFromPayrollRun'])->name('ird-filings.from-payroll');
        Route::get('/ird-filings/{filing}', [IrdFilingController::class, 'show'])->name('ird-filings.show');
        Route::post('/ird-filings/{filing}/validate', [IrdFilingController::class, 'validateFiling'])->name('ird-filings.validate');
        Route::post('/ird-filings/{filing}/submit', [IrdFilingController::class, 'submit'])->name('ird-filings.submit');
    });

    // ── Consolidation & Intercompany ─────────────────────────────────────
    Route::middleware([
        RejectUnsupportedConsolidation::class,
        'permission:finance.admin',
    ])->group(function () {
        Route::get('/consolidation', [ConsolidationController::class, 'index'])->name('consolidation.index');
        Route::post('/consolidation', [ConsolidationController::class, 'store'])->name('consolidation.store');
        Route::get('/consolidation/{group}', [ConsolidationController::class, 'show'])->name('consolidation.show');
        Route::post('/consolidation/{group}/entities', [ConsolidationController::class, 'addEntity'])->name('consolidation.add-entity');
        Route::delete('/consolidation/{group}/entities/{entity}', [ConsolidationController::class, 'removeEntity'])->name('consolidation.remove-entity');
        Route::get('/consolidation/{group}/runs', [ConsolidationController::class, 'runs'])->name('consolidation.runs');
        Route::post('/consolidation/{group}/run', [ConsolidationController::class, 'runConsolidation'])->name('consolidation.run');
        Route::get('/consolidation/{group}/runs/{run}', [ConsolidationController::class, 'showRun'])->name('consolidation.show-run');
        Route::get('/consolidation/{group}/mapping', [ConsolidationController::class, 'mapping'])->name('consolidation.mapping');
        Route::put('/consolidation/{group}/mapping', [ConsolidationController::class, 'updateMapping'])->name('consolidation.mapping.update');

        Route::get('/intercompany/{group}', [IntercompanyController::class, 'index'])->name('intercompany.index');
        Route::post('/intercompany/{group}', [IntercompanyController::class, 'store'])->name('intercompany.store');
        Route::post('/intercompany/{group}/{transaction}/post', [IntercompanyController::class, 'post'])->name('intercompany.post');
    });

    // ── Invoices (AR) ─────────────────────────────────────────────────
    Route::get('/invoices', [InvoiceController::class, 'index'])
        ->name('invoices.index')
        ->middleware('permission:finance.ar.view');
    // CSV export must be registered BEFORE /invoices/{invoice} so "export" isn't
    // captured as an invoice id.
    Route::get('/invoices/export', [InvoiceController::class, 'export'])
        ->name('invoices.export')
        ->middleware('permission:finance.ar.view');
    Route::get('/invoices/create', [InvoiceController::class, 'create'])
        ->name('invoices.create')
        ->middleware('permission:finance.ar.manage');
    Route::post('/invoices', [InvoiceController::class, 'store'])
        ->name('invoices.store')
        ->middleware('permission:finance.ar.manage');
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])
        ->name('invoices.show')
        ->middleware('permission:finance.ar.view');
    Route::get('/invoices/{invoice}/edit', [InvoiceController::class, 'edit'])
        ->name('invoices.edit')
        ->middleware('permission:finance.ar.manage');
    Route::put('/invoices/{invoice}', [InvoiceController::class, 'update'])
        ->name('invoices.update')
        ->middleware('permission:finance.ar.manage');
    Route::post('/invoices/{invoice}/send', [InvoiceController::class, 'send'])
        ->name('invoices.send')
        ->middleware('permission:finance.ar.manage');
    Route::get('/invoices/{invoice}/pdf', [InvoiceController::class, 'downloadPdf'])
        ->name('invoices.pdf')
        ->middleware('permission:finance.ar.view');
    Route::post('/invoices/{invoiceId}/mark-paid', [InvoiceController::class, 'markPaid'])
        ->name('invoices.mark-paid')
        ->middleware('permission:finance.ar.manage');
    Route::post('/invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])
        ->name('invoices.cancel')
        ->middleware('permission:finance.ar.manage');

    // ── Audit Exports ─────────────────────────────────────────────────
    Route::get('/audit-exports', [AuditExportController::class, 'index'])
        ->name('audit-exports.index')
        ->middleware('permission:finance.reports.view');
    // Create is a WizardShell modal on the index page; the retired full-page
    // URL redirects to the list.
    Route::redirect('/audit-exports/create', '/finance/audit-exports')->name('audit-exports.create');
    Route::post('/audit-exports', [AuditExportController::class, 'store'])
        ->name('audit-exports.store')
        ->middleware('permission:finance.admin');
    Route::get('/audit-exports/{export}/download', [AuditExportController::class, 'download'])
        ->name('audit-exports.download')
        ->middleware('permission:finance.reports.view');
    Route::delete('/audit-exports/{export}', [AuditExportController::class, 'destroy'])
        ->name('audit-exports.destroy')
        ->middleware('permission:finance.admin');

    // ── EFTPOS ────────────────────────────────────────────────────────────
    Route::middleware('permission:finance.bank.manage')->group(function () {
        Route::redirect('/eftpos-terminals', '/finance/eftpos/terminals')
            ->name('eftpos.terminals.legacy');
        Route::get('/eftpos/terminals', [EftposController::class, 'terminals'])->name('eftpos.terminals');
        Route::post('/eftpos/terminals', [EftposController::class, 'storeTerminal'])->name('eftpos.terminals.store');
        Route::put('/eftpos/terminals/{terminal}', [EftposController::class, 'updateTerminal'])->name('eftpos.terminals.update');
        Route::get('/eftpos/batches', [EftposController::class, 'batches'])->name('eftpos.batches');
        Route::post('/eftpos/batches/import', [EftposController::class, 'importBatch'])->name('eftpos.batches.import');
        Route::post('/eftpos/batches/{batch}/reconcile', [EftposController::class, 'reconcile'])->name('eftpos.batches.reconcile');
        Route::get('/eftpos/batches/{batch}', [EftposController::class, 'batchDetail'])->name('eftpos.batches.show');
    });

    // ── Donor Funds ───────────────────────────────────────────────────────
    // Create is a WizardShell modal on the index page; the retired full-page URL
    // redirects to the list. (Must stay registered BEFORE /donor-funds/{fund}.)
    Route::get('/donor-funds', [DonorFundController::class, 'index'])
        ->name('donor-funds.index')
        ->middleware('permission:finance.reports.view');
    Route::redirect('/donor-funds/create', '/finance/donor-funds')->name('donor-funds.create');
    Route::post('/donor-funds', [DonorFundController::class, 'store'])
        ->name('donor-funds.store')
        ->middleware('permission:finance.admin');
    Route::get('/donor-funds/{fund}', [DonorFundController::class, 'show'])
        ->name('donor-funds.show')
        ->middleware('permission:finance.reports.view');
    Route::put('/donor-funds/{fund}', [DonorFundController::class, 'update'])
        ->name('donor-funds.update')
        ->middleware('permission:finance.admin');
    Route::post('/donor-funds/{fund}/receipt', [DonorFundController::class, 'receipt'])
        ->name('donor-funds.receipt')
        ->middleware('permission:finance.admin');
    Route::post('/donor-funds/{fund}/expenditure', [DonorFundController::class, 'expenditure'])
        ->name('donor-funds.expenditure')
        ->middleware('permission:finance.admin');
    Route::post('/donor-funds/{fund}/transactions/{transaction}/reverse', [DonorFundController::class, 'reverse'])
        ->name('donor-funds.transactions.reverse')
        ->middleware('permission:finance.admin');
    Route::post('/donor-funds/{fund}/report', [DonorFundController::class, 'report'])
        ->name('donor-funds.report')
        ->middleware('permission:finance.reports.view');
    Route::get('/donor-funds/{fund}/reports', [DonorFundController::class, 'reports'])
        ->name('donor-funds.reports')
        ->middleware('permission:finance.reports.view');
    Route::get('/donor-funds/{fund}/reports/{report}/download', [DonorFundController::class, 'downloadReport'])
        ->name('donor-funds.reports.download')
        ->middleware('permission:finance.reports.view');

    // ── Financial Insights API (JSON) ──────────────────────────────────
    // These endpoints return JSON for dashboard widgets and async data loading.
    Route::prefix('api')->name('api.')->middleware('permission:finance.dashboard')->group(function () {

        // Site financial data
        Route::get('/sites/overview', [FinancialInsightsApiController::class, 'sitesOverview'])
            ->name('sites.overview');
        Route::get('/sites/{site}/financial-summary', [FinancialInsightsApiController::class, 'siteFinancialSummary'])
            ->name('sites.financial-summary')
            ->whereNumber('site');

        // Client financial data
        Route::get('/clients/{client}/financial-summary', [FinancialInsightsApiController::class, 'clientFinancialSummary'])
            ->name('clients.financial-summary')
            ->whereNumber('client');
        Route::get('/clients/{client}/ledger', [FinancialInsightsApiController::class, 'clientLedger'])
            ->name('clients.ledger')
            ->whereNumber('client');

        // KPIs
        Route::get('/kpis', [FinancialInsightsApiController::class, 'kpis'])->name('kpis');
        Route::get('/kpis/sites', [FinancialInsightsApiController::class, 'siteKpis'])->name('kpis.sites');
        Route::get('/kpis/clients', [FinancialInsightsApiController::class, 'clientKpis'])->name('kpis.clients');

        // Insights
        Route::get('/insights', [FinancialInsightsApiController::class, 'insights'])->name('insights');

        // Budgets
        Route::get('/budgets', [BudgetForecastApiController::class, 'budgetOverview'])->name('budgets');
        Route::get('/sites/{site}/budget', [BudgetForecastApiController::class, 'siteBudget'])
            ->name('sites.budget')
            ->whereNumber('site');

        // Variance
        Route::get('/variance', [BudgetForecastApiController::class, 'organisationVariance'])->name('variance');
        Route::get('/sites/{site}/variance', [BudgetForecastApiController::class, 'siteVariance'])
            ->name('sites.variance')
            ->whereNumber('site');
        Route::get('/sites/{site}/variance/trend', [BudgetForecastApiController::class, 'siteVarianceTrend'])
            ->name('sites.variance.trend')
            ->whereNumber('site');

        // Forecast
        Route::get('/forecast', [BudgetForecastApiController::class, 'organisationForecast'])->name('forecast');
        Route::get('/sites/{site}/forecast', [BudgetForecastApiController::class, 'siteForecast'])
            ->name('sites.forecast')
            ->whereNumber('site');
    });
});
