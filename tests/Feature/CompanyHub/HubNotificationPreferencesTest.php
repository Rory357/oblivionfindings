<?php

use App\Domain\CompanyHub\Notifications\HubConversationReply;
use App\Domain\CompanyHub\Notifications\HubNotice;
use App\Domain\CompanyHub\Services\HubConversations;
use App\Domain\CompanyHub\Services\HubDiscovery;
use App\Domain\CompanyHub\Services\HubFeed;
use App\Domain\CompanyHub\Services\HubIdeas;
use App\Domain\CompanyHub\Services\HubNotices;
use App\Domain\CompanyHub\Services\HubNotificationPreferences;
use App\Domain\CompanyHub\Services\HubParticipation;
use App\Domain\CompanyHub\Services\HubPublishing;
use App\Domain\Hr\Models\HrAnnouncement;
use App\Domain\Hr\Models\HrDepartment;
use App\Domain\Hr\Notifications\FeedReplyNotification;
use App\Domain\Hr\Notifications\KudosReceivedNotification;
use App\Domain\Hr\Services\FeedService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\CompanyHubPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $this->travelTo(Carbon::parse('2026-10-14T04:00:00Z'));
    Notification::fake();
    Mail::fake();
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    $this->site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->team = HrDepartment::create(['name' => 'Current work team', 'is_active' => true]);
    $this->team->sites()->attach($this->site->id);
    $staff = function (string $name, string $role = 'support_worker', ?int $department = null): User {
        $user = User::factory()->create(['name' => $name, 'role' => $role, 'approved_at' => now()]);
        $user->roles()->attach(Role::where('name', $role)->sole());
        ensureCanonicalHrStaffProfile($user, $this->site, ['department_id' => $department]);

        return $user;
    };
    $this->editor = $staff('Conversation owner', 'hr');
    $this->reader = $staff('Current participant', department: $this->team->id);
    $this->other = $staff('New replier', department: $this->team->id);
    $this->outsider = $staff('Unrelated reader');
    $this->preferences = app(HubNotificationPreferences::class);
    $this->savePreferences = function (User $viewer, array $changes = []) {
        $context = $this->preferences->read($viewer);

        return $this->actingAs($viewer)->putJson('/company-hub/notifications', ['actor_id' => $viewer->id, 'version' => $context['version'],
            'recognition' => true, 'replies' => true, 'events' => true, 'news' => true, ...$changes]);
    };
    $this->publisher = app(HubPublishing::class);
    $this->page = $this->publisher->create($this->editor, 'news', 'Private-title-notification-needle');
    $content = [...$this->page->draft, 'topics' => ['Learning'], 'team_ids' => [$this->team->id], 'review_on' => null, 'comments_enabled' => true,
        'sections' => [['id' => 'main', 'columns' => 1, 'blocks' => [['id' => 'intro', 'type' => 'text', 'title' => 'A company story', 'body' => 'Private-body-notification-needle. A complete story for the canonical preference contract.']]]]];
    $this->page = $this->publisher->save($this->editor, $this->page, $content, 1);
    $this->revision = $this->publisher->publish($this->editor, $this->page, 2, null, null);
    $this->comment = function (User $viewer, ?int $parent = null, string $body = 'Private-comment-notification-needle'): int {
        app(HubParticipation::class)->write($viewer, $this->page, ['kind' => 'comment', 'body' => $body, 'parent_id' => $parent]);

        return (int) DB::table('hub_interactions')->where('kind', 'comment')->max('id');
    };
    $this->deliver = fn (User $viewer, $notice, array $channels = ['database']) => unserialize(serialize(new SendQueuedNotifications($viewer, $notice, $channels)))->handle(new ChannelManager(app()));
});

test('notification preferences expose normalised defaults and confirmed versioned saves without replacing other personal settings', function () {
    $this->actingAs($this->reader)->getJson('/company-hub/notifications')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('actor_id', $this->reader->id)->assertJsonPath('version', 1)->assertJsonPath('ready', true)
        ->assertJsonPath('preferences.recognition', true)->assertJsonPath('preferences.replies', true);
    DB::table('hub_preferences')->insert(['user_id' => $this->reader->id, 'notifications' => '{"news":0,"reviews":"0"}',
        'shortcuts' => '[{"id":"retained"}]', 'interests' => '{"topics":["Learning"],"teams":[]}', 'interests_version' => 7,
        'followed_pages' => json_encode([$this->page->id])]);
    $snapshot = DB::table('hub_preferences')->where('user_id', $this->reader->id)->first();
    ($this->savePreferences)($this->reader, ['recognition' => false, 'replies' => false, 'news' => false])->assertOk()
        ->assertJsonPath('saved', true)->assertJsonPath('version', 2)->assertJsonPath('preferences.reviews', false);
    $row = DB::table('hub_preferences')->where('user_id', $this->reader->id)->first();
    expect([$row->shortcuts, $row->interests, $row->interests_version, $row->followed_pages])->toBe([$snapshot->shortcuts, $snapshot->interests, $snapshot->interests_version, $snapshot->followed_pages]);
    $feed = app(HubFeed::class)->forUser($this->reader);
    expect($feed['notificationPreferenceContext'])->toBe(['ready' => true, 'actor_id' => $this->reader->id, 'version' => 2])
        ->and($feed['notificationPreferences'])->toBe(['recognition' => false, 'replies' => false, 'events' => true, 'news' => false, 'reviews' => false]);
    Notification::assertNothingSent();
});

