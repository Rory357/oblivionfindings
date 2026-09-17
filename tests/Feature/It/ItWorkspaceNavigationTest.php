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

test('dedicated IT workspaces render directly and legacy links retain query context', function () {
    $actor = itNavigationActor(['it.view']);
    $this->actingAs($actor)->get('/it/reports?from=2026-09-01&to=2026-09-08')->assertOk()
        ->assertInertia(fn ($page) => $page->component('it/reports/index')->missing('tickets')->missing('requests')->missing('kbArticles'));
    $this->get('/it/provisioning?status=failed')->assertOk()
        ->assertInertia(fn ($page) => $page->component('it/provisioning/index')->where('filters.status', 'failed'));
    $this->get('/it/knowledge')->assertOk()
        ->assertInertia(fn ($page) => $page->component('it/knowledge/index')->missing('tickets')->missing('requests'));
    $this->get('/it/knowledge?article=42')->assertNotFound();
    $this->get('/it?tab=reports&from=2026-09-01&to=2026-09-08')->assertRedirect('/it/reports?from=2026-09-01&to=2026-09-08');
    $this->get('/it?tab=knowledge&article=42')->assertRedirect('/it/knowledge?article=42');
    $this->get('/it?tab=provisioning&status=failed&requests_page=2&list_view=cards')->assertRedirect('/it/provisioning?status=failed&requests_page=2&list_view=cards');
    $this->get('/it/work?q=keyboard&site_id=123')->assertRedirect('/it?q=keyboard&site_id=123&tab=tickets&view=mine');
});

test('requesters and separately entitled knowledge authors cannot enter work or reports', function () {
    foreach (['it.request', 'it.knowledge.author', 'it.knowledge.review'] as $permission) {
        $actor = itNavigationActor([$permission]);
        $this->actingAs($actor)->get('/it/knowledge')->assertOk()
            ->assertInertia(fn ($page) => $page->component('it/knowledge/index')->missing('tickets')->missing('requests'));
        $this->get('/it/work')->assertForbidden();
        $this->get('/it/reports')->assertForbidden();
        $this->get('/it/provisioning')->assertForbidden();
        $this->get('/it?tab=provisioning')->assertForbidden();
        $this->get('/it?tab=reports')->assertForbidden();
        $items = collect(ItModuleNavigation::forUser($actor))->pluck('items')->flatten(1);
        expect($items->where('href', '/it/knowledge'))->toHaveCount(1);
        expect($items->where('href', '/it/reports'))->toHaveCount(0);
        expect($items->where('href', '/it/work'))->toHaveCount(0);
    }
});

test('an IT agent has one canonical knowledge, reports and work destination', function () {
    $items = collect(ItModuleNavigation::forUser(itNavigationActor(['it.view', 'it.request', 'it.manage'])))->pluck('items')->flatten(1);
    foreach (['/it/knowledge', '/it/reports', '/it/work', '/it/provisioning'] as $href) {
        expect($items->where('href', $href))->toHaveCount(1);
    }
    expect($items->where('href', '/it?tab=knowledge'))->toHaveCount(0);
    $this->actingAs(itNavigationActor([]))->get('/it/knowledge')->assertForbidden();
});
