<?php

use App\Domain\CompanyHub\Services\HubCommunity;
use App\Domain\CompanyHub\Services\HubPublishing;
use App\Domain\CompanyHub\Services\HubServices;
use App\Models\ItCatalogItem;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CompanyHubPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    $this->reader = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->reader->roles()->attach(Role::where('name', 'support_worker')->firstOrFail());
    $this->site = ensureCanonicalHrStaffProfile($this->reader, null, ['preferred_name' => 'Compass Colleague', 'work_phone' => '555 1234']);
    $this->editor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->editor->roles()->attach(Role::where('name', 'hr')->firstOrFail());
    ensureCanonicalHrStaffProfile($this->editor, $this->site);
});

test('global hub search includes visible people ideas and published services without private forms or requests', function () {
    $service = ItCatalogItem::factory()->create(['name' => 'Compass equipment help', 'site_scope' => [$this->site->id], 'form_schema' => ['fields' => [['key' => 'internal', 'label' => 'Private technician note', 'type' => 'text', 'visibility' => 'internal']]]]);
    $service->update(['name' => 'Unpublished service title']);
    app(HubCommunity::class)->createIdea($this->reader, ['title' => 'Compass improvement', 'body' => 'Make equipment easier to find.', 'category' => 'Better ways of working']);
    $publisher = app(HubPublishing::class);
    // The default story template starts with unfinished blocks; this search only needs a published title.
    $page = $publisher->create($this->editor, 'news', 'Compass guide', 'blank');
    $publisher->publish($this->editor, $page, 1, null, null);
    $this->actingAs($this->reader)->get('/company-hub?view=home&search=Compass')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('company-hub/index')->where('search', 'Compass')->has('people', 1)->where('people.0.name', 'Compass Colleague')
        ->has('ideas', 1)->where('ideas.0.title', 'Compass improvement')->has('searchServices', 1)->where('searchServices.0.name', 'Compass equipment help')
        ->missing('searchServices.0.form_schema')->missing('people.0.date_of_birth')->where('services', null)->has('pages', 1));
    $this->get('/company-hub?view=home')->assertOk()->assertInertia(fn (Assert $page) => $page->has('people', 0)->has('ideas', 0)->has('searchServices', 0));
});

test('search excludes inaccessible staff hidden ideas and unavailable service contracts and rechecks permission changes', function () {
    $outside = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $person = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $person->roles()->attach(Role::where('name', 'support_worker')->firstOrFail());
    ensureCanonicalHrStaffProfile($person, $outside, ['preferred_name' => 'Compass confidential colleague']);
    foreach ([['is_published' => false], ['internal_only' => true], ['site_scope' => [$outside->id]]] as $attributes) {
        ItCatalogItem::factory()->create(['name' => 'Compass private service', ...$attributes]);
    }
    $idea = app(HubCommunity::class)->createIdea($this->reader, ['title' => 'Compass withdrawn idea', 'body' => 'Hidden', 'category' => 'Our people']);
    DB::table('hub_ideas')->where('id', $idea)->update(['hidden_at' => now()]);
    $this->actingAs($this->reader)->get('/company-hub?search=Compass')->assertOk()->assertInertia(fn (Assert $page) => $page->has('people', 1)->has('ideas', 0)->has('searchServices', 0));
    ItCatalogItem::factory()->create(['name' => 'Compass available service', 'site_scope' => [$this->site->id]]);
    expect(app(HubServices::class)->catalogue($this->reader, 'Compass'))->toHaveCount(1);
    $this->reader->permissionOverrides()->attach(Permission::whereIn('key', ['it.request', 'it.manage'])->pluck('id')->all(), ['allowed' => false]);
    expect(app(HubServices::class)->catalogue($this->reader->fresh(), 'Compass'))->toBe([]);
    $this->reader->hrEmployeeProfile()->update(['is_active' => false]);
    $this->actingAs($this->reader->fresh())->get('/company-hub?search=Compass')->assertForbidden();
});

test('idea search applies before the recent result limit and treats wildcard characters literally', function () {
    $community = app(HubCommunity::class);
    $id = $community->createIdea($this->reader, ['title' => 'Older 50% saving', 'body' => 'Practical improvement', 'category' => 'Sustainability']);
    DB::table('hub_ideas')->where('id', $id)->update(['updated_at' => now()->subMonth()]);
    DB::table('hub_ideas')->insert(array_fill(0, 201, ['user_id' => $this->reader->id, 'title' => 'Recent unrelated idea', 'body' => 'A newer idea', 'category' => 'Our people', 'created_at' => now(), 'updated_at' => now()]));
    expect($community->ideas($this->reader, '50%'))->toHaveCount(1)->and($community->ideas($this->reader, '50%')[0]['id'])->toBe($id)
        ->and($community->ideas($this->reader, '_'))->toBe([]);
});
