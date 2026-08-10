<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\User;

test('my hr profile page exposes flattened personal and emergency contact fields', function () {
    $user = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    HrEmployeeProfile::query()->create([
        'user_id' => $user->id,
        'employee_number' => 'EMP-'.$user->id,
        'work_email' => $user->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subMonth()->toDateString(),
        'is_active' => true,
        'personal_email' => 'personal@example.test',
        'personal_phone' => '+64 21 111 2222',
        'emergency_contacts' => [[
            'name' => 'Jane Doe',
            'phone' => '+64 21 333 4444',
            'relationship' => 'Sibling',
        ]],
    ]);

    $response = $this->actingAs($user)->get('/hr/my/profile');
    $response->assertOk();

    expect($response->inertiaProps('profile.phone'))->toBe('+64 21 111 2222');
    expect($response->inertiaProps('profile.emergency_contact_name'))->toBe('Jane Doe');
    expect($response->inertiaProps('profile.emergency_contact_phone'))->toBe('+64 21 333 4444');
    expect($response->inertiaProps('profile.emergency_contact_relationship'))->toBe('Sibling');
});

test('my hr profile update accepts ui phone and emergency contact fields', function () {
    $user = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);

    $profile = HrEmployeeProfile::query()->create([
        'user_id' => $user->id,
        'employee_number' => 'EMP-'.$user->id,
        'work_email' => $user->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subMonth()->toDateString(),
        'is_active' => true,
    ]);

    $response = $this->actingAs($user)->put('/hr/my/profile', [
        'personal_email' => 'updated.personal@example.test',
        'phone' => '+64 21 555 6666',
        'home_address' => '1 Example Street',
        'emergency_contact_name' => 'Mary Smith',
        'emergency_contact_phone' => '+64 21 777 8888',
        'emergency_contact_relationship' => 'Parent',
    ]);

    $response->assertSessionHas('success');

    $profile->refresh();
    expect($profile->personal_email)->toBe('updated.personal@example.test');
    expect($profile->personal_phone)->toBe('+64 21 555 6666');
    expect($profile->home_address)->toBe('1 Example Street');

    $contacts = collect($profile->emergency_contacts ?? []);
    expect($contacts)->toHaveCount(1);
    expect($contacts->first()['name'] ?? null)->toBe('Mary Smith');
    expect($contacts->first()['phone'] ?? null)->toBe('+64 21 777 8888');
    expect($contacts->first()['relationship'] ?? null)->toBe('Parent');
});
