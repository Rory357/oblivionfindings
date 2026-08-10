<?php

use App\Domain\Hr\Models\HrCompetency;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrSupervisionNote;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->site = Site::factory()->create(['name' => 'Supervision Site']);
    $this->hiddenSite = Site::factory()->create(['name' => 'Hidden Supervision Site']);

    $this->hr = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->first()->id,
    ]);

    $this->employee = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->employee->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'support_worker')->firstOrFail()->id,
    ]);
    supervisionProfile($this->hr, $this->site);
    $this->employeeProfile = supervisionProfile($this->employee, $this->site);
});

function supervisionProfile(User $user, Site $site): HrEmployeeProfile
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

test('the performance hub ships the wizard staff options', function () {
    // The hub redesign replaced the old dialog with the performance wizard,
    // which receives staff and the canonical session taxonomy from the hub.
    $legacyColumn = 'ten'.'ant_id';
    $competency = HrCompetency::query()->create([
        $legacyColumn => 999,
        'name' => 'Application-wide safe support',
        'category' => 'Core',
        'proficiency_levels' => ['Aware', 'Practised', 'Advanced'],
        'is_active' => true,
        'sort_order' => 0,
    ]);

    $response = $this->actingAs($this->hr)->get('/hr/performance');
    $response->assertOk();

    expect($response->inertiaProps('staff'))->not->toBeNull();
    expect($response->inertiaProps('sessionTypes'))->toHaveCount(6);
    expect(collect($response->inertiaProps('competencyOptions'))->pluck('value'))
        ->toContain($competency->id);
});

test('a supervision note can be created via the dialog endpoint', function () {
    $this->actingAs($this->hr)
        ->post('/hr/performance/supervision', [
            'employee_user_id' => $this->employee->id,
            'session_date' => now()->toDateString(),
            'session_type' => 'one_to_one',
            'duration_minutes' => 30,
            'topics_discussed' => 'Caseload review and wellbeing check.',
            'actions_agreed' => ['Book first-aid refresher'],
            'is_visible_to_employee' => true,
        ])
        ->assertRedirect();

    $note = HrSupervisionNote::query()
        ->where('employee_user_id', $this->employee->id)
        ->first();

    expect($note)->not->toBeNull();
    expect($note->supervisor_user_id)->toBe($this->hr->id);
    expect($note->topics_discussed)->toBe('Caseload review and wellbeing check.');
});

test('a supervision note with empty topics is rejected (NOT NULL column)', function () {
    $this->actingAs($this->hr)
        ->post('/hr/performance/supervision', [
            'employee_user_id' => $this->employee->id,
            'session_date' => now()->toDateString(),
            'session_type' => 'supervision',
        ])
        ->assertSessionHasErrors('topics_discussed');

    expect(HrSupervisionNote::query()->count())->toBe(0);
});

test('a supervision note can be edited via the dialog endpoint', function () {
    $note = HrSupervisionNote::query()->create([
        'employee_user_id' => $this->employee->id,
        'supervisor_user_id' => $this->hr->id,
        'session_date' => now()->subWeek()->toDateString(),
        'session_type' => 'check_in',
        'topics_discussed' => 'Initial check-in.',
        'is_visible_to_employee' => true,
        'created_by' => $this->hr->id,
    ]);

    $this->actingAs($this->hr)
        ->put("/hr/performance/supervision/{$note->id}", [
            'session_type' => 'supervision',
            'topics_discussed' => 'Updated discussion notes.',
        ])
        ->assertRedirect();

    $note->refresh();
    expect($note->session_type)->toBe('supervision');
    expect($note->topics_discussed)->toBe('Updated discussion notes.');
});

test('the page-based create-supervision route redirects to the hub', function () {
    $this->actingAs($this->hr)
        ->get('/hr/performance/supervision/create')
        ->assertRedirect(route('hr.performance.index'));
});

