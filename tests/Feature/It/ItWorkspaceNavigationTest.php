<?php

use App\Domain\It\ItModuleNavigation;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;

function itNavigationActor(array $permissions): User
{
    $role = Role::query()->create(['name' => 'it-navigation-'.str()->uuid(), 'label' => 'IT navigation test', 'level' => 10, 'type' => 'custom']);
    $actor = User::factory()->create(['role' => $role->name, 'approved_at' => now()]);
    $actor->roles()->attach($role);
    foreach ($permissions as $key) {
        $permission = Permission::query()->firstOrCreate(['key' => $key], ['description' => $key, 'group' => 'it', 'module' => 'Operations']);
        $role->permissions()->syncWithoutDetaching([$permission->id]);
    }

    return $actor;
}

test('IT workspace adapters retain query context and canonical destinations', function () {
    $actor = itNavigationActor(['it.view']);
    $this->actingAs($actor)->get('/it/reports?from=2026-09-01&to=2026-09-08')->assertRedirect('/it?from=2026-09-01&to=2026-09-08&tab=reports');
    $this->get('/it/work?q=keyboard&site_id=123')->assertRedirect('/it?q=keyboard&site_id=123&tab=tickets&view=mine');
    $this->get('/it/knowledge?article=42')->assertRedirect('/it?article=42&tab=knowledge');
});

test('requesters and separately entitled knowledge authors cannot enter work or reports', function () {
    foreach (['it.request', 'it.knowledge.author', 'it.knowledge.review'] as $permission) {
        $actor = itNavigationActor([$permission]);
        $this->actingAs($actor)->get('/it/knowledge')->assertRedirect('/it?tab=knowledge');
        $this->get('/it/work')->assertForbidden();
        $this->get('/it/reports')->assertForbidden();
        $items = collect(ItModuleNavigation::forUser($actor))->pluck('items')->flatten(1);
        expect($items->where('href', '/it/knowledge'))->toHaveCount(1);
        expect($items->where('href', '/it/reports'))->toHaveCount(0);
        expect($items->where('href', '/it/work'))->toHaveCount(0);
    }
});

test('an IT agent has one canonical knowledge, reports and work destination', function () {
    $items = collect(ItModuleNavigation::forUser(itNavigationActor(['it.view', 'it.request', 'it.manage'])))->pluck('items')->flatten(1);
    foreach (['/it/knowledge', '/it/reports', '/it/work'] as $href) {
        expect($items->where('href', $href))->toHaveCount(1);
    }
    expect($items->where('href', '/it?tab=knowledge'))->toHaveCount(0);
    $this->actingAs(itNavigationActor([]))->get('/it/knowledge')->assertForbidden();
});
