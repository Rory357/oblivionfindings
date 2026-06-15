<?php

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinFundingStream;
use App\Domain\Finance\Models\FinInvoice;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Models\FinJournalLine;
use App\Domain\Finance\Services\DashboardAggregatorService;
use App\Models\BillingEntry;
use App\Models\FundingClaim;
use App\Models\ServiceAgreement;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Phase B lock-in: DashboardAggregatorService scopes posted-GL totals to the
 * selected period (month / quarter / NZ financial year) and to optional
 * funding-stream filters, and breaks revenue down by funding stream. Draft
 * journals are never counted.
 *
 * Time is frozen to 2026-06-15 (June → Q2 → NZ FY 1 Apr 2026 – 31 Mar 2027).
 */
beforeEach(function () {
    Carbon::setTestNow(Carbon::create(2026, 6, 15, 12));
});

afterEach(function () {
    Carbon::setTestNow();
});

function fdAccount(string $code, string $type): FinAccount
{
    return FinAccount::factory()->create([
        'organization_id' => 1,
        'code' => $code,
        'name' => $code.' account',
        'type' => $type,
        'is_active' => true,
    ]);
}

function fdStream(string $code, string $name): FinFundingStream
{
    return FinFundingStream::create([
        'organization_id' => 1,
        'code' => $code,
        'name' => $name,
        'funder_type' => 'other',
        'is_active' => true,
    ]);
}

function fdPostRevenue(FinAccount $rev, string $date, float $amount, ?int $streamId, string $status = 'posted'): void
{
    $journal = FinJournal::create([
        'organization_id' => 1,
        'journal_number' => 'JNL-'.uniqid(),
        'journal_date' => $date,
        'type' => 'standard',
        'status' => $status,
        'total_amount' => $amount,
        'posted_at' => $status === 'posted' ? now() : null,
    ]);
    FinJournalLine::create([
        'journal_id' => $journal->id,
        'account_id' => $rev->id,
        'credit' => $amount,
        'debit' => 0,
        'funding_stream_id' => $streamId,
    ]);
}

it('scopes revenue and expense totals to the selected period', function () {
    $rev = fdAccount('4000', 'revenue');
    $exp = fdAccount('6000', 'expense');
    $moh = fdStream('MOH', 'MoH');
    $acc = fdStream('ACC', 'ACC');

    fdPostRevenue($rev, '2026-06-10', 1000, $moh->id);       // month + quarter + fy
    fdPostRevenue($rev, '2026-04-15', 500, $acc->id);        // quarter + fy
    fdPostRevenue($rev, '2026-09-10', 300, $moh->id);        // fy only (Q3, same NZ FY)
    fdPostRevenue($rev, '2026-06-11', 9999, $moh->id, 'draft'); // never counted

    $journal = FinJournal::create([
        'organization_id' => 1, 'journal_number' => 'JNL-EXP', 'journal_date' => '2026-06-12',
        'type' => 'standard', 'status' => 'posted', 'total_amount' => 200, 'posted_at' => now(),
    ]);
    FinJournalLine::create(['journal_id' => $journal->id, 'account_id' => $exp->id, 'debit' => 200, 'credit' => 0]);

    $svc = app(DashboardAggregatorService::class);

    $month = $svc->getDashboardData(1, 'month');
    expect($month['totalRevenue'])->toBe(1000.0)
        ->and($month['totalExpenses'])->toBe(200.0)
        ->and($month['netProfit'])->toBe(800.0)
        ->and($month['period'])->toBe('month');

    expect($svc->getDashboardData(1, 'quarter')['totalRevenue'])->toBe(1500.0);
    expect($svc->getDashboardData(1, 'fy')['totalRevenue'])->toBe(1800.0);
});

it('applies the funding-stream filter to revenue totals', function () {
    $rev = fdAccount('4000', 'revenue');
    $moh = fdStream('MOH', 'MoH');
    $acc = fdStream('ACC', 'ACC');

    fdPostRevenue($rev, '2026-06-10', 1000, $moh->id);
    fdPostRevenue($rev, '2026-04-15', 500, $acc->id);
    fdPostRevenue($rev, '2026-09-10', 300, $moh->id);

    $svc = app(DashboardAggregatorService::class);

    expect($svc->getDashboardData(1, 'month', [], [$moh->id])['totalRevenue'])->toBe(1000.0);
    expect($svc->getDashboardData(1, 'quarter', [], [$acc->id])['totalRevenue'])->toBe(500.0);
    expect($svc->getDashboardData(1, 'fy', [], [$moh->id])['totalRevenue'])->toBe(1300.0);
});

