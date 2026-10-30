<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

// The My HR directory is shown to every staff member. It publishes the HR
// work email only; the sign-in email can be personal, so it is never used as
// a fallback when HR hasn't recorded a work email.

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->site = Site::factory()->create(['name' => 'Directory email Site']);
    $supportWorkerRole = Role::query()->where('name', 'support_worker')->firstOrFail();

    $this->makeStaff = function (array $userAttributes = [], array $profileAttributes = []) use ($supportWorkerRole): User {
        $user = User::factory()->create([
            'role' => 'support_worker',
            'approved_at' => now(),
            ...$userAttributes,
        ]);
        $user->roles()->syncWithoutDetaching([$supportWorkerRole->id]);
        ensureCanonicalHrStaffProfile($user, $this->site, $profileAttributes);

        return $user;
    };

    $this->directoryEntryFor = function (User $viewer, User $colleague): array {
        $response = $this->actingAs($viewer)
            ->get(route('hr.my.directory'))
            ->assertOk();
        $profileId = HrEmployeeProfile::query()->where('user_id', $colleague->id)->value('id');
        $entry = collect($response->inertiaProps('people'))->firstWhere('id', $profileId);

        expect($entry)->not->toBeNull();

        return [$response, $entry];
    };
});

test('the My HR directory never shows the sign-in email when the HR work email is empty', function () {
    $colleague = ($this->makeStaff)(
        ['email' => 'aroha.signin@example.test'],
        ['work_email' => ''],
    );
    $viewer = ($this->makeStaff)();

    [$response, $entry] = ($this->directoryEntryFor)($viewer, $colleague);

    expect($entry['email'])->toBeNull();
    $response->assertDontSee('aroha.signin@example.test');
});

test('HR can clear a work email, which then shows as none in the My HR directory', function () {
    $colleague = ($this->makeStaff)(
        ['email' => 'aroha.signin@example.test'],
        ['work_email' => 'aroha@care.example.test'],
    );
    $hrManager = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $hrManager->roles()->syncWithoutDetaching([Role::query()->where('name', 'hr')->firstOrFail()->id]);
    ensureCanonicalHrStaffProfile($hrManager, $this->site);
    $profile = HrEmployeeProfile::query()->where('user_id', $colleague->id)->sole();

    // An empty field arrives as null (ConvertEmptyStringsToNull). The column
    // used to be NOT NULL, so this save failed with a 500.
    $this->actingAs($hrManager)
        ->put(route('hr.people.update', $profile), ['work_email' => ''])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    expect($profile->refresh()->work_email)->toBeNull();

    [$response, $entry] = ($this->directoryEntryFor)($hrManager, $colleague);

    expect($entry['email'])->toBeNull();
    $response->assertDontSee('aroha@care.example.test')
        ->assertDontSee('aroha.signin@example.test');
});

test('an HR-entered work email still shows in the My HR directory', function () {
    $colleague = ($this->makeStaff)(
        ['email' => 'aroha.signin@example.test'],
        ['work_email' => 'aroha@care.example.test'],
    );
    $viewer = ($this->makeStaff)();

    [$response, $entry] = ($this->directoryEntryFor)($viewer, $colleague);

    expect($entry['email'])->toBe('aroha@care.example.test');
    $response->assertDontSee('aroha.signin@example.test');
});
