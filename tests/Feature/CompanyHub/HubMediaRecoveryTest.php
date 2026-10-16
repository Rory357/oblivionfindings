<?php

use App\Domain\CompanyHub\Models\HubMedia;
use App\Domain\CompanyHub\Services\HubAccess;
use App\Domain\CompanyHub\Services\HubMediaLibrary;
use App\Domain\CompanyHub\Services\HubPageCopy;
use App\Domain\CompanyHub\Services\HubPublishing;
use App\Domain\Hr\Models\HrDepartment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CompanyHubPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

beforeEach(function () {
    Notification::fake();
    Storage::fake('private');
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    $this->editor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->editor->roles()->attach(Role::where('name', 'hr')->sole());
    $this->team = HrDepartment::create(['name' => 'Media team', 'is_active' => true]);
    $this->site = ensureCanonicalHrStaffProfile($this->editor, overrides: ['department_id' => $this->team->id]);
    $this->reader = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->reader->roles()->attach(Role::where('name', 'support_worker')->sole());
    ensureCanonicalHrStaffProfile($this->reader, $this->site, ['department_id' => $this->team->id]);
    $this->publisher = app(HubPublishing::class);
    $this->page = $this->publisher->create($this->editor, 'department', 'Our shared space', 'team');
    $image = imagecreatetruecolor(32, 24);
    imagefill($image, 0, 0, imagecolorallocate($image, 230, 20, 30));
    ob_start();
    imagepng($image);
    $this->bytes = ob_get_clean();
    imagedestroy($image);
    Storage::disk('private')->put('company-hub/media-recovery-source.png', $this->bytes);
    $this->image = HubMedia::create([
        'hub_page_id' => $this->page->id, 'uploaded_by' => $this->editor->id,
        'path' => 'company-hub/media-recovery-source.png', 'name' => 'Source.png', 'mime' => 'image/png', 'size' => strlen($this->bytes),
        'alt' => 'Original image description', 'caption' => 'Original library caption', 'focal_x' => 35, 'focal_y' => 70, 'version' => 1,
    ]);
    Storage::disk('private')->put('company-hub/media-recovery-guide.pdf', 'A source guide');
    $this->pdf = HubMedia::create(['hub_page_id' => $this->page->id, 'uploaded_by' => $this->editor->id, 'path' => 'company-hub/media-recovery-guide.pdf', 'name' => 'Guide.pdf', 'mime' => 'application/pdf', 'size' => 14, 'version' => 1]);
    $this->page = $this->publisher->save($this->editor, $this->page, [
        ...$this->page->draft, 'cover_id' => $this->image->id, 'team_ids' => [$this->team->id],
        'audience' => ['type' => 'sites', 'ids' => [$this->site->id]],
        'hero' => [...$this->page->draft['hero'], 'style' => 'image', 'focal_x' => 20, 'focal_y' => 80],
        'sections' => [['id' => 'photos', 'columns' => 1, 'blocks' => [
            ['id' => 'photo', 'type' => 'image', 'title' => 'Our people', 'media_id' => $this->image->id, 'alt' => 'Authored alternative text', 'body' => 'Published authored caption'],
            ['id' => 'hidden-guide', 'type' => 'file', 'title' => 'Private authoring attachment', 'media_id' => $this->pdf->id, 'hidden' => true],
        ]]],
    ], 1);
    $this->release = $this->publisher->publish($this->editor, $this->page, 2, null, null);
    $this->mediaUrl = '/company-hub/manage/'.$this->page->id.'/media';
    $this->availabilityUrl = $this->mediaUrl.'/'.$this->image->id.'/availability';
    $this->actingAs($this->editor);
});

