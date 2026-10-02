<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;

// Settings › Profile's phone is the user's personal mobile (users.cellphone).
// The HR work phone is published to every staff member in the My HR
// directory, so the personal number must never be copied into it.

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->seed(SeedHrPermissionsSeeder::class);

    $this->site = Site::factory()->create(['name' => 'Phone privacy Site']);
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
});

test('saving a phone in Settings profile leaves the HR work phone unchanged', function (?string $typedPhone) {
    $user = ($this->makeStaff)(['cellphone' => '021 000 0001'], ['work_phone' => '0800 111 222']);

    $this->actingAs($user)
        ->patch(route('profile.update'), [
            'name' => $user->name,
            'email' => $user->email,
            'phone' => $typedPhone,
            'job_title' => 'Senior Support Worker',
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $profile = HrEmployeeProfile::query()->where('user_id', $user->id)->sole();

    // The job title proves the HR profile was found and written in the same
    // save, so an unchanged work_phone is not just a missed profile lookup.
    expect($profile->position_title)->toBe('Senior Support Worker');
    expect($profile->work_phone)->toBe('0800 111 222');
    expect($user->refresh()->cellphone)->toBe($typedPhone);
})->with([
    'a new personal number' => ['021 555 0101'],
    'a cleared personal number' => [null],
]);

test('the My HR directory does not show a number typed only in Settings profile', function () {
    $colleague = ($this->makeStaff)([], ['work_phone' => null]);
    $viewer = ($this->makeStaff)();

    $this->actingAs($colleague)
        ->patch(route('profile.update'), [
            'name' => $colleague->name,
            'email' => $colleague->email,
            'phone' => '021 555 0199',
        ])
        ->assertSessionHasNoErrors();

    expect($colleague->refresh()->cellphone)->toBe('021 555 0199');

    $response = $this->actingAs($viewer)
        ->get(route('hr.my.directory'))
        ->assertOk()
        ->assertDontSee('021 555 0199');

    $colleagueProfileId = HrEmployeeProfile::query()->where('user_id', $colleague->id)->value('id');
    $colleagueEntry = collect($response->inertiaProps('people'))->firstWhere('id', $colleagueProfileId);

    expect($colleagueEntry)->not->toBeNull();
    expect($colleagueEntry['phone'])->toBeNull();
});

test('the My HR directory keeps showing the HR work phone after a Settings profile save', function () {
    $colleague = ($this->makeStaff)([], ['work_phone' => '0800 333 444']);
    $viewer = ($this->makeStaff)();

    $this->actingAs($colleague)
        ->patch(route('profile.update'), [
            'name' => $colleague->name,
            'email' => $colleague->email,
            'phone' => '021 555 0188',
        ])
        ->assertSessionHasNoErrors();

    $response = $this->actingAs($viewer)
        ->get(route('hr.my.directory'))
        ->assertOk()
        ->assertDontSee('021 555 0188');

    $colleagueProfileId = HrEmployeeProfile::query()->where('user_id', $colleague->id)->value('id');

    expect(collect($response->inertiaProps('people'))->firstWhere('id', $colleagueProfileId)['phone'] ?? null)
        ->toBe('0800 333 444');
});

test('the Settings profile page reads back the personal mobile, never the HR work phone', function () {
    $withPersonal = ($this->makeStaff)(['cellphone' => '021 555 0177'], ['work_phone' => '0800 555 666']);
    $withoutPersonal = ($this->makeStaff)(['cellphone' => null], ['work_phone' => '0800 777 888']);

    $this->actingAs($withPersonal)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/profile')
            ->where('profile.phone', '021 555 0177'));

    $this->actingAs($withoutPersonal)
        ->get(route('profile.edit'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('settings/profile')
            ->where('profile.phone', null));
});
