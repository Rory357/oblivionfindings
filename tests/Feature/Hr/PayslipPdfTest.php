<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrPayslip;
use App\Domain\Hr\Services\PayslipService;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->worker = User::factory()->create([
        'organization_id' => 1,
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $this->profile = HrEmployeeProfile::query()->create([
        'tenant_id' => 1,
        'user_id' => $this->worker->id,
        'employee_number' => 'EMP-PDF-'.$this->worker->id,
        'work_email' => 'pdf'.$this->worker->id.'@example.test',
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
    ]);
});

function makePdfPayslip(int $userId, int $profileId, array $overrides = []): HrPayslip
{
    return HrPayslip::query()->create(array_merge([
        'tenant_id' => 1,
        'user_id' => $userId,
        'employee_profile_id' => $profileId,
        'pay_period_start' => now()->startOfMonth()->toDateString(),
        'pay_period_end' => now()->endOfMonth()->toDateString(),
        'payment_date' => now()->toDateString(),
        'gross_pay' => 5000,
        'regular_hours' => 80,
        'overtime_hours' => 0,
        'paye' => 900,
        'acc_levy' => 80,
        'kiwisaver_rate' => 3,
        'kiwisaver_employee' => 150,
        'kiwisaver_employer' => 150,
        'student_loan' => 0,
        'holiday_pay' => 0,
        'total_deductions' => 1130,
        'net_pay' => 3870,
        'tax_code' => 'M',
        'status' => 'final',
    ], $overrides));
}

test('generatePayslipPdf writes a real PDF (%PDF magic bytes) under a .pdf path', function () {
    Storage::fake('private');

    $payslip = makePdfPayslip($this->worker->id, $this->profile->id);

    $path = app(PayslipService::class)->generatePayslipPdf($payslip);

    expect($path)->toEndWith('.pdf');
    Storage::disk('private')->assertExists($path);

    $contents = Storage::disk('private')->get($path);
    expect(substr($contents, 0, 4))->toBe('%PDF');

    expect($payslip->fresh()->pdf_path)->toBe($path);
});

test('downloading an own payslip serves application/pdf', function () {
    Storage::fake('private');

    $payslip = makePdfPayslip($this->worker->id, $this->profile->id);

    $response = $this->actingAs($this->worker)
        ->get("/hr/my/payslips/{$payslip->id}/download");

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('application/pdf');
    expect($response->headers->get('content-disposition'))->toContain('.pdf');
});

test('downloading upgrades a stale pre-PDF (.html) artefact to a real PDF', function () {
    Storage::fake('private');
    Storage::disk('private')->put('payslips/legacy.html', '<html>old payslip</html>');

    $payslip = makePdfPayslip($this->worker->id, $this->profile->id, ['pdf_path' => 'payslips/legacy.html']);

    $response = $this->actingAs($this->worker)
        ->get("/hr/my/payslips/{$payslip->id}/download");

    $response->assertOk();
    expect($payslip->fresh()->pdf_path)->toEndWith('.pdf');
});
