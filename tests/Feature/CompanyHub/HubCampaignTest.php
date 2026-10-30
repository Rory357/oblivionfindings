<?php

use App\Domain\CompanyHub\Services\HubCampaign;
use App\Domain\CompanyHub\Services\HubFeed;
use App\Domain\CompanyHub\Services\HubParticipation;
use App\Domain\CompanyHub\Services\HubPublishing;
use App\Domain\Hr\Models\HrCalendarEvent;
use App\Domain\Hr\Models\HrDepartment;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CompanyHubPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Notification::fake();
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    $this->editor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->editor->roles()->attach(Role::where('name', 'hr')->sole());
    $this->team = HrDepartment::create(['name' => 'Initiative team', 'is_active' => true]);
    $this->site = ensureCanonicalHrStaffProfile($this->editor, overrides: ['department_id' => $this->team->id, 'work_email' => 'initiatives@company.test', 'bio' => 'Private HR note']);
    $this->reader = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->reader->roles()->attach(Role::where('name', 'support_worker')->sole());
    ensureCanonicalHrStaffProfile($this->reader, $this->site, ['department_id' => $this->team->id]);
    $this->publisher = app(HubPublishing::class);
    $this->campaign = $this->publisher->create($this->editor, 'campaign', 'Working together', 'campaign');
    // The campaign template starts with an empty callout, which cannot be published.
    $draft = $this->campaign->draft;
    $draft['sections'][0]['blocks'][0]['body'] = 'Why we are working together.';
    $this->campaign->update(['draft' => $draft]);
    $this->settings = ['ends_on' => today()->addMonth()->toDateString(), 'steps' => [['id' => 'join', 'title' => 'Join a conversation']],
        'milestones' => [['id' => 'guide', 'title' => 'Support guide shared', 'status' => 'done'], ['id' => 'coffee', 'title' => 'Coffee and conversations', 'status' => 'active'], ['id' => 'reflect', 'title' => 'Reflect and share', 'status' => 'next']],
        'story_ids' => [], 'resource_ids' => [], 'event_keys' => []];
    $this->publishItem = function (string $kind, string $title, array $extra = []) {
        $item = $this->publisher->create($this->editor, $kind, $title, 'blank');
        $item = $this->publisher->save($this->editor, $item, [...$item->draft, ...$extra], 1);
        $this->publisher->publish($this->editor, $item, 2, null, null);

        return $item;
    };
    $this->publishCampaign = function (array $settings = []) {
        $this->campaign = $this->publisher->save($this->editor, $this->campaign, [...$this->campaign->draft, 'campaign' => [...$this->settings, ...$settings]], 1);
        $this->release = $this->publisher->publish($this->editor, $this->campaign, 2, null, null);

        return '/company-hub/read/'.$this->campaign->slug;
    };
});

