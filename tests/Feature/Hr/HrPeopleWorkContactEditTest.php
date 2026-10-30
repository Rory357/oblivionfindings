<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

// HR People › Edit is where work contact details (shown to all staff in the
// My HR directory) are set. Empty means none; the sign-in email is separate
// and never stands in for the work email.

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->site = Site::factory()->create(['name' => 'Work contact Site']);
    $this->otherSite = Site::factory()->create(['name' => 'Work contact other Site']);

    $this->hrManager = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->hrManager->roles()->syncWithoutDetaching([Role::query()->where('name', 'hr')->firstOrFail()->id]);
    // Site-bound HR: the role's all-Sites People bypass is denied, so access
    // comes only from this manager's own Site (as in StaffCreationWorkflowTest).
    $this->hrManager->permissionOverrides()->syncWithoutDetaching([
        Permission::query()->where('key', 'hr.employees.viewAllSites')->firstOrFail()->id => ['allowed' => false],
    ]);
    $this->hrManager->refresh();
    ensureCanonicalHrStaffProfile($this->hrManager, $this->site);

    $this->makeEmployee = function (Site $site): User {
        $user = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
            'email' => 'aroha.signin.'.$site->id.'@example.test',
        ]);
        $user->roles()->syncWithoutDetaching([Role::query()->where('name', 'support_worker')->firstOrFail()->id]);
        ensureCanonicalHrStaffProfile($user, $site, [
            'work_email' => 'aroha.'.$site->id.'@care.example.test',
            'work_phone' => '04 555 0100',
        ]);

        return $user;
    };
});

test('HR can set and clear the work email and work phone on the People edit form', function () {
    $employee = ($this->makeEmployee)($this->site);
    $profile = HrEmployeeProfile::query()->where('user_id', $employee->id)->sole();

    $this->actingAs($this->hrManager)
        ->get(route('hr.people.edit', $profile))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('hr/employees/edit')
            ->where('profile.work_email', $profile->work_email)
            ->where('profile.work_phone', '04 555 0100')
            ->where('profile.user.email', $employee->email));

    $this->actingAs($this->hrManager)
        ->put(route('hr.people.update', $profile), [
            'work_email' => '  Aroha.New@Care.Example.Test ',
            'work_phone' => '04 555 0199',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($profile->refresh())
        ->work_email->toBe('aroha.new@care.example.test')
        ->work_phone->toBe('04 555 0199');

    // Audited like the other profile fields.
    $audit = AuditLog::query()
        ->where('action', 'hremployeeprofile.update')
        ->where('auditable_id', $profile->id)
        ->where('user_id', $this->hrManager->id)
        ->latest('id')
        ->firstOrFail();
    expect($audit->meta['fields'])->toContain('work_email', 'work_phone');

    // Empty means none — never the sign-in email.
    $this->actingAs($this->hrManager)
        ->put(route('hr.people.update', $profile), [
            'work_email' => '',
            'work_phone' => '',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($profile->refresh())
        ->work_email->toBeNull()
        ->work_phone->toBeNull();
    expect($employee->refresh()->email)->toBe('aroha.signin.'.$this->site->id.'@example.test');
});

test('the People edit form rejects an invalid work email', function () {
    $employee = ($this->makeEmployee)($this->site);
    $profile = HrEmployeeProfile::query()->where('user_id', $employee->id)->sole();
    $before = $profile->work_email;

    $this->actingAs($this->hrManager)
        ->put(route('hr.people.update', $profile), ['work_email' => 'not-an-email'])
        ->assertSessionHasErrors('work_email');

    expect($profile->refresh()->work_email)->toBe($before);
});

test('a user without HR employee edit permission cannot change work contact', function () {
    $employee = ($this->makeEmployee)($this->site);
    $profile = HrEmployeeProfile::query()->where('user_id', $employee->id)->sole();
    // A same-Site support worker: Site access, but no HR employee edit permission.
    $colleague = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $colleague->roles()->syncWithoutDetaching([Role::query()->where('name', 'support_worker')->firstOrFail()->id]);
    ensureCanonicalHrStaffProfile($colleague, $this->site);

    expect($colleague->canDo('hr.employees.manage'))->toBeFalse();

    $this->actingAs($colleague)
        ->put(route('hr.people.update', $profile), [
            'work_email' => 'hijack@example.test',
            'work_phone' => '000',
        ])
        ->assertForbidden();

    expect($profile->refresh())
        ->work_email->toBe('aroha.'.$this->site->id.'@care.example.test')
        ->work_phone->toBe('04 555 0100');
});

test('HR cannot view or change work contact for a profile on another Site', function () {
    $hidden = ($this->makeEmployee)($this->otherSite);
    $profile = HrEmployeeProfile::query()->where('user_id', $hidden->id)->sole();

    $this->actingAs($this->hrManager)
        ->get(route('hr.people.edit', $profile))
        ->assertNotFound();

    $this->actingAs($this->hrManager)
        ->put(route('hr.people.update', $profile), [
            'work_email' => 'hijack@example.test',
            'work_phone' => '000',
        ])
        ->assertNotFound();

    expect($profile->refresh())
        ->work_email->toBe('aroha.'.$this->otherSite->id.'@care.example.test')
        ->work_phone->toBe('04 555 0100');
});
