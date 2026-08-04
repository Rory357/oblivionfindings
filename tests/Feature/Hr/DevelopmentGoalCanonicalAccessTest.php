<?php

use App\Domain\Hr\Models\HrCompetency;
use App\Domain\Hr\Models\HrDevelopmentGoal;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrGoal;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    Notification::fake();

    $this->site = Site::factory()->create(['name' => 'Development Canonical Site']);
    $this->hiddenSite = Site::factory()->create(['name' => 'Hidden Development Site']);
    $this->manager = developmentCanonicalStaff('Development Manager', $this->site, 'hr');
    $this->manager->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->firstOrFail()->id,
    ]);
    $this->employee = developmentCanonicalStaff('Development Employee', $this->site, 'support_worker');
    $this->peer = developmentCanonicalStaff('Development Peer', $this->site, 'support_worker');
    $this->hiddenEmployee = developmentCanonicalStaff('Hidden Development Employee', $this->hiddenSite, 'support_worker');
});

function developmentCanonicalStaff(
    string $name,
    Site $site,
    string $role,
    array $profileOverrides = [],
): User {
    $user = User::factory()->create([
        'name' => $name,
        'email' => str($name)->slug().'-'.str()->random(6).'@example.test',
        'role' => $role,
        'approved_at' => now(),
    ]);
    HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
        'start_date' => today()->subYear(),
        'end_date' => null,
        'is_active' => true,
        ...$profileOverrides,
    ]);

    return $user;
}

function developmentCanonicalGoal(User $employee, User $manager, array $overrides = []): HrDevelopmentGoal
{
    return HrDevelopmentGoal::query()->create([
        'employee_user_id' => $employee->id,
        'manager_user_id' => $manager->id,
        'title' => 'Canonical development plan',
        'category' => 'growth',
        'status' => 'not_started',
        'progress_percent' => 0,
        'created_by' => $manager->id,
        'updated_by' => $manager->id,
        ...$overrides,
    ]);
}

test('manager creates a plan using current Site-visible staff and application configuration', function (): void {
    $objective = HrGoal::query()->create([
        'user_id' => $this->employee->id,
        'created_by' => $this->manager->id,
        'title' => 'Visible capability objective',
        'goal_type' => 'individual',
        'priority' => 'medium',
        'status' => 'active',
        'start_date' => '2026-07-01',
        'due_date' => '2026-12-31',
    ]);
    $competency = HrCompetency::query()->create([
        'name' => 'Medication capability',
        'category' => 'clinical',
        'proficiency_levels' => [],
        'is_active' => true,
    ]);

    $this->actingAs($this->manager)
        ->post('/hr/goals/development', [
            'employee_user_id' => $this->employee->id,
            'manager_user_id' => $this->manager->id,
            'hr_goal_id' => $objective->id,
            'competency_id' => $competency->id,
            'title' => 'Medication development plan',
            'category' => 'capability',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $plan = HrDevelopmentGoal::query()->where('title', 'Medication development plan')->firstOrFail();
    expect($plan->employee_user_id)->toBe($this->employee->id)
        ->and($plan->manager_user_id)->toBe($this->manager->id)
        ->and($plan->hr_goal_id)->toBe($objective->id)
        ->and($plan->competency_id)->toBe($competency->id);
    foreach ($plan->getHidden() as $hiddenField) {
        expect($plan->toArray())->not->toHaveKey($hiddenField);
    }
});

test('hidden and former staff cannot be assigned and hidden objectives remain concealed', function (): void {
    $former = developmentCanonicalStaff(
        'Former Development Employee',
        $this->site,
        'support_worker',
        ['is_active' => false, 'end_date' => today()->subDay()],
    );
    $hiddenObjective = HrGoal::query()->create([
        'user_id' => $this->hiddenEmployee->id,
        'created_by' => $this->manager->id,
        'title' => 'Hidden objective',
        'goal_type' => 'individual',
        'priority' => 'medium',
        'status' => 'active',
        'start_date' => '2026-07-01',
        'due_date' => '2026-12-31',
    ]);

    foreach ([$this->hiddenEmployee, $former] as $subject) {
        $this->actingAs($this->manager)
            ->post('/hr/goals/development', [
                'employee_user_id' => $subject->id,
                'title' => 'Rejected development plan',
                'category' => 'growth',
            ])
            ->assertNotFound();
    }

    $this->actingAs($this->manager)
        ->post('/hr/goals/development', [
            'employee_user_id' => $this->employee->id,
            'manager_user_id' => $this->hiddenEmployee->id,
            'title' => 'Rejected manager plan',
            'category' => 'growth',
        ])
        ->assertNotFound();
    $this->actingAs($this->manager)
        ->post('/hr/goals/development', [
            'employee_user_id' => $this->employee->id,
            'hr_goal_id' => $hiddenObjective->id,
            'title' => 'Rejected objective plan',
            'category' => 'growth',
        ])
        ->assertNotFound();

    expect(HrDevelopmentGoal::query()->where('title', 'like', 'Rejected%')->exists())->toBeFalse();
});

test('hidden Site plans are concealed from manager mutations before validation', function (): void {
    $hidden = developmentCanonicalGoal($this->hiddenEmployee, $this->manager);

    $this->actingAs($this->manager)
        ->put('/hr/goals/development/'.$hidden->id, ['progress_percent' => 999])
        ->assertNotFound();
    $this->actingAs($this->manager)
        ->delete('/hr/goals/development/'.$hidden->id)
        ->assertNotFound();

    expect($hidden->fresh()->progress_percent)->toBe(0)
        ->and(HrDevelopmentGoal::query()->whereKey($hidden->id)->exists())->toBeTrue();
});

test('only the exact current employee or a Site-visible manager can mutate a plan', function (): void {
    $plan = developmentCanonicalGoal($this->employee, $this->manager);

    $this->actingAs($this->peer)
        ->put('/hr/goals/development/'.$plan->id, ['status' => 'completed'])
        ->assertNotFound();
    $this->actingAs($this->employee)
        ->put('/hr/goals/development/'.$plan->id, [
            'status' => 'in_progress',
            'progress_percent' => 35,
        ])
        ->assertSessionHas('success');
    expect($plan->fresh()->progress_percent)->toBe(35);

    $this->employee->hrEmployeeProfile->update([
        'is_active' => false,
        'end_date' => today()->subDay(),
    ]);
    $this->actingAs($this->employee)
        ->put('/hr/goals/development/'.$plan->id, ['progress_percent' => 60])
        ->assertNotFound();
    expect($plan->fresh()->progress_percent)->toBe(35);
});
