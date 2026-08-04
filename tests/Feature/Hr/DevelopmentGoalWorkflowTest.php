<?php

use App\Domain\Hr\Models\HrDevelopmentGoal;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);

    $this->hr = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);

    $this->staff = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $this->other = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $hrRole = Role::where('name', 'hr')->first();
    if ($hrRole) {
        $this->hr->roles()->syncWithoutDetaching([$hrRole->id]);
    }

    $supportRole = Role::where('name', 'support_worker')->first();
    if ($supportRole) {
        $this->staff->roles()->syncWithoutDetaching([$supportRole->id]);
        $this->other->roles()->syncWithoutDetaching([$supportRole->id]);
    }

    $this->site = Site::factory()->create(['name' => 'Development workflow Site']);
    developmentWorkflowProfile($this->hr, $this->site);
    developmentWorkflowProfile($this->staff, $this->site);
    developmentWorkflowProfile($this->other, $this->site);
});

function developmentWorkflowProfile(User $user, Site $site): HrEmployeeProfile
{
    return HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
    ]);
}

test('hr can assign development goal and employee can update own progress', function () {
    $this->actingAs($this->hr)
        ->post('/hr/goals/development', [
            'employee_user_id' => $this->staff->id,
            'manager_user_id' => $this->hr->id,
            'title' => 'Improve communication in family meetings',
            'description' => 'Complete mentoring and demonstrate structured communication techniques.',
            'category' => 'capability',
            'competency_area' => 'Communication',
            'target_level' => 4,
            'current_level' => 2,
            'start_date' => now()->toDateString(),
            'due_date' => now()->addMonths(2)->toDateString(),
            'review_frequency' => 'monthly',
        ])
        ->assertSessionHas('success');

    $goal = HrDevelopmentGoal::query()->where('employee_user_id', $this->staff->id)->first();
    expect($goal)->not->toBeNull();
    expect($goal?->status)->toBe('not_started');

    $this->actingAs($this->staff)
        ->put("/hr/goals/development/{$goal->id}", [
            'status' => 'in_progress',
            'progress_percent' => 45,
            'current_level' => 3,
            'review_notes' => 'Completed first mentoring block.',
        ])
        ->assertSessionHas('success');

    $goal->refresh();
    expect($goal->status)->toBe('in_progress');
    expect((int) $goal->progress_percent)->toBe(45);
    expect((int) $goal->current_level)->toBe(3);
});

test('employee cannot update another employees development goal', function () {
    $goal = HrDevelopmentGoal::query()->create([
        'employee_user_id' => $this->staff->id,
        'manager_user_id' => $this->hr->id,
        'title' => 'Goal owned by another user',
        'category' => 'growth',
        'status' => 'not_started',
        'progress_percent' => 0,
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);

    $this->actingAs($this->other)
        ->put("/hr/goals/development/{$goal->id}", [
            'status' => 'completed',
            'progress_percent' => 100,
        ])
        ->assertNotFound();
});
