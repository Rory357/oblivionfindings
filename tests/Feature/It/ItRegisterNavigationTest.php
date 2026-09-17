<?php

use App\Domain\It\ItModuleNavigation;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;

test('register-only actors discover their canonical destination without unrelated IT or secret rights', function (string $permission, string $destination) {
    $role = Role::create(['name' => 'register-nav-'.str()->uuid(), 'label' => 'Register navigation', 'level' => 10, 'type' => 'custom']);
    $grant = Permission::firstOrCreate(['key' => $permission], ['description' => $permission, 'group' => 'sites', 'module' => 'Operations']);
    $role->permissions()->attach($grant);
    $actor = User::factory()->create(['role' => $role->name, 'approved_at' => now()]);
    $actor->roles()->attach($role);
    $items = collect(ItModuleNavigation::forUser($actor))->pluck('items')->flatten(1);
    expect($items->pluck('href')->all())->toBe([$destination])
        ->and($actor->canDo('credentials.reveal'))->toBeFalse()
        ->and($actor->canDo('credentials.copy'))->toBeFalse();
    $this->actingAs($actor)->get($destination)->assertOk()
        ->assertInertia(fn ($page) => $page->where('auth.can.'.str_replace('.view', '', $permission).'.view', true));
    $actor->update(['approved_at' => null]);
    expect(ItModuleNavigation::registerCapabilities($actor->fresh()))->toBe(['vendors' => false, 'contracts' => false, 'credentials' => false]);
})->with([
    ['vendors.view', '/vendors?tab=vendors'],
    ['credentials.view', '/vendors?tab=credentials'],
]);
