<?php

use App\Domain\CompanyHub\Models\HubMedia;
use App\Domain\CompanyHub\Services\HubFeed;
use App\Domain\CompanyHub\Services\HubNotices;
use App\Domain\CompanyHub\Services\HubPublishing;
use App\Domain\Hr\Models\HrDepartment;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\CompanyHubPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Notification::fake();
    Storage::fake('private');
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    $this->editor = User::factory()->create(['name' => 'Mara Metadata author', 'role' => 'hr', 'approved_at' => now()]);
    $this->editor->roles()->attach(Role::where('name', 'hr')->sole());
    $this->team = HrDepartment::create(['name' => 'Learning metadata team', 'is_active' => true]);
    $this->site = ensureCanonicalHrStaffProfile($this->editor, overrides: ['department_id' => $this->team->id, 'bio' => 'Private metadata HR biography']);
    $this->reader = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->reader->roles()->attach(Role::where('name', 'support_worker')->sole());
    ensureCanonicalHrStaffProfile($this->reader, $this->site, ['department_id' => $this->team->id]);
    $this->publisher = app(HubPublishing::class);
});

test('Process knowledge projects current public metadata search review and exactly visible conversation counts', function () {
    $owner = User::factory()->create(['name' => 'Rowan Metadata owner', 'role' => 'hr', 'approved_at' => now()]);
    $owner->roles()->attach(Role::where('name', 'hr')->sole());
    ensureCanonicalHrStaffProfile($owner, $this->site, ['department_id' => $this->team->id]);
    $page = $this->publisher->create($this->editor, 'page', 'A published process', 'blank');
    $media = [];
    foreach (['image' => 'image/jpeg', 'document' => 'application/pdf'] as $name => $mime) {
        Storage::disk('private')->put('company-hub/metadata-'.$name, $name);
        $media[$name] = HubMedia::create(['hub_page_id' => $page->id, 'uploaded_by' => $this->editor->id, 'path' => 'company-hub/metadata-'.$name, 'name' => $name, 'mime' => $mime, 'size' => 8]);
    }
    $draft = [...$page->draft, 'resource_type' => 'Process', 'team_ids' => [$this->team->id], 'topics' => ['Learning'], 'collection' => 'First week',
        'review_on' => now(config('app.worker_timezone', 'Pacific/Auckland'))->subDay()->toDateString(), 'sections' => [['id' => 'process', 'columns' => 1, 'blocks' => [
            ['id' => 'intro', 'type' => 'text', 'title' => 'Instructions', 'body' => str_repeat('instruction ', 180)],
            ['id' => 'caption', 'type' => 'image', 'title' => 'A process photo', 'body' => 'Caption.', 'media_id' => $media['image']->id],
            ['id' => 'hidden', 'type' => 'text', 'title' => 'Unpublished detail', 'body' => str_repeat('HiddenMetadataNeedle ', 900), 'hidden' => true],
            ['id' => 'download', 'type' => 'file', 'title' => 'Process download', 'media_id' => $media['document']->id],
        ]]]];
    $page = $this->publisher->save($this->editor, $page, $draft, 1);
    $release = $this->publisher->publish($this->editor, $page, 2, null, null);
    $snapshot = $release->fresh()->content;
    $this->publisher->changeOwner($this->editor, $page, $owner, 2);
    $comment = fn ($body, $parent = null, $extra = []) => DB::table('hub_interactions')->insertGetId([
        'hub_page_id' => $page->id, 'user_id' => $this->reader->id, 'kind' => 'comment', 'body' => $body, 'parent_id' => $parent,
        'created_at' => now(), 'updated_at' => now(), ...$extra,
    ]);
    $parent = $comment('Visible discussion');
    $comment('Visible reply', $parent);
    $hidden = $comment('Hidden parent', extra: ['moderator_hidden_at' => now()]);
    $comment('Unmarked child of hidden parent', $hidden);
    $comment('Author removed', extra: ['author_removed_at' => now()]);
    DB::table('hub_bookmarks')->insert(['user_id' => $this->editor->id, 'subject_type' => 'page', 'subject_id' => $page->id, 'created_at' => now(), 'updated_at' => now()]);
    $this->actingAs($this->reader)->get('/company-hub/read/'.$page->slug)->assertOk()->assertInertia(fn (Assert $p) => $p
        ->where('knowledge.review_overdue', true)->has('participation.comments', 2)
        ->where('pages.0.owner', $owner->name)->where('pages.0.author', $this->editor->name)->where('pages.0.team_names', [$this->team->name])
        ->where('pages.0.read_minutes', 2)->where('pages.0.comment_count', 2)->where('pages.0.bookmarked', false)->where('pages.0.review_overdue', true)
        ->where('resourceItems.0.author', $this->editor->name)->where('resourceItems.0.team_names', [$this->team->name]));
    foreach ([$owner->name, $this->editor->name, 'Learning metadata team', 'Learning', 'First week', 'Process'] as $term) {
        $data = app(HubFeed::class)->forUser($this->reader, $term);
        expect(array_column($data['pages'], 'id'))->toContain($page->id)->and($data['resourceItems'])->toHaveCount(1);
        $result = $this->getJson('/company-hub/search?query='.rawurlencode($term))->assertOk()->json('results');
        expect(collect($result)->pluck('url')->all())->toContain('/company-hub/read/'.$page->slug);
    }
    $this->getJson('/company-hub/search?query=HiddenMetadataNeedle')->assertJsonCount(0, 'results');
    $this->post('/company-hub/bookmarks', ['subject_type' => 'page', 'subject_id' => $page->id, 'saved' => true])->assertRedirect();
    expect(collect(app(HubFeed::class)->forUser($this->reader)['pages'])->firstWhere('id', $page->id)['bookmarked'])->toBeTrue();
    $owner->hrEmployeeProfile()->update(['is_active' => false]);
    $summary = collect(app(HubFeed::class)->forUser($this->reader)['pages'])->firstWhere('id', $page->id);
    expect($summary['owner'])->toBe('Company team')->and($summary['author'])->toBe($this->editor->name)
        ->and(json_encode($summary))->not->toContain('Private metadata HR biography')
        ->and($release->fresh()->content)->toBe($snapshot);
    $page = $this->publisher->save($this->editor, $page, [...$page->fresh()->draft, 'comments_enabled' => false, 'review_on' => now(config('app.worker_timezone', 'Pacific/Auckland'))->toDateString()], 3);
    $this->publisher->publish($this->editor, $page, 4, null, null);
    $this->get('/company-hub/read/'.$page->slug)->assertOk()->assertInertia(fn (Assert $p) => $p
        ->where('knowledge.review_overdue', false)->where('pages.0.comment_count', 0)->where('pages.0.comments_enabled', false)->where('participation.comments', []));
    expect($release->fresh()->content)->toBe($snapshot);
    $this->actingAs($this->editor)->putJson('/company-hub/manage/'.$page->id, ['version' => 4, 'content' => [...$page->fresh()->draft, 'show_in_news' => false]])->assertUnprocessable();
});

