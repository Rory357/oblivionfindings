<?php

use App\Domain\Hr\Models\HrBenefitEnrollment;
use App\Domain\Hr\Models\HrBonusPayment;
use App\Domain\Hr\Models\HrSalaryBand;
use Database\Seeders\HrDemoSeeder;

test('the HR demo seeder populates the compensation and benefits hubs (idempotently)', function () {
    // Run twice — the seeder must be idempotent (updateOrCreate on natural keys).
    $this->seed(HrDemoSeeder::class);
    $this->seed(HrDemoSeeder::class);

    // Compensation: salary bands + a review + bonuses.
    $this->assertDatabaseHas('hr_salary_bands', [
        'tenant_id' => 1,
        'position_role' => 'support_worker',
        'band_name' => 'Support Worker',
    ]);
    expect(HrSalaryBand::where('tenant_id', 1)->count())->toBe(3);

    $this->assertDatabaseHas('hr_compensation_reviews', [
        'tenant_id' => 1,
        'title' => 'FY2026 Annual Review',
        'review_cycle' => 'annual',
    ]);

    $this->assertDatabaseHas('hr_bonus_payments', [
        'tenant_id' => 1,
        'bonus_type' => 'spot',
    ]);
    expect(HrBonusPayment::where('tenant_id', 1)->count())->toBe(2);

    // Benefits: plans + enrollments.
    $this->assertDatabaseHas('hr_benefit_plans', [
        'tenant_id' => 1,
        'name' => 'KiwiSaver (Employer 3%)',
        'type' => 'kiwisaver',
    ]);
    expect(HrBenefitEnrollment::where('tenant_id', 1)->count())->toBe(3);
});