test('notification preference mutations deny changed accounts stale versions forged targets and current staff site or permission withdrawal', function () {
    ($this->savePreferences)($this->reader)->assertOk();
    $payload = ['actor_id' => $this->reader->id, 'version' => 2, 'recognition' => false, 'replies' => false, 'events' => false, 'news' => false];
    $this->actingAs($this->reader)->putJson('/company-hub/notifications', [...$payload, 'actor_id' => $this->editor->id])->assertConflict();
    $this->putJson('/company-hub/notifications', [...$payload, 'version' => 1])->assertConflict();
    $this->putJson('/company-hub/notifications', [...$payload, 'user_id' => $this->other->id])->assertUnprocessable();
    $this->putJson('/company-hub/notifications', [...$payload, 'news' => 'false'])->assertUnprocessable();
    expect($this->preferences->read($this->reader)['version'])->toBe(2)->and($this->preferences->allows($this->reader, 'recognition'))->toBeTrue();
    $this->reader->hrEmployeeProfile->update(['is_active' => false]);
    $this->putJson('/company-hub/notifications', $payload)->assertForbidden();
    $this->reader->hrEmployeeProfile->update(['is_active' => true]);
    $this->site->update(['is_active' => false]);
    $this->putJson('/company-hub/notifications', $payload)->assertForbidden();
    $this->site->update(['is_active' => true]);
    $this->reader->permissionOverrides()->attach(Permission::where('key', 'company_hub.view')->value('id'), ['allowed' => false]);
    $this->putJson('/company-hub/notifications', $payload)->assertForbidden();
    Notification::assertNothingSent();
});

test('legacy news and review writes merge new categories and invalidate stale guarded editors', function () {
    ($this->savePreferences)($this->reader, ['recognition' => false, 'replies' => false, 'events' => false])->assertOk();
    $this->actingAs($this->reader)->put('/company-hub/notifications', ['news' => false, 'reviews' => false])->assertRedirect();
    expect($this->preferences->read($this->reader))->toMatchArray(['version' => 3,
        'preferences' => ['recognition' => false, 'replies' => false, 'events' => false, 'news' => false, 'reviews' => false]]);
    $this->putJson('/company-hub/notifications', ['actor_id' => $this->reader->id, 'version' => 2, 'recognition' => true, 'replies' => true, 'events' => true, 'news' => true])->assertConflict();
    Notification::assertNothingSent();
});

test('migration pending preferences remain readable guarded saves fail clearly and legacy writes safely merge', function () {
    ($this->savePreferences)($this->reader, ['recognition' => false])->assertOk();
    $original = Schema::getFacadeRoot();
    $pending = Mockery::mock(get_class($original), [$original->getConnection()])->makePartial();
    $pending->shouldReceive('hasColumn')->with('hub_preferences', 'notifications_version')->andReturn(false);
    Schema::swap($pending);
    try {
        $this->actingAs($this->reader)->getJson('/company-hub/notifications')->assertOk()->assertJsonPath('ready', false)->assertJsonPath('version', null)->assertJsonPath('preferences.recognition', false);
        $this->putJson('/company-hub/notifications', ['actor_id' => $this->reader->id, 'version' => 2, 'recognition' => true, 'replies' => true, 'events' => true, 'news' => true])->assertStatus(503);
        $this->putJson('/company-hub/notifications', ['news' => false, 'reviews' => true])->assertOk()->assertJsonPath('preferences.recognition', false)->assertJsonPath('version', null);
        expect($this->preferences->allows($this->reader, 'recognition'))->toBeFalse();
    } finally {
        Schema::swap($original);
    }
    expect($this->preferences->read($this->reader)['version'])->toBe(2);
});