test('news discovery off preserves direct saved and curated page access while featured media search activity and notices respect eligibility', function () {
    $source = $this->publisher->create($this->editor, 'news', 'An explicitly linked story');
    $this->publisher->publish($this->editor, $source, 1, null, null);
    $fallback = $this->publisher->create($this->editor, 'news', 'Eligible fallback story');
    $this->publisher->publish($this->editor, $fallback, 1, null, now()->addHour()->toISOString());
    $page = $this->publisher->create($this->editor, 'page', 'Feature placement', 'blank');
    Storage::disk('private')->put('company-hub/metadata-feature.jpg', 'photo');
    $image = HubMedia::create(['hub_page_id' => $page->id, 'uploaded_by' => $this->editor->id, 'path' => 'company-hub/metadata-feature.jpg', 'name' => 'photo.jpg', 'mime' => 'image/jpeg', 'size' => 5]);
    $page = $this->publisher->save($this->editor, $page, [...$page->draft, 'sections' => [['id' => 'features', 'columns' => 1, 'blocks' => [
        ['id' => 'feature', 'type' => 'featured', 'title' => 'Retained discovery heading', 'body' => 'RetainedDiscoveryNeedle', 'story_id' => $source->id, 'media_id' => $image->id],
        ['id' => 'news', 'type' => 'news', 'title' => 'Current company news'],
    ]]]], 1);
    $placementRelease = $this->publisher->publish($this->editor, $page, 2, null, null);
    $placementSnapshot = $placementRelease->fresh()->content;
    $source = $this->publisher->save($this->editor, $source, [...$source->draft, 'show_in_news' => false], 1);
    $this->publisher->publish($this->editor, $source, 2, null, null);
    $this->actingAs($this->reader)->get('/company-hub/read/'.$source->slug)->assertOk();
    $this->post('/company-hub/bookmarks', ['subject_type' => 'page', 'subject_id' => $source->id, 'saved' => true])->assertRedirect();
    $this->get('/company-hub/read/'.$page->slug)->assertOk()->assertInertia(fn (Assert $p) => $p
        ->where('blockData.feature.featured.lead.id', $fallback->id)->where('selectedPage.content.sections.0.blocks.0.media_id', null)
        ->where('blockData.news.news.0.id', $fallback->id));
    $this->get('/company-hub/media/'.$image->id)->assertNotFound();
    $this->getJson('/company-hub/search?query=RetainedDiscoveryNeedle')->assertJsonCount(0, 'results');
    $this->getJson('/company-hub/search?query=explicitly%20linked')->assertJsonCount(1, 'results');
    $data = app(HubFeed::class)->forUser($this->reader);
    expect(collect($data['pages'])->firstWhere('id', $source->id)['show_in_news'])->toBeFalse()
        ->and(collect($data['pages'])->firstWhere('id', $source->id)['bookmarked'])->toBeTrue()
        ->and(collect($data['updates'])->pluck('page_id')->all())->not->toContain($source->id)
        ->and($data['heroSummary'][0]['value'])->toBe(1);
    $campaign = $this->publisher->create($this->editor, 'campaign', 'An explicit collection', 'campaign');
    $campaign = $this->publisher->save($this->editor, $campaign, [...$campaign->draft, 'campaign' => ['steps' => [], 'story_ids' => [$source->id]]], 1);
    $this->publisher->publish($this->editor, $campaign, 2, null, null);
    $this->get('/company-hub/read/'.$campaign->slug)->assertOk()->assertInertia(fn (Assert $p) => $p->where('campaign.stories.0.id', $source->id));
    $this->travel(2)->hours();
    expect(app(HubNotices::class)->deliver())->toBe(0)->and($placementRelease->fresh()->content)->toBe($placementSnapshot);
    Notification::assertNothingSent();
});
