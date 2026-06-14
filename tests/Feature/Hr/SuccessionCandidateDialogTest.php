<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrSuccessionCandidate;
use App\Domain\Hr\Models\HrSuccessionPlan;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

function makeSuccessionProfile(): HrEmployeeProfile
{
    $user = User::factory()->create();

    return HrEmployeeProfile::query()->create([
        'tenant_id' => 1,
        'user_id' => $user->id,
        'employee_number' => 'EMP-'.$user->id,
        'work_email' => $user->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
    ]);
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->hr = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->first()->id,
    ]);

    $this->plan = HrSuccessionPlan::query()->create([
        'tenant_id' => null,
        'role_title' => 'House Manager',
        'risk_level' => 'high',
        'is_active' => true,
        'created_by' => $this->hr->id,
    ]);
});

test('the succession plan page ships the candidate-dialog employees', function () {
    makeSuccessionProfile();

    $response = $this->actingAs($this->hr)->get("/hr/succession/{$this->plan->id}");
    $response->assertOk();

    expect($response->inertiaProps('employees'))->not->toBeNull();
});

test('a candidate can be added to a plan via the dialog endpoint', function () {
    $profile = makeSuccessionProfile();

    $this->actingAs($this->hr)
        ->post("/hr/succession/{$this->plan->id}/candidates", [
            'employee_profile_id' => $profile->id,
            'readiness' => 'ready_1_year',
            'strengths' => 'Strong clinical lead.',
            'overall_rating' => 4,
        ])
        ->assertRedirect();

    $candidate = HrSuccessionCandidate::query()
        ->where('succession_plan_id', $this->plan->id)
        ->first();

    expect($candidate)->not->toBeNull();
    expect($candidate->employee_profile_id)->toBe($profile->id);
    expect($candidate->readiness)->toBe('ready_1_year');
});

test('a candidate can be updated via the dialog endpoint', function () {
    $profile = makeSuccessionProfile();
    $candidate = $this->plan->candidates()->create([
        'employee_profile_id' => $profile->id,
        'readiness' => 'developing',
        'assessed_by' => $this->hr->id,
        'assessed_at' => now()->toDateString(),
    ]);

    $this->actingAs($this->hr)
        ->put("/hr/succession/candidates/{$candidate->id}", [
            'readiness' => 'ready_now',
            'overall_rating' => 5,
        ])
        ->assertRedirect();

    $candidate->refresh();
    expect($candidate->readiness)->toBe('ready_now');
    expect($candidate->overall_rating)->toBe(5);
});
