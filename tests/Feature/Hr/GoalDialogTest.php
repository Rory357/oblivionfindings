<?php

use App\Domain\Hr\Models\HrGoal;
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

    $this->owner = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
});

test('the goals hub ships the goal-dialog options', function () {
    $response = $this->actingAs($this->hr)->get('/hr/goals');
    $response->assertOk();

    expect(collect($response->inertiaProps('goalTypes'))->pluck('value'))
        ->toContain('individual');
    expect(collect($response->inertiaProps('priorities'))->pluck('value'))
        ->toContain('high');
    expect($response->inertiaProps('parentGoals'))->not->toBeNull();
});

test('a goal can be created via the dialog endpoint', function () {
    $this->actingAs($this->hr)
        ->post('/hr/goals', [
            'user_id' => $this->owner->id,
            'title' => 'Reduce medication errors',
            'description' => 'Halve the error rate this quarter.',
            'goal_type' => 'individual',
            'priority' => 'high',
            'target_value' => 20,
            'unit' => '%',
            'start_date' => '2026-01-01',
            'due_date' => '2026-03-31',
        ])
        ->assertRedirect();

    $goal = HrGoal::query()->where('user_id', $this->owner->id)->first();
    expect($goal)->not->toBeNull();
    expect($goal->title)->toBe('Reduce medication errors');
    expect($goal->priority)->toBe('high');
});

test('a goal can be edited via the now-wired update endpoint', function () {
    $goal = HrGoal::query()->create([
        'tenant_id' => 1,
        'user_id' => $this->owner->id,
        'created_by' => $this->hr->id,
        'title' => 'Original',
        'goal_type' => 'individual',
        'priority' => 'low',
        'status' => 'active',
        'start_date' => '2026-01-01',
        'due_date' => '2026-06-30',
    ]);

    $this->actingAs($this->hr)
        ->put("/hr/goals/{$goal->id}", [
            'title' => 'Updated objective',
            'priority' => 'high',
        ])
        ->assertRedirect();

    $goal->refresh();
    expect($goal->title)->toBe('Updated objective');
    expect($goal->priority)->toBe('high');
});

test('the page-based goals create route redirects to the hub', function () {
    $this->actingAs($this->hr)
        ->get('/hr/goals/create')
        ->assertRedirect(route('hr.goals.index'));
});
