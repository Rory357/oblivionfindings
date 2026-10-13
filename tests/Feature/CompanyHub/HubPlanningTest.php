<?php

use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\CompanyHub\Services\HubPublishing;
use App\Domain\Hr\Models\HrAnnouncement;
use App\Domain\Hr\Models\HrCalendarEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\CompanyHubPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    $this->editor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->editor->roles()->attach(Role::where('name', 'hr')->firstOrFail());
    $this->site = ensureCanonicalHrStaffProfile($this->editor);
    $this->writer = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->writer->roles()->attach(Role::where('name', 'support_worker')->firstOrFail());
    ensureCanonicalHrStaffProfile($this->writer, $this->site);
    $this->writer->permissionOverrides()->attach(Permission::where('key', 'company_hub.write')->value('id'), ['allowed' => true]);
    $this->writer = $this->writer->fresh();
    $this->planData = ['title' => 'A planned learning update', 'target_date' => now()->toDateString(), 'owner_id' => $this->writer->id, 'template' => 'guide', 'brief' => 'A private editorial brief.', 'audience' => ['type' => 'all', 'ids' => []]];
});

test('editorial plans are independent private assigned work with version conflicts and one linked draft', function () {
    $this->actingAs($this->editor);
    $plan = $this->postJson('/company-hub/editor/plans', $this->planData)->assertOk()->assertJsonPath('version', 1)->json();
    expect(HubPage::count())->toBe(0);
    $url = '/company-hub/editor/plans/'.$plan['id'];
    $updated = $this->putJson($url, [...$this->planData, 'version' => 1, 'title' => 'Our learning guide'])->assertOk()->assertJsonPath('version', 2)->json();
    $this->putJson($url, [...$this->planData, 'version' => 1])->assertConflict();
    $this->postJson($url.'/start', ['version' => 1])->assertConflict();
    $pageId = $this->postJson($url.'/start', ['version' => 2])->assertOk()->json('page_id');
    $this->postJson($url.'/start', ['version' => 2])->assertOk()->assertJsonPath('page_id', $pageId);
    $page = HubPage::findOrFail($pageId);
    expect(HubPage::count())->toBe(1)->and($page->owner_id)->toBe($this->writer->id)->and($page->kind)->toBe('knowledge')
        ->and($page->draft['planned_for'])->toBe($this->planData['target_date'])->and($page->draft['title'])->toBe('Our learning guide')
        ->and(json_encode($page->draft))->not->toContain('private editorial brief')->and($page->revisions()->count())->toBe(0);
    $this->putJson($url, [...$updated, 'version' => 3, 'owner_id' => $this->editor->id])->assertUnprocessable();
    $this->actingAs($this->writer)->getJson('/company-hub/editor/planning?month='.now()->format('Y-m'))->assertOk()->assertJsonCount(1, 'plans');
    $this->postJson($url.'/start', ['version' => 3])->assertOk()->assertJsonPath('page_id', $pageId);
});

test('planning refuses other authors private plans and out of scope audiences or assignments', function () {
    $this->actingAs($this->editor);
    $plan = $this->postJson('/company-hub/editor/plans', [...$this->planData, 'owner_id' => $this->editor->id])->assertOk()->json();
    $outside = Site::factory()->create(['is_active' => true]);
    $this->postJson('/company-hub/editor/plans', [...$this->planData, 'audience' => ['type' => 'sites', 'ids' => [$outside->id]]])->assertUnprocessable();
    $this->actingAs($this->writer)->getJson('/company-hub/editor/planning?month='.now()->format('Y-m'))->assertOk()->assertJsonCount(0, 'plans');
    $this->putJson('/company-hub/editor/plans/'.$plan['id'], [...$this->planData, 'version' => 1])->assertNotFound();
    $this->postJson('/company-hub/editor/plans/'.$plan['id'].'/start', ['version' => 1])->assertNotFound();
    $this->postJson('/company-hub/editor/plans', [...$this->planData, 'owner_id' => $this->editor->id])->assertForbidden();
    $this->writer->permissionOverrides()->updateExistingPivot(Permission::where('key', 'company_hub.write')->value('id'), ['allowed' => false]);
    $this->actingAs($this->writer->fresh())->getJson('/company-hub/editor/planning?month='.now()->format('Y-m'))->assertForbidden();
    $this->getJson('/company-hub/editor/insights?days=7')->assertForbidden();
});

