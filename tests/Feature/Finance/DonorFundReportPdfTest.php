<?php

use App\Domain\Finance\Models\FinAccount;
use App\Domain\Finance\Models\FinDonorFund;
use App\Domain\Finance\Models\FinFiscalPeriod;
use App\Domain\Finance\Models\FinJournal;
use App\Domain\Finance\Services\DonorFundService;
use App\Models\User;
use Database\Seeders\FinanceSeeder;
use Illuminate\Support\Facades\Storage;

it('covers the donor fund receipt expenditure report PDF cycle', function () {
    app(FinanceSeeder::class)->run(1);

    FinFiscalPeriod::create([
        'organization_id' => 1,
        'name' => 'FY2026',
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'status' => 'open',
    ]);

    Storage::fake('local');

    $user = User::factory()->create(['organization_id' => 1]);
    $this->actingAs($user);

    $donorAccount = FinAccount::where('organization_id', 1)->where('code', '4220')->firstOrFail();
    $expenseAccount = FinAccount::where('organization_id', 1)->where('code', '6500')->firstOrFail();
    $fund = FinDonorFund::factory()->create([
        'organization_id' => 1,
        'fund_code' => 'DONOR-001',
        'fund_name' => 'Accessibility Grant',
        'fund_type' => 'grant',
        'gl_account_id' => $donorAccount->id,
        'status' => 'active',
        'is_restricted' => true,
        'total_received' => 0,
        'total_spent' => 0,
        'total_committed' => 0,
        'available_balance' => 0,
    ]);

    $service = app(DonorFundService::class);

    $receipt = $service->recordReceipt($fund, [
        'transaction_date' => '2026-05-01',
        'description' => 'Grant receipt',
        'amount' => 500,
        'reference' => 'DONOR-REC-001',
    ]);
    $fund->refresh();
    $expenditure = $service->recordExpenditure($fund, [
        'transaction_date' => '2026-05-02',
        'description' => 'Accessibility equipment',
        'amount' => 125,
        'reference' => 'DONOR-SPEND-001',
        'expense_account_id' => $expenseAccount->id,
    ]);

    $report = $service->generateReport($fund->refresh(), '2026-05-01', '2026-05-31');
    $exported = $service->exportReportPdf($report);

    expect($receipt->journal_id)->not->toBeNull()
        ->and($expenditure->journal_id)->not->toBeNull()
        ->and(FinJournal::whereKey([$receipt->journal_id, $expenditure->journal_id])->where('status', 'posted')->count())->toBe(2)
        ->and((string) $report->total_receipts)->toBe('500.00')
        ->and((string) $report->total_expenditure)->toBe('125.00')
        ->and((string) $report->closing_balance)->toBe('375.00')
        ->and($exported->file_path)->not->toBeNull();

    Storage::disk('local')->assertExists($exported->file_path);
});
