<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Notifications\EmployeeInviteNotification;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);
    $this->hr = User::factory()->create(['organization_id' => 1, 'role' => 'hr', 'approved_at' => now()]);
    $this->hr->roles()->syncWithoutDetaching([Role::query()->where('name', 'hr')->firstOrFail()->id]);
});

test('an inactive employee receives an hr branded invite backed by a reset token', function () {
    Notification::fake();
    $employee = User::factory()->create(['organization_id' => 1, 'approved_at' => null]);
    $profile = HrEmployeeProfile::factory()->create(['tenant_id' => 1, 'user_id' => $employee->id]);

    $this->actingAs($this->hr)
        ->post("/hr/people/{$profile->id}/invite")
        ->assertSessionHas('success');

    Notification::assertSentTo($employee, EmployeeInviteNotification::class, function ($notification) use ($employee) {
        $payload = $notification->toArray($employee);

        return $payload['type'] === 'employee_invite'
            && str_contains($payload['action_url'], '/reset-password/')
            && str_contains($payload['action_url'], urlencode($employee->email));
    });
});

test('an already active employee cannot be reinvited and no notification is sent', function () {
    Notification::fake();
    $employee = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);
    $profile = HrEmployeeProfile::factory()->create(['tenant_id' => 1, 'user_id' => $employee->id]);

    $this->actingAs($this->hr)
        ->post("/hr/people/{$profile->id}/invite")
        ->assertSessionHasErrors('invite');

    Notification::assertNothingSent();
});

test('an employee in another organisation cannot be reinvited', function () {
    Notification::fake();
    $employee = User::factory()->create(['organization_id' => 2, 'approved_at' => null]);
    $profile = HrEmployeeProfile::factory()->create(['tenant_id' => 2, 'user_id' => $employee->id]);

    $this->actingAs($this->hr)
        ->post("/hr/people/{$profile->id}/invite")
        ->assertNotFound();

    Notification::assertNothingSent();
});