test('starting assigned plans rechecks owner permissions and linked page audiences', function () {
    $this->actingAs($this->editor);
    $plan = $this->postJson('/company-hub/editor/plans', $this->planData)->assertOk()->json();
    $this->writer->permissionOverrides()->updateExistingPivot(Permission::where('key', 'company_hub.write')->value('id'), ['allowed' => false]);
    $url = '/company-hub/editor/plans/'.$plan['id'];
    $this->postJson($url.'/start', ['version' => 1])->assertUnprocessable();
    expect(HubPage::count())->toBe(0);
    $this->putJson($url, [...$this->planData, 'owner_id' => $this->editor->id, 'version' => 1])->assertOk();
    $pageId = $this->postJson($url.'/start', ['version' => 2])->assertOk()->json('page_id');
    $page = HubPage::find($pageId);
    $page->update(['draft' => [...$page->draft, 'audience' => ['type' => 'sites', 'ids' => [Site::factory()->create(['is_active' => true])->id]]]]);
    $this->getJson('/company-hub/editor/planning?month='.now()->format('Y-m'))->assertOk()->assertJsonCount(0, 'plans');
    $this->postJson($url.'/start', ['version' => 3])->assertNotFound();
});

test('editorial month combines canonical visible event occurrences pages plans and active announcements', function () {
    $this->editor->permissionOverrides()->attach(Permission::where('key', 'hr.announcements.manage')->value('id'), ['allowed' => false]);
    $this->editor = $this->editor->fresh();
    $this->travelTo(now()->startOfMonth()->addDays(12)->setTime(10, 0));
    $this->actingAs($this->editor);
    $this->postJson('/company-hub/editor/plans', [...$this->planData, 'target_date' => now()->toDateString()])->assertOk();
    $publisher = app(HubPublishing::class);
    $page = $publisher->create($this->editor, 'news', 'Scheduled story');
    $publisher->publish($this->editor, $page, 1, now()->addDay()->toIso8601String(), null);
    HrCalendarEvent::create(['title' => 'Visible learning session', 'event_type' => 'company', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(), 'rrule' => 'FREQ=WEEKLY;COUNT=2', 'created_by' => $this->editor->id]);
    HrCalendarEvent::create(['title' => 'Other site event', 'event_type' => 'company', 'site_id' => Site::factory()->create(['is_active' => true])->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(), 'created_by' => $this->editor->id]);
    HrAnnouncement::create(['title' => 'Current announcement', 'content' => 'Useful update', 'status' => 'published', 'published_at' => now(), 'target_audience' => 'all', 'created_by' => $this->editor->id]);
    HrAnnouncement::create(['title' => 'Expired announcement', 'content' => 'Past', 'status' => 'published', 'published_at' => now()->subDay(), 'expires_at' => now()->subHour(), 'target_audience' => 'all', 'created_by' => $this->editor->id]);
    $items = collect($this->getJson('/company-hub/editor/planning?month='.now()->format('Y-m'))->assertOk()->json('items'));
    expect($items->where('type', 'plan'))->toHaveCount(1)->and($items->where('type', 'page'))->toHaveCount(1)
        ->and($items->where('type', 'event'))->toHaveCount(2)->and($items->where('type', 'notice'))->toHaveCount(1)
        ->and($items->pluck('title')->all())->not->toContain('Other site event', 'Expired announcement');
    $this->getJson('/company-hub/editor/planning?month=2026-99')->assertUnprocessable();
});

test('announcement managers see dated lifecycle milestones with canonical detail links and local month boundaries', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-13 10:00:00', 'Pacific/Auckland')->utc());
    $base = ['content' => 'Editorial notice', 'target_audience' => 'all', 'created_by' => $this->editor->id];
    $scheduled = HrAnnouncement::create([...$base, 'title' => 'Next month launch', 'status' => 'scheduled', 'published_at' => '2026-10-31 11:30:00', 'expires_at' => '2026-11-05 00:00:00']);
    $expired = HrAnnouncement::create([...$base, 'title' => 'Past notice', 'status' => 'published', 'published_at' => '2026-10-01 00:00:00', 'expires_at' => '2026-10-05 00:00:00', 'ack_deadline' => '2026-10-03 00:00:00']);
    $draft = HrAnnouncement::create([...$base, 'title' => 'Undated private draft', 'status' => 'draft']);
    $archived = HrAnnouncement::create([...$base, 'title' => 'Archived notice', 'status' => 'archived', 'published_at' => '2026-10-02 00:00:00']);
    $deleted = HrAnnouncement::create([...$base, 'title' => 'Deleted notice', 'status' => 'draft']);
    $deleted->delete();
    $this->actingAs($this->editor);
    $items = collect($this->getJson('/company-hub/editor/planning?month=2026-10')->assertOk()->json('items'))->where('type', 'notice');
    expect($items->pluck('title')->all())->not->toContain($scheduled->title, $deleted->title)
        ->and($items->firstWhere('key', 'notice:'.$expired->id.':publication')['status'])->toBe('Expired announcement')
        ->and($items->firstWhere('key', 'notice:'.$expired->id.':expiry')['date'])->toBe('2026-10-05')
        ->and($items->firstWhere('key', 'notice:'.$expired->id.':acknowledgement')['status'])->toBe('Read confirmation due')
        ->and($items->firstWhere('key', 'notice:'.$draft->id.':publication')['status'])->toBe('Draft created')
        ->and($items->firstWhere('key', 'notice:'.$archived->id.':publication')['url'])->toBe('/hr/announcements/'.$archived->id);
    $next = collect($this->getJson('/company-hub/editor/planning?month=2026-11')->assertOk()->json('items'))->where('type', 'notice');
    expect($next->firstWhere('key', 'notice:'.$scheduled->id.':publication')['date'])->toBe('2026-11-01')
        ->and($next->firstWhere('key', 'notice:'.$scheduled->id.':publication')['status'])->toBe('Scheduled announcement');
    foreach ([$scheduled, $expired, $draft, $archived] as $notice) {
        $this->get('/hr/announcements/'.$notice->id)->assertOk();
    }
});

