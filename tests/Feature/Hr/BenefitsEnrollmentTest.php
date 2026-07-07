<?php

use App\Domain\Hr\Models\HrBenefitEnrollment;
use App\Domain\Hr\Models\HrBenefitPlan;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->hr = User::factory()->create([
        'organization_id' => 1,
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->first()->id,
    ]);

    $this->worker = User::factory()->create([
        'organization_id' => 1,
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $this->profile = HrEmployeeProfile::query()->create([
        'tenant_id' => 1,
        'user_id' => $this->worker->id,
        'employee_number' => 'EMP-'.$this->worker->id,
        'work_email' => $this->worker->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
    ]);

    $this->plan = HrBenefitPlan::query()->create([
        'tenant_id' => 1,
        'name' => 'KiwiSaver 3%',
        'type' => 'kiwisaver',
        'employer_contribution_rate' => 3,
        'is_active' => true,
    ]);

    $this->enrollment = HrBenefitEnrollment::query()->create([
        'tenant_id' => 1,
        'employee_profile_id' => $this->profile->id,
        'benefit_plan_id' => $this->plan->id,
        'enrollment_date' => now()->subMonths(2)->toDateString(),
        'status' => 'active',
        'employee_contribution_rate' => 3,
        'employer_contribution_rate' => 3,
    ]);
});

test('the benefits index lists tenant enrollments and ships employees', function () {
    // Regression: index used forTenant($user->tenant_id) — null — which becomes
    // whereNull(tenant_id), so real (profile-tenant) enrollments never appeared.
    $response = $this->actingAs($this->hr)->get('/hr/compensation/benefits');
    $response->assertOk();

    $enrollmentIds = collect($response->inertiaProps('enrollments.data'))->pluck('id')->all();
    expect($enrollmentIds)->toContain($this->enrollment->id);

    $employeeIds = collect($response->inertiaProps('employees'))->pluck('id')->all();
    expect($employeeIds)->toContain($this->profile->id);
});

test('an enrollment can be updated from the UI endpoint', function () {
    $response = $this->actingAs($this->hr)->put(
        "/hr/compensation/benefits/enrollments/{$this->enrollment->id}",
        ['status' => 'suspended', 'employee_contribution_rate' => 5],
    );

    $response->assertSessionHas('success');

    $this->assertDatabaseHas('hr_benefit_enrollments', [
        'id' => $this->enrollment->id,
        'status' => 'suspended',
        'employee_contribution_rate' => 5,
    ]);
});

test('creating a benefit plan resolves the tenant', function () {
    // Regression: storePlan wrote tenant_id => null but the column is NOT NULL.
    $response = $this->actingAs($this->hr)->post('/hr/compensation/benefits/plans', [
        'name' => 'Health Cover',
        'type' => 'health_insurance',
        'employer_contribution_rate' => 0,
    ]);

    $response->assertSessionHas('success');

    $this->assertDatabaseHas('hr_benefit_plans', [
        'tenant_id' => 1,
        'name' => 'Health Cover',
        'type' => 'health_insurance',
    ]);
});

test('a user without hr.benefits.manage cannot update an enrollment', function () {
    $this->actingAs($this->worker)->put(
        "/hr/compensation/benefits/enrollments/{$this->enrollment->id}",
        ['status' => 'terminated'],
    )->assertForbidden();

    $this->assertDatabaseHas('hr_benefit_enrollments', [
        'id' => $this->enrollment->id,
        'status' => 'active',
    ]);
});

test('the benefits index exposes plan employer rates + a salary map for the cost preview', function () {
    $this->profile->update(['annual_salary' => 65000]);

    // json_encode drops the ".0" from integral floats (65000.0 → "65000"), so a
    // strict float identity can never survive the Inertia JSON round-trip —
    // assert the decrypted salary numerically instead.
    $this->actingAs($this->hr)->get('/hr/compensation/benefits')->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('hr/compensation/benefits/index')
            ->where("annualSalaryByProfileId.{$this->profile->id}", fn ($v) => is_numeric($v) && (float) $v === 65000.0)
            ->where('plans.0.employer_contribution_rate', fn ($v) => $v !== null));
});
