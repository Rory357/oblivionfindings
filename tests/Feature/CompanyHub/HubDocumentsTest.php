<?php

use App\Domain\CompanyHub\Models\HubCollection;
use App\Domain\CompanyHub\Models\HubMedia;
use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\CompanyHub\Services\HubDocuments;
use App\Domain\CompanyHub\Services\HubPublishing;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CompanyHubPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Notification::fake();
    Storage::fake('private');
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    $this->editor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->editor->roles()->attach(Role::where('name', 'hr')->sole());
    $this->site = ensureCanonicalHrStaffProfile($this->editor);
    $this->reader = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->reader->roles()->attach(Role::where('name', 'support_worker')->sole());
    ensureCanonicalHrStaffProfile($this->reader, $this->site);
    $this->publisher = app(HubPublishing::class);
    $this->documents = app(HubDocuments::class);
    $this->page = $this->publisher->create($this->editor, 'knowledge', 'Work guide', 'blank')->fresh();
    Storage::disk('private')->put('company-hub/document-original.pdf', "%PDF-1.7\nOriginal work guide\n");
    $this->media = HubMedia::create(['hub_page_id' => $this->page->id, 'uploaded_by' => $this->editor->id,
        'path' => 'company-hub/document-original.pdf', 'name' => 'Original.pdf', 'mime' => 'application/pdf', 'size' => 32, 'alt' => '', 'version' => 1])->fresh();
    $this->page = $this->publisher->save($this->editor, $this->page, [...$this->page->draft, 'resource_type' => 'Guide',
        'audience' => ['type' => 'sites', 'ids' => [$this->site->id]], 'sections' => [['id' => 'guide', 'columns' => 1, 'blocks' => [
            ['id' => 'file', 'type' => 'file', 'title' => 'Work guide file', 'body' => 'Instructions for our work', 'media_id' => $this->media->id],
        ]]]], 1);
    $this->release = $this->publisher->publish($this->editor, $this->page, 2, null, null);
    $this->url = '/company-hub/manage/'.$this->page->id.'/documents/'.$this->media->id;
    $this->payload = ['actor_id' => $this->editor->id, 'page_version' => 2, 'document_version' => 1];
    $this->pdf = fn (string $body = 'New work guide') => UploadedFile::fake()->createWithContent('Work guide.pdf', "%PDF-1.7\n".$body."\n");
    $this->snapshot = fn () => ['draft' => $this->page->fresh()->toArray(), 'media' => HubMedia::orderBy('id')->get()->toArray(),
        'history' => DB::table('hub_document_versions')->orderBy('id')->get()->toArray(), 'files' => Storage::disk('private')->allFiles()];
    $this->actingAs($this->editor);
    Notification::fake();
});

test('document readers and PDF inline previews recheck published reference privacy staff sites and hidden state', function () {
    $this->actingAs($this->reader)->get('/company-hub/documents/'.$this->media->id)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('company-hub/document')->where('document.id', $this->media->id)->where('document.media_id', $this->media->id)
        ->where('document.version', 1)->where('document.document_version', null)->where('document.edition', 'published')->where('document.can_edit', false));
    $this->get('/company-hub/media/'.$this->media->id.'/preview')->assertOk()->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($this->get('/company-hub/media/'.$this->media->id.'/preview')->headers->get('Content-Disposition'))->toStartWith('inline;');
    expect($this->get('/company-hub/media/'.$this->media->id)->headers->get('Content-Disposition'))->toStartWith('attachment;');
    $private = HubMedia::create(['hub_page_id' => $this->page->id, 'uploaded_by' => $this->editor->id, 'path' => 'private.pdf', 'name' => 'Private.pdf', 'mime' => 'application/pdf', 'size' => 10, 'version' => 1]);
    $this->get('/company-hub/documents/'.$private->id)->assertNotFound();
    $this->get('/company-hub/media/'.$private->id.'/preview')->assertNotFound();
    $outside = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->reader->hrEmployeeProfile()->update(['primary_site_id' => $outside->id]);
    $this->get('/company-hub/documents/'.$this->media->id)->assertNotFound();
    $this->get('/company-hub/media/'.$this->media->id.'/preview')->assertNotFound();
    $this->reader->hrEmployeeProfile()->update(['primary_site_id' => $this->site->id, 'end_date' => today()->subDay()]);
    $this->get('/company-hub/media/'.$this->media->id.'/preview')->assertForbidden();
});