test('image captions and focal coordinates persist independently with version actor bounds and literal caption search', function () {
    $before = $this->release->content;
    $url = $this->mediaUrl.'/'.$this->image->id;
    $this->patchJson($url, ['name' => 'A welcoming space.png', 'caption' => 'Welcome_100% together', 'focal_x' => 0, 'focal_y' => 100, 'version' => 1, 'actor_id' => $this->editor->id])
        ->assertOk()->assertJsonPath('version', 2)->assertJsonPath('caption', 'Welcome_100% together')->assertJsonPath('focal_x', 0)->assertJsonPath('focal_y', 100);
    $this->getJson($this->mediaUrl.'?q=Welcome_100%25')->assertOk()->assertJsonCount(1, 'media')->assertJsonPath('media.0.id', $this->image->id);
    $this->getJson($this->mediaUrl.'?q=WelcomeX100')->assertOk()->assertJsonCount(0, 'media');
    $this->patchJson($url, ['name' => 'Stale.png', 'caption' => 'Lost work', 'version' => 1])->assertConflict();
    $this->patchJson($url, ['name' => 'Wrong actor.png', 'version' => 2, 'actor_id' => $this->reader->id])->assertConflict();
    foreach ([['focal_x' => -1], ['focal_y' => 101], ['caption' => str_repeat('x', 2001)]] as $invalid) {
        $this->patchJson($url, ['name' => 'Invalid.png', 'version' => 2, ...$invalid])->assertUnprocessable();
    }
    $this->patchJson($this->mediaUrl.'/'.$this->pdf->id, ['name' => 'Guide.pdf', 'version' => 1, 'caption' => 'Image caption'])->assertUnprocessable();
    $this->patchJson($url, ['name' => 'A welcoming space.png', 'caption' => null, 'version' => 2])->assertOk()->assertJsonPath('caption', '')->assertJsonPath('focal_y', 100);
    expect($this->release->fresh()->content)->toEqual($before)->and($this->page->fresh()->draft['hero']['focal_y'])->toBe(80)
        ->and(Storage::disk('private')->get($this->image->path))->toBe($this->bytes);
    Notification::assertNothingSent();
});

test('removal and restore version library availability without deleting bytes records or immutable references', function () {
    $before = $this->release->content;
    $payload = ['actor_id' => $this->editor->id, 'version' => 1, 'action' => 'remove'];
    $this->putJson($this->availabilityUrl, $payload)->assertOk()->assertJsonPath('version', 2)->assertJsonPath('can_remove', false)->assertJsonPath('can_restore', true);
    $this->getJson($this->mediaUrl)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonCount(1, 'media')->assertJsonPath('media.0.id', $this->pdf->id)
        ->assertJsonPath('actor_id', $this->editor->id)->assertJsonPath('metadata_enabled', true)->assertJsonPath('recovery_enabled', true);
    $this->getJson($this->mediaUrl.'?state=removed&type=image')->assertOk()->assertJsonCount(1, 'media')->assertJsonPath('media.0.caption', 'Original library caption');
    $this->getJson($this->mediaUrl.'?type=file')->assertOk()->assertJsonCount(1, 'media');
    $this->getJson($this->mediaUrl.'?state=removed&type=file')->assertOk()->assertJsonCount(0, 'media');
    $this->putJson($this->availabilityUrl, $payload)->assertConflict();
    $this->putJson($this->availabilityUrl, [...$payload, 'version' => 2])->assertConflict();
    $this->putJson($this->availabilityUrl, ['actor_id' => $this->reader->id, 'version' => 2, 'action' => 'restore'])->assertConflict();
    $this->putJson($this->availabilityUrl, ['actor_id' => $this->editor->id, 'version' => 2, 'action' => 'restore'])->assertOk()->assertJsonPath('version', 3)->assertJsonPath('removed_at', null)->assertJsonPath('can_remove', true);
    $this->getJson($this->mediaUrl.'?state=removed')->assertOk()->assertJsonCount(0, 'media');
    $this->getJson($this->mediaUrl)->assertOk()->assertJsonCount(2, 'media');
    expect($this->image->fresh()->removed_by)->toBeNull()->and($this->release->fresh()->content)->toEqual($before)
        ->and($this->page->fresh()->draft_version)->toBe(2)->and(Storage::disk('private')->get($this->image->path))->toBe($this->bytes);
    $this->assertDatabaseCount('hub_media', 2);
    Notification::assertNothingSent();
});

