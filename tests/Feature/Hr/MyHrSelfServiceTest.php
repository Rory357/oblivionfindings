<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrKudos;
use App\Domain\Hr\Models\HrSupervisionNote;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->employee = User::factory()->create([
        'organization_id' => 1,
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    HrEmployeeProfile::query()->create([
        'tenant_id' => 1,
        'user_id' => $this->employee->id,
        'employee_number' => 'EMP-SS-'.$this->employee->id,
        'work_email' => 'ss'.$this->employee->id.'@example.test',
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
    ]);

    $this->teammate = User::factory()->create([
        'organization_id' => 1,
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    HrEmployeeProfile::query()->create([
        'tenant_id' => 1,
        'user_id' => $this->teammate->id,
        'employee_number' => 'EMP-SS-'.$this->teammate->id,
        'work_email' => 'ss'.$this->teammate->id.'@example.test',
        'position_title' => 'Senior Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subYears(2)->toDateString(),
        'is_active' => true,
    ]);
});

test('the 1:1s page renders and surfaces an employee-visible supervision note', function () {
    HrSupervisionNote::query()->create([
        'tenant_id' => 1,
        'employee_user_id' => $this->employee->id,
        'supervisor_user_id' => $this->teammate->id,
        'session_date' => now()->subWeek()->toDateString(),
        'session_type' => 'one_to_one',
        'topics_discussed' => 'Settling in and study plan.',
        'actions_agreed' => ['Book first-aid refresher'],
        'is_visible_to_employee' => true,
        'created_by' => $this->teammate->id,
    ]);

    $response = $this->actingAs($this->employee)->get('/hr/my/one');
    $response->assertOk();

    expect($response->inertiaProps('sessions'))->toHaveCount(1);
    expect($response->inertiaProps('openActions'))->toHaveCount(1);
});

test('an employee-hidden supervision note is NOT surfaced on the 1:1s page', function () {
    HrSupervisionNote::query()->create([
        'tenant_id' => 1,
        'employee_user_id' => $this->employee->id,
        'supervisor_user_id' => $this->teammate->id,
        'session_date' => now()->subWeek()->toDateString(),
        'session_type' => 'one_to_one',
        'topics_discussed' => 'Private manager-only note.',
        'is_visible_to_employee' => false,
        'created_by' => $this->teammate->id,
    ]);

    $response = $this->actingAs($this->employee)->get('/hr/my/one');
    $response->assertOk();

    expect($response->inertiaProps('sessions'))->toHaveCount(0);
});

test('an employee can acknowledge a 1:1 with a comment', function () {
    $note = HrSupervisionNote::query()->create([
        'tenant_id' => 1,
        'employee_user_id' => $this->employee->id,
        'supervisor_user_id' => $this->teammate->id,
        'session_date' => now()->subWeek()->toDateString(),
        'session_type' => 'one_to_one',
        'topics_discussed' => 'Caseload review.',
        'is_visible_to_employee' => true,
        'created_by' => $this->teammate->id,
    ]);

    $this->actingAs($this->employee)
        ->post("/hr/my/one/{$note->id}/acknowledge", [
            'employee_comments' => 'Thanks, all clear.',
        ])
        ->assertRedirect();

    $note->refresh();
    expect($note->employee_acknowledged)->toBeTrue();
    expect($note->employee_comments)->toBe('Thanks, all clear.');
    expect($note->employee_acknowledged_at)->not->toBeNull();
});

test('an employee cannot acknowledge a 1:1 that is not theirs', function () {
    $note = HrSupervisionNote::query()->create([
        'tenant_id' => 1,
        'employee_user_id' => $this->teammate->id,
        'supervisor_user_id' => $this->employee->id,
        'session_date' => now()->subWeek()->toDateString(),
        'session_type' => 'one_to_one',
        'topics_discussed' => 'Someone else’s note.',
        'is_visible_to_employee' => true,
        'created_by' => $this->employee->id,
    ]);

    $this->actingAs($this->employee)
        ->post("/hr/my/one/{$note->id}/acknowledge")
        ->assertForbidden();
});

test('an employee can send kudos to a teammate via self-service', function () {
    $this->actingAs($this->employee)
        ->post('/hr/my/kudos', [
            'to_user_id' => $this->teammate->id,
            'category' => 'teamwork',
            'message' => 'Thanks for covering my round at short notice — legend. 🙌',
        ])
        ->assertRedirect();

    $kudos = HrKudos::query()
        ->where('from_user_id', $this->employee->id)
        ->where('to_user_id', $this->teammate->id)
        ->first();

    expect($kudos)->not->toBeNull();
    expect($kudos->category)->toBe('teamwork');
});

test('sending kudos rejects an unknown category', function () {
    $this->actingAs($this->employee)
        ->post('/hr/my/kudos', [
            'to_user_id' => $this->teammate->id,
            'category' => 'not_a_real_value',
            'message' => 'Nice work.',
        ])
        ->assertSessionHasErrors('category');

    expect(HrKudos::query()->count())->toBe(0);
});
