<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrPayslip;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->visibleSite = Site::factory()->create();
    $this->hiddenSite = Site::factory()->create();

    $this->manager = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $this->manager->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->firstOrFail()->id,
    ]);
    $this->managerProfile = payslipCanonicalProfile($this->manager, $this->visibleSite, 'PAY-MGR');

    $this->visibleWorker = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $this->visibleProfile = payslipCanonicalProfile($this->visibleWorker, $this->visibleSite, 'PAY-A');

    $this->hiddenWorker = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $this->hiddenProfile = payslipCanonicalProfile($this->hiddenWorker, $this->hiddenSite, 'PAY-B');
});

function payslipCanonicalProfile(User $user, Site $site, string $number): HrEmployeeProfile
{
    return HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'employee_number' => $number,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'annual_salary' => 72000,
        'tax_code' => 'M',
        'kiwisaver_rate' => 3,
        'pay_frequency' => 'fortnightly',
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
    ]);
}

function payslipCanonicalRecord(User $user, HrEmployeeProfile $profile, array $overrides = []): HrPayslip
{
    return HrPayslip::query()->create([
        'user_id' => $user->id,
        'employee_profile_id' => $profile->id,
        'pay_period_start' => '2026-07-01',
        'pay_period_end' => '2026-07-14',
        'gross_pay' => 3000,
        'paye' => 540,
        'acc_levy' => 48,
        'kiwisaver_employee' => 90,
        'kiwisaver_employer' => 90,
        'student_loan' => 0,
        'holiday_pay' => 0,
        'total_deductions' => 678,
        'net_pay' => 2322,
        'status' => 'draft',
        ...$overrides,
    ]);
}

test('the payroll payslip worklist and counts use canonical historical Site visibility', function (): void {
    $visible = payslipCanonicalRecord($this->visibleWorker, $this->visibleProfile);
    $hidden = payslipCanonicalRecord($this->hiddenWorker, $this->hiddenProfile);

    $this->actingAs($this->manager)
        ->get('/hr/payroll/payslips')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('payslips.data', function ($rows) use ($visible, $hidden): bool {
                $ids = collect($rows)->pluck('id');

                return $ids->contains($visible->id) && ! $ids->contains($hidden->id);
            })
            ->where('employees', fn ($rows): bool => collect($rows)->pluck('id')->contains($this->visibleWorker->id)
                && ! collect($rows)->pluck('id')->contains($this->hiddenWorker->id))
            ->where('statusCounts.total', 1)
            ->where('statusCounts.draft', 1));
});

test('manager direct payslip access is concealed outside visible Sites while exact owners retain access', function (): void {
    $visible = payslipCanonicalRecord($this->visibleWorker, $this->visibleProfile);
    $hidden = payslipCanonicalRecord($this->hiddenWorker, $this->hiddenProfile);

    $this->actingAs($this->manager)
        ->get("/hr/payroll/payslips/{$visible->id}")
        ->assertOk();
    $this->actingAs($this->manager)
        ->get("/hr/payroll/payslips/{$hidden->id}")
        ->assertNotFound();
    $this->actingAs($this->hiddenWorker)
        ->get("/hr/my/payslips/{$hidden->id}")
        ->assertOk();
});

test('selected generation accepts only current staff at a visible Site and is idempotent per pay period', function (): void {
    $payload = [
        'period_start' => '2026-07-15',
        'period_end' => '2026-07-28',
    ];

    $this->actingAs($this->manager)
        ->post('/hr/payroll/payslips/generate', [
            ...$payload,
            'employee_profile_id' => $this->hiddenProfile->id,
        ])
        ->assertNotFound();

    $visiblePayload = [
        ...$payload,
        'employee_profile_id' => $this->visibleProfile->id,
    ];
    $this->actingAs($this->manager)
        ->post('/hr/payroll/payslips/generate', $visiblePayload)
        ->assertRedirect();
    $this->actingAs($this->manager)
        ->post('/hr/payroll/payslips/generate', $visiblePayload)
        ->assertRedirect();

    expect(HrPayslip::query()
        ->where('user_id', $this->visibleWorker->id)
        ->whereDate('pay_period_start', $payload['period_start'])
        ->whereDate('pay_period_end', $payload['period_end'])
        ->count())->toBe(1)
        ->and(HrPayslip::query()
            ->where('user_id', $this->hiddenWorker->id)
            ->whereDate('pay_period_start', $payload['period_start'])
            ->count())->toBe(0);
});

test('a former employee cannot use the self-service payslip surface', function (): void {
    $former = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $formerProfile = payslipCanonicalProfile($former, $this->visibleSite, 'PAY-FORMER');
    $formerProfile->update([
        'is_active' => false,
        'end_date' => today()->subDay(),
    ]);
    $payslip = payslipCanonicalRecord($former, $formerProfile);

    $this->actingAs($former)
        ->get("/hr/my/payslips/{$payslip->id}")
        ->assertNotFound();
    $this->actingAs($former)
        ->get('/hr/my/payslips')
        ->assertForbidden();
});