test('removed published references stay readable but private hidden archived and newly out of site files still fail closed', function () {
    $this->putJson($this->availabilityUrl, ['actor_id' => $this->editor->id, 'version' => 1, 'action' => 'remove'])->assertOk();
    $this->actingAs($this->reader)->get('/company-hub/media/'.$this->image->id)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    $this->get('/company-hub/media/'.$this->pdf->id)->assertNotFound();
    $this->getJson($this->mediaUrl.'?state=removed')->assertNotFound();
    $outside = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->reader->hrEmployeeProfile()->update(['primary_site_id' => $outside->id]);
    $this->get('/company-hub/media/'.$this->image->id)->assertNotFound();
    $this->reader->hrEmployeeProfile()->update(['primary_site_id' => $this->site->id]);
    $this->get('/company-hub/media/'.$this->image->id)->assertOk();
    $this->publisher->archive($this->editor, $this->page, 2);
    $this->get('/company-hub/media/'.$this->image->id)->assertNotFound();
    $this->actingAs($this->editor)->get('/company-hub/media/'.$this->image->id)->assertOk();
    $this->getJson('/company-hub/manage/'.$this->page->id.'/revisions/'.$this->release->id)->assertOk()->assertJsonPath('content.cover_id', $this->image->id);
    Notification::assertNothingSent();
});

test('removed media cannot be directly reused or cropped while full authorised existing snapshots can copy independently', function () {
    $this->putJson($this->availabilityUrl, ['actor_id' => $this->editor->id, 'version' => 1, 'action' => 'remove'])->assertOk();
    $this->postJson($this->mediaUrl.'/reuse', ['media_id' => $this->image->id, 'version' => 2, 'copy' => true, 'fromSnapshot' => true])->assertConflict();
    $this->postJson($this->mediaUrl.'/'.$this->image->id.'/crop', ['version' => 2, 'ratio' => '1:1', 'x' => 50, 'y' => 50])->assertConflict();
    $copies = app(HubPageCopy::class);
    $context = $copies->read($this->editor, $this->page, 'published');
    $copy = $copies->duplicate($this->editor, $this->page, [...$context, 'media_versions' => (array) $context['media_versions']]);
    $asset = HubMedia::findOrFail($copy->draft['cover_id']);
    expect($asset->removed_at)->toBeNull()->and($asset->caption)->toBe('Original library caption')->and($asset->focal_y)->toBe(70)
        ->and($asset->path)->not->toBe($this->image->path)->and(Storage::disk('private')->get($asset->path))->toBe($this->bytes);
    $update = $this->publisher->create($this->editor, 'news', 'A team update', 'story', ['page_id' => $this->page->id, 'revision_id' => $this->release->id]);
    expect(HubMedia::findOrFail($update->draft['cover_id'])->removed_at)->toBeNull()->and($this->image->fresh()->removed_at)->not->toBeNull();
    $this->actingAs($this->reader)->get('/company-hub/media/'.$asset->id)->assertNotFound();
    $this->get('/company-hub/media/'.$this->image->id)->assertOk();
    Notification::assertNothingSent();
});

test('active independent copies preserve caption and focus while a cropped variant has its own centred coordinates', function () {
    $id = $this->postJson($this->mediaUrl.'/reuse', ['media_id' => $this->image->id, 'version' => 1, 'copy' => true])->assertOk()
        ->assertJsonPath('caption', 'Original library caption')->assertJsonPath('focal_x', 35)->assertJsonPath('focal_y', 70)->json('id');
    $this->patchJson($this->mediaUrl.'/'.$id, ['name' => 'Copy.png', 'version' => 1, 'caption' => 'Independent caption', 'focal_y' => 10])->assertOk();
    $cropped = $this->postJson($this->mediaUrl.'/'.$this->image->id.'/crop', ['version' => 1, 'ratio' => '1:1', 'x' => 50, 'y' => 50])->assertOk()
        ->assertJsonPath('caption', 'Original library caption')->assertJsonPath('focal_x', 50)->assertJsonPath('focal_y', 50)->json('id');
    expect($this->image->fresh()->caption)->toBe('Original library caption')->and($this->image->fresh()->focal_y)->toBe(70)
        ->and(HubMedia::findOrFail($cropped)->path)->not->toBe($this->image->path);
    Storage::disk('private')->delete($this->image->path);
    Storage::disk('private')->assertExists(HubMedia::findOrFail($id)->path);
    Storage::disk('private')->assertExists(HubMedia::findOrFail($cropped)->path);
    Notification::assertNothingSent();
});

