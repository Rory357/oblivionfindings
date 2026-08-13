<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Services\EmployeeIntakeService;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\Notification;

/**
 * Close-out C2 (Decision D-2) — role-assignment guard on the intake doors.
 *
 * EmployeeIntakeService::intake()/rehire() used to assign ANY existing role
 * (validated only as `exists:roles,name`), so anyone with hr.employees.manage
 * could mint a more privileged login. The grant boundary now:
 *   - keeps administrator-grade assignment behind administrator authority;
 *   - keeps Clinical Lead behind its explicit grant permission;
 *   - external portal personas (`client`, `next_of_kin`) are never assignable
 *     through employee intake, for anyone.
 * Blocked paths throw InvalidArgumentException, which every intake caller
 * surfaces as a flash/validation error (store + onboarding wizard wrapped;
 * rehire + manual convert already caught it) — never a 500.
 */
beforeEach(function () {
    Notification::fake();
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->hrActor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->hrActor->roles()->syncWithoutDetaching([Role::query()->where('name', 'hr')->first()->id]);

    $this->adminActor = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
    $this->adminActor->roles()->syncWithoutDetaching([Role::query()->where('name', 'admin')->first()->id]);
    $this->site = Site::factory()->create(['name' => 'Intake Guard Allowed Site']);
    foreach ([$this->hrActor, $this->adminActor] as $actor) {
        HrEmployeeProfile::factory()->create([
            'user_id' => $actor->id,
            'primary_site_id' => $this->site->id,
            'is_active' => true,
            'start_date' => now()->subYear()->toDateString(),
        ]);
    }
});

function hrCloseoutIntake(User $actor, string $roleName, string $email): HrEmployeeProfile
{
    return app(EmployeeIntakeService::class)->intake(
        name: 'Guard Test '.$roleName,
        email: $email,
        roleName: $roleName,
        profileAttributes: [
            'position_title' => 'Guard Test',
            'position_role' => $roleName,
            'employment_type' => 'full_time',
            'primary_site_id' => $actor->hrEmployeeProfile()->value('primary_site_id'),
            'start_date' => now()->toDateString(),
        ],
        actorId: $actor->id,
        startOnboarding: false,
        sendInvite: false,
    );
}

test('C2: a non-admin actor cannot intake an employee as admin', function () {
    expect(fn () => hrCloseoutIntake($this->hrActor, 'admin', 'blocked.admin@example.test'))
        ->toThrow(InvalidArgumentException::class, 'administrator-level');

    // Nothing was written — the guard fires before the user/profile transaction.
    expect(User::query()->where('email', 'blocked.admin@example.test')->exists())->toBeFalse();
});

test('C2: an HR actor cannot grant the higher Clinical Lead role', function () {
    expect(fn () => hrCloseoutIntake($this->hrActor, 'clinical_lead', 'blocked.clinical@example.test'))
        ->toThrow(InvalidArgumentException::class, 'not allowed');

    expect(User::query()->where('email', 'blocked.clinical@example.test')->exists())->toBeFalse();
});

test('C2: an admin actor can still intake an admin (allowed path)', function () {
    $profile = hrCloseoutIntake($this->adminActor, 'admin', 'allowed.admin@example.test');

    $user = $profile->fresh()->user;
    expect($user->role)->toBe('admin');
    expect($user->hasRole('admin'))->toBeTrue();
});

test('C2: external portal personas are never assignable via intake, even by an admin', function () {
    expect(fn () => hrCloseoutIntake($this->adminActor, 'client', 'blocked.client@example.test'))
        ->toThrow(InvalidArgumentException::class, 'external portal persona');

    expect(fn () => hrCloseoutIntake($this->hrActor, 'next_of_kin', 'blocked.nok@example.test'))
        ->toThrow(InvalidArgumentException::class, 'external portal persona');

    expect(User::query()->whereIn('email', ['blocked.client@example.test', 'blocked.nok@example.test'])->exists())
        ->toBeFalse();
});

test('C2: a normal staff intake by a non-admin actor still works (regression)', function () {
    $profile = hrCloseoutIntake($this->hrActor, 'support_worker', 'normal.staff@example.test');

    expect($profile->is_active)->toBeTrue();
    expect($profile->user->role)->toBe('support_worker');
});

test('C2: rehiring onto an admin-grade role requires an admin actor', function () {
    $former = User::factory()->create(['role' => 'admin']);
    $profile = HrEmployeeProfile::factory()->create([
        'user_id' => $former->id,
        'position_role' => 'admin',
        'is_active' => false,
    ]);

    expect(fn () => app(EmployeeIntakeService::class)->rehire(
        $profile,
        ['start_date' => now()->toDateString()],
        $this->hrActor->id,
        sendInvite: false,
        startOnboarding: false,
    ))->toThrow(InvalidArgumentException::class, 'administrator-level');

    // An admin actor may complete the same re-hire.
    $rehired = app(EmployeeIntakeService::class)->rehire(
        $profile->fresh(),
        ['start_date' => now()->toDateString()],
        $this->adminActor->id,
        sendInvite: false,
        startOnboarding: false,
    );
    expect($rehired->is_active)->toBeTrue();
});

test('C2: the Add Employee endpoint surfaces the guard as a validation error, not a 500', function () {
    $response = $this->actingAs($this->hrActor)->post('/hr/people', [
        'name' => 'Sneaky Elevation',
        'email' => 'sneaky.elevation@example.test',
        'role' => 'admin',
        'primary_site_id' => $this->site->id,
    ]);

    $response->assertRedirect();
    $response->assertSessionHasErrors('role');
    expect(User::query()->where('email', 'sneaky.elevation@example.test')->exists())->toBeFalse();
});