test('document first uploads create complete private canonical drafts and exact request receipts prevent duplicate creation', function () {
    $request = ['actor_id' => $this->editor->id, 'request_id' => (string) Str::uuid(), 'title' => 'New staff document', 'description' => 'A useful document'];
    $before = HubPage::count();
    $first = $this->postJson('/company-hub/documents', [...$request, 'file' => ($this->pdf)()])->assertCreated()
        ->assertJsonPath('saved', true)->assertJsonPath('actor_id', $this->editor->id)->assertJsonPath('page_version', 2)->assertJsonPath('document_version', 1);
    $page = HubPage::findOrFail($first->json('page_id'));
    expect($page->owner_id)->toBe($this->editor->id)->and($page->kind)->toBe('knowledge')->and($page->currentRelease)->toBeNull()
        ->and($page->revisions()->count())->toBe(0)->and($page->draft['sections'][0]['blocks'][0]['media_id'])->toBe($first->json('media_id'));
    $this->postJson('/company-hub/documents', [...$request, 'file' => ($this->pdf)()])->assertOk()->assertJsonPath('replayed', true)
        ->assertJsonPath('page_id', $page->id)->assertJsonPath('document_id', $first->json('document_id'));
    $this->getJson('/company-hub/documents/uploads/'.$request['request_id'].'?actor_id='.$this->editor->id)->assertOk()->assertJsonPath('page_id', $page->id);
    $this->postJson('/company-hub/documents', [...$request, 'file' => ($this->pdf)('Different bytes')])->assertConflict();
    expect(HubPage::count())->toBe($before + 1);
    $this->actingAs($this->reader)->get('/company-hub/documents/'.$first->json('document_id'))->assertNotFound();
    Notification::assertNothingSent();
});

test('document review dates follow the authorised edition without leaking private draft changes', function (?string $draftReviewOn) {
    $readerUrl = '/company-hub/documents/'.$this->media->id;
    $this->get($readerUrl)->assertOk()->assertInertia(fn (Assert $page) => $page->where('document.review_on', null));

    $publishedReviewOn = '2026-11-15';
    $this->page = $this->publisher->save($this->editor, $this->page, [...$this->page->draft, 'review_on' => $publishedReviewOn], 2);
    $release = $this->publisher->publish($this->editor, $this->page, 3, null, null);
    $publishedSnapshot = $release->content;
    $this->page = $this->publisher->save($this->editor, $this->page->fresh(), [...$this->page->draft, 'review_on' => $draftReviewOn], 3);
    $draftUrl = $readerUrl.'?edition=draft&actor_id='.$this->editor->id.'&page_version=4';

    $this->get($readerUrl)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('document.edition', 'published')->where('document.review_on', $publishedReviewOn));
    $this->get($draftUrl)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('document.edition', 'draft')->where('document.review_on', $draftReviewOn));
    $this->getJson($this->url)->assertOk()
        ->assertJsonPath('draft.review_on', $draftReviewOn)->assertJsonPath('published.review_on', $publishedReviewOn);
    $this->actingAs($this->reader)->get($readerUrl)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('document.edition', 'published')->where('document.review_on', $publishedReviewOn));
    $this->get($readerUrl.'?edition=draft&actor_id='.$this->reader->id.'&page_version=4')->assertNotFound();
    expect($release->fresh()->content)->toEqual($publishedSnapshot);

    $this->publisher->publish($this->editor, $this->page->fresh(), 4, null, null);
    $this->get($readerUrl)->assertOk()->assertInertia(fn (Assert $page) => $page->where('document.review_on', $draftReviewOn));
    expect($release->fresh()->content)->toEqual($publishedSnapshot);
    Notification::assertNothingSent();
})->with(['rescheduled' => ['2026-12-15'], 'cleared' => [null]]);