test('hub authors cannot discover private announcement lifecycle records and departed managers lose access', function () {
    $this->writer->permissionOverrides()->attach(Permission::where('key', 'hr.announcements.view')->value('id'), ['allowed' => true]);
    $this->writer = $this->writer->fresh();
    $this->travelTo(now()->startOfMonth()->addDays(12));
    $base = ['content' => 'Private editorial notice', 'target_audience' => 'all', 'created_by' => $this->editor->id];
    $private = collect([
        ['title' => 'Draft', 'status' => 'draft', 'published_at' => null],
        ['title' => 'Scheduled', 'status' => 'scheduled', 'published_at' => now()->addDay()],
        ['title' => 'Expired', 'status' => 'published', 'published_at' => now()->subDays(2), 'expires_at' => now()->subDay()],
        ['title' => 'Archived', 'status' => 'archived', 'published_at' => now()->subDay()],
        ['title' => 'Other site', 'status' => 'published', 'published_at' => now(), 'target_audience' => 'site', 'target_value' => (string) Site::factory()->create(['is_active' => true])->id],
    ])->map(fn ($fields) => HrAnnouncement::create([...$base, ...$fields]));
    $this->actingAs($this->writer)->getJson('/company-hub/editor/planning?month='.now()->format('Y-m'))->assertOk()->assertJsonCount(0, 'items');
    foreach ($private as $notice) {
        $this->get('/hr/announcements/'.$notice->id)->assertNotFound();
    }
    $this->editor->hrEmployeeProfile()->update(['is_active' => false]);
    $this->actingAs($this->editor->fresh())->getJson('/company-hub/editor/planning?month='.now()->format('Y-m'))->assertForbidden();
});

test('period insights use recorded activity scope without exposing reader identities', function () {
    $page = app(HubPublishing::class)->create($this->editor, 'news', 'Accessible page');
    $private = app(HubPublishing::class)->create($this->editor, 'news', 'Private page');
    $private->update(['draft' => [...$private->draft, 'audience' => ['type' => 'sites', 'ids' => [Site::factory()->create(['is_active' => true])->id]]]]);
    foreach ([[$page->id, $this->editor->id, 1], [$page->id, $this->writer->id, 14], [$private->id, $this->writer->id, 1]] as [$id, $userId, $days]) {
        DB::table('hub_reads')->insert(['hub_page_id' => $id, 'user_id' => $userId, 'first_read_at' => now()->subDays($days), 'last_read_at' => now()->subDays($days)]);
    }
    DB::table('hub_bookmarks')->insert(['subject_type' => 'page', 'subject_id' => $page->id, 'user_id' => $this->writer->id, 'created_at' => now()->subDays(10), 'updated_at' => now()]);
    foreach ([['comment', 1, null], ['reaction', 14, null], ['comment', 1, now()]] as [$kind, $days, $hidden]) {
        DB::table('hub_interactions')->insert(['hub_page_id' => $page->id, 'user_id' => $this->writer->id, 'kind' => $kind, 'body' => 'Private interaction content', 'hidden_at' => $hidden, 'created_at' => now()->subDays($days), 'updated_at' => now()]);
    }
    $this->actingAs($this->editor)->getJson('/company-hub/editor/insights?days=7')->assertOk()->assertJsonPath('readers', 1)->assertJsonCount(1, 'pages')->assertJsonPath('pages.0.comments', 1)->assertJsonPath('pages.0.reactions', 0)->assertJsonPath('pages.0.saves', 0)->assertDontSee('Private interaction content')->assertDontSee('user_id');
    $this->getJson('/company-hub/editor/insights?days=28')->assertOk()->assertJsonPath('readers', 2)->assertJsonPath('pages.0.readers', 2)->assertJsonPath('pages.0.reactions', 1)->assertJsonPath('pages.0.saves', 1);
    $this->getJson('/company-hub/editor/insights?days=3')->assertUnprocessable();
});
