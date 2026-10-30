<?php

use App\Domain\CompanyHub\Models\HubMedia;
use App\Domain\CompanyHub\Services\HubCommandSearch;
use App\Domain\CompanyHub\Services\HubFeed;
use App\Domain\CompanyHub\Services\HubPublishing;
use App\Domain\CompanyHub\Services\HubResourceDocuments;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CompanyHubPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Dompdf\Dompdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    Notification::fake();
    Storage::fake('private');
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    $this->site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->outside = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->editor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->editor->roles()->attach(Role::where('name', 'hr')->sole());
    ensureCanonicalHrStaffProfile($this->editor, $this->site, ['secondary_site_ids' => [$this->outside->id]]);
    $this->reader = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->reader->roles()->attach(Role::where('name', 'support_worker')->sole());
    ensureCanonicalHrStaffProfile($this->reader, $this->site);
    $this->publisher = app(HubPublishing::class);
    $this->page = $this->publisher->create($this->editor, 'knowledge', 'Everyday document guide', 'blank')->fresh();
    $pdf = new Dompdf;
    $pdf->loadHtml('<h1>Meridian continuity protocol</h1><p>Searchable calm handover instructions.</p>');
    $pdf->render();
    $bytes = $pdf->output();
    Storage::disk('private')->put('company-hub/discovery-guide.pdf', $bytes);
    $this->media = HubMedia::create(['hub_page_id' => $this->page->id, 'uploaded_by' => $this->editor->id,
        'path' => 'company-hub/discovery-guide.pdf', 'name' => 'Workplace-handbook.pdf', 'mime' => 'application/pdf', 'size' => strlen($bytes), 'version' => 1]);
    $this->draft = [...$this->page->draft, 'sections' => [['id' => 'documents', 'columns' => 1, 'blocks' => [
        ['id' => 'handbook', 'type' => 'file', 'title' => 'Workplace handbook', 'body' => 'Useful everyday guidance.', 'media_id' => $this->media->id],
    ]]]];
    $this->page = $this->publisher->save($this->editor, $this->page, $this->draft, 1);
    $this->publisher->publish($this->editor, $this->page, 2, null, null);
    $this->actingAs($this->reader);
});

test('published document resource rows expose exact file details and separate document versus guide saving identities', function () {
    $this->post('/company-hub/bookmarks', ['subject_type' => 'page', 'subject_id' => $this->page->id, 'saved' => true])->assertRedirect();
    $data = app(HubFeed::class)->forUser($this->reader);
    $file = collect($data['resourceItems'])->firstWhere('media_id', $this->media->id);
    expect($file)->toMatchArray([
        'id' => $this->page->id, 'page_title' => 'Everyday document guide', 'block_id' => 'handbook',
        'document_id' => $this->media->id, 'document_file_version' => 1, 'media_id' => $this->media->id,
        'filename' => 'Workplace-handbook.pdf', 'mime' => 'application/pdf', 'size' => $this->media->size,
        'reader_url' => '/company-hub/documents/'.$this->media->id,
        'download_url' => '/company-hub/media/'.$this->media->id.'?download=1',
        'preview_url' => '/company-hub/media/'.$this->media->id.'/preview',
        'bookmark_subject_type' => 'media', 'bookmark_subject_id' => $this->media->id, 'bookmarked' => false,
    ])->not->toHaveKeys(['path', 'document_version', 'extracted_text']);
    expect(collect($data['pages'])->firstWhere('id', $this->page->id)['bookmarked'])->toBeTrue();
    $this->post('/company-hub/bookmarks', ['subject_type' => 'media', 'subject_id' => $this->media->id, 'saved' => true])->assertRedirect();
    expect(collect(app(HubFeed::class)->forUser($this->reader)['resourceItems'])->first()['bookmarked'])->toBeTrue();
    expect(DB::table('hub_bookmarks')->where('user_id', $this->reader->id)->pluck('subject_type')->sort()->values()->all())->toBe(['media', 'page']);
});