test('news delivery follows only current visible topic or team interests with generic payloads and revision deduplication', function () {
    expect(app(HubNotices::class)->deliver())->toBe(0);
    app(HubDiscovery::class)->saveInterests($this->reader, ['actor_id' => $this->reader->id, 'topics' => ['Learning'], 'teams' => [], 'version' => 1]);
    expect(app(HubNotices::class)->deliver())->toBe(1)->and(app(HubNotices::class)->deliver())->toBe(0);
    $notice = Notification::sent($this->reader, HubNotice::class)->sole();
    expect(json_encode($notice->toArray($this->reader)))->not->toContain('Private-title-notification-needle', 'Private-body-notification-needle');
    ($this->savePreferences)($this->reader, ['news' => false])->assertOk();
    expect($notice->shouldSend($this->reader, 'database'))->toBeFalse();
    ($this->savePreferences)($this->reader)->assertOk();
    $this->page = $this->publisher->save($this->editor, $this->page, [...$this->page->draft, 'topics' => ['Digital tools']], 2);
    expect($notice->shouldSend($this->reader, 'database'))->toBeTrue();
    $this->publisher->publish($this->editor, $this->page, 3, null, null);
    expect($notice->shouldSend($this->reader, 'database'))->toBeFalse()->and(app(HubNotices::class)->deliver())->toBe(0);
    app(HubDiscovery::class)->saveInterests($this->reader, ['actor_id' => $this->reader->id, 'topics' => [], 'teams' => [$this->team->id], 'version' => 2]);
    expect(app(HubNotices::class)->deliver())->toBe(1);
    $this->team->update(['is_active' => false]);
    $latest = Notification::sent($this->reader, HubNotice::class)->last();
    expect($latest->shouldSend($this->reader, 'database'))->toBeFalse();
    $this->team->update(['is_active' => true]);
    $this->page->update(['archived_at' => now()]);
    expect($latest->shouldSend($this->reader, 'database'))->toBeFalse();
});

test('queued person team recognition and replies recheck opt outs at actual channel delivery without changing canonical social records', function () {
    $feed = app(FeedService::class);
    $person = $feed->sendKudos($this->editor, $this->reader->id, null, 'Recognition remains saved when notices are muted.');
    $team = $feed->sendTeamKudos($this->editor, $this->team->id, null, 'A complete whole-team recognition stays canonical.');
    $feed->addReply($person, $this->editor->id, 'A canonical reply for the recipient.');
    $feed->addReply($team, $this->editor->id, 'A canonical whole-team conversation reply.');
    $received = Notification::sent($this->reader, KudosReceivedNotification::class);
    $replies = Notification::sent($this->reader, FeedReplyNotification::class);
    expect($received)->toHaveCount(2)->and($replies)->toHaveCount(2);
    ($this->savePreferences)($this->reader, ['recognition' => false, 'replies' => false])->assertOk();
    foreach ($received as $notice) {
        ($this->deliver)($this->reader, $notice, ['database', 'mail']);
        expect($notice->shouldSend($this->reader, 'mail'))->toBeFalse();
    }
    foreach ($replies as $notice) {
        ($this->deliver)($this->reader, $notice);
        expect($notice->shouldSend($this->reader, 'database'))->toBeFalse();
    }
    $this->assertDatabaseCount('notifications', 0);
    Mail::assertNothingSent();
    expect($person->replies()->count())->toBe(1)->and($team->replies()->count())->toBe(1);
    ($this->savePreferences)($this->reader)->assertOk();
    expect($received->first()->shouldSend($this->reader, 'database'))->toBeTrue()->and($replies->first()->shouldSend($this->reader, 'database'))->toBeTrue();
    $person->delete();
    expect($received->first()->shouldSend($this->reader, 'database'))->toBeFalse()->and($replies->first()->shouldSend($this->reader, 'database'))->toBeFalse();
});

test('page conversation hooks notify only prior current participants and database retries have one generic private receipt', function () {
    $parent = ($this->comment)($this->editor);
    ($this->comment)($this->reader, $parent);
    Notification::fake();
    $source = ($this->comment)($this->other, $parent);
    Notification::assertSentTo($this->editor, HubConversationReply::class);
    Notification::assertSentTo($this->reader, HubConversationReply::class);
    Notification::assertNotSentTo($this->other, HubConversationReply::class);
    Notification::assertNotSentTo($this->outsider, HubConversationReply::class);
    $notice = Notification::sent($this->reader, HubConversationReply::class)->sole();
    expect($notice->afterCommit)->toBeTrue()->and(json_encode($notice->toArray($this->reader)))->not->toContain('Private-comment-notification-needle', $this->editor->name, $this->other->name, 'participants');
    ($this->deliver)($this->reader, $notice);
    ($this->deliver)($this->reader, $notice);
    $this->assertDatabaseCount('notifications', 1);
    $this->assertDatabaseHas('notifications', ['id' => $notice->id, 'notifiable_id' => $this->reader->id]);
    expect(app(HubConversations::class)->context($this->outsider, 'page', $source))->toBeNull();
    $this->assertDatabaseHas('hub_interactions', ['id' => $source, 'body' => 'Private-comment-notification-needle']);
});

