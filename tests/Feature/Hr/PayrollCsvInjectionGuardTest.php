<?php

use App\Domain\Finance\Services\PayrollJournalService;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrPayrollRun;
use App\Domain\Hr\Models\HrPayslip;
use App\Domain\Hr\Services\PayrollExportService;
use App\Models\User;

/**
 * Close-out C1 (Decision D-6) — payroll CSV formula-injection guard.
 *
 * The app-wide `SanitizesCsvOutput` trait (mounted on the base Controller since
 * the eMAR sweep) neutralises cells starting =/+/-/@/tab/CR by prefixing an
 * apostrophe. Both payroll CSV builders hand-assemble their output in services
 * and bypassed it entirely:
 *   - PayrollExportService::encodeCsvRow — the payroll-run export (employee
 *     names + free-text static profile values flow in);
 *   - PayrollJournalService::buildNetPayDirectCreditCsv — the net-pay bank
 *     batch (employee names again; names are user-chosen).
 * Both now run every cell through sanitizeCsvCell. Purely-numeric cells
 * (negative amounts, +64 phone formats) are deliberately left untouched so
 * bank/payroll imports keep exact values.
 */
test('C1: the payroll run export CSV neutralises formula-leading cells but leaves numerics intact', function () {
    $service = app(PayrollExportService::class);

    $method = new ReflectionMethod($service, 'buildCsvFromRows');
    $csv = $method->invoke(
        $service,
        [[
            'name' => '=cmd|\'/c calc\'!A1',
            'net_pay' => '-1234.50',
        ]],
        [
            ['header' => 'Employee Name', 'source' => 'name'],
            ['header' => 'Net Pay', 'source' => 'net_pay'],
            ['header' => 'Memo', 'source' => 'static', 'value' => '@SUM(A1:A9)'],
        ],
        ',',
        '"',
        "\n",
        true,
    );

    // The user-chosen name and the free-text static value are neutralised…
    expect($csv)->toContain('"\'=cmd');
    expect($csv)->toContain('"\'@SUM(A1:A9)"');
    // …while a purely numeric (negative) amount keeps its exact value.
    expect($csv)->toContain('"-1234.50"');
});

test('C1: the net-pay direct-credit CSV neutralises a formula-leading employee name', function () {
    $staff = User::factory()->create([
        'name' => '=HYPERLINK("http://evil.test","Pay me")',
    ]);
    $profile = HrEmployeeProfile::factory()->create([
        'user_id' => $staff->id,
        'bank_account' => '12-3456-7890123-00',
    ]);
    $run = HrPayrollRun::factory()->create([
        'status' => 'locked',
        'period_start' => '2026-06-01',
        'period_end' => '2026-06-30',
    ]);
    HrPayslip::create([
        'payroll_run_id' => $run->id,
        'employee_profile_id' => $profile->id,
        'user_id' => $staff->id,
        'pay_period_start' => '2026-06-01',
        'pay_period_end' => '2026-06-30',
        'gross_pay' => 4200.00,
        'net_pay' => 3150.75,
    ]);

    $csv = app(PayrollJournalService::class)->buildNetPayDirectCreditCsv($run->fresh());

    // The name cell is apostrophe-prefixed, so the formula can never execute…
    expect($csv)->toContain("'=HYPERLINK(");
    // …no line begins with a live formula…
    foreach (preg_split('/\r?\n/', trim($csv)) as $line) {
        expect(str_starts_with($line, '='))->toBeFalse();
    }
    // …and the bank account + amount survive exactly (import fidelity).
    expect($csv)->toContain('12-3456-7890123-00');
    expect($csv)->toContain('3150.75');
});
