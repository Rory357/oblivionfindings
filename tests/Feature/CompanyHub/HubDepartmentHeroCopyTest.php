<?php

use App\Domain\CompanyHub\Models\HubMedia;
use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\CompanyHub\Services\HubMediaLibrary;
use App\Domain\CompanyHub\Services\HubPublishing;
use App\Domain\Hr\Models\HrDepartment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CompanyHubPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Notification::fake();
    Storage::fake('private');
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    $this->editor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->editor->roles()->attach(Role::where('name', 'hr')->sole());
    $this->team = HrDepartment::create(['name' => 'Shared learning team', 'is_active' => true]);
    $this->site = ensureCanonicalHrStaffProfile($this->editor, overrides: ['department_id' => $this->team->id]);
    $this->reader = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->reader->roles()->attach(Role::where('name', 'support_worker')->sole());
    ensureCanonicalHrStaffProfile($this->reader, $this->site, ['department_id' => $this->team->id]);
    $this->publisher = app(HubPublishing::class);
    $this->space = $this->publisher->create($this->editor, 'department', 'Our learning space', 'team');
    Storage::disk('private')->put('company-hub/published-cover.png', 'published image');
    $this->cover = HubMedia::create(['hub_page_id' => $this->space->id, 'uploaded_by' => $this->editor->id, 'path' => 'company-hub/published-cover.png',
        'name' => 'Team photograph.png', 'mime' => 'image/png', 'size' => 15, 'alt' => 'Learning together', 'crop' => ['ratio' => '16:9', 'x' => 50, 'y' => 50], 'version' => 1]);
    $this->space = $this->publisher->save($this->editor, $this->space, [...$this->space->draft, 'team_ids' => [$this->team->id], 'cover_id' => $this->cover->id,
        'hero' => [...$this->space->draft['hero'], 'style' => 'image'], 'audience' => ['type' => 'sites', 'ids' => [$this->site->id]]], 1);
    $this->release = $this->publisher->publish($this->editor, $this->space, 2, null, null);
    $this->source = ['page_id' => $this->space->id, 'revision_id' => $this->release->id];
});

test('new department updates copy the published cover independently into the author owned private draft', function () {
    Storage::disk('private')->put('company-hub/unpublished-cover.png', 'private draft image');
    $draftCover = HubMedia::create(['hub_page_id' => $this->space->id, 'uploaded_by' => $this->editor->id, 'path' => 'company-hub/unpublished-cover.png', 'name' => 'Draft.png', 'mime' => 'image/png', 'size' => 19]);
    $this->space = $this->publisher->save($this->editor, $this->space, [...$this->space->draft, 'cover_id' => $draftCover->id], 2);
    $update = $this->publisher->create($this->editor, 'news', 'A shared update', 'story', $this->source);
    $copy = HubMedia::findOrFail($update->draft['cover_id']);
    expect($update->owner_id)->toBe($this->editor->id)->and($update->draft['team_ids'])->toBe([$this->team->id])
        ->and($update->draft['audience'])->toEqual(['type' => 'sites', 'ids' => [$this->site->id]])->and($update->draft['hero']['style'])->toBe('image')
        ->and($update->revisions()->count())->toBe(0)->and($copy->id)->not->toBe($this->cover->id)->and($copy->hub_page_id)->toBe($update->id)
        ->and($copy->uploaded_by)->toBe($this->editor->id)->and($copy->source_media_id)->toBe($this->cover->id)
        ->and($copy->alt)->toBe('Learning together')->and($copy->crop)->toEqual($this->cover->crop)
        ->and($copy->path)->not->toBe($this->cover->path)->and(Storage::disk('private')->get($copy->path))->toBe('published image');
    app(HubMediaLibrary::class)->update($this->editor, $update, $copy, ['version' => 1, 'name' => 'Update-specific image.png', 'alt' => 'Our new update']);
    expect($this->cover->fresh()->name)->toBe('Team photograph.png')->and($this->cover->fresh()->alt)->toBe('Learning together');
    Storage::disk('private')->delete($this->cover->path);
    Storage::disk('private')->assertExists($copy->path);
    $this->actingAs($this->reader)->get('/company-hub/media/'.$copy->id)->assertNotFound();
    $this->get('/company-hub/read/'.$update->slug)->assertNotFound();
    Notification::assertNothingSent();
});