test('document replacement saves a new private edition while immutable published files remain current until canonical publication', function () {
    $snapshot = $this->release->content;
    $changed = $this->postJson($this->url.'/replace', [...$this->payload, 'file' => ($this->pdf)()])->assertOk()
        ->assertJsonPath('saved', true)->assertJsonPath('document_version', 2)->assertJsonPath('page_version', 3)
        ->assertJsonPath('draft.version', 2)->assertJsonPath('published.version', 1);
    $newId = $changed->json('draft.media_id');
    expect($newId)->not->toBe($this->media->id)->and($this->release->fresh()->content)->toEqual($snapshot)
        ->and(HubMedia::find($newId)->documentId())->toBe($this->media->id);
    $this->actingAs($this->reader)->get('/company-hub/documents/'.$this->media->id)->assertInertia(fn (Assert $page) => $page->where('document.media_id', $this->media->id)->where('document.version', 1));
    $this->get('/company-hub/media/'.$newId.'/preview')->assertNotFound();
    $this->publisher->publish($this->editor, $this->page->fresh(), 3, null, null);
    $this->get('/company-hub/documents/'.$this->media->id)->assertInertia(fn (Assert $page) => $page->where('document.media_id', $newId)->where('document.version', 2));
    $this->get('/company-hub/media/'.$newId.'/preview')->assertOk();
    $this->get('/company-hub/media/'.$this->media->id.'/preview')->assertNotFound();
});

test('document restore creates an independent private file edition preserving the published release and immutable history', function () {
    $changed = $this->postJson($this->url.'/replace', [...$this->payload, 'file' => ($this->pdf)()])->assertOk();
    $initial = collect($changed->json('history'))->firstWhere('version', 1);
    $oldHistory = DB::table('hub_document_versions')->where('id', $initial['id'])->first();
    $restored = $this->postJson($this->url.'/restore', ['actor_id' => $this->editor->id, 'page_version' => 3, 'document_version' => 2, 'history_id' => $initial['id']])
        ->assertOk()->assertJsonPath('document_version', 3)->assertJsonPath('draft.version', 3)->assertJsonPath('published.version', 1);
    $restoredMedia = HubMedia::findOrFail($restored->json('draft.media_id'));
    expect($restoredMedia->id)->not->toBe($this->media->id)->and($restoredMedia->path)->not->toBe($this->media->path)
        ->and(Storage::disk('private')->get($restoredMedia->path))->toBe(Storage::disk('private')->get($this->media->path))
        ->and($restoredMedia->name)->toBe('Original.pdf')->and(DB::table('hub_document_versions')->where('id', $initial['id'])->first())->toEqual($oldHistory);
    $this->assertDatabaseCount('hub_document_versions', 3);
});

test('document actor page and generation conflicts and foreign history never overwrite saved choices or create files', function () {
    $before = ($this->snapshot)();
    foreach ([['actor_id' => $this->reader->id], ['page_version' => 1], ['document_version' => 2]] as $conflict) {
        $this->postJson($this->url.'/replace', [...$this->payload, ...$conflict, 'file' => ($this->pdf)()])->assertConflict();
    }
    $this->postJson($this->url.'/restore', [...$this->payload, 'history_id' => 2147483647])->assertNotFound();
    expect(($this->snapshot)())->toEqual($before);
    $this->actingAs($this->reader)->getJson($this->url)->assertNotFound();
    $this->postJson($this->url.'/replace', [...$this->payload, 'actor_id' => $this->reader->id, 'file' => ($this->pdf)()])->assertNotFound();
});

test('document draft previews require the exact current actor and page version while published readers never expose draft bytes', function () {
    $changed = $this->postJson($this->url.'/replace', [...$this->payload, 'file' => ($this->pdf)()])->assertOk();
    $reader = '/company-hub/documents/'.$this->media->id.'?edition=draft&actor_id='.$this->editor->id.'&page_version=';
    $this->get($reader.'2')->assertConflict();
    $this->get($reader.'3')->assertOk()->assertInertia(fn (Assert $page) => $page->where('document.edition', 'draft')->where('document.media_id', $changed->json('draft.media_id')));
    $this->actingAs($this->reader)->get($reader.'3')->assertConflict();
    $this->get('/company-hub/documents/'.$this->media->id.'?edition=draft&actor_id='.$this->reader->id.'&page_version=3')->assertNotFound();
});

