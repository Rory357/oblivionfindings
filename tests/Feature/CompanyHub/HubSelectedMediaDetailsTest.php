<?php

use App\Domain\CompanyHub\Models\HubMedia;
use App\Domain\CompanyHub\Services\HubPublishing;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CompanyHubPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Notification::fake();
    Storage::fake('private');
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    $this->site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->editor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->editor->roles()->attach(Role::where('name', 'hr')->sole());
    ensureCanonicalHrStaffProfile($this->editor, $this->site);
    $this->reader = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->reader->roles()->attach(Role::where('name', 'support_worker')->sole());
    ensureCanonicalHrStaffProfile($this->reader, $this->site);
    $this->publisher = app(HubPublishing::class);
    $this->page = $this->publisher->create($this->editor, 'knowledge', 'Selected file guide', 'blank');
    Storage::disk('private')->put('company-hub/selected-details.pdf', '%PDF-1.7 selected edition');
    $this->media = HubMedia::create(['hub_page_id' => $this->page->id, 'uploaded_by' => $this->editor->id,
        'path' => 'company-hub/selected-details.pdf', 'name' => 'Selected-edition.pdf', 'mime' => 'application/pdf', 'size' => 25, 'version' => 1]);
    $this->page = $this->publisher->save($this->editor, $this->page, [...$this->page->draft,
        'sections' => [['id' => 'files', 'columns' => 1, 'blocks' => [
            ['id' => 'selected', 'type' => 'file', 'title' => 'The selected file', 'media_id' => $this->media->id],
        ]]],
    ], 1);
    $this->detailsUrl = '/company-hub/manage/'.$this->page->id.'/media/'.$this->media->id.'/details';
    $this->actorQuery = '?actor_id='.$this->editor->id;
    $this->actingAs($this->editor);
});

test('selected media metadata preserves the exact edition and omits private storage and document counters', function () {
    $next = HubMedia::create(['hub_page_id' => $this->page->id, 'uploaded_by' => $this->editor->id,
        'path' => $this->media->path, 'name' => 'Newer-edition.pdf', 'mime' => 'application/pdf', 'size' => 99,
        'version' => 1, 'document_root_id' => $this->media->id, 'document_sequence' => 2]);
    $this->media->update(['document_version' => 2]);
    $this->getJson($this->detailsUrl.$this->actorQuery)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('actor_id', $this->editor->id)->assertJsonPath('page_id', $this->page->id)
        ->assertJsonPath('media.id', $this->media->id)->assertJsonPath('media.name', 'Selected-edition.pdf')
        ->assertJsonPath('media.mime', 'application/pdf')->assertJsonPath('media.size', 25)
        ->assertJsonPath('media.document_id', $this->media->id)->assertJsonPath('media.document_file_version', 1)
        ->assertJsonPath('media.download_url', '/company-hub/media/'.$this->media->id.'?download=1')
        ->assertJsonPath('media.preview_url', '/company-hub/media/'.$this->media->id.'/preview')
        ->assertJsonMissingPath('media.path')->assertJsonMissingPath('media.uploaded_by')->assertJsonMissingPath('media.document_version');
    $this->getJson('/company-hub/manage/'.$this->page->id.'/media/'.$next->id.'/details'.$this->actorQuery)
        ->assertOk()->assertJsonPath('media.id', $next->id)->assertJsonPath('media.document_id', $this->media->id)
        ->assertJsonPath('media.document_file_version', 2)->assertJsonPath('media.name', 'Newer-edition.pdf');
});

test('selected media metadata requires and confirms the current actor without altering the saved page', function () {
    $before = $this->page->fresh()->draft;
    $this->getJson($this->detailsUrl)->assertUnprocessable()->assertJsonValidationErrors('actor_id');
    $this->getJson($this->detailsUrl.'?actor_id='.$this->reader->id)->assertConflict()->assertJsonMissingPath('media');
    $this->getJson($this->detailsUrl.'?actor_id=0')->assertUnprocessable();
    expect($this->page->fresh()->draft)->toEqual($before)->and($this->page->fresh()->draft_version)->toBe(2);
    Notification::assertNothingSent();
});

test('selected media metadata denies readers and media from another editable page', function () {
    $this->actingAs($this->reader)->getJson($this->detailsUrl.'?actor_id='.$this->reader->id)->assertNotFound();
    $other = $this->publisher->create($this->editor, 'knowledge', 'Another guide', 'blank');
    $this->actingAs($this->editor)->getJson('/company-hub/manage/'.$other->id.'/media/'.$this->media->id.'/details'.$this->actorQuery)
        ->assertNotFound()->assertJsonMissingPath('media');
});

test('removed selected media remains describable without restoration or changes to its saved reference', function () {
    $before = $this->page->fresh()->draft;
    $this->media->update(['removed_at' => now(), 'removed_by' => $this->editor->id, 'version' => 2]);
    $this->getJson('/company-hub/manage/'.$this->page->id.'/media')->assertOk()->assertJsonCount(0, 'media');
    $this->getJson($this->detailsUrl.$this->actorQuery)->assertOk()->assertJsonPath('media.id', $this->media->id)
        ->assertJsonPath('media.name', 'Selected-edition.pdf')->assertJsonPath('media.version', 2)
        ->assertJsonPath('media.can_remove', false)->assertJsonPath('media.can_restore', true);
    expect($this->media->fresh()->removed_at)->not->toBeNull()
        ->and($this->page->fresh()->draft)->toEqual($before)->and($this->page->fresh()->draft_version)->toBe(2);
    Storage::disk('private')->assertExists($this->media->path);
    $this->actingAs($this->reader)->getJson($this->detailsUrl.'?actor_id='.$this->reader->id)->assertNotFound();
});

test('selected media metadata rechecks withdrawn edit permissions and current approved site access', function () {
    $cachedActor = $this->editor->load(['roles.permissions', 'permissionOverrides']);
    foreach (['company_hub.write', 'company_hub.edit_all'] as $key) {
        $this->editor->permissionOverrides()->attach(Permission::where('key', $key)->sole(), ['allowed' => false]);
    }
    $this->actingAs($cachedActor)->getJson($this->detailsUrl.$this->actorQuery)->assertNotFound();
    $this->editor->permissionOverrides()->detach();
    $outside = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->page->update(['draft' => [...$this->page->draft, 'audience' => ['type' => 'sites', 'ids' => [$outside->id]]]]);
    $this->getJson($this->detailsUrl.$this->actorQuery)->assertNotFound()->assertJsonMissingPath('media');
});
