<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Notifications\EmployeeInviteNotification;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);
    $this->hr = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->hr->roles()->syncWithoutDetaching([Role::query()->where('name', 'hr')->firstOrFail()->id]);
    $this->allowedSite = Site::factory()->create(['name' => 'Invite Allowed Site']);
    $this->hiddenSite = Site::factory()->create(['name' => 'Invite Hidden Site']);
    HrEmployeeProfile::factory()->create([
        'user_id' => $this->hr->id,
        'primary_site_id' => $this->allowedSite->id,
        'is_active' => true,
        'start_date' => now()->subYear()->toDateString(),
    ]);
});

test('an inactive employee receives an hr branded invite backed by a reset token', function () {
    Notification::fake();
    $employee = User::factory()->create(['approved_at' => null]);
    $profile = HrEmployeeProfile::factory()->create([
        'user_id' => $employee->id,
        'primary_site_id' => $this->allowedSite->id,
    ]);

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
    $employee = User::factory()->create(['approved_at' => now()]);
    $profile = HrEmployeeProfile::factory()->create([
        'user_id' => $employee->id,
        'primary_site_id' => $this->allowedSite->id,
    ]);

    $this->actingAs($this->hr)
        ->post("/hr/people/{$profile->id}/invite")
        ->assertSessionHasErrors('invite');

    Notification::assertNothingSent();
});

test('an employee at a hidden Site cannot be reinvited', function () {
    Notification::fake();
    $employee = User::factory()->create(['approved_at' => null]);
    $profile = HrEmployeeProfile::factory()->create([
        'user_id' => $employee->id,
        'primary_site_id' => $this->hiddenSite->id,
    ]);

    $this->actingAs($this->hr)
        ->post("/hr/people/{$profile->id}/invite")
        ->assertNotFound();

    Notification::assertNothingSent();
});

test('employee invite is sent once only after an outer transaction commits and never after rollback', function () {
    Notification::fake();
    $employee = User::factory()->create(['approved_at' => null]);
    $profile = HrEmployeeProfile::factory()->create([
        'user_id' => $employee->id,
        'primary_site_id' => $this->allowedSite->id,
    ]);

    DB::beginTransaction();
    $this->actingAs($this->hr)
        ->post("/hr/people/{$profile->id}/invite")
        ->assertSessionHas('success');
    Notification::assertNothingSent();
    DB::rollBack();
    Notification::assertNothingSent();

    DB::beginTransaction();
    $this->actingAs($this->hr)
        ->post("/hr/people/{$profile->id}/invite")
        ->assertSessionHas('success');
    Notification::assertNothingSent();
    DB::commit();

    Notification::assertSentToTimes($employee, EmployeeInviteNotification::class, 1);
});