test('document replacement obeys canonical review schedule discard and archive locks without editing the published edition', function (string $state) {
    if ($state === 'review') {
        $this->page = $this->publisher->save($this->editor, $this->page, [...$this->page->draft, 'summary' => 'A private edit'], 2);
        $this->publisher->submit($this->editor, $this->page, 3);
    } elseif ($state === 'schedule') {
        $this->publisher->publish($this->editor, $this->page, 2, now()->addDay()->toIso8601String(), null);
    } elseif ($state === 'discard') {
        $this->page->update(['draft_discarded_at' => now()]);
    } else {
        $this->page->update(['archived_at' => now()]);
    }
    $before = ($this->snapshot)();
    $this->postJson($this->url.'/replace', [...$this->payload, 'page_version' => (int) $this->page->fresh()->draft_version, 'file' => ($this->pdf)()])->assertConflict();
    expect(($this->snapshot)())->toEqual($before);
})->with(['review', 'schedule', 'discard', 'archive']);

test('document writes refresh retained permission and staff models and optional schema leaves authorised legacy PDFs readable', function () {
    $this->editor->load(['permissionOverrides', 'roles.permissions']);
    $this->editor->permissionOverrides()->attach(Permission::where('key', 'company_hub.view')->value('id'), ['allowed' => false]);
    $this->postJson($this->url.'/replace', [...$this->payload, 'file' => ($this->pdf)()])->assertForbidden();
    $this->editor->permissionOverrides()->detach();
    app()->instance(HubDocuments::class, new class extends HubDocuments
    {
        public function ready(): bool
        {
            return false;
        }
    });
    $this->getJson('/company-hub/documents/options')->assertOk()->assertJsonPath('ready', false);
    $this->get('/company-hub/documents/'.$this->media->id)->assertOk();
    $this->postJson($this->url.'/replace', [...$this->payload, 'file' => ($this->pdf)()])->assertUnprocessable()->assertJsonValidationErrors('document');
    $this->actingAs($this->reader)->get('/company-hub/media/'.$this->media->id.'/preview')->assertOk();
});

test('late document audit failures roll back edition journal draft and receipt state and compensate private files', function () {
    $before = ($this->snapshot)();
    $fail = true;
    DB::listen(function (QueryExecuted $query) use (&$fail): void {
        if ($fail && str_starts_with(strtolower($query->sql), 'insert into `audit_logs`') && in_array('company_hub.document_replaced', $query->bindings, true)) {
            $fail = false;
            throw new RuntimeException('Synthetic document audit failure.');
        }
    });
    $this->withoutExceptionHandling();
    expect(fn () => $this->postJson($this->url.'/replace', [...$this->payload, 'file' => ($this->pdf)()]))->toThrow(RuntimeException::class, 'Synthetic document audit failure.');
    expect(($this->snapshot)())->toEqual($before);
    Notification::assertNothingSent();
});

test('compact document details save canonical metadata privately and publish through the stable reader link', function () {
    $snapshot = $this->release->content;
    $saved = $this->patchJson($this->url.'/details', [...$this->payload, 'title' => 'Updated work guide',
        'description' => 'Clear instructions for our work', 'category' => 'Learning', 'resource_type' => 'Policy',
        'review_on' => '2027-03-20', 'team_ids' => [], 'audience' => ['type' => 'sites', 'ids' => [$this->site->id]]])
        ->assertOk()->assertJsonPath('page_version', 3)->assertJsonPath('document_version', 1)
        ->assertJsonPath('metadata.title', 'Updated work guide')->assertJsonPath('metadata.review_on', '2027-03-20')
        ->assertJsonPath('can_publish', true)->assertJsonPath('governance_warnings', []);
    expect($this->release->fresh()->content)->toBe($snapshot)->and($this->page->fresh()->draft['title'])->toBe('Updated work guide');
    $this->actingAs($this->reader)->get('/company-hub/documents/'.$this->media->id)->assertInertia(fn (Assert $page) => $page->where('document.title', 'Work guide file'));
    $this->actingAs($this->editor)->postJson($this->url.'/publication', ['actor_id' => $this->editor->id,
        'page_version' => $saved->json('page_version'), 'document_version' => 1, 'action' => 'publish'])
        ->assertOk()->assertJsonPath('publication_state', 'published')->assertJsonPath('published.title', 'Updated work guide');
    $this->actingAs($this->reader)->get('/company-hub/documents/'.$this->media->id)->assertInertia(fn (Assert $page) => $page
        ->where('document.title', 'Updated work guide')->where('document.description', 'Clear instructions for our work')->where('document.review_on', '2027-03-20'));
    expect($this->release->fresh()->content)->toBe($snapshot);
    Notification::assertNothingSent();
});