test('recovery listing and mutations recheck source ownership current permission and site without exposing foreign removed items or albums', function () {
    $other = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $other->roles()->attach(Role::where('name', 'hr')->sole());
    $outside = ensureCanonicalHrStaffProfile($other);
    $page = $this->publisher->create($other, 'page', 'A private source');
    $page->update(['draft' => [...$page->draft, 'audience' => ['type' => 'sites', 'ids' => [$outside->id]]]]);
    $album = DB::table('hub_media_albums')->insertGetId(['owner_id' => $other->id, 'name' => 'Private album', 'created_at' => now(), 'updated_at' => now()]);
    $foreign = HubMedia::create(['hub_page_id' => $page->id, 'uploaded_by' => $other->id, 'path' => 'foreign.png', 'name' => 'Secret source', 'mime' => 'image/png', 'size' => 1, 'caption' => 'Secret caption', 'removed_at' => now(), 'album_id' => $album, 'version' => 1]);
    $this->getJson('/company-hub/editor/media?state=removed')->assertOk()->assertJsonCount(0, 'media')->assertJsonCount(0, 'albums')->assertJsonMissing(['name' => 'Private album']);
    $this->putJson($this->mediaUrl.'/'.$foreign->id.'/availability', ['actor_id' => $this->editor->id, 'version' => 1, 'action' => 'restore'])->assertNotFound();
    $this->putJson('/company-hub/manage/'.$page->id.'/media/'.$foreign->id.'/availability', ['actor_id' => $this->editor->id, 'version' => 1, 'action' => 'restore'])->assertNotFound();
    $write = Permission::where('key', 'company_hub.write')->sole();
    $this->reader->permissionOverrides()->syncWithoutDetaching([$write->id => ['allowed' => true]]);
    $this->actingAs($this->reader)->putJson($this->availabilityUrl, ['actor_id' => $this->reader->id, 'version' => 1, 'action' => 'remove'])->assertNotFound();
    $this->actingAs($this->editor);
    $this->getJson($this->mediaUrl)->assertOk();
    $this->editor->hrEmployeeProfile()->update(['primary_site_id' => $outside->id]);
    $this->getJson($this->mediaUrl)->assertNotFound();
    $this->putJson($this->availabilityUrl, ['actor_id' => $this->editor->id, 'version' => 1, 'action' => 'remove'])->assertNotFound();
    $this->editor->hrEmployeeProfile()->update(['primary_site_id' => $this->site->id]);
    $denials = Permission::whereIn('key', ['company_hub.write', 'company_hub.edit_all'])->pluck('id')->all();
    $this->editor->permissionOverrides()->syncWithoutDetaching(array_fill_keys($denials, ['allowed' => false]));
    $this->getJson($this->mediaUrl)->assertNotFound();
    $this->putJson($this->availabilityUrl, ['actor_id' => $this->editor->id, 'version' => 1, 'action' => 'remove'])->assertNotFound();
    $this->editor->permissionOverrides()->syncWithoutDetaching(array_fill_keys($denials, ['allowed' => true]));
    $this->editor->hrEmployeeProfile()->update(['end_date' => today()->subDay()]);
    $this->getJson('/company-hub/editor/media?state=removed')->assertForbidden();
    $this->putJson($this->availabilityUrl, ['actor_id' => $this->editor->id, 'version' => 1, 'action' => 'remove'])->assertNotFound();
    $missing = Mockery::mock(User::class);
    $missing->shouldReceive('fresh')->once()->andReturnNull();
    expect(fn () => app(HubMediaLibrary::class)->fileFor($missing, $this->image))->toThrow(NotFoundHttpException::class);
    expect($this->image->fresh()->removed_at)->toBeNull()->and($foreign->fresh()->removed_at)->not->toBeNull();
});

