<?php

namespace Database\Seeders;

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinBankAccount;
use App\Domain\Finance\Models\FinBill;
use App\Domain\Finance\Models\FinCreditNote;
use App\Domain\Finance\Models\FinDonorFund;
use App\Domain\Finance\Models\FinFixedAsset;
use App\Domain\Finance\Models\FinGstReturn;
use App\Domain\Finance\Models\FinInvoice;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Models\FinPaymentRun;
use App\Domain\Finance\Models\FinPettyCashFund;
use App\Domain\Finance\Models\FinPurchaseOrder;
use App\Domain\Finance\Models\FinVendor;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Populates the finance hubs (and the obligation Calendar) with a realistic demo
 * dataset for organization 1, so that on a fresh `migrate:fresh --seed` every
 * finance surface renders with data instead of an empty state. Mirrors the
 * factory recipe already proven by DuskDatabaseSeeder, but adds near-term
 * invoice/bill/payment-run/GST dates so the Finance Calendar shows live events
 * in the current view.
 *
 * Idempotent: skips when demo invoices already exist for the organisation.
 */
class FinanceDemoSeeder extends Seeder
{
    private const ORG_ID = 1;

    public function run(): void
    {
        if (FinInvoice::where('organization_id', self::ORG_ID)->exists()) {
            return;
        }

        // ── Ledger: a small chart of accounts + a couple of posted journals ──
        // `sub_type` matters: the bank-account modal only lists `bank` sub-type GL
        // accounts, and petty-cash likewise expects an asset GL — without a `bank`
        // account the Add-Bank-Account modal has an empty (unsubmittable) GL picker.
        $accounts = [
            ['code' => '1000', 'name' => 'Bank', 'type' => 'asset', 'sub_type' => 'bank'],
            ['code' => '1100', 'name' => 'Accounts Receivable', 'type' => 'asset', 'sub_type' => 'accounts_receivable'],
            ['code' => '2000', 'name' => 'Accounts Payable', 'type' => 'liability', 'sub_type' => 'accounts_payable'],
            ['code' => '2100', 'name' => 'GST Payable', 'type' => 'liability', 'sub_type' => 'current_liability'],
            ['code' => '4000', 'name' => 'Funding Revenue', 'type' => 'revenue', 'sub_type' => 'revenue'],
            ['code' => '5000', 'name' => 'Wages', 'type' => 'expense', 'sub_type' => 'expense'],
            ['code' => '6000', 'name' => 'Supplies', 'type' => 'expense', 'sub_type' => 'expense'],
            // Gain/Loss on Asset Disposal — the balancing leg of a disposal journal
            // (config finance.fixed_asset.gain_loss_account). Without it, disposing
            // an asset at a gain/loss dead-ends server-side.
            ['code' => '8400', 'name' => 'Gain/Loss on Asset Disposal', 'type' => 'expense', 'sub_type' => 'expense'],
        ];
        foreach ($accounts as $account) {
            FinAccount::factory()->create([
                'organization_id' => self::ORG_ID,
                'code' => $account['code'],
                'name' => $account['name'],
                'type' => $account['type'],
                'sub_type' => $account['sub_type'],
            ]);
        }

        FinJournal::factory()->count(3)->create([
            'organization_id' => self::ORG_ID,
            'status' => 'posted',
        ]);

        // An OPEN fiscal period covering "now" so the journal-posting modals
        // (donor receipt/expenditure, asset disposal, petty-cash top-up, …) can
        // actually post — JournalPostingService rejects any date without an open
        // period, so without this every posting modal dead-ends on a fresh seed.
        \App\Domain\Finance\Models\FinFiscalPeriod::firstOrCreate(
            [
                'organization_id' => self::ORG_ID,
                'start_date' => Carbon::now()->startOfYear()->toDateString(),
            ],
            [
                'name' => 'FY'.Carbon::now()->year,
                'end_date' => Carbon::now()->endOfYear()->toDateString(),
                'status' => 'open',
            ]
        );

        // ── Banking ──────────────────────────────────────────────────────────
        FinBankAccount::factory()->count(2)->create(['organization_id' => self::ORG_ID]);
        FinPettyCashFund::factory()->create(['organization_id' => self::ORG_ID]);

        // ── Payables: vendors + bills (near-term due) + a purchase order ─────
        $vendors = FinVendor::factory()->count(3)->create(['organization_id' => self::ORG_ID]);
        foreach ($vendors as $index => $vendor) {
            FinBill::factory()->create([
                'organization_id' => self::ORG_ID,
                'vendor_id' => $vendor->id,
                'status' => 'approved',
                'amount_paid' => 0,
                'due_date' => Carbon::now()->addDays(4 + $index * 5),
            ]);
        }
        // One overdue bill so the Calendar shows a critical (overdue) marker.
        FinBill::factory()->create([
            'organization_id' => self::ORG_ID,
            'vendor_id' => $vendors->first()->id,
            'status' => 'approved',
            'amount_paid' => 0,
            'due_date' => Carbon::now()->subDays(6),
        ]);
        FinPurchaseOrder::factory()->create([
            'organization_id' => self::ORG_ID,
            'vendor_id' => $vendors->first()->id,
        ]);

        FinPaymentRun::factory()->create([
            'organization_id' => self::ORG_ID,
            'status' => 'approved',
            'payment_date' => Carbon::now()->addDays(9),
        ]);

        // ── Receivables: invoices (near-term + overdue) + a credit note ──────
        FinInvoice::factory()->count(3)->create([
            'organization_id' => self::ORG_ID,
            'status' => 'sent',
        ]);
        FinInvoice::factory()->create([
            'organization_id' => self::ORG_ID,
            'status' => 'sent',
            'due_date' => Carbon::now()->addDays(7),
        ]);
        FinInvoice::factory()->create([
            'organization_id' => self::ORG_ID,
            'status' => 'sent',
            'due_date' => Carbon::now()->subDays(3),
        ]);
        FinInvoice::factory()->create([
            'organization_id' => self::ORG_ID,
            'status' => 'paid',
            'due_date' => Carbon::now()->subDays(10),
        ]);
        FinCreditNote::factory()->create(['organization_id' => self::ORG_ID]);

        // ── Tax: a recently-ended GST period so its filing deadline is soon ──
        FinGstReturn::factory()->create([
            'organization_id' => self::ORG_ID,
            'status' => 'draft',
            'period_start' => Carbon::now()->subMonths(2)->startOfMonth(),
            'period_end' => Carbon::now()->subMonth()->endOfMonth(),
            'gst_payable' => 1850.00,
        ]);

        // ── Extras so the remaining tabs aren't bare ─────────────────────────
        // Wire GL accounts so the disposal / donor-transaction modals can post a
        // real balanced journal on demo data (an unwired asset/fund posts nothing
        // — the modals correctly warn, but then the happy path is unreachable).
        $bankId = FinAccount::where('organization_id', self::ORG_ID)->where('code', '1000')->value('id');
        $assetGlId = FinAccount::where('organization_id', self::ORG_ID)->where('code', '1100')->value('id');
        $expenseGlId = FinAccount::where('organization_id', self::ORG_ID)->where('code', '6000')->value('id');
        $revenueGlId = FinAccount::where('organization_id', self::ORG_ID)->where('code', '4000')->value('id');

        FinFixedAsset::factory()->create([
            'organization_id' => self::ORG_ID,
            'status' => 'active',
            'gl_asset_account_id' => $assetGlId,
            'gl_depreciation_account_id' => $expenseGlId,
        ]);
        FinDonorFund::factory()->create([
            'organization_id' => self::ORG_ID,
            'gl_account_id' => $revenueGlId,
        ]);
    }
}