test('document search reads real PDF text and filenames without returning raw extracted contents or private paths', function () {
    foreach (['Meridian continuity', 'Workplace-handbook.pdf'] as $query) {
        $this->getJson('/company-hub/search?query='.rawurlencode($query))->assertOk()
            ->assertJsonPath('results.0.kind', 'Document')->assertJsonPath('results.0.document_id', $this->media->id)
            ->assertJsonPath('results.0.url', '/company-hub/documents/'.$this->media->id)
            ->assertDontSee('Searchable calm handover instructions.')->assertDontSee('company-hub/discovery-guide.pdf');
        expect(app(HubFeed::class)->forUser($this->reader, $query)['resourceItems'])->toHaveCount(1);
    }
    $this->getJson('/company-hub/search?query=AbsentPhrase')->assertOk()->assertJsonCount(0, 'results');
});

test('cached PDF text cannot outlive a hidden file section audience or current actor permission', function () {
    expect(app(HubCommandSearch::class)->search($this->reader, 'Meridian continuity'))->toHaveCount(1);
    $hidden = $this->page->fresh()->draft;
    $hidden['sections'][0]['hidden'] = true;
    $this->page = $this->publisher->save($this->editor, $this->page->fresh(), $hidden, 2);
    $this->publisher->publish($this->editor, $this->page, 3, null, null);
    $this->getJson('/company-hub/search?query=Meridian%20continuity')->assertOk()->assertJsonCount(0, 'results');
    $this->post('/company-hub/bookmarks', ['subject_type' => 'media', 'subject_id' => $this->media->id, 'saved' => true])->assertNotFound();
    $hidden['sections'][0]['hidden'] = false;
    $hidden['audience'] = ['type' => 'sites', 'ids' => [$this->outside->id]];
    $this->page = $this->publisher->save($this->editor, $this->page->fresh(), $hidden, 3);
    $this->publisher->publish($this->editor, $this->page, 4, null, null);
    $this->getJson('/company-hub/search?query=Meridian%20continuity')->assertOk()->assertJsonCount(0, 'results');
    $cachedActor = $this->reader->load(['permissionOverrides', 'roles.permissions']);
    $this->reader->permissionOverrides()->attach(Permission::where('key', 'company_hub.view')->value('id'), ['allowed' => false]);
    expect(fn () => app(HubCommandSearch::class)->search($cachedActor, 'Meridian continuity'))->toThrow(HttpException::class);
    $this->assertDatabaseCount('hub_bookmarks', 0);
});

test('file bookmarks follow the stable document while draft replacements and their counters stay private', function () {
    $payload = ['hub_receipt' => true, 'actor_id' => $this->reader->id, 'subject_type' => 'media', 'subject_id' => $this->media->id,
        'saved' => true, 'expected_saved' => false, 'expected_version' => null];
    $receipt = $this->postJson('/company-hub/bookmarks', $payload)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('saved', true)->assertJsonPath('actor_id', $this->reader->id)
        ->assertJsonPath('subject_type', 'media')->assertJsonPath('subject_id', $this->media->id)
        ->assertJsonPath('bookmarked', true)->assertJsonPath('previous_saved', false)->assertJsonPath('changed', true)->json();
    $this->postJson('/company-hub/bookmarks', [...$payload, 'saved' => false, 'expected_saved' => true, 'expected_version' => str_repeat('0', 64)])->assertConflict();
    $next = HubMedia::create(['hub_page_id' => $this->page->id, 'uploaded_by' => $this->editor->id,
        'path' => $this->media->path, 'name' => 'Next-edition.pdf', 'mime' => 'application/pdf', 'size' => $this->media->size,
        'version' => 1, 'document_root_id' => $this->media->id, 'document_sequence' => 2]);
    $this->media->update(['document_version' => 2]);
    $draft = $this->page->fresh()->draft;
    $draft['sections'][0]['blocks'][0]['media_id'] = $next->id;
    $this->page = $this->publisher->save($this->editor, $this->page->fresh(), $draft, 2);
    $file = collect(app(HubFeed::class)->forUser($this->reader)['resourceItems'])->first();
    expect($file['media_id'])->toBe($this->media->id)->and($file['document_file_version'])->toBe(1)->and($file['bookmarked'])->toBeTrue();
    $this->getJson('/company-hub/bookmarks/state?'.http_build_query(['actor_id' => $this->reader->id, 'subject_type' => 'media', 'subject_id' => $next->id]))->assertNotFound();
    $this->publisher->publish($this->editor, $this->page, 3, null, null);
    $file = collect(app(HubFeed::class)->forUser($this->reader)['resourceItems'])->first();
    expect($file['media_id'])->toBe($next->id)->and($file['document_id'])->toBe($this->media->id)
        ->and($file['document_file_version'])->toBe(2)->and($file['bookmarked'])->toBeTrue();
    $this->postJson('/company-hub/bookmarks', [...$payload, 'saved' => false, 'expected_saved' => true, 'expected_version' => $receipt['version']])
        ->assertOk()->assertJsonPath('bookmarked', false);
});