it('breaks revenue down by funding stream for the period', function () {
    $rev = fdAccount('4000', 'revenue');
    $moh = fdStream('MOH', 'MoH');
    $acc = fdStream('ACC', 'ACC');

    fdPostRevenue($rev, '2026-06-10', 1000, $moh->id);
    fdPostRevenue($rev, '2026-04-15', 500, $acc->id);

    $svc = app(DashboardAggregatorService::class);
    $byStream = collect($svc->getDashboardData(1, 'quarter')['revenueByFundingStream']);

    expect($byStream->firstWhere('name', 'MoH')['amount'])->toBe(1000.0)
        ->and($byStream->firstWhere('name', 'ACC')['amount'])->toBe(500.0);
});

it('sources AR and aging from the live FinInvoice table, not the legacy orphan (gap 3.1)', function () {
    FinInvoice::create([
        'organization_id' => 1, 'invoice_number' => 'INV-CUR', 'invoice_date' => '2026-06-01',
        'due_date' => '2026-06-20', 'client_name' => 'Acme Trust', 'total_amount' => 1000, 'status' => 'sent',
    ]);
    FinInvoice::create([
        'organization_id' => 1, 'invoice_number' => 'INV-OLD', 'invoice_date' => '2026-02-20',
        'due_date' => '2026-03-01', 'client_name' => 'Acme Trust', 'total_amount' => 500, 'status' => 'sent',
    ]);
    // Paid + draft invoices must NOT count toward outstanding AR.
    FinInvoice::create([
        'organization_id' => 1, 'invoice_number' => 'INV-PAID', 'invoice_date' => '2026-06-01',
        'due_date' => '2026-06-20', 'client_name' => 'Acme Trust', 'total_amount' => 9999, 'status' => 'paid',
    ]);

    $data = app(DashboardAggregatorService::class)->getDashboardData(1, 'month');

    expect($data['accountsReceivable'])->toBe(1500.0)
        ->and($data['arAging']['current'])->toBe(1000.0)
        ->and($data['arAging']['d90_plus'])->toBe(500.0)
        ->and($data['arAging']['over60'])->toBe(500.0)
        ->and($data['arAging']['total'])->toBe(1500.0);
});

it('aggregates funding-claim utilisation buckets and the claims table (Phase D)', function () {
    $agreement = ServiceAgreement::factory()->create([
        'organization_id' => 1,
        'funding_body' => 'MoH Disability',
    ]);
    $clientId = $agreement->client_id;
    $staff = User::factory()->create();

    foreach ([['paid', 300, 'FC-1'], ['approved', 80, 'FC-2'], ['submitted', 20, 'FC-3'], ['draft', 999, 'FC-4']] as [$status, $amount, $ref]) {
        FundingClaim::create([
            'organization_id' => 1, 'service_agreement_id' => $agreement->id, 'client_id' => $clientId,
            'claim_reference' => $ref, 'status' => $status, 'period_start' => '2026-06-01',
            'period_end' => '2026-06-30', 'total_amount' => $amount,
        ]);
    }

    $mkBilling = function (string $status, string $date, float $amount) use ($clientId, $staff) {
        BillingEntry::create([
            'organization_id' => 1, 'client_id' => $clientId, 'staff_id' => $staff->id,
            'service_date' => $date, 'hours' => 1, 'rate' => $amount, 'amount' => $amount, 'status' => $status,
        ]);
    };
    $mkBilling('pending', '2026-06-10', 50);    // recent → delivered_unclaimed
    $mkBilling('pending', '2026-01-01', 25);    // >90d old → write_off_risk
    $mkBilling('invoiced', '2026-06-10', 500);  // not 'pending' → excluded

    $data = app(DashboardAggregatorService::class)->getDashboardData(1, 'month');
    $u = $data['fundingUtilisation'];

    expect($u['claimed_paid'])->toBe(300.0)
        ->and($u['awaiting_remittance'])->toBe(100.0)   // approved 80 + submitted 20
        ->and($u['delivered_unclaimed'])->toBe(50.0)
        ->and($u['write_off_risk'])->toBe(25.0)
        ->and($u['unclaimed_total'])->toBe(75.0)
        ->and($u['utilisation_pct'])->toBe(84);          // 400 claimed / 475 deliverable

    $claims = collect($data['fundingClaims']);
    expect($claims->firstWhere('reference', 'FC-1')['funder'])->toBe('MoH Disability')
        ->and($claims->firstWhere('reference', 'FC-1')['status'])->toBe('paid');
});
