<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;

beforeEach(function () {
    $this->seed(RbacSeeder::class);

    $this->hr = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->first()->id,
    ]);
});

test('the standalone directory route redirects into the People hub tab', function () {
    $this->actingAs($this->hr)
        ->get('/hr/directory')
        ->assertRedirect(route('hr.people.index', ['tab' => 'people']));
});

test('the directory route forwards the search query to the hub', function () {
    $this->actingAs($this->hr)
        ->get('/hr/directory?q=ana')
        ->assertRedirect(route('hr.people.index', ['tab' => 'people', 'q' => 'ana']));
});

test('the people index exposes directory card fields on each row', function () {
    $staff = User::factory()->create([
        'name' => 'Ana Williams',
        'email' => 'ana.dir@example.test',
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    HrEmployeeProfile::query()->create([
        'tenant_id' => 1,
        'user_id' => $staff->id,
        'employee_number' => 'EMP-DIR-1',
        'preferred_name' => 'Ana',
        'work_email' => 'ana.dir@example.test',
        'work_phone' => '021 555 0001',
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subMonth()->toDateString(),
        'is_active' => true,
    ]);

    $response = $this->actingAs($this->hr)->get('/hr/people');
    $response->assertOk();

    $row = collect($response->inertiaProps('profiles.data'))
        ->firstWhere('user.name', 'Ana Williams');

    expect($row)->not->toBeNull();
    expect($row)->toHaveKeys(['preferred_name', 'profile_photo_path', 'work_email', 'phone']);
    expect($row['preferred_name'])->toBe('Ana');
    expect($row['phone'])->toBe('021 555 0001');
});