test('document bookmarks deny draft-only and withdrawn file references even to an editor', function () {
    $draftOnly = HubMedia::create(['hub_page_id' => $this->page->id, 'uploaded_by' => $this->editor->id,
        'path' => $this->media->path, 'name' => 'Private-draft.pdf', 'mime' => 'application/pdf', 'size' => 10, 'version' => 1]);
    $this->actingAs($this->editor)->post('/company-hub/bookmarks', ['subject_type' => 'media', 'subject_id' => $draftOnly->id, 'saved' => true])->assertNotFound();
    $this->publisher->archive($this->editor, $this->page->fresh(), 2);
    $this->post('/company-hub/bookmarks', ['subject_type' => 'media', 'subject_id' => $this->media->id, 'saved' => true])->assertNotFound();
    expect(fn () => app(HubResourceDocuments::class)->published($this->reader, $this->media->id))->toThrow(HttpException::class);
    $this->assertDatabaseCount('hub_bookmarks', 0);
});

test('corrupt PDF content retains filename search and never breaks document discovery', function () {
    Storage::disk('private')->put($this->media->path, '%PDF-1.7 broken document');
    $this->getJson('/company-hub/search?query=Workplace-handbook')->assertOk()->assertJsonPath('results.0.kind', 'Document');
    $this->getJson('/company-hub/search?query=Meridian%20continuity')->assertOk()->assertJsonCount(0, 'results');
});

test('hidden File blocks and an expired newest release cannot be discovered through a warmed PDF cache', function () {
    expect(app(HubCommandSearch::class)->search($this->reader, 'Meridian continuity'))->toHaveCount(1);
    $draft = $this->page->fresh()->draft;
    $draft['sections'][0]['blocks'][0]['hidden'] = true;
    $this->page = $this->publisher->save($this->editor, $this->page->fresh(), $draft, 2);
    $this->publisher->publish($this->editor, $this->page, 3, null, null);
    $this->getJson('/company-hub/search?query=Meridian%20continuity')->assertOk()->assertJsonCount(0, 'results');
    expect(app(HubFeed::class)->forUser($this->reader)['resourceItems'])->toBe([]);
    $draft['sections'][0]['blocks'][0]['hidden'] = false;
    $this->page = $this->publisher->save($this->editor, $this->page->fresh(), $draft, 3);
    $this->publisher->publish($this->editor, $this->page, 4, null, now()->addMinute()->toIso8601String());
    expect(app(HubCommandSearch::class)->search($this->reader, 'Meridian continuity'))->toHaveCount(1);
    $this->travel(2)->minutes();
    $this->getJson('/company-hub/search?query=Meridian%20continuity')->assertOk()->assertJsonCount(0, 'results');
    $this->post('/company-hub/bookmarks', ['subject_type' => 'media', 'subject_id' => $this->media->id, 'saved' => true])->assertNotFound();
});