test('compact document actions reject stale actors page versions foreign files and current permission loss without mutations', function () {
    $before = ($this->snapshot)();
    foreach ([['actor_id' => $this->reader->id], ['page_version' => 1], ['document_version' => 2]] as $conflict) {
        $this->patchJson($this->url.'/details', [...$this->payload, ...$conflict, 'title' => 'Must not overwrite'])->assertConflict();
        $this->postJson($this->url.'/publication', [...$this->payload, ...$conflict, 'action' => 'publish'])->assertConflict();
    }
    $other = $this->publisher->create($this->editor, 'knowledge', 'Another document', 'blank');
    $foreign = HubMedia::create(['hub_page_id' => $other->id, 'uploaded_by' => $this->editor->id, 'path' => 'foreign.pdf',
        'name' => 'Foreign.pdf', 'mime' => 'application/pdf', 'size' => 32, 'version' => 1]);
    $this->patchJson('/company-hub/manage/'.$this->page->id.'/documents/'.$foreign->id.'/details', [...$this->payload, 'title' => 'Foreign'])->assertNotFound();
    expect($this->page->fresh()->toArray())->toBe($before['draft']);
    $this->editor->permissionOverrides()->attach(Permission::where('key', 'company_hub.view')->value('id'), ['allowed' => false]);
    $this->patchJson($this->url.'/details', [...$this->payload, 'title' => 'Revoked'])->assertForbidden();
    $this->postJson($this->url.'/publication', [...$this->payload, 'action' => 'publish'])->assertForbidden();
    expect($this->page->fresh()->toArray())->toBe($before['draft']);
});

test('compact details retain authored companion blocks and canonical governance is advisory', function () {
    $content = $this->page->draft;
    $companion = ['id' => 'intro', 'type' => 'text', 'title' => 'Read before using', 'body' => 'Important authored instructions.'];
    $content['sections'][0]['blocks'][] = $companion;
    $this->page = $this->publisher->save($this->editor, $this->page, $content, 2);
    $this->patchJson($this->url.'/details', [...$this->payload, 'page_version' => 3, 'title' => 'Attachment title', 'review_on' => null])
        ->assertOk()->assertJsonPath('metadata.title', 'Attachment title')->assertJsonPath('readiness.content_ready', true)
        ->assertJsonPath('governance_warnings.0.code', 'review_missing');
    $draft = $this->page->fresh()->draft;
    expect($draft['title'])->toBe('Work guide')->and($draft['sections'][0]['blocks'][1])->toEqual($companion);
    $this->postJson($this->url.'/publication', [...$this->payload, 'page_version' => 4, 'action' => 'publish'])
        ->assertOk()->assertJsonPath('publication_state', 'published');
});

test('compact document review and scheduled publication lock metadata until their canonical workflows release the draft', function (string $action) {
    $this->page = $this->publisher->save($this->editor, $this->page, [...$this->page->draft, 'requires_approval' => $action === 'submit'], 2);
    $data = [...$this->payload, 'page_version' => 3];
    if ($action === 'submit') {
        $this->postJson($this->url.'/publication', [...$data, 'action' => 'publish'])->assertUnprocessable()->assertJsonValidationErrors('publish');
    }
    $this->postJson($this->url.'/publication', [...$data, 'action' => $action,
        ...($action === 'publish' ? ['publish_at' => now()->addDay()->toIso8601String()] : [])])->assertOk()
        ->assertJsonPath('publication_state', $action === 'submit' ? 'review' : 'scheduled')->assertJsonPath('can_edit_details', false);
    $before = ($this->snapshot)();
    $this->patchJson($this->url.'/details', [...$data, 'title' => 'Locked edit'])->assertConflict();
    expect(($this->snapshot)())->toEqual($before);
})->with(['submit', 'publish']);

