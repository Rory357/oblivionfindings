<?php

use App\Domain\Hr\Models\HrBenefitEnrollment;
use App\Domain\Hr\Models\HrBenefitPlan;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\BenefitsService;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

test('the benefits service rejects a cross-organisation plan before writes or notifications', function () {
    Notification::fake();
    $worker = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);
    $profile = HrEmployeeProfile::factory()->create([
        'tenant_id' => 1,
        'user_id' => $worker->id,
        'is_active' => true,
    ]);
    $foreignPlan = HrBenefitPlan::query()->create([
        'tenant_id' => 2,
        'name' => 'Foreign health plan',
        'type' => 'health_insurance',
        'employer_contribution_rate' => 0,
        'is_active' => true,
    ]);

    expect(fn () => app(BenefitsService::class)->enrollEmployee($profile, $foreignPlan, [
        'enrollment_date' => now()->toDateString(),
    ]))->toThrow(ValidationException::class);

    expect(HrBenefitEnrollment::query()->count())->toBe(0);
    Notification::assertNothingSent();
});
