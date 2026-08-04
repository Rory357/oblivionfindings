<?php

use App\Domain\Hr\Models\HrApprovalChain;
use App\Domain\Hr\Models\HrBenefitEnrollment;
use App\Domain\Hr\Models\HrBonusPayment;
use App\Domain\Hr\Models\HrDriverEligibility;
use App\Domain\Hr\Models\HrSalaryBand;
use App\Domain\Hr\Models\HrSavedReport;
use Database\Seeders\HrDemoSeeder;

test('the HR demo seeder populates the compensation and benefits hubs (idempotently)', function () {
    // Run twice — the seeder must be idempotent (updateOrCreate on natural keys).
    $this->seed(HrDemoSeeder::class);
    $this->seed(HrDemoSeeder::class);

    // Compensation: salary bands + a review + bonuses.
    $this->assertDatabaseHas('hr_salary_bands', [
        'position_role' => 'support_worker',
        'band_name' => 'Support Worker',
    ]);
    expect(HrSalaryBand::query()->count())->toBe(3);

    $this->assertDatabaseHas('hr_compensation_reviews', [
        'title' => 'FY2026 Annual Review',
        'review_cycle' => 'annual',
    ]);

    $this->assertDatabaseHas('hr_bonus_payments', [
        'bonus_type' => 'spot',
    ]);
    expect(HrBonusPayment::query()->count())->toBe(2);

    // Benefits: plans + enrollments.
    $this->assertDatabaseHas('hr_benefit_plans', [
        'name' => 'KiwiSaver (Employer 3%)',
        'type' => 'kiwisaver',
    ]);
    expect(HrBenefitEnrollment::query()->count())->toBe(3);
});

test('the HR demo seeder populates drivers, vetting, approval chains and saved reports', function () {
    $this->seed(HrDemoSeeder::class);
    $this->seed(HrDemoSeeder::class);

    // Drivers register.
    $this->assertDatabaseHas('hr_driver_eligibility', [
        'licence_number' => 'DL-DEMO-001',
        'status' => 'eligible',
    ]);
    expect(HrDriverEligibility::query()->count())->toBe(2);

    // Vetting / background checks.
    $this->assertDatabaseHas('staff_background_checks', [
        'check_type' => 'police_check',
        'status' => 'clear',
        'reference_number' => 'PV-DEMO-001',
    ]);

    // Approval chains (2 chains, each with one step).
    $this->assertDatabaseHas('hr_approval_chains', [
        'name' => 'Leave Approval',
        'process_type' => 'leave',
    ]);
    expect(HrApprovalChain::query()->count())->toBe(2);

    // Creator-owned saved reports.
    $this->assertDatabaseHas('hr_saved_reports', [
        'name' => 'Active Staff',
        'report_type' => 'employee',
    ]);
    expect(HrSavedReport::query()->count())->toBe(2)
        ->and(HrSavedReport::query()->distinct()->count('created_by'))->toBe(1);
});
