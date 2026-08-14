<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Site;
use App\Models\Staff;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('profile.edit'));

    $response->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/profile')
            ->where('mustVerifyEmail', true));
});

test('profile information can be updated', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();

    expect($user->name)->toBe('Test User');
    expect($user->email)->toBe('test@example.com');
    expect($user->email_verified_at)->toBeNull();
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

test('self service employment fields update only the current canonical HR profile', function () {
    $user = User::factory()->create([
        'approved_at' => now(),
        'cellphone' => '021 OLD',
    ]);
    $site = Site::factory()->create();
    $profile = HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => $site->id,
        'position_title' => 'Support Worker',
        'work_phone' => '0800 OLD',
        'work_email' => $user->email,
        'is_active' => true,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);
    $compatibilityProfile = Staff::factory()->create([
        'user_id' => $user->id,
        'job_title' => 'Compatibility title',
        'work_phone' => '0800 COMPAT',
    ]);

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Updated Worker',
            'email' => 'updated.worker@example.test',
            'phone' => '021 NEW',
            'job_title' => 'Senior Support Worker',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    expect($profile->refresh())
        ->position_title->toBe('Senior Support Worker')
        ->work_phone->toBe('021 NEW')
        ->work_email->toBe('updated.worker@example.test')
        ->updated_by->toBe($user->id);
    expect($compatibilityProfile->refresh())
        ->job_title->toBe('Compatibility title')
        ->work_phone->toBe('0800 COMPAT');
});

test('self service cannot invent employment provenance when no canonical HR profile exists', function () {
    $user = User::factory()->create(['approved_at' => now()]);

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => 'Attempted Name',
            'email' => 'attempted@example.test',
            'job_title' => 'Invented Job',
        ])
        ->assertSessionHasErrors('job_title');

    expect($user->refresh()->name)->not->toBe('Attempted Name');
    expect(Staff::query()->where('user_id', $user->id)->exists())->toBeFalse();
});

test('user can delete their account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->delete(route('profile.destroy'), [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home'));

    $this->assertGuest();
    expect($user->fresh())->toBeNull();
});

test('staff cannot delete the canonical employment record through profile settings', function () {
    $user = User::factory()->create(['approved_at' => now()]);
    $profile = HrEmployeeProfile::factory()->create([
        'user_id' => $user->id,
        'primary_site_id' => Site::factory()->create()->id,
        'is_active' => true,
        'created_by' => $user->id,
        'updated_by' => $user->id,
    ]);

    $this->actingAs($user)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), [
            'password' => 'password',
        ])
        ->assertSessionHasErrors('password')
        ->assertRedirect(route('profile.edit'));

    $this->assertAuthenticatedAs($user);
    $this->assertDatabaseHas('users', ['id' => $user->id]);
    $this->assertDatabaseHas('hr_employee_profiles', ['id' => $profile->id]);
});

test('correct password must be provided to delete account', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->from(route('profile.edit'))
        ->delete(route('profile.destroy'), [
            'password' => 'wrong-password',
        ]);

    $response
        ->assertSessionHasErrors('password')
        ->assertRedirect(route('profile.edit'));

    expect($user->fresh())->not->toBeNull();
});