test('shared milestones use the immutable release and remain separate from personal campaign activities', function () {
    $url = ($this->publishCampaign)();
    $activity = $this->settings['steps'][0];
    $payload = ['kind' => 'campaign', 'step_id' => 'join', 'step_key' => HubParticipation::stepKey($activity), 'active' => true];
    $this->actingAs($this->reader)->post('/company-hub/pages/'.$this->campaign->id.'/interactions', $payload)->assertRedirect();
    $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('view', 'campaigns')
        ->where('campaign.total', 3)->where('campaign.done', 1)->where('campaign.milestones', $this->settings['milestones'])
        ->where('participation.campaign.0.done', true)->where('campaign.owner.work_email', 'initiatives@company.test')
        ->missing('campaign.owner.user_id')->missing('campaign.owner.bio')->missing('campaign.owner.date_of_birth'));
    $this->actingAs($this->editor)->get($url)->assertInertia(fn (Assert $page) => $page->where('participation.campaign.0.done', false)->where('campaign.done', 1));
    $changed = $this->settings;
    $changed['milestones'][1]['status'] = 'done';
    $changed['steps'][0]['title'] = 'Join a refreshed conversation';
    $this->campaign = $this->publisher->save($this->editor, $this->campaign, [...$this->campaign->draft, 'campaign' => $changed], 2);
    $this->actingAs($this->reader)->get($url)->assertInertia(fn (Assert $page) => $page->where('campaign.done', 1)->where('campaign.milestones.1.status', 'active')->where('participation.campaign.0.done', true));
    expect(collect(app(HubFeed::class)->forUser($this->reader)['pages'])->firstWhere('id', $this->campaign->id)['campaign_progress']['done'])->toBe(1);
    $this->publisher->publish($this->editor, $this->campaign, 3, null, null);
    $this->get($url)->assertInertia(fn (Assert $page) => $page->where('campaign.done', 2)->where('participation.campaign.0.done', false));
    $this->postJson('/company-hub/pages/'.$this->campaign->id.'/interactions', $payload)->assertConflict();
    $this->postJson('/company-hub/pages/'.$this->campaign->id.'/interactions', [...$payload, 'step_id' => 'coffee'])->assertNotFound();
    expect($this->release->fresh()->content['campaign']['milestones'][1]['status'])->toBe('active');
    $this->assertDatabaseCount('hub_interactions', 1);
    Notification::assertNothingSent();
});

