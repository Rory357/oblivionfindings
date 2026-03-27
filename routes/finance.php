<?php

use App\Domain\Finance\Http\Controllers\AccountsReceivableController;
use App\Domain\Finance\Http\Controllers\BankAccountController;
use App\Domain\Finance\Http\Controllers\BankReconciliationController;
use App\Domain\Finance\Http\Controllers\BankTransactionController;
use App\Domain\Finance\Http\Controllers\BillController;
use App\Domain\Finance\Http\Controllers\BudgetActualsController;
use App\Domain\Finance\Http\Controllers\ChartOfAccountsController;
use App\Domain\Finance\Http\Controllers\CostCentreController;
use App\Domain\Finance\Http\Controllers\CreditNoteController;
use App\Domain\Finance\Http\Controllers\FinanceDashboardController;
use App\Domain\Finance\Http\Controllers\FinancialReportController;
use App\Domain\Finance\Http\Controllers\FiscalPeriodController;
use App\Domain\Finance\Http\Controllers\FixedAssetController;
use App\Domain\Finance\Http\Controllers\FundingStreamController;
use App\Domain\Finance\Http\Controllers\GstReturnController;
use App\Domain\Finance\Http\Controllers\JournalController;
use App\Domain\Finance\Http\Controllers\PaymentAllocationController;
use App\Domain\Finance\Http\Controllers\PaymentRunController;
use App\Domain\Finance\Http\Controllers\PettyCashController;
use App\Domain\Finance\Http\Controllers\PurchaseOrderController;
use App\Domain\Finance\Http\Controllers\VendorController;
use Illuminate\Support\Facades\Route;

/**
 * Finance Module Routes
 */

Route::middleware(['auth'])->prefix('finance')->name('finance.')->group(function () {

    // Dashboard
    Route::get('/dashboard', [FinanceDashboardController::class, 'index'])
        ->name('dashboard')
        ->middleware('permission:finance.dashboard');

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
    Route::get('/credit-notes', [CreditNoteController::class, 'index'])
        ->name('credit-notes.index')
        ->middleware('permission:finance.ap.view');
    Route::get('/credit-notes/create', [CreditNoteController::class, 'create'])
        ->name('credit-notes.create')
        ->middleware('permission:finance.ap.manage');
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
    Route::post('/payment-runs/{paymentRun}/approve', [PaymentRunController::class, 'approve'])
        ->name('payment-runs.approve')
        ->middleware('permission:finance.ap.manage');
    Route::post('/payment-runs/{paymentRun}/process', [PaymentRunController::class, 'process'])
        ->name('payment-runs.process')
        ->middleware('permission:finance.ap.manage');
    Route::get('/payment-runs/{paymentRun}/download', [PaymentRunController::class, 'download'])
        ->name('payment-runs.download')
        ->middleware('permission:finance.ap.manage');

    // ── Payment Allocations ─────────────────────────────────────────────
    Route::get('/payment-allocations', [PaymentAllocationController::class, 'index'])
        ->name('payment-allocations.index')
        ->middleware('permission:finance.ar.view');
    Route::post('/payment-allocations', [PaymentAllocationController::class, 'store'])
        ->name('payment-allocations.store')
        ->middleware('permission:finance.ar.manage');

    // ── Accounts Receivable ─────────────────────────────────────────────
    Route::middleware('permission:finance.ar.view')->group(function () {
        Route::get('/receivables', [AccountsReceivableController::class, 'index'])->name('receivables.index');
        Route::get('/receivables/aging', [AccountsReceivableController::class, 'aging'])->name('receivables.aging');
        Route::get('/receivables/statements', [AccountsReceivableController::class, 'statements'])->name('receivables.statements');
    });

    Route::post('/receivables/allocate', [AccountsReceivableController::class, 'allocate'])
        ->name('receivables.allocate')
        ->middleware('permission:finance.ar.manage');

    // ── Bank Accounts ───────────────────────────────────────────────────
    Route::get('/bank-accounts', [BankAccountController::class, 'index'])
        ->name('bank-accounts.index')
        ->middleware('permission:finance.bank.view');
    Route::get('/bank-accounts/create', [BankAccountController::class, 'create'])
        ->name('bank-accounts.create')
        ->middleware('permission:finance.bank.manage');
    Route::post('/bank-accounts', [BankAccountController::class, 'store'])
        ->name('bank-accounts.store')
        ->middleware('permission:finance.bank.manage');
    Route::get('/bank-accounts/{bankAccount}', [BankAccountController::class, 'show'])
        ->name('bank-accounts.show')
        ->middleware('permission:finance.bank.view');
    Route::get('/bank-accounts/{bankAccount}/edit', [BankAccountController::class, 'edit'])
        ->name('bank-accounts.edit')
        ->middleware('permission:finance.bank.manage');
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

    // ── Fixed Assets ────────────────────────────────────────────────────
    Route::get('/fixed-assets', [FixedAssetController::class, 'index'])
        ->name('fixed-assets.index')
        ->middleware('permission:finance.assets.view');
    Route::get('/fixed-assets/create', [FixedAssetController::class, 'create'])
        ->name('fixed-assets.create')
        ->middleware('permission:finance.assets.manage');
    Route::post('/fixed-assets', [FixedAssetController::class, 'store'])
        ->name('fixed-assets.store')
        ->middleware('permission:finance.assets.manage');
    Route::post('/fixed-assets/run-depreciation', [FixedAssetController::class, 'runDepreciation'])
        ->name('fixed-assets.run-depreciation')
        ->middleware('permission:finance.assets.manage');
    Route::get('/fixed-assets/{fixedAsset}', [FixedAssetController::class, 'show'])
        ->name('fixed-assets.show')
        ->middleware('permission:finance.assets.view');
    Route::get('/fixed-assets/{fixedAsset}/edit', [FixedAssetController::class, 'edit'])
        ->name('fixed-assets.edit')
        ->middleware('permission:finance.assets.manage');
    Route::put('/fixed-assets/{fixedAsset}', [FixedAssetController::class, 'update'])
        ->name('fixed-assets.update')
        ->middleware('permission:finance.assets.manage');
    Route::post('/fixed-assets/{fixedAsset}/dispose', [FixedAssetController::class, 'dispose'])
        ->name('fixed-assets.dispose')
        ->middleware('permission:finance.assets.manage');

    // ── Petty Cash ──────────────────────────────────────────────────────
    Route::get('/petty-cash', [PettyCashController::class, 'index'])
        ->name('petty-cash.index')
        ->middleware('permission:finance.petty_cash.view');
    Route::get('/petty-cash/create', [PettyCashController::class, 'create'])
        ->name('petty-cash.create')
        ->middleware('permission:finance.petty_cash.manage');
    Route::post('/petty-cash', [PettyCashController::class, 'store'])
        ->name('petty-cash.store')
        ->middleware('permission:finance.petty_cash.manage');
    Route::get('/petty-cash/{fund}', [PettyCashController::class, 'show'])
        ->name('petty-cash.show')
        ->middleware('permission:finance.petty_cash.view');
    Route::post('/petty-cash/{fund}/transaction', [PettyCashController::class, 'storeTransaction'])
        ->name('petty-cash.transaction')
        ->middleware('permission:finance.petty_cash.manage');

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
});