test('a source reader with author permission can create an update without copying protected library media', function () {
    Role::where('name', 'support_worker')->sole()->permissions()->syncWithoutDetaching(Permission::where('key', 'company_hub.write')->pluck('id'));
    $actor = $this->reader->fresh();
    $update = $this->publisher->create($actor, 'news', 'My team update', 'story', $this->source);
    expect($update->owner_id)->toBe($actor->id)->and($update->draft['cover_id'])->toBeNull()->and($update->draft['hero']['style'])->toBe('brand')
        ->and($update->draft['team_ids'])->toBe([$this->team->id])->and($update->revisions()->count())->toBe(0);
    $this->assertDatabaseCount('hub_media', 1);
    $this->actingAs($actor)->postJson('/company-hub/manage/'.$update->id.'/media/reuse', ['media_id' => $this->cover->id])->assertNotFound();
    Notification::assertNothingSent();
});

test('media reuse keeps its same-page version duplicate and destination permission behaviour', function () {
    $target = $this->publisher->create($this->editor, 'news', 'Destination');
    $url = '/company-hub/manage/'.$target->id.'/media/reuse';
    $this->actingAs($this->reader)->postJson($url, ['media_id' => $this->cover->id])->assertNotFound();
    $this->actingAs($this->editor)->postJson($url, ['media_id' => $this->cover->id, 'version' => 2])->assertConflict();
    $id = $this->postJson($url, ['media_id' => $this->cover->id, 'version' => 1])->assertOk()->json('id');
    $this->postJson($url, ['media_id' => $id, 'version' => 1])->assertOk()->assertJsonPath('id', $id);
    $duplicateId = $this->postJson($url, ['media_id' => $id, 'version' => 1, 'copy' => true])->assertOk()->json('id');
    $duplicate = HubMedia::findOrFail($duplicateId);
    expect($duplicateId)->not->toBe($id)->and($duplicate->name)->toBe('Team photograph — copy.png')
        ->and($duplicate->source_media_id)->toBe($id)->and(Storage::disk('private')->get($duplicate->path))->toBe('published image');
    $this->assertDatabaseCount('hub_media', 3);
});

test('failed department creation and media insertion roll back copied private files and database records', function () {
    $sourceFiles = Storage::disk('private')->allFiles();
    $failTable = 'audit_logs';
    DB::listen(function ($query) use (&$failTable): void {
        if ($failTable !== null && str_starts_with(strtolower($query->sql), 'insert') && str_contains($query->sql, $failTable)) {
            $failTable = null;
            throw new RuntimeException('Isolated copied-cover write failure');
        }
    });
    expect(fn () => $this->publisher->create($this->editor, 'news', 'Update that fails', 'story', $this->source))
        ->toThrow(RuntimeException::class, 'Isolated copied-cover write failure');
    expect(Storage::disk('private')->allFiles())->toBe($sourceFiles);
    $this->assertDatabaseCount('hub_pages', 1);
    $this->assertDatabaseCount('hub_media', 1);
    $target = $this->publisher->create($this->editor, 'news', 'Destination that survives');
    $failTable = 'hub_media';
    expect(fn () => app(HubMediaLibrary::class)->reuse($this->editor, $target, $this->cover))
        ->toThrow(RuntimeException::class, 'Isolated copied-cover write failure');
    expect(Storage::disk('private')->allFiles())->toBe($sourceFiles);
    $this->assertDatabaseCount('hub_media', 1);
    expect(HubPage::whereKey($target->id)->exists())->toBeTrue();
    Notification::assertNothingSent();
});
