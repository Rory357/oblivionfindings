<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Role;
use App\Models\User;

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);

    $this->hr = User::factory()->create([
        'role' => 'hr',
        'approved_at' => now(),
    ]);
    $this->hr->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'hr')->first()->id,
    ]);
});

test('an HR manager can add an employee via people.store', function () {
    $response = $this->actingAs($this->hr)->post('/hr/people', [
        'name' => 'Ana Williams',
        'email' => 'ana.williams@example.test',
        'role' => 'support_worker',
        'employment_type' => 'full_time',
        'position_title' => 'Support Worker',
    ]);

    $response->assertRedirect();

    $newUser = User::query()->where('email', 'ana.williams@example.test')->first();
    expect($newUser)->not->toBeNull();
    expect($newUser->roles->pluck('name'))->toContain('support_worker');

    $profile = HrEmployeeProfile::query()->where('user_id', $newUser->id)->first();
    expect($profile)->not->toBeNull();
    expect($profile->employment_type)->toBe('full_time');
    expect($profile->position_title)->toBe('Support Worker');
    expect((bool) $profile->is_active)->toBeTrue();
    expect($profile->employee_number)->not->toBeNull();
});

test('a non-manager cannot add an employee', function () {
    $worker = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $worker->roles()->syncWithoutDetaching([
        Role::query()->where('name', 'support_worker')->first()->id,
    ]);

    $this->actingAs($worker)->post('/hr/people', [
        'name' => 'Blocked Person',
        'email' => 'blocked@example.test',
    ])->assertForbidden();

    expect(User::query()->where('email', 'blocked@example.test')->exists())->toBeFalse();
});

test('a duplicate email is rejected', function () {
    User::factory()->create(['email' => 'dupe@example.test']);

    $this->actingAs($this->hr)->post('/hr/people', [
        'name' => 'Dupe Person',
        'email' => 'dupe@example.test',
    ])->assertSessionHasErrors('email');
});
