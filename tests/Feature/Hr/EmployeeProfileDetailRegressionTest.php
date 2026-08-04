<?php

use App\Domain\Hr\Models\HrCompetency;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrPerformanceImprovementPlan;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->hr = User::factory()->create([
        'name' => 'HR Manager',
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $hrRole = Role::query()->where('name', 'hr')->first();
    if ($hrRole) {
        $this->hr->roles()->syncWithoutDetaching([$hrRole->id]);
    }
    $this->site = Site::factory()->create(['name' => 'Employee Detail Allowed Site']);
    HrEmployeeProfile::query()->create([
        'user_id' => $this->hr->id,
        'employee_number' => 'EMP-DETAIL-VIEWER',
        'work_email' => $this->hr->email,
        'position_title' => 'HR Manager',
        'position_role' => 'hr',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
        'primary_site_id' => $this->site->id,
    ]);

    $this->staff = User::factory()->create([
        'name' => 'Employee Detail Staff',
        'email' => 'employee.detail.staff@example.test',
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $this->profile = HrEmployeeProfile::query()->create([
        'user_id' => $this->staff->id,
        'employee_number' => 'EMP-DETAIL',
        'work_email' => $this->staff->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subMonth()->toDateString(),
        'is_active' => true,
        'primary_site_id' => $this->site->id,
    ]);
});

test('employee profile detail renders PIPs keyed by employee user', function () {
    HrPerformanceImprovementPlan::query()->create([
        'employee_user_id' => $this->staff->id,
        'manager_user_id' => $this->hr->id,
        'title' => 'Improve medication documentation',
        'reason' => 'Documentation gaps need support.',
        'expectations' => 'Complete medication notes before shift handover.',
        'start_date' => now()->subWeek()->toDateString(),
        'end_date' => now()->addWeeks(3)->toDateString(),
        'status' => 'active',
        'created_by' => $this->hr->id,
    ]);

    $this->actingAs($this->hr)
        ->get("/hr/people/{$this->profile->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('hr/employees/show')
            ->where('profile.id', $this->profile->id)
            ->where('pips.0.title', 'Improve medication documentation'));
});

test('PIP list and detail pages resolve employee users', function () {
    $pip = HrPerformanceImprovementPlan::query()->create([
        'employee_user_id' => $this->staff->id,
        'manager_user_id' => $this->hr->id,
        'title' => 'Strengthen incident follow-up',
        'reason' => 'Incident follow-up needs clearer ownership.',
        'expectations' => 'Close assigned follow-up actions by due dates.',
        'start_date' => now()->subWeek()->toDateString(),
        'end_date' => now()->addWeeks(3)->toDateString(),
        'status' => 'active',
        'created_by' => $this->hr->id,
    ]);

    $this->actingAs($this->hr)
        ->get('/hr/performance/pips')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('hr/performance/pips/index')
            ->where('pips.data.0.employee.name', 'Employee Detail Staff'));

    $this->actingAs($this->hr)
        ->get("/hr/performance/pips/{$pip->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('hr/performance/pips/show')
            ->where('pip.employee.name', 'Employee Detail Staff'));
});

test('competency assessments store and render from the profile keyed table', function () {
    $competency = HrCompetency::query()->create([
        'name' => 'Safe medication support',
        'description' => 'Demonstrates safe medication support practice.',
        'category' => 'Clinical',
        'proficiency_levels' => ['Aware', 'Developing', 'Competent', 'Advanced', 'Expert'],
        'is_active' => true,
        'sort_order' => 1,
    ]);

    $this->actingAs($this->hr)
        ->post('/hr/performance/competencies/assess', [
            'employee_user_id' => $this->staff->id,
            'assessments' => [[
                'competency_id' => $competency->id,
                'proficiency_level' => 3,
                'target_level' => 4,
                'notes' => 'Consistent practice with occasional prompts.',
            ]],
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('hr_competency_assessments', [
        'employee_profile_id' => $this->profile->id,
        'competency_id' => $competency->id,
        'assessed_level' => 3,
        'assessed_by' => $this->hr->id,
    ]);

    $this->actingAs($this->hr)
        ->get("/hr/performance/competencies/{$this->profile->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('hr/performance/competencies/profile')
            ->where('latestAssessments.0.competency.name', 'Safe medication support')
            ->where('latestAssessments.0.assessed_level', 3));

    $this->actingAs($this->hr)
        ->get("/hr/people/{$this->profile->id}")
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('hr/employees/show')
            ->where('competencyAssessments.0.competency_name', 'Safe medication support')
            ->where('competencyAssessments.0.proficiency_level', 3));
});
