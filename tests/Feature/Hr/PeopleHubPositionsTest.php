<?php

use App\Domain\Hr\Models\HrDepartment;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrPosition;
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
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->first()->id,
    ]);
    $this->site = Site::factory()->create(['name' => 'Position Test Site']);
    HrEmployeeProfile::query()->create([
        'user_id' => $this->hr->id,
        'employee_number' => 'EMP-POS-VIEWER',
        'work_email' => $this->hr->email,
        'position_title' => 'HR Manager',
        'position_role' => 'hr',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'is_active' => true,
        'primary_site_id' => $this->site->id,
    ]);
});

test('the standalone positions route redirects into the People hub tab', function () {
    $this->actingAs($this->hr)
        ->get('/hr/positions')
        ->assertRedirect(route('hr.people.index', ['tab' => 'positions']));
});

test('the positions route forwards filters to the hub', function () {
    $this->actingAs($this->hr)
        ->get('/hr/positions?q=care&status=active')
        ->assertRedirect(route('hr.people.index', [
            'tab' => 'positions',
            'pq' => 'care',
            'pstatus' => 'active',
        ]));
});

test('the people hub exposes positions data with headcount fields', function () {
    HrPosition::query()->create([
        'title' => 'Care Lead',
        'code' => 'CL-1',
        'employment_type' => 'full_time',
        'headcount_budget' => 4,
    ]);

    $response = $this->actingAs($this->hr)->get('/hr/people?tab=positions');
    $response->assertOk();

    $row = collect($response->inertiaProps('positions.data'))
        ->firstWhere('code', 'CL-1');

    expect($row)->not->toBeNull();
    expect($row['title'])->toBe('Care Lead');
    expect($row)->toHaveKeys(['current_headcount', 'vacancies', 'description']);
    expect($row['vacancies'])->toBe(4);
});

test('a position can be created from the hub modal endpoint', function () {
    $this->actingAs($this->hr)->post('/hr/positions', [
        'title' => 'Night Support',
        'code' => 'NS-1',
        'employment_type' => 'part_time',
        'fte' => '0.50',
        'headcount_budget' => '3',
    ])->assertRedirect();

    $position = HrPosition::query()->where('code', 'NS-1')->first();
    expect($position)->not->toBeNull();
    expect($position->title)->toBe('Night Support');
});

test('a position can be updated from the hub modal endpoint', function () {
    $position = HrPosition::query()->create([
        'title' => 'Old Title',
        'code' => 'UPD-1',
        'employment_type' => 'full_time',
    ]);

    $this->actingAs($this->hr)->put("/hr/positions/{$position->id}", [
        'title' => 'New Title',
        'code' => 'UPD-1',
        'employment_type' => 'full_time',
        'fte' => '1.00',
        'headcount_budget' => '2',
        'is_active' => true,
    ])->assertRedirect();

    expect($position->fresh()->title)->toBe('New Title');
});

test('position codes and configuration selectors are application global', function () {
    $department = HrDepartment::query()->create([
        'name' => 'Clinical Quality',
        'is_active' => true,
    ]);
    $position = HrPosition::query()->create([
        'title' => 'Quality Lead',
        'code' => 'QL-GLOBAL',
        'employment_type' => 'full_time',
        'is_active' => true,
    ]);

    $create = $this->actingAs($this->hr)->get('/hr/positions/create');
    $create->assertOk();
    expect(collect($create->inertiaProps('parentPositions'))->pluck('id')->all())
        ->toContain($position->id)
        ->and(collect($create->inertiaProps('departments'))->pluck('id')->all())
        ->toContain($department->id);

    $this->actingAs($this->hr)->post('/hr/positions', [
        'title' => 'Duplicate Quality Lead',
        'code' => 'QL-GLOBAL',
        'employment_type' => 'full_time',
        'fte' => 1,
        'headcount_budget' => 1,
    ])->assertSessionHasErrors('code');

    expect(HrPosition::query()->where('code', 'QL-GLOBAL')->count())->toBe(1);
});
