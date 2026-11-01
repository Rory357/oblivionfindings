<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Http\Request;

// A page view (GET) reads the viewer's roles and permission overrides once,
// fresh, onto that request's own User object, so every canDo() in the request
// reads memory instead of three queries each (30 Oct 2026). Nothing is kept
// between requests: no cache, no static, no shared binding.

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

function pageViewUser(string $role): User
{
    $user = User::factory()->create(['approved_at' => now(), 'role' => $role]);
    $user->roles()->sync([Role::query()->where('name', $role)->value('id')]);

    return $user;
}

function pageViewShare(User $user, string $method): void
{
    $request = Request::create('/settings/profile', $method);
    $request->setLaravelSession(app('session.store'));
    $request->setUserResolver(fn () => $user);
    app(HandleInertiaRequests::class)->share($request);
}

it('loads the roles fresh on each page view, so a role removed between two requests counts on the second', function () {
    $user = pageViewUser('admin');
    expect($user->canDo('controlRoom.viewAny'))->toBeTrue();

    $this->actingAs($user)->get('/control-room')->assertOk();
    // The same User object is reused by the test, as a long-lived object would be.
    expect($user->relationLoaded('roles'))->toBeTrue();

    $user->roles()->detach();

    $this->actingAs($user)->get('/control-room')->assertForbidden();
});

it('keeps the loaded roles on that request\'s user only: a fresh copy, or a queued job, reads the database', function () {
    $user = pageViewUser('admin');
    $this->actingAs($user)->get('/settings/profile')->assertOk();

    // What a queued job or the scheduler would load: nothing carried over.
    $fresh = User::query()->findOrFail($user->id);
    expect($fresh->relationLoaded('roles'))->toBeFalse()
        ->and($fresh->relationLoaded('permissionOverrides'))->toBeFalse();

    $user->roles()->detach();
    expect($fresh->canDo('controlRoom.viewAny'))->toBeFalse()
        ->and(User::query()->findOrFail($user->id)->canDo('controlRoom.viewAny'))->toBeFalse();
});

it('changes nothing for saves: POST, PUT and DELETE still check permissions per call', function (string $method) {
    $user = pageViewUser('support_worker');
    // Warm the 300 s permission map first, as on a normal day, so only this change could load the roles.
    pageViewShare($user, 'GET');
    $user->unsetRelations();

    pageViewShare($user, $method);

    // Existing shared props may still read the role names on any request; what a page
    // view adds is the overrides and each role's permission list, and saves never get them.
    expect($user->relationLoaded('permissionOverrides'))->toBeFalse()
        ->and($user->relationLoaded('roles') && $user->roles->contains(fn (Role $role) => $role->relationLoaded('permissions')))
        ->toBeFalse();
})->with(['POST', 'PUT', 'PATCH', 'DELETE']);

it('loads the roles once for a page view', function () {
    $user = pageViewUser('support_worker');

    pageViewShare($user, 'GET');

    expect($user->relationLoaded('roles'))->toBeTrue()
        ->and($user->roles->first()?->relationLoaded('permissions'))->toBeTrue()
        ->and($user->relationLoaded('permissionOverrides'))->toBeTrue();
});