test('queued page conversation notices deny hidden parents removed sources opt outs and withdrawn current access', function () {
    $parent = ($this->comment)($this->reader);
    $source = ($this->comment)($this->editor, $parent);
    $notice = Notification::sent($this->reader, HubConversationReply::class)->sole();
    DB::table('hub_interactions')->where('id', $parent)->update(['moderator_hidden_at' => now(), 'hidden_at' => now()]);
    ($this->deliver)($this->reader, $notice);
    expect($notice->toArray($this->reader)['message'])->toBe('');
    DB::table('hub_interactions')->where('id', $parent)->update(['moderator_hidden_at' => null, 'hidden_at' => null]);
    ($this->savePreferences)($this->reader, ['replies' => false])->assertOk();
    ($this->deliver)($this->reader, $notice);
    ($this->savePreferences)($this->reader)->assertOk();
    $this->reader->hrEmployeeProfile->update(['is_active' => false]);
    ($this->deliver)($this->reader, $notice);
    $this->reader->hrEmployeeProfile->update(['is_active' => true]);
    $outside = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->reader->hrEmployeeProfile->update(['primary_site_id' => $outside->id]);
    $this->editor->hrEmployeeProfile->update(['secondary_site_ids' => [$outside->id]]);
    $this->page = $this->publisher->save($this->editor->fresh(), $this->page, [...$this->page->draft, 'audience' => ['type' => 'sites', 'ids' => [$this->site->id]]], 2);
    $this->publisher->publish($this->editor->fresh(), $this->page, 3, null, null);
    ($this->deliver)($this->reader, $notice);
    DB::table('hub_interactions')->where('id', $source)->update(['author_removed_at' => now(), 'body' => null]);
    expect($notice->shouldSend($this->reader, 'database'))->toBeFalse();
    $this->assertDatabaseCount('notifications', 0);
});

test('idea conversations use creator and prior visible commenters without notifying supporters and withdrawn sources suppress queued delivery', function () {
    $ideas = app(HubIdeas::class);
    $id = $ideas->create($this->editor, ['title' => 'A staff idea', 'body' => 'An idea conversation for current colleagues.', 'category' => 'Something else']);
    $ideas->mutate($this->outsider, $id, ['action' => 'support', 'active' => true]);
    $ideas->mutate($this->reader, $id, ['action' => 'comment', 'body' => 'Private-idea-conversation-needle']);
    Notification::fake();
    $ideas->mutate($this->other, $id, ['action' => 'comment', 'body' => 'Another private idea contribution.']);
    Notification::assertSentTo($this->reader, HubConversationReply::class);
    Notification::assertSentTo($this->editor, HubConversationReply::class);
    Notification::assertNotSentTo($this->outsider, HubConversationReply::class);
    Notification::assertNotSentTo($this->other, HubConversationReply::class);
    $notice = Notification::sent($this->reader, HubConversationReply::class)->sole();
    expect(json_encode($notice->toArray($this->reader)))->not->toContain('Private-idea-conversation-needle', 'Another private idea contribution.', $this->other->name);
    $source = DB::table('hub_idea_comments')->where('user_id', $this->other->id)->sole();
    $ideas->mutate($this->other, $id, ['action' => 'remove_comment', 'comment_id' => $source->id]);
    ($this->deliver)($this->reader, $notice);
    expect($notice->shouldSend($this->reader, 'database'))->toBeFalse();
    DB::table('hub_idea_comments')->where('id', $source->id)->update(['hidden_at' => null]);
    $ideas->mutate($this->editor, $id, ['action' => 'remove', 'version' => 1]);
    ($this->deliver)($this->reader, $notice);
    $this->assertDatabaseCount('notifications', 0);
});

test('all optional categories off retain canonical required reading and acknowledgements', function () {
    ($this->savePreferences)($this->reader, ['recognition' => false, 'replies' => false, 'events' => false, 'news' => false, 'reviews' => false])->assertOk();
    $notice = HrAnnouncement::create(['title' => 'Required reading stays mandatory', 'content' => 'Please read the current guide.', 'status' => 'published',
        'published_at' => now()->subHour(), 'requires_acknowledgement' => true, 'target_audience' => 'all', 'created_by' => $this->editor->id]);
    $updates = collect(app(HubFeed::class)->forUser($this->reader)['updates'])->where('type', 'announcements')->values();
    expect($updates)->toHaveCount(1)->and($updates[0]['required'])->toBeTrue();
    $this->actingAs($this->reader)->postJson('/company-hub/seen', ['keys' => [$updates[0]['key']]])->assertOk();
    $remaining = collect(app(HubFeed::class)->forUser($this->reader)['updates'])->where('type', 'announcements')->first();
    expect($remaining['required'])->toBeTrue()->and($notice->acknowledgements()->count())->toBe(0);
    Notification::assertNothingSent();
});
