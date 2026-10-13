<?php

use App\Domain\CompanyHub\Services\HubPublishing;
use App\Models\ItCatalogItem;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CompanyHubPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;

beforeEach(function () {
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    $this->reader = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->reader->roles()->attach(Role::where('name', 'support_worker')->firstOrFail());
    $this->site = ensureCanonicalHrStaffProfile($this->reader, overrides: ['preferred_name' => 'Compass Colleague', 'bio' => 'Private HR biography']);
    $this->editor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->editor->roles()->attach(Role::where('name', 'hr')->firstOrFail());
    ensureCanonicalHrStaffProfile($this->editor, $this->site);
    $this->publisher = app(HubPublishing::class);
});

test('command search returns exact published pages visible colleagues and canonical service links with no private payloads', function () {
    $page = $this->publisher->create($this->editor, 'news', 'Compass published guide');
    $this->publisher->publish($this->editor, $page, 1, null, null);
    $this->publisher->save($this->editor, $page, [...$page->draft, 'title' => 'Unpublished working title'], 1);
    $service = ItCatalogItem::factory()->create(['name' => 'Compass equipment help', 'site_scope' => [$this->site->id], 'form_schema' => ['fields' => [['key' => 'secret', 'label' => 'Private technician field', 'type' => 'text', 'visibility' => 'internal']]]]);
    $this->actingAs($this->reader)->getJson('/company-hub/search?query=Compass')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('actor_id', $this->reader->id)->assertJsonCount(3, 'results')
        ->assertJsonPath('results.0.title', 'Compass published guide')->assertJsonPath('results.0.url', '/company-hub/read/'.$page->slug)
        ->assertJsonPath('results.1.url', '/company-hub/people/'.$this->reader->hrEmployeeProfile()->sole()->id)
        ->assertJsonPath('results.2.url', '/company-hub?view=services&search=Compass%20equipment%20help')
        ->assertDontSee('Private technician field')->assertDontSee('Private HR biography')->assertDontSee('Unpublished working title');
    $this->getJson('/company-hub/search?query=leave')->assertOk()->assertJsonPath('results.0.url', '/hr/my/leave');
    $this->getJson('/company-hub/search?query=Private%20HR%20biography')->assertOk()->assertJsonCount(0, 'results');
    $service->update(['is_published' => false]);
    $this->getJson('/company-hub/search?query=Compass')->assertJsonCount(2, 'results');
});

test('command lookup excludes drafts future expired and outside-audience content and rechecks current staff and permission', function () {
    $outside = Site::factory()->create(['is_active' => true, 'archived' => false]);
    ensureCanonicalHrStaffProfile($this->editor, $this->site, ['secondary_site_ids' => [$outside->id]]);
    $this->editor = $this->editor->fresh();
    $this->publisher->create($this->editor, 'news', 'Sealed draft');
    $scheduled = $this->publisher->create($this->editor, 'news', 'Sealed future');
    $this->publisher->publish($this->editor, $scheduled, 1, now()->addDays(2)->toIso8601String(), null);
    $private = $this->publisher->create($this->editor, 'news', 'Sealed outside site');
    $private = $this->publisher->save($this->editor, $private, [...$private->draft, 'audience' => ['type' => 'sites', 'ids' => [$outside->id]]], 1);
    $this->publisher->publish($this->editor, $private, 2, null, null);
    $expired = $this->publisher->create($this->editor, 'news', 'Sealed expired');
    $this->publisher->publish($this->editor, $expired, 1, null, now()->addMinute()->toIso8601String());
    $this->travel(2)->minutes();
    $outsider = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $outsider->roles()->attach(Role::where('name', 'support_worker')->firstOrFail());
    ensureCanonicalHrStaffProfile($outsider, $outside, ['preferred_name' => 'Sealed outside colleague']);
    ItCatalogItem::factory()->create(['name' => 'Sealed internal service', 'internal_only' => true]);
    ItCatalogItem::factory()->create(['name' => 'Sealed outside service', 'site_scope' => [$outside->id]]);
    $this->actingAs($this->reader)->getJson('/company-hub/search?query=Sealed')->assertOk()->assertJsonCount(0, 'results');
    $this->reader->hrEmployeeProfile()->update(['is_active' => false]);
    $this->actingAs($this->reader->fresh())->getJson('/company-hub/search?query=Compass')->assertForbidden();
    $this->reader->hrEmployeeProfile()->update(['is_active' => true]);
    $permission = Permission::where('key', 'company_hub.view')->sole();
    $this->reader->permissionOverrides()->syncWithoutDetaching([$permission->id => ['allowed' => false]]);
    $this->actingAs($this->reader->fresh())->getJson('/company-hub/search?query=Compass')->assertForbidden();
});

test('command search finds deliberately shared skills and limits result groups and input length', function () {
    $profileId = $this->reader->hrEmployeeProfile()->sole()->id;
    $this->actingAs($this->reader)->putJson('/company-hub/people/'.$profileId.'/profile', ['expected_version' => 0, 'about' => '', 'help_with' => ['Accessible documents']])->assertOk();
    $this->getJson('/company-hub/search?query=Accessible%20documents')->assertOk()->assertJsonCount(1, 'results')->assertJsonPath('results.0.kind', 'Person');
    foreach (range(1, 7) as $number) {
        $page = $this->publisher->create($this->editor, 'news', 'Compass guide '.$number);
        $this->publisher->publish($this->editor, $page, 1, null, null);
    }
    foreach (range(1, 5) as $number) {
        ItCatalogItem::factory()->create(['name' => 'Compass service '.$number, 'site_scope' => [$this->site->id]]);
    }
    $result = $this->getJson('/company-hub/search?query=Compass')->assertOk()->assertJsonCount(9, 'results')->json('results');
    expect(collect($result)->where('kind', 'Story'))->toHaveCount(5)->and(collect($result)->where('kind', 'Service'))->toHaveCount(3);
    $this->getJson('/company-hub/search?query=a')->assertUnprocessable();
    $this->getJson('/company-hub/search?query='.str_repeat('a', 151))->assertUnprocessable();
    $this->getJson('/company-hub/search?query=%25_')->assertOk()->assertJsonCount(0, 'results');
});
