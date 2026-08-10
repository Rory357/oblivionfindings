<?php

use App\Domain\Hr\Models\HrBenefitEnrollment;
use App\Domain\Hr\Models\HrBenefitPlan;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrSalaryBand;
use App\Domain\Hr\Notifications\BenefitEnrolledNotification;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);
    Notification::fake();

    $this->site = Site::factory()->create(['name' => 'Benefits canonical visible Site']);
    $this->hiddenSite = Site::factory()->create(['name' => 'Benefits canonical hidden Site']);
    $this->manager = User::factory()->create([
        'name' => 'Benefits HR manager',
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $this->manager->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->firstOrFail()->id,
    ]);
    $this->worker = User::factory()->create([
        'name' => 'Benefits visible worker',
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $this->hiddenWorker = User::factory()->create([
        'name' => 'Benefits hidden worker',
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $this->formerWorker = User::factory()->create([
        'name' => 'Benefits former worker',
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $this->managerProfile = benefitCanonicalProfile($this->manager, $this->site);
    $this->workerProfile = benefitCanonicalProfile($this->worker, $this->site, [
        'annual_salary' => 65000,
    ]);
    $this->hiddenProfile = benefitCanonicalProfile($this->hiddenWorker, $this->hiddenSite, [
        'annual_salary' => 91000,
    ]);
    $this->formerProfile = benefitCanonicalProfile($this->formerWorker, $this->site, [
        'is_active' => false,
        'end_date' => today()->subDay(),
    ]);
    $this->plan = benefitCanonicalPlan('Application health cover');
    $this->visibleEnrollment = benefitCanonicalEnrollment($this->workerProfile, $this->plan);
    $this->hiddenEnrollment = benefitCanonicalEnrollment($this->hiddenProfile, $this->plan);
});

function benefitCanonicalProfile(User $user, Site $site, array $overrides = []): HrEmployeeProfile
{
    return HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'position_role' => 'support_worker',
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
        ...$overrides,
    ]);
}

function benefitCanonicalPlan(string $name, array $overrides = []): HrBenefitPlan
{
    return HrBenefitPlan::query()->create([
        'name' => $name,
        'type' => 'health_insurance',
        'employer_contribution_rate' => 2,
        'is_active' => true,
        ...$overrides,
    ]);
}

function benefitCanonicalEnrollment(
    HrEmployeeProfile $profile,
    HrBenefitPlan $plan,
    array $overrides = [],
): HrBenefitEnrollment {
    return HrBenefitEnrollment::query()->create([
        'employee_profile_id' => $profile->id,
        'benefit_plan_id' => $plan->id,
        'enrollment_date' => today(),
        'status' => 'active',
        'employee_contribution_rate' => 4,
        'employer_contribution_rate' => 2,
        ...$overrides,
    ]);
}

function benefitCanonicalGrantView(User $user): void
{
    $permissions = Permission::query()
        ->whereIn('key', ['hr.benefits.view', 'hr.benefits.manage'])
        ->get();
    $user->permissionOverrides()->syncWithoutDetaching(
        $permissions->mapWithKeys(fn (Permission $permission): array => [
            $permission->id => ['allowed' => true],
        ])->all(),
    );
}

test('the register picker summaries and salary preview use canonical Site access', function (): void {
    $response = $this->actingAs($this->manager)
        ->get('/hr/compensation/benefits')
        ->assertOk();

    $enrollmentIds = collect($response->inertiaProps('enrollments.data'))->pluck('id');
    $employeeIds = collect($response->inertiaProps('employees'))->pluck('id');
    $planIds = collect($response->inertiaProps('plans'))->pluck('id');
    $salaryMap = collect($response->inertiaProps('annualSalaryByProfileId'));

    expect($enrollmentIds)->toContain($this->visibleEnrollment->id)
        ->not->toContain($this->hiddenEnrollment->id)
        ->and($employeeIds)->toContain($this->managerProfile->id, $this->workerProfile->id)
        ->not->toContain($this->hiddenProfile->id, $this->formerProfile->id)
        ->and($planIds)->toContain($this->plan->id)
        ->and($response->inertiaProps('summary.health_insurance.total_enrolled'))->toBe(1)
        ->and($response->inertiaProps('tabCounts.benefits'))->toBe(1)
        ->and($salaryMap)->toHaveKey((string) $this->workerProfile->id)
        ->not->toHaveKey((string) $this->hiddenProfile->id);
});

test('plan enrollment counts include only Site-visible historical staff', function (): void {
    $response = $this->actingAs($this->manager)
        ->get('/hr/compensation/benefits/plans')
        ->assertOk();
    $plan = collect($response->inertiaProps('plans.data'))->firstWhere('id', $this->plan->id);

    expect($plan)->not->toBeNull()
        ->and($plan['enrollments_count'])->toBe(1);
});

test('hidden enrollment mutations and hidden current profile enrollment are concealed', function (): void {
    $this->actingAs($this->manager)
        ->put("/hr/compensation/benefits/enrollments/{$this->hiddenEnrollment->id}", [
            'status' => 'terminated',
        ])
        ->assertNotFound();

    $newPlan = benefitCanonicalPlan('Application life cover', ['type' => 'life_insurance']);
    $this->actingAs($this->manager)
        ->post('/hr/compensation/benefits/enroll', [
            'employee_profile_id' => $this->hiddenProfile->id,
            'benefit_plan_id' => $newPlan->id,
            'enrollment_date' => today()->toDateString(),
            'employee_contribution_rate' => 3,
        ])
        ->assertNotFound();

    expect($this->hiddenEnrollment->fresh()->status)->toBe('active')
        ->and(HrBenefitEnrollment::query()
            ->where('employee_profile_id', $this->hiddenProfile->id)
            ->where('benefit_plan_id', $newPlan->id)
            ->exists())->toBeFalse();
    Notification::assertNothingSent();
});

test('enrollment requires an active application plan and is idempotent per employee plan', function (): void {
    $plan = benefitCanonicalPlan('Application wellbeing cover');
    $payload = [
        'employee_profile_id' => $this->workerProfile->id,
        'benefit_plan_id' => $plan->id,
        'enrollment_date' => today()->toDateString(),
        'employee_contribution_rate' => 5,
    ];

    $this->actingAs($this->manager)
        ->post('/hr/compensation/benefits/enroll', $payload)
        ->assertSessionHas('success');
    $this->actingAs($this->manager)
        ->post('/hr/compensation/benefits/enroll', $payload)
        ->assertSessionHasErrors('benefit_plan_id');

    expect(HrBenefitEnrollment::query()
        ->where('employee_profile_id', $this->workerProfile->id)
        ->where('benefit_plan_id', $plan->id)
        ->count())->toBe(1);
    Notification::assertSentToTimes($this->worker, BenefitEnrolledNotification::class, 1);

    $inactive = benefitCanonicalPlan('Closed benefit plan', ['is_active' => false]);
    $this->actingAs($this->manager)
        ->post('/hr/compensation/benefits/enroll', [
            ...$payload,
            'benefit_plan_id' => $inactive->id,
        ])
        ->assertNotFound();
});

test('material enrollment updates synchronize KiwiSaver and notify the covered employee', function (): void {
    $kiwiSaver = benefitCanonicalPlan('Application KiwiSaver', [
        'type' => 'kiwisaver',
        'employer_contribution_rate' => 3,
    ]);
    $enrollment = benefitCanonicalEnrollment($this->workerProfile, $kiwiSaver, [
        'employee_contribution_rate' => 6,
        'employer_contribution_rate' => 3,
    ]);
    $this->workerProfile->update(['kiwisaver_rate' => 6]);

    $this->actingAs($this->manager)
        ->put("/hr/compensation/benefits/enrollments/{$enrollment->id}", [
            'status' => 'opted_out',
            'opt_out_date' => today()->toDateString(),
        ])
        ->assertSessionHas('success');

    expect($enrollment->fresh()->status)->toBe('opted_out')
        ->and((float) $this->workerProfile->fresh()->kiwisaver_rate)->toBe(0.0);
    Notification::assertSentToTimes($this->worker, BenefitEnrolledNotification::class, 1);
});

test('plan names are application unique and lifecycle mutation is serialized', function (): void {
    $this->actingAs($this->manager)
        ->post('/hr/compensation/benefits/plans', [
            'name' => $this->plan->name,
            'type' => 'other',
            'employer_contribution_rate' => 0,
        ])
        ->assertSessionHasErrors('name');

    $this->actingAs($this->manager)
        ->put("/hr/compensation/benefits/plans/{$this->plan->id}", ['is_active' => false])
        ->assertSessionHas('success');

    expect($this->plan->fresh()->is_active)->toBeFalse()
        ->and(HrBenefitPlan::query()->where('name', $this->plan->name)->count())->toBe(1);
});

test('benefits managers without compensation access never receive salary data or statistics', function (): void {
    HrSalaryBand::query()->create([
        'position_role' => 'support_worker',
        'band_name' => 'Support worker standard',
        'min_salary' => 55000,
        'mid_salary' => 65000,
        'max_salary' => 75000,
        'min_hourly' => 26,
        'max_hourly' => 36,
        'currency' => 'NZD',
        'effective_from' => today()->subMonth(),
        'is_active' => true,
        'created_by' => $this->manager->id,
    ]);
    benefitCanonicalGrantView($this->worker);

    $response = $this->actingAs($this->worker)
        ->get('/hr/compensation/benefits')
        ->assertOk();

    expect($response->inertiaProps('annualSalaryByProfileId'))->toBe([])
        ->and($response->inertiaProps('stats.bands_total'))->toBe(0)
        ->and($response->inertiaProps('stats.people_placed'))->toBe(0)
        ->and($response->inertiaProps('can.manage'))->toBeTrue();
});
