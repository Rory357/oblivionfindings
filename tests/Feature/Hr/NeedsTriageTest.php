<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);

    $this->manager = User::factory()->create([
        'name' => 'HR Manager',
        'role' => 'admin',
        'approved_at' => now(),
        'last_login_at' => now(),
    ]);
    $adminRole = Role::query()->where('name', 'admin')->first();
    if ($adminRole) {
        $this->manager->roles()->syncWithoutDetaching([$adminRole->id]);
    }
});

function triageStaff(string $name, array $userOverrides = [], array $profileOverrides = []): HrEmployeeProfile
{
    $staff = User::factory()->create(array_merge([
        'name' => $name,
        'email' => str($name)->slug() . '@example.test',
        'role' => 'support_worker',
        'approved_at' => now(),
    ], $userOverrides));

    return HrEmployeeProfile::query()->create(array_merge([
        'tenant_id' => 1,
        'user_id' => $staff->id,
        'employee_number' => 'EMP-' . $staff->id,
        'work_email' => $staff->email,
        'position_title' => 'Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subMonth()->toDateString(),
        'is_active' => true,
    ], $profileOverrides));
}

test('the people index exposes the triage payload with three rails', function () {
    triageStaff('Never Loggedin', ['last_login_at' => null]);
    triageStaff('Already In', ['last_login_at' => now()]);

    $response = $this->actingAs($this->manager)->get('/hr/people');
    $response->assertOk();

    $triage = $response->inertiaProps('triage');
    expect($triage)->toBeArray()
        ->and($triage)->toHaveKeys(['compliance', 'probation', 'invites']);

    $inviteNames = collect($triage['invites'])->pluck('name');
    expect($inviteNames)->toContain('Never Loggedin')
        ->and($inviteNames)->not->toContain('Already In');

    expect($response->inertiaProps('summary.pending_invites'))
        ->toBeGreaterThanOrEqual(1);
});

test('a staffer within probation surfaces on the probation rail', function () {
    triageStaff('On Probation', ['last_login_at' => now()], [
        'probation_end_date' => now()->addWeeks(4)->toDateString(),
    ]);

    $response = $this->actingAs($this->manager)->get('/hr/people');
    $names = collect($response->inertiaProps('triage')['probation'])->pluck('name');

    expect($names)->toContain('On Probation');
});

test('resendInvite sends a reset-link invite and is manage-gated', function () {
    Notification::fake();

    $profile = triageStaff('Invite Me', ['last_login_at' => null]);

    // A non-manager cannot send invites.
    $viewer = User::factory()->create([
        'role' => 'support_worker',
        'approved_at' => now(),
    ]);
    $this->actingAs($viewer)
        ->post("/hr/people/{$profile->id}/invite")
        ->assertForbidden();

    // A manager can (re)send the login invite.
    $this->actingAs($this->manager)
        ->post("/hr/people/{$profile->id}/invite")
        ->assertRedirect();

    Notification::assertSentTo($profile->user, ResetPassword::class);
});
