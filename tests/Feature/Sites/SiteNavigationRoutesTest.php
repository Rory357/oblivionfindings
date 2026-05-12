<?php

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);
});

function siteNavUser(string $roleName): User
{
    $user = User::factory()->create([
        'role' => $roleName,
        'approved_at' => now(),
    ]);

    $role = Role::query()->where('name', $roleName)->first();
    if ($role) {
        $user->roles()->syncWithoutDetaching([$role->id]);
    }

    return $user;
}

function grantPermission(User $user, string $key): void
{
    $permission = Permission::query()->where('key', $key)->firstOrFail();
    $user->permissionOverrides()->syncWithoutDetaching([
        $permission->id => ['allowed' => true],
    ]);
}

test('global inspections page loads for users with checklists.view', function () {
    $user = siteNavUser('admin');

    $this->actingAs($user)
        ->get('/sites/inspections')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('sites/inspections/global')
            ->has('schedules')
            ->has('records')
            ->has('sites')
            ->has('inspectionTypes')
            ->has('filters')
        );
});

test('global inspections page is forbidden without checklists.view', function () {
    $user = siteNavUser('support_worker');

    $this->actingAs($user)
        ->get('/sites/inspections')
        ->assertForbidden();
});

test('global vendors page loads for users with vendors.view', function () {
    $user = siteNavUser('admin');

    $this->actingAs($user)
        ->get('/vendors')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('sites/vendors-credentials/global')
            ->has('vendors')
            ->has('credentials')
            ->has('sites')
            ->has('serviceTypes')
            ->has('credentialTypes')
            ->has('filters')
        );
});

test('global vendors page is forbidden without vendors.view or credentials.view', function () {
    $user = siteNavUser('support_worker');

    $this->actingAs($user)
        ->get('/vendors')
        ->assertForbidden();
});

test('global vendors page loads for credentials.view-only users (controller OR-logic)', function () {
    $user = siteNavUser('support_worker');
    grantPermission($user, 'credentials.view');

    expect($user->canDo('vendors.view'))->toBeFalse();
    expect($user->canDo('credentials.view'))->toBeTrue();

    $this->actingAs($user)
        ->get('/vendors')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('sites/vendors-credentials/global'));
});

test('legacy /sites/vendors-credentials redirects to canonical /vendors', function () {
    $user = siteNavUser('admin');

    $this->actingAs($user)
        ->get('/sites/vendors-credentials')
        ->assertRedirect('/vendors');
});

test('removed sidebar destination /site-hardware returns 404', function () {
    $user = siteNavUser('admin');

    $this->actingAs($user)
        ->get('/site-hardware')
        ->assertNotFound();
});

test('removed sidebar destination /unifi returns 404 (canonical location is /security-devices/integrations/unifi)', function () {
    $user = siteNavUser('admin');

    $this->actingAs($user)
        ->get('/unifi')
        ->assertNotFound();
});
