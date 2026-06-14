<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrExitInterview;
use App\Domain\Hr\Models\HrOffboardingChecklist;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

function makeOffProfile(): HrEmployeeProfile
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
});

test('the offboarding wizard store creates a checklist with the standard tasks', function () {
    $profile = makeOffProfile();

    $this->actingAs($this->hr)
        ->post('/hr/offboarding', [
            'employee_profile_id' => $profile->id,
            'end_date' => now()->addWeeks(2)->toDateString(),
        ])
        ->assertRedirect();

    $checklist = HrOffboardingChecklist::query()
        ->where('employee_profile_id', $profile->id)
        ->first();

    expect($checklist)->not->toBeNull();
    expect($checklist->tasks()->count())->toBeGreaterThanOrEqual(8);
});

test('scheduling an exit interview in the wizard creates a real exit-interview record', function () {
    $profile = makeOffProfile();

    $this->actingAs($this->hr)
        ->post('/hr/offboarding', [
            'employee_profile_id' => $profile->id,
            'end_date' => now()->addWeeks(2)->toDateString(),
            'schedule_exit_interview' => true,
            'departure_reason' => 'career_growth',
            'interviewer_user_id' => $this->hr->id,
            'interview_date' => now()->addWeeks(2)->toDateString(),
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('hr_exit_interviews', [
        'employee_profile_id' => $profile->id,
        'interviewer_user_id' => $this->hr->id,
        'departure_reason' => 'career_growth',
    ]);
});

test('scheduling an exit interview without its details is rejected', function () {
    $profile = makeOffProfile();

    $this->actingAs($this->hr)
        ->post('/hr/offboarding', [
            'employee_profile_id' => $profile->id,
            'schedule_exit_interview' => true,
        ])
        ->assertSessionHasErrors(['departure_reason', 'interviewer_user_id', 'interview_date']);

    expect(HrOffboardingChecklist::query()->count())->toBe(0);
});

test('the offboarding index ships the wizard data', function () {
    $profile = makeOffProfile();

    $response = $this->actingAs($this->hr)->get('/hr/offboarding');
    $response->assertOk();

    expect(collect($response->inertiaProps('employees'))->pluck('id'))
        ->toContain($profile->id);
    expect(collect($response->inertiaProps('departureReasons')))->not->toBeEmpty();
    expect(collect($response->inertiaProps('interviewers')))->not->toBeEmpty();
    expect(collect($response->inertiaProps('defaultTasks')))->not->toBeEmpty();
});

test('recording an exit interview from offboarding redirects back', function () {
    $profile = makeOffProfile();

    $this->actingAs($this->hr)
        ->from("/hr/offboarding")
        ->post('/hr/exit-interviews', [
            'employee_profile_id' => $profile->id,
            'interviewer_user_id' => $this->hr->id,
            'interview_date' => now()->toDateString(),
            'departure_reason' => 'retirement',
            'from_offboarding' => true,
        ])
        ->assertRedirect('/hr/offboarding');

    expect(HrExitInterview::query()->where('employee_profile_id', $profile->id)->exists())
        ->toBeTrue();
});
