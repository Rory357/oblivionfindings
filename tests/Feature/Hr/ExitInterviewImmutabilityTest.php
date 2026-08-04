<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrExitInterview;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

function makeImmutableExitInterview(User $manager): HrExitInterview
{
    $employee = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $profile = HrEmployeeProfile::query()->create([
        'user_id' => $employee->id,
        'employee_number' => 'EXIT-LOCK-'.$employee->id,
        'work_email' => $employee->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
        'primary_site_id' => Site::query()->firstOrFail()->id,
    ]);

    return HrExitInterview::query()->create([
        'employee_profile_id' => $profile->id,
        'interviewer_user_id' => $manager->id,
        'interview_date' => now()->toDateString(),
        'departure_reason' => 'career_growth',
        'overall_satisfaction' => 4,
        'what_went_well' => 'The team supported one another.',
        'additional_comments' => 'Original submitted comment.',
        'is_confidential' => true,
        'created_by' => $manager->id,
    ]);
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);
    $this->hr = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->first()->id,
    ]);
    $this->site = Site::factory()->create(['name' => 'Immutable Exit Interview Site']);
    HrEmployeeProfile::factory()->create([
        'user_id' => $this->hr->id,
        'primary_site_id' => $this->site->id,
        'is_active' => true,
        'start_date' => today()->subYear(),
    ]);
});

test('submitted exit interview answers cannot be edited', function () {
    $interview = makeImmutableExitInterview($this->hr);

    $this->actingAs($this->hr)
        ->from("/hr/exit-interviews/{$interview->id}")
        ->patch("/hr/exit-interviews/{$interview->id}", [
            'departure_reason' => 'management',
            'overall_satisfaction' => 1,
            'what_went_well' => 'Replacement answer.',
        ])
        ->assertRedirect("/hr/exit-interviews/{$interview->id}")
        ->assertSessionHas('error', 'Submitted exit interviews are locked. Add an addendum instead.');

    $interview->refresh();

    expect($interview->departure_reason)->toBe('career_growth')
        ->and($interview->overall_satisfaction)->toBe(4)
        ->and($interview->what_went_well)->toBe('The team supported one another.')
        ->and($interview->additional_comments)->toBe('Original submitted comment.');
});

test('an addendum appends a note without changing submitted answers', function () {
    $interview = makeImmutableExitInterview($this->hr);

    $this->actingAs($this->hr)
        ->post("/hr/exit-interviews/{$interview->id}/addenda", [
            'note' => 'Employee clarified that the new role starts next month.',
        ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Addendum appended. The submitted interview remains unchanged.');

    $interview->refresh();

    expect($interview->departure_reason)->toBe('career_growth')
        ->and($interview->overall_satisfaction)->toBe(4)
        ->and($interview->what_went_well)->toBe('The team supported one another.')
        ->and($interview->additional_comments)->toStartWith('Original submitted comment.')
        ->and($interview->additional_comments)->toContain('Addendum')
        ->and($interview->additional_comments)->toContain($this->hr->name)
        ->and($interview->additional_comments)->toContain('Employee clarified that the new role starts next month.');
});