test('compact document uploads include organisation metadata in the immutable request receipt fingerprint', function () {
    $request = ['actor_id' => $this->editor->id, 'request_id' => (string) Str::uuid(), 'title' => 'Team document',
        'description' => 'Working instructions', 'category' => 'Learning', 'resource_type' => 'Process', 'review_on' => '2027-04-10',
        'audience' => ['type' => 'sites', 'ids' => [$this->site->id]], 'team_ids' => []];
    $first = $this->postJson('/company-hub/documents', [...$request, 'file' => ($this->pdf)()])->assertCreated();
    $page = HubPage::findOrFail($first->json('page_id'));
    expect($page->draft['review_on'])->toBe('2027-04-10')->and($page->draft['audience'])->toEqual($request['audience'])
        ->and($page->draft['resource_type'])->toBe('Process')->and($page->revisions()->count())->toBe(0);
    $this->postJson('/company-hub/documents', [...$request, 'file' => ($this->pdf)()])->assertOk()->assertJsonPath('replayed', true);
    $this->postJson('/company-hub/documents', [...$request, 'audience' => ['ids' => [$this->site->id], 'type' => 'sites'], 'file' => ($this->pdf)()])
        ->assertOk()->assertJsonPath('replayed', true);
    $this->postJson('/company-hub/documents', [...$request, 'review_on' => '2027-04-11', 'file' => ($this->pdf)()])->assertConflict();
    expect($first->json('details_url'))->toBe('/company-hub/manage/'.$page->id.'/documents/'.$first->json('document_id').'/edit');
});

test('compact document metadata enforces authorised collection audience team and ownership choices atomically', function () {
    $before = ($this->snapshot)();
    foreach ([['collection_id' => 2147483647], ['team_ids' => [2147483647]],
        ['audience' => ['type' => 'sites', 'ids' => [2147483647]]], ['owner_id' => $this->reader->id]] as $invalid) {
        $this->patchJson($this->url.'/details', [...$this->payload, 'title' => 'Must roll back', ...$invalid])->assertUnprocessable();
        expect(($this->snapshot)())->toEqual($before);
    }
    Notification::assertNothingSent();
});

test('compact document scheduled editions become current only when due and expiry never resurrects the previous release', function () {
    $starts = now()->addHour();
    $ends = now()->addHours(2);
    $snapshot = $this->release->content;
    $this->patchJson($this->url.'/details', [...$this->payload, 'title' => 'Scheduled guidance'])->assertOk();
    $this->postJson($this->url.'/publication', [...$this->payload, 'page_version' => 3, 'action' => 'publish',
        'publish_at' => $starts->toIso8601String(), 'expires_at' => $ends->toIso8601String()])->assertOk()->assertJsonPath('publication_state', 'scheduled');
    $this->actingAs($this->reader)->get('/company-hub/documents/'.$this->media->id)->assertInertia(fn (Assert $page) => $page->where('document.title', 'Work guide file'));
    $this->travelTo($starts->copy()->addSecond());
    $this->get('/company-hub/documents/'.$this->media->id)->assertInertia(fn (Assert $page) => $page->where('document.title', 'Scheduled guidance'));
    $this->travelTo($ends->copy()->addSecond());
    $this->get('/company-hub/documents/'.$this->media->id)->assertNotFound();
    expect($this->release->fresh()->content)->toBe($snapshot);
    Notification::assertNothingSent();
});

test('compact document organisation and eligible ownership changes use canonical version increments', function () {
    $owner = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $owner->roles()->attach(Role::where('name', 'hr')->sole());
    ensureCanonicalHrStaffProfile($owner, $this->site);
    $collection = HubCollection::create(['name' => 'Shared work guidance', 'created_by' => $this->editor->id, 'updated_by' => $this->editor->id]);
    $snapshot = $this->release->content;
    $this->patchJson($this->url.'/details', [...$this->payload, 'owner_id' => $owner->id, 'collection_id' => $collection->id])
        ->assertOk()->assertJsonPath('page_version', 4)->assertJsonPath('document_version', 1)
        ->assertJsonPath('metadata.owner_id', $owner->id)->assertJsonPath('metadata.collection_id', $collection->id);
    expect($this->page->fresh()->owner_id)->toBe($owner->id)->and($this->release->fresh()->content)->toBe($snapshot);
    Notification::assertNothingSent();
});

test('compact document changes reject discarded and archived drafts without touching saved files', function (string $state) {
    $this->page->update([$state === 'archived' ? 'archived_at' : 'draft_discarded_at' => now()]);
    $before = ($this->snapshot)();
    $this->patchJson($this->url.'/details', [...$this->payload, 'title' => 'Locked'])->assertConflict();
    $this->postJson($this->url.'/publication', [...$this->payload, 'action' => 'publish'])->assertConflict();
    expect(($this->snapshot)())->toEqual($before);
})->with(['archived', 'discarded']);