test('missing files cannot be restored and removed metadata remains recoverable without claiming file availability', function () {
    $this->putJson($this->availabilityUrl, ['actor_id' => $this->editor->id, 'version' => 1, 'action' => 'remove'])->assertOk();
    $this->patchJson($this->mediaUrl.'/'.$this->image->id, ['name' => 'Retained details.png', 'version' => 2, 'caption' => 'A retained caption'])->assertOk()->assertJsonPath('version', 3);
    Storage::disk('private')->delete($this->image->path);
    $this->getJson($this->mediaUrl.'?state=removed')->assertOk()->assertJsonPath('media.0.can_restore', false)->assertJsonPath('media.0.caption', 'A retained caption');
    $this->putJson($this->availabilityUrl, ['actor_id' => $this->editor->id, 'version' => 3, 'action' => 'restore'])->assertUnprocessable();
    expect($this->image->fresh()->removed_at)->not->toBeNull()->and($this->image->fresh()->version)->toBe(3);
});

test('audit failure rolls back removal restoration and metadata versions without changing private bytes', function () {
    $fail = false;
    DB::listen(function ($query) use (&$fail): void {
        if ($fail && str_starts_with(strtolower($query->sql), 'insert') && str_contains($query->sql, 'audit_logs')) {
            $fail = false;
            throw new RuntimeException('Isolated media recovery audit failure');
        }
    });
    $library = app(HubMediaLibrary::class);
    $remove = ['actor_id' => $this->editor->id, 'version' => 1, 'action' => 'remove'];
    $fail = true;
    expect(fn () => $library->availability($this->editor, $this->page, $this->image, $remove))->toThrow(RuntimeException::class);
    expect($this->image->fresh()->removed_at)->toBeNull()->and($this->image->fresh()->version)->toBe(1);
    $library->availability($this->editor, $this->page, $this->image, $remove);
    $fail = true;
    expect(fn () => $library->availability($this->editor, $this->page, $this->image, ['actor_id' => $this->editor->id, 'version' => 2, 'action' => 'restore']))->toThrow(RuntimeException::class);
    $fail = true;
    expect(fn () => $library->update($this->editor, $this->page, $this->image, ['name' => 'Lost details.png', 'version' => 2, 'caption' => 'Lost caption', 'focal_y' => 0]))->toThrow(RuntimeException::class);
    expect($this->image->fresh()->removed_at)->not->toBeNull()->and($this->image->fresh()->version)->toBe(2)
        ->and($this->image->fresh()->caption)->toBe('Original library caption')->and(Storage::disk('private')->get($this->image->path))->toBe($this->bytes);
    Notification::assertNothingSent();
});

test('pending optional schema keeps legacy media readable and editable while clearly refusing recovery or new metadata', function () {
    $library = new class(app(HubAccess::class)) extends HubMediaLibrary
    {
        public function ready(): bool
        {
            return false;
        }
    };
    app()->instance(HubMediaLibrary::class, $library);
    $this->getJson($this->mediaUrl)->assertOk()->assertJsonPath('metadata_enabled', false)->assertJsonPath('recovery_enabled', false)->assertJsonPath('media.1.focal_y', 50);
    $this->getJson($this->mediaUrl.'?state=removed')->assertOk()->assertJsonCount(0, 'media');
    $this->putJson($this->availabilityUrl, ['actor_id' => $this->editor->id, 'version' => 1, 'action' => 'remove'])->assertUnprocessable()->assertJsonValidationErrors('action');
    $this->patchJson($this->mediaUrl.'/'.$this->image->id, ['name' => 'Not yet.png', 'version' => 1, 'caption' => 'Unstored'])->assertUnprocessable()->assertJsonValidationErrors('caption');
    $this->patchJson($this->mediaUrl.'/'.$this->image->id, ['name' => 'Legacy details.png', 'version' => 1])->assertOk()->assertJsonPath('version', 2);
    $this->actingAs($this->reader)->get('/company-hub/media/'.$this->image->id)->assertOk();
    expect($this->image->fresh()->removed_at)->toBeNull();
});
