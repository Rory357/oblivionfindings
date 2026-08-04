<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrExitInterview;
use App\Domain\Hr\Models\HrOffboardingTask;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

function makeExitProfile(): HrEmployeeProfile
{
    $user = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    return HrEmployeeProfile::query()->create([
        'user_id' => $user->id,
        'employee_number' => 'EMP-X'.$user->id,
        'work_email' => $user->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
        'primary_site_id' => Site::query()->firstOrFail()->id,
    ]);
}

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);
    $this->hr = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->first()->id,
    ]);
    $this->site = Site::factory()->create(['name' => 'Exit Interview Wizard Site']);
    HrEmployeeProfile::factory()->create([
        'user_id' => $this->hr->id,
        'primary_site_id' => $this->site->id,
        'is_active' => true,
        'start_date' => today()->subYear(),
    ]);
});

test('the exit-interview create GET redirects to the index with the wizard param', function () {
    $this->actingAs($this->hr)
        ->get('/hr/exit-interviews/create')
        ->assertRedirect(route('hr.exit-interviews.index', ['new' => 1]));
});

test('the exit-interviews index ships the wizard data and stats for managers', function () {
    $profile = makeExitProfile();

    $response = $this->actingAs($this->hr)->get('/hr/exit-interviews');
    $response->assertOk();

    expect(collect($response->inertiaProps('employees'))->pluck('id'))
        ->toContain($profile->id);
    expect(collect($response->inertiaProps('interviewers')))->not->toBeEmpty();
    expect(collect($response->inertiaProps('departureReasons')))->not->toBeEmpty();
    expect($response->inertiaProps('stats.total'))->toBe(0);
});

test('storing an exit interview auto-completes the open offboarding exit-interview task', function () {
    $profile = makeExitProfile();

    // Launch offboarding first so the default checklist (with its pending
    // "Exit interview" task) exists for the leaver.
    $this->actingAs($this->hr)
        ->post('/hr/offboarding', [
            'employee_profile_id' => $profile->id,
            'end_date' => now()->addWeeks(2)->toDateString(),
        ])
        ->assertRedirect();

    $task = HrOffboardingTask::query()
        ->where('title', 'Exit interview')
        ->whereHas('checklist', fn ($q) => $q->where('employee_profile_id', $profile->id))
        ->first();

    expect($task)->not->toBeNull();
    expect($task->status)->not->toBe('completed');

    $this->actingAs($this->hr)
        ->post('/hr/exit-interviews', [
            'employee_profile_id' => $profile->id,
            'interviewer_user_id' => $this->hr->id,
            'interview_date' => now()->toDateString(),
            'departure_reason' => 'retirement',
        ])
        ->assertRedirect(route('hr.exit-interviews.index'));

    expect(HrExitInterview::query()->where('employee_profile_id', $profile->id)->exists())
        ->toBeTrue();

    $task->refresh();
    expect($task->status)->toBe('completed');
    expect($task->completed_by)->toBe($this->hr->id);
    expect((string) $task->notes)->toContain('Auto-completed: exit interview recorded.');
});

test('storing an exit interview without an offboarding checklist still succeeds', function () {
    $profile = makeExitProfile();

    $this->actingAs($this->hr)
        ->post('/hr/exit-interviews', [
            'employee_profile_id' => $profile->id,
            'interviewer_user_id' => $this->hr->id,
            'interview_date' => now()->toDateString(),
            'departure_reason' => 'career_growth',
        ])
        ->assertRedirect(route('hr.exit-interviews.index'));

    expect(HrExitInterview::query()->where('employee_profile_id', $profile->id)->exists())
        ->toBeTrue();
});
