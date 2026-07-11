<?php

use App\Domain\Hr\Models\HrSupervisionNote;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->hr = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->first()->id,
    ]);

    $this->employee = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
});

test('the performance hub ships the wizard staff options', function () {
    // The hub redesign replaced the old dialog with the performance wizard,
    // which receives staff and the canonical session taxonomy from the hub.
    $response = $this->actingAs($this->hr)->get('/hr/performance');
    $response->assertOk();

    expect($response->inertiaProps('staff'))->not->toBeNull();
    expect($response->inertiaProps('sessionTypes'))->toHaveCount(6);
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
        'tenant_id' => 1,
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
