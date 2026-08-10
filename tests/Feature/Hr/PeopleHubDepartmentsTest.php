<?php

use App\Domain\Hr\Models\HrDepartment;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);

    $this->hr = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->first()->id,
    ]);
    $this->site = Site::factory()->create(['name' => 'Department Test Site']);
    HrEmployeeProfile::query()->create([
        'user_id' => $this->hr->id,
        'employee_number' => 'EMP-DEPT-VIEWER',
        'work_email' => $this->hr->email,
        'position_title' => 'HR Manager',
        'position_role' => 'hr',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
        'primary_site_id' => $this->site->id,
    ]);
});

test('the standalone departments route redirects into the People hub tab', function () {
    $this->actingAs($this->hr)
        ->get('/hr/departments')
        ->assertRedirect(route('hr.people.index', ['tab' => 'departments']));
});

test('the departments route forwards filters to the hub', function () {
    $this->actingAs($this->hr)
        ->get('/hr/departments?q=care&status=active')
        ->assertRedirect(route('hr.people.index', [
            'tab' => 'departments',
            'dept_q' => 'care',
            'dept_status' => 'active',
        ]));
});

test('the people hub exposes departments data', function () {
    HrDepartment::query()->create([
        'name' => 'Care Services',
        'sort_order' => 0,
        'is_active' => true,
    ]);

    $response = $this->actingAs($this->hr)->get('/hr/people?tab=departments');
    $response->assertOk();

    $names = collect($response->inertiaProps('departmentsPane.data'))
        ->pluck('name')
        ->all();

    expect($names)->toContain('Care Services');
});

test('a department can be created from the hub modal endpoint', function () {
    $this->actingAs($this->hr)
        ->post('/hr/departments', ['name' => 'Night Team', 'sort_order' => 0])
        ->assertRedirect();

    expect(HrDepartment::query()->where('name', 'Night Team')->exists())->toBeTrue();
});

test('department names are unique across the single application', function () {
    HrDepartment::query()->create([
        'name' => 'Clinical Governance',
        'sort_order' => 0,
        'is_active' => true,
    ]);

    $this->actingAs($this->hr)
        ->post('/hr/departments', ['name' => 'Clinical Governance'])
        ->assertSessionHasErrors('name');

    expect(HrDepartment::query()->where('name', 'Clinical Governance')->count())->toBe(1);
});