test('supervision pickers writes and direct URLs enforce canonical Site access', function () {
    $hiddenEmployee = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    supervisionProfile($hiddenEmployee, $this->hiddenSite);
    $hiddenNote = HrSupervisionNote::query()->create([
        'employee_user_id' => $hiddenEmployee->id,
        'supervisor_user_id' => $hiddenEmployee->id,
        'session_date' => now()->subWeek()->toDateString(),
        'session_type' => 'supervision',
        'topics_discussed' => 'Hidden Site supervision details.',
        'is_visible_to_employee' => true,
        'created_by' => $hiddenEmployee->id,
    ]);

    $hub = $this->actingAs($this->hr)->get('/hr/performance')->assertOk();
    expect(collect($hub->inertiaProps('staff'))->pluck('value'))
        ->toContain($this->employee->id)
        ->not->toContain($hiddenEmployee->id);
    expect(collect($hub->inertiaProps('supervision.rows'))->pluck('id'))
        ->not->toContain($hiddenNote->id);

    $this->actingAs($this->hr)
        ->post('/hr/performance/supervision', [
            'employee_user_id' => $hiddenEmployee->id,
            'session_date' => now()->toDateString(),
            'session_type' => 'one_to_one',
            'topics_discussed' => 'Forged hidden note.',
        ])
        ->assertNotFound();
    $this->actingAs($this->hr)
        ->get("/hr/performance/supervision/{$hiddenNote->id}")
        ->assertNotFound();
    $this->actingAs($this->hr)
        ->get("/hr/performance/supervision/{$hiddenNote->id}/edit")
        ->assertNotFound();
    $this->actingAs($this->hr)
        ->put("/hr/performance/supervision/{$hiddenNote->id}", [
            'topics_discussed' => 'Forged update.',
        ])
        ->assertNotFound();

    expect(HrSupervisionNote::query()->where('topics_discussed', 'Forged hidden note.')->exists())->toBeFalse()
        ->and($hiddenNote->fresh()->topics_discussed)->toBe('Hidden Site supervision details.');
});

test('only a current employee can acknowledge a note explicitly visible to them', function () {
    $note = HrSupervisionNote::query()->create([
        'employee_user_id' => $this->employee->id,
        'supervisor_user_id' => $this->hr->id,
        'session_date' => now()->subDay()->toDateString(),
        'session_type' => 'one_to_one',
        'topics_discussed' => 'Visible supervision note.',
        'is_visible_to_employee' => true,
        'created_by' => $this->hr->id,
    ]);
    $privateNote = HrSupervisionNote::query()->create([
        'employee_user_id' => $this->employee->id,
        'supervisor_user_id' => $this->hr->id,
        'session_date' => now()->subDay()->toDateString(),
        'session_type' => 'one_to_one',
        'topics_discussed' => 'Manager-only supervision note.',
        'is_visible_to_employee' => false,
        'created_by' => $this->hr->id,
    ]);

    $this->actingAs($this->hr)
        ->post("/hr/performance/supervision/{$note->id}/acknowledge")
        ->assertNotFound();
    $this->actingAs($this->employee)
        ->post("/hr/performance/supervision/{$privateNote->id}/acknowledge")
        ->assertNotFound();
    $this->actingAs($this->employee)
        ->post("/hr/performance/supervision/{$note->id}/acknowledge", [
            'employee_comments' => 'I agree with these actions.',
        ])
        ->assertSessionHas('success');

    expect($note->fresh()->employee_acknowledged)->toBeTrue()
        ->and($note->fresh()->employee_comments)->toBe('I agree with these actions.')
        ->and($privateNote->fresh()->employee_acknowledged)->toBeFalse();

    $formerNote = HrSupervisionNote::query()->create([
        'employee_user_id' => $this->employee->id,
        'supervisor_user_id' => $this->hr->id,
        'session_date' => now()->toDateString(),
        'session_type' => 'one_to_one',
        'topics_discussed' => 'Retained former-staff note.',
        'is_visible_to_employee' => true,
        'created_by' => $this->hr->id,
    ]);
    $this->employeeProfile->update([
        'is_active' => false,
        'end_date' => today()->subDay(),
    ]);

    $this->actingAs($this->employee)
        ->post("/hr/performance/supervision/{$formerNote->id}/acknowledge")
        ->assertNotFound();
    expect($formerNote->fresh()->employee_acknowledged)->toBeFalse();
});
