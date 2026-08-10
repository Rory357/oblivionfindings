<?php

use App\Domain\Hr\Models\HrBenefitEnrollment;
use App\Domain\Hr\Models\HrBenefitPlan;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\BenefitsService;
use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Notification;

test('the benefits service conceals a profile outside the actor Site before writes or notifications', function () {
    Notification::fake();
    $site = Site::factory()->create(['name' => 'Benefits service visible Site']);
    $hiddenSite = Site::factory()->create(['name' => 'Benefits service hidden Site']);
    $actor = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $actor->id,
        'primary_site_id' => $site->id,
        'is_active' => true,
    ]);
    $worker = User::factory()->create(['approved_at' => now()]);
    $profile = HrEmployeeProfile::factory()->create([
        'user_id' => $worker->id,
        'primary_site_id' => $hiddenSite->id,
        'is_active' => true,
    ]);
    $plan = HrBenefitPlan::query()->create([
        'name' => 'Application health plan',
        'type' => 'health_insurance',
        'employer_contribution_rate' => 0,
        'is_active' => true,
    ]);

    expect(fn () => app(BenefitsService::class)->enrollEmployee($profile, $plan, [
        'enrollment_date' => now()->toDateString(),
    ], $actor))->toThrow(ModelNotFoundException::class);

    expect(HrBenefitEnrollment::query()->count())->toBe(0);
    Notification::assertNothingSent();
});