test('curated canonical stories resources and exact event occurrences retain editorial order', function () {
    $first = ($this->publishItem)('news', 'First story');
    $second = ($this->publishItem)('news', 'Second story');
    $guide = ($this->publishItem)('knowledge', 'Helpful guide');
    $page = ($this->publishItem)('page', 'Useful page');
    $series = HrCalendarEvent::create(['title' => 'Learning circles', 'event_type' => 'company', 'created_by' => $this->editor->id, 'site_id' => $this->site->id,
        'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addHour(), 'rrule' => 'FREQ=WEEKLY;COUNT=3']);
    $events = collect(app(HubFeed::class)->forUser($this->reader)['events'])->where('extendedProps.eventId', $series->id)->values();
    // The actual feed keys carry the occurrence date, not a synthetic campaign event id.
    expect($events)->toHaveCount(3);
    $keys = [$events[2]['id'], $events[0]['id']];
    $url = ($this->publishCampaign)(['story_ids' => [$second->id, $first->id], 'resource_ids' => [$page->id, $guide->id], 'event_keys' => $keys]);
    $this->actingAs($this->reader)->get($url)->assertOk()->assertInertia(fn (Assert $result) => $result
        ->where('campaign.stories', fn ($items) => collect($items)->pluck('id')->all() === [$second->id, $first->id])
        ->where('campaign.resources', fn ($items) => collect($items)->pluck('id')->all() === [$page->id, $guide->id])
        ->where('campaign.events', fn ($items) => collect($items)->pluck('id')->all() === $keys)
        ->where('selectedPage.content.campaign.story_ids', [$second->id, $first->id])->where('selectedPage.content.campaign.event_keys', $keys));
    $this->publisher->save($this->editor, $second, [...$second->draft, 'title' => 'Unpublished private story text'], 2);
    $this->get($url)->assertInertia(fn (Assert $result) => $result->where('campaign.stories.0.title', 'Second story'));
    Notification::assertNothingSent();
});

test('unavailable curated references disappear from cards and raw reader id arrays', function () {
    $otherSite = Site::factory()->create(['is_active' => true, 'archived' => false]);
    ensureCanonicalHrStaffProfile($this->editor, $this->site, ['department_id' => $this->team->id, 'secondary_site_ids' => [$otherSite->id]]);
    $this->editor = $this->editor->fresh();
    $visible = ($this->publishItem)('news', 'Visible story');
    $private = ($this->publishItem)('news', 'Private story', ['audience' => ['type' => 'sites', 'ids' => [$otherSite->id]]]);
    $expired = ($this->publishItem)('knowledge', 'Expired guide');
    $archived = ($this->publishItem)('page', 'Archived guide');
    $scoped = ($this->publishItem)('knowledge', 'Current site guide', ['audience' => ['type' => 'sites', 'ids' => [$this->site->id]]]);
    $event = HrCalendarEvent::create(['title' => 'Upcoming shared event', 'event_type' => 'company', 'created_by' => $this->editor->id,
        'site_id' => $this->site->id, 'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addHour()]);
    $liveEvent = HrCalendarEvent::create(['title' => 'Current site session', 'event_type' => 'company', 'created_by' => $this->editor->id,
        'site_id' => $this->site->id, 'starts_at' => now()->addDays(3), 'ends_at' => now()->addDays(3)->addHour()]);
    $privateEvent = HrCalendarEvent::create(['title' => 'Private planning session', 'event_type' => 'company', 'created_by' => $this->editor->id,
        'site_id' => $this->site->id, 'starts_at' => now()->addDays(4), 'ends_at' => now()->addDays(4)->addHour()]);
    $privateEvent->attendees()->create(['audience_type' => 'person', 'user_id' => $this->editor->id, 'rsvp_status' => 'none']);
    $url = ($this->publishCampaign)(['story_ids' => [$private->id, $visible->id], 'resource_ids' => [$expired->id, $archived->id, $scoped->id],
        'event_keys' => ['event-'.$event->id, 'event-'.$liveEvent->id, 'event-'.$privateEvent->id]]);
    $expired->currentRelease()->sole()->update(['expires_at' => now()->subMinute()]);
    $archived->update(['archived_at' => now()]);
    $event->update(['archived_at' => now()]);
    $this->actingAs($this->reader)->get($url)->assertOk()->assertInertia(fn (Assert $result) => $result
        ->has('campaign.stories', 1)->where('campaign.stories.0.id', $visible->id)->has('campaign.resources', 1)->where('campaign.resources.0.id', $scoped->id)
        ->has('campaign.events', 1)->where('campaign.events.0.id', 'event-'.$liveEvent->id)
        ->where('selectedPage.content.campaign.story_ids', [$visible->id])->where('selectedPage.content.campaign.resource_ids', [$scoped->id])
        ->where('selectedPage.content.campaign.event_keys', ['event-'.$liveEvent->id]));
    // References stay in the immutable editorial snapshot for editors/history.
    expect($this->release->fresh()->content['campaign']['story_ids'])->toBe([$private->id, $visible->id]);
    $this->editor->hrEmployeeProfile()->update(['end_date' => today()->subDay()]);
    $this->get($url)->assertInertia(fn (Assert $result) => $result->where('campaign.owner', null));
    $this->reader->hrEmployeeProfile()->update(['primary_site_id' => $otherSite->id]);
    $this->actingAs($this->reader->fresh())->get($url)->assertInertia(fn (Assert $result) => $result
        ->has('campaign.stories', 2)->where('campaign.owner', null)->where('campaign.resources', [])->where('campaign.events', [])
        ->where('selectedPage.content.campaign.resource_ids', [])->where('selectedPage.content.campaign.event_keys', []));
    $this->reader->hrEmployeeProfile()->update(['end_date' => today()->subDay()]);
    $this->actingAs($this->reader->fresh())->get($url)->assertNotFound();
});

test('saving campaign curation rejects wrong kinds private drafts expired and unknown choices', function () {
    $story = ($this->publishItem)('news', 'Available story');
    $resource = ($this->publishItem)('knowledge', 'Available guide');
    $draft = $this->publisher->create($this->editor, 'news', 'Draft story');
    $privateSite = Site::factory()->create(['is_active' => true, 'archived' => false]);
    ensureCanonicalHrStaffProfile($this->editor, $this->site, ['department_id' => $this->team->id, 'secondary_site_ids' => [$privateSite->id]]);
    $this->editor = $this->editor->fresh();
    $private = ($this->publishItem)('news', 'Private story', ['audience' => ['type' => 'sites', 'ids' => [$privateSite->id]]]);
    ensureCanonicalHrStaffProfile($this->editor, $this->site, ['department_id' => $this->team->id]);
    $this->editor = $this->editor->fresh();
    $expired = ($this->publishItem)('news', 'Expired story');
    $expired->currentRelease()->sole()->update(['expires_at' => now()->subMinute()]);
    $privateEvent = HrCalendarEvent::create(['title' => 'Outside-site private event', 'event_type' => 'company', 'created_by' => $this->editor->id,
        'site_id' => $privateSite->id, 'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addHour()]);
    $this->actingAs($this->editor);
    foreach ([['story_ids', $resource->id], ['resource_ids', $story->id], ['story_ids', $draft->id], ['story_ids', $private->id], ['story_ids', $expired->id], ['story_ids', 999999], ['event_keys', 'event-'.$privateEvent->id], ['event_keys', 'event-999999'], ['event_keys', 'holiday-2026-10-31']] as [$field, $id]) {
        $this->putJson('/company-hub/manage/'.$this->campaign->id, ['version' => 1, 'content' => [...$this->campaign->draft, 'campaign' => [...$this->settings, $field => [$id]]]])
            ->assertUnprocessable()->assertJsonValidationErrors('campaign.'.$field);
    }
    $bad = $this->settings;
    $bad['milestones'][0]['status'] = 'completed';
    $this->putJson('/company-hub/manage/'.$this->campaign->id, ['version' => 1, 'content' => [...$this->campaign->draft, 'campaign' => $bad]])
        ->assertUnprocessable()->assertJsonValidationErrors('campaign.milestones.0.status');
    expect($this->campaign->fresh()->draft_version)->toBe(1);
    Notification::assertNothingSent();
});

test('publication rechecks references and finished occurrences earlier today are unavailable', function () {
    $this->travelTo(now()->startOfDay()->addHours(12));
    $story = ($this->publishItem)('news', 'Story to retire');
    $this->campaign = $this->publisher->save($this->editor, $this->campaign, [...$this->campaign->draft, 'campaign' => [...$this->settings, 'story_ids' => [$story->id]]], 1);
    $submitted = $this->publisher->submit($this->editor, $this->campaign, 2);
    $story->update(['archived_at' => now()]);
    $this->actingAs($this->editor)->postJson('/company-hub/manage/'.$this->campaign->id.'/revisions/'.$submitted->id.'/approve')
        ->assertUnprocessable()->assertJsonValidationErrors('campaign.story_ids');
    expect($submitted->fresh()->state)->toBe('submitted');
    $event = HrCalendarEvent::create(['title' => 'Finished today', 'event_type' => 'company', 'created_by' => $this->editor->id, 'site_id' => $this->site->id,
        'starts_at' => now()->startOfDay(), 'ends_at' => now()->subMinute()]);
    expect(collect(app(HubFeed::class)->forUser($this->editor)['events'])->pluck('id')->all())->toContain('event-'.$event->id);
    $this->putJson('/company-hub/manage/'.$this->campaign->id, ['version' => 2, 'content' => [...$this->campaign->draft, 'campaign' => [...$this->settings, 'event_keys' => ['event-'.$event->id]]]])
        ->assertUnprocessable()->assertJsonValidationErrors('campaign.event_keys');
    Notification::assertNothingSent();
});

test('reader content without a dedicated projection clears curated ids and preserves personal activities', function () {
    $content = ['campaign' => [...$this->settings, 'story_ids' => [123], 'resource_ids' => [456], 'event_keys' => ['event-789']]];
    $projected = HubCampaign::readerContent($content, null);
    expect($projected['campaign']['story_ids'])->toBe([])->and($projected['campaign']['resource_ids'])->toBe([])
        ->and($projected['campaign']['event_keys'])->toBe([])->and($projected['campaign']['steps'])->toBe($this->settings['steps'])
        ->and($content['campaign']['story_ids'])->toBe([123]);
});
