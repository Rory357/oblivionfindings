<?php

use App\Domain\Hr\Models\HrDevelopmentGoal;
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

    $this->worker = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
});

test('a manager can soft-delete an OKR objective and it leaves the goals list', function () {
    $goal = HrGoal::query()->create([
        'tenant_id' => 1,
        'user_id' => $this->worker->id,
        'created_by' => $this->hr->id,
        'title' => 'Objective to remove',
        'goal_type' => 'individual',
        'priority' => 'medium',
        'status' => 'active',
        'start_date' => '2026-01-01',
        'due_date' => '2026-06-30',
    ]);

    $this->actingAs($this->hr)
        ->delete("/hr/goals/{$goal->id}")
        ->assertRedirect(route('hr.goals.index'));

    $this->assertSoftDeleted('hr_goals', ['id' => $goal->id]);

    $response = $this->actingAs($this->hr)->get('/hr/goals');
    $response->assertOk();
    expect(collect($response->inertiaProps('goals')['data'])->pluck('id'))
        ->not->toContain($goal->id);
});

test('a manager can soft-delete a development plan and it leaves the development list', function () {
    $plan = HrDevelopmentGoal::query()->create([
        'tenant_id' => 1,
        'employee_user_id' => $this->worker->id,
        'manager_user_id' => $this->hr->id,
        'title' => 'Development plan to remove',
        'category' => 'growth',
        'status' => 'not_started',
        'progress_percent' => 0,
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);

    $this->actingAs($this->hr)
        ->delete("/hr/goals/development/{$plan->id}")
        ->assertRedirect();

    $this->assertSoftDeleted('hr_development_goals', ['id' => $plan->id]);

    $response = $this->actingAs($this->hr)->get('/hr/goals/development');
    $response->assertOk();
    expect(collect($response->inertiaProps('goals')['data'])->pluck('id'))
        ->not->toContain($plan->id);
});

test('a non-manager cannot delete an OKR objective', function () {
    $goal = HrGoal::query()->create([
        'tenant_id' => 1,
        'user_id' => $this->worker->id,
        'created_by' => $this->hr->id,
        'title' => 'Objective protected from workers',
        'goal_type' => 'individual',
        'priority' => 'low',
        'status' => 'active',
        'start_date' => '2026-01-01',
        'due_date' => '2026-06-30',
    ]);

    $this->actingAs($this->worker)
        ->delete("/hr/goals/{$goal->id}")
        ->assertStatus(403);

    expect(HrGoal::query()->whereKey($goal->id)->exists())->toBeTrue();
});

test('a non-manager cannot delete a development plan', function () {
    $plan = HrDevelopmentGoal::query()->create([
        'tenant_id' => 1,
        'employee_user_id' => $this->worker->id,
        'manager_user_id' => $this->hr->id,
        'title' => 'Development plan protected from workers',
        'category' => 'growth',
        'status' => 'not_started',
        'progress_percent' => 0,
        'created_by' => $this->hr->id,
        'updated_by' => $this->hr->id,
    ]);

    $this->actingAs($this->worker)
        ->delete("/hr/goals/development/{$plan->id}")
        ->assertStatus(403);

    expect(HrDevelopmentGoal::query()->whereKey($plan->id)->exists())->toBeTrue();
});
