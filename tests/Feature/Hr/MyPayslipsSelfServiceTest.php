<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrPayslip;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    // Plain employee — NO role synced, so no hr.payslips.view permission.
    $this->worker = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $this->workerProfile = makePayslipProfile($this->worker->id);

    $this->other = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $this->otherProfile = makePayslipProfile($this->other->id);
});

function makePayslipProfile(int $userId): HrEmployeeProfile
{
    return HrEmployeeProfile::query()->create([
        'user_id' => $userId,
        'employee_number' => 'EMP-'.$userId,
        'work_email' => 'emp'.$userId.'@example.test',
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
    ]);
}

function makePayslipFor(int $userId, int $profileId, array $overrides = []): HrPayslip
{
    return HrPayslip::query()->create(array_merge([
        'user_id' => $userId,
        'employee_profile_id' => $profileId,
        'pay_period_start' => now()->startOfMonth()->toDateString(),
        'pay_period_end' => now()->endOfMonth()->toDateString(),
        'payment_date' => now()->toDateString(),
        'gross_pay' => 5000,
        'paye' => 900,
        'acc_levy' => 80,
        'kiwisaver_employee' => 150,
        'kiwisaver_employer' => 150,
        'student_loan' => 0,
        'holiday_pay' => 0,
        'total_deductions' => 1130,
        'net_pay' => 3870,
        'status' => 'final',
    ], $overrides));
}

test('an employee can view their own payslip via self-service (no hr.payslips.view needed)', function () {
    $payslip = makePayslipFor($this->worker->id, $this->workerProfile->id);

    $this->actingAs($this->worker)
        ->get("/hr/my/payslips/{$payslip->id}")
        ->assertOk();
});

test('an employee can download their own payslip via self-service', function () {
    Storage::fake('private');
    Storage::disk('private')->put('payslips/own.html', '<html>payslip</html>');
    $payslip = makePayslipFor($this->worker->id, $this->workerProfile->id, ['pdf_path' => 'payslips/own.html']);

    $this->actingAs($this->worker)
        ->get("/hr/my/payslips/{$payslip->id}/download")
        ->assertOk();
});

test('an employee cannot view another persons payslip', function () {
    $othersPayslip = makePayslipFor($this->other->id, $this->otherProfile->id);

    $this->actingAs($this->worker)
        ->get("/hr/my/payslips/{$othersPayslip->id}")
        ->assertForbidden();
});
