<?php

use App\Domain\CompanyHub\Services\HubFeed;
use App\Domain\CompanyHub\Services\HubPublishing;
use App\Domain\Hr\Models\HrCalendarEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CompanyHubPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    Notification::fake();
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    $this->site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->outside = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $staff = function (string $role): User {
        $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        $user->roles()->attach(Role::where('name', $role)->sole());
        ensureCanonicalHrStaffProfile($user, $this->site);

        return $user;
    };
    $this->editor = $staff('hr');
    $this->reader = $staff('support_worker');
    $this->other = $staff('support_worker');
    $this->publisher = app(HubPublishing::class);
    $this->page = $this->publisher->create($this->editor, 'news', 'An authorised saved story')->fresh();
    $draft = $this->page->draft;
    $draft['sections'] = [['id' => 'story', 'columns' => 1, 'blocks' => [[
        'id' => 'body', 'type' => 'text', 'title' => 'Saved story',
        'body' => 'A complete published story for current bookmark access.',
    ]]]];
    $this->page = $this->publisher->save($this->editor, $this->page, $draft, $this->page->draft_version);
    $this->publisher->publish($this->editor, $this->page, $this->page->draft_version, null, null);
    $this->event = HrCalendarEvent::create(['created_by' => $this->editor->id, 'title' => 'A canonical saved series',
        'event_type' => 'company', 'site_id' => $this->site->id, 'starts_at' => now()->addWeek(), 'ends_at' => now()->addWeek()->addHour(),
        'rrule' => 'FREQ=WEEKLY;COUNT=3']);
    $this->event->attendees()->create(['audience_type' => 'org', 'rsvp_status' => 'none']);
    $this->url = '/company-hub/bookmarks';
    $this->payload = ['hub_receipt' => true, 'actor_id' => $this->reader->id,
        'subject_type' => 'page', 'subject_id' => $this->page->id, 'saved' => true, 'expected_saved' => false, 'expected_version' => null];
    $this->stateUrl = fn (string $type = 'page', ?int $id = null, ?int $actor = null) => $this->url.'/state?'.http_build_query([
        'actor_id' => $actor ?? $this->reader->id, 'subject_type' => $type, 'subject_id' => $id ?? $this->page->id,
    ]);
    Notification::fake();
    $this->actingAs($this->reader);
});

test('bookmark acknowledged sets and receipt based Undo preserve canonical state generation and idempotent saves', function () {
    $this->getJson(($this->stateUrl)())->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('actor_id', $this->reader->id)->assertJsonPath('subject_id', $this->page->id)
        ->assertJsonPath('bookmarked', false)->assertJsonPath('version', null)->assertJsonMissingPath('saved');
    $saved = $this->postJson($this->url, $this->payload)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('saved', true)->assertJsonPath('actor_id', $this->reader->id)->assertJsonPath('subject_type', 'page')
        ->assertJsonPath('subject_id', $this->page->id)->assertJsonPath('bookmarked', true)
        ->assertJsonPath('previous_saved', false)->assertJsonPath('changed', true);
    $version = $saved->json('version');
    expect($version)->toBeString()->toHaveLength(64);
    $before = DB::table('hub_bookmarks')->sole();
    $this->postJson($this->url, [...$this->payload, 'expected_saved' => true, 'expected_version' => $version])
        ->assertOk()->assertJsonPath('bookmarked', true)->assertJsonPath('version', $version)->assertJsonPath('changed', false);
    expect(DB::table('hub_bookmarks')->sole())->toEqual($before);
    expect(app(HubFeed::class)->forUser($this->reader)['bookmarks'])->toBe([
        ['subject_type' => 'page', 'subject_id' => $this->page->id, 'version' => $version],
    ]);
    $this->postJson($this->url, [...$this->payload, 'saved' => $saved->json('previous_saved'),
        'expected_saved' => $saved->json('bookmarked'), 'expected_version' => $version])
        ->assertOk()->assertJsonPath('bookmarked', false)->assertJsonPath('version', null)
        ->assertJsonPath('previous_saved', true)->assertJsonPath('changed', true);
    $this->assertDatabaseCount('hub_bookmarks', 0);
    Notification::assertNothingSent();
});

test('bookmark Undo rejects a replacement saved row even when its current saved state is unchanged', function () {
    $version = $this->postJson($this->url, $this->payload)->assertOk()->json('version');
    $oldId = DB::table('hub_bookmarks')->sole()->id;
    $legacy = ['subject_type' => 'page', 'subject_id' => $this->page->id];
    $this->post($this->url, [...$legacy, 'saved' => false])->assertRedirect();
    $this->post($this->url, [...$legacy, 'saved' => true])->assertRedirect();
    $current = $this->getJson(($this->stateUrl)())->assertOk()->assertJsonPath('bookmarked', true)->json('version');
    expect($current)->not->toBe($version)->and(DB::table('hub_bookmarks')->sole()->id)->not->toBe($oldId);
    $this->postJson($this->url, [...$this->payload, 'saved' => false, 'expected_saved' => true, 'expected_version' => $version])
        ->assertConflict();
    $this->getJson(($this->stateUrl)())->assertJsonPath('bookmarked', true)->assertJsonPath('version', $current);
    $this->assertDatabaseCount('hub_bookmarks', 1);
});

test('bookmark absence Undo and unknown outcome recovery never overwrite a different current saved state', function () {
    $saved = $this->postJson($this->url, $this->payload)->assertOk();
    $removed = $this->postJson($this->url, [...$this->payload, 'saved' => false,
        'expected_saved' => true, 'expected_version' => $saved->json('version')])->assertOk()
        ->assertJsonPath('bookmarked', false)->assertJsonPath('version', null)->assertJsonPath('previous_saved', true);
    $this->post($this->url, ['subject_type' => 'page', 'subject_id' => $this->page->id, 'saved' => true])->assertRedirect();
    $current = DB::table('hub_bookmarks')->sole();
    $this->postJson($this->url, [...$this->payload, 'saved' => $removed->json('previous_saved'),
        'expected_saved' => $removed->json('bookmarked'), 'expected_version' => $removed->json('version')])->assertConflict();
    $this->postJson($this->url, $this->payload)->assertConflict();
    $state = $this->getJson(($this->stateUrl)())->assertOk()->assertJsonPath('bookmarked', true)->assertJsonMissingPath('saved');
    expect($state->json('version'))->toHaveLength(64)->and(DB::table('hub_bookmarks')->sole())->toEqual($current);
    $this->actingAs($this->other)->getJson(($this->stateUrl)(actor: $this->other->id))
        ->assertJsonPath('bookmarked', false)->assertJsonPath('version', null);
    $this->assertDatabaseCount('hub_bookmarks', 1);
});

test('bookmark actor provenance current permissions and published page visibility guard reads writes and Undo', function () {
    $this->actingAs($this->other)->postJson($this->url, $this->payload)->assertConflict();
    $this->getJson(($this->stateUrl)())->assertConflict();
    $this->actingAs($this->reader);
    foreach (['actor_id', 'expected_saved', 'expected_version'] as $field) {
        $this->postJson($this->url, array_diff_key($this->payload, [$field => true]))->assertUnprocessable()->assertJsonValidationErrors($field);
    }
    $this->postJson($this->url, [...$this->payload, 'user_id' => $this->other->id])->assertUnprocessable()->assertJsonValidationErrors('user_id');
    $unpublished = $this->publisher->create($this->editor, 'news', 'A private unsaved story');
    $this->getJson(($this->stateUrl)('page', $unpublished->id))->assertNotFound();
    $this->postJson($this->url, [...$this->payload, 'subject_id' => $unpublished->id])->assertNotFound();
    $this->reader->load(['permissionOverrides', 'roles.permissions']);
    $this->reader->permissionOverrides()->attach(Permission::where('key', 'company_hub.view')->value('id'), ['allowed' => false]);
    expect($this->reader->canDo('company_hub.view'))->toBeTrue();
    $this->getJson(($this->stateUrl)())->assertForbidden();
    $this->postJson($this->url, $this->payload)->assertForbidden();
    $this->reader->permissionOverrides()->detach();
    $this->reader->hrEmployeeProfile()->update(['end_date' => today()->subDay()]);
    $this->getJson(($this->stateUrl)())->assertForbidden();
    $this->postJson($this->url, $this->payload)->assertForbidden();
    $this->reader->hrEmployeeProfile()->update(['end_date' => null]);
    $this->publisher->archive($this->editor, $this->page->fresh(), $this->page->fresh()->draft_version);
    $this->getJson(($this->stateUrl)())->assertNotFound();
    $this->postJson($this->url, $this->payload)->assertNotFound();
    $this->assertDatabaseCount('hub_bookmarks', 0);
});

test('bookmark event sets retain exact series identity legacy redirects and current canonical audience site and archive guards', function () {
    $payload = [...$this->payload, 'subject_type' => 'event', 'subject_id' => $this->event->id];
    $saved = $this->postJson($this->url, $payload)->assertOk()->assertJsonPath('subject_type', 'event')
        ->assertJsonPath('subject_id', $this->event->id)->assertJsonPath('bookmarked', true);
    $this->postJson($this->url, ['subject_type' => 'event', 'subject_id' => $this->event->id, 'saved' => true])->assertRedirect();
    $this->assertDatabaseCount('hub_bookmarks', 1);
    $undo = [...$payload, 'saved' => false, 'expected_saved' => true, 'expected_version' => $saved->json('version')];
    $this->event->update(['site_id' => $this->outside->id]);
    $this->getJson(($this->stateUrl)('event', $this->event->id))->assertNotFound();
    $this->postJson($this->url, $undo)->assertNotFound();
    $this->event->update(['site_id' => $this->site->id]);
    $this->event->attendees()->delete();
    $this->event->attendees()->create(['audience_type' => 'person', 'user_id' => $this->other->id, 'rsvp_status' => 'none']);
    $this->getJson(($this->stateUrl)('event', $this->event->id))->assertNotFound();
    $this->postJson($this->url, $undo)->assertNotFound();
    $this->event->attendees()->delete();
    $this->event->attendees()->create(['audience_type' => 'org', 'rsvp_status' => 'none']);
    $this->event->update(['archived_at' => now()]);
    $this->getJson(($this->stateUrl)('event', $this->event->id))->assertNotFound();
    $this->postJson($this->url, $undo)->assertNotFound();
    expect(DB::table('hub_bookmarks')->sole()->subject_id)->toBe($this->event->id);
    Notification::assertNothingSent();
});

test('bookmark failed inserts and Undo deletion roll back with truthful authorised recovery state', function () {
    $failInsert = true;
    DB::listen(function (QueryExecuted $query) use (&$failInsert): void {
        if ($failInsert && str_starts_with(strtolower($query->sql), 'insert into `hub_bookmarks`')) {
            $failInsert = false;
            throw new RuntimeException('Synthetic bookmark insert failure.');
        }
    });
    $this->withoutExceptionHandling();
    expect(fn () => $this->postJson($this->url, $this->payload))->toThrow(RuntimeException::class, 'Synthetic bookmark insert failure.');
    $this->getJson(($this->stateUrl)())->assertJsonPath('bookmarked', false)->assertJsonPath('version', null);
    $saved = $this->postJson($this->url, $this->payload)->assertOk();
    $failDelete = true;
    DB::listen(function (QueryExecuted $query) use (&$failDelete): void {
        if ($failDelete && str_starts_with(strtolower($query->sql), 'delete from `hub_bookmarks`')) {
            $failDelete = false;
            throw new RuntimeException('Synthetic bookmark Undo failure.');
        }
    });
    expect(fn () => $this->postJson($this->url, [...$this->payload, 'saved' => false,
        'expected_saved' => true, 'expected_version' => $saved->json('version')]))->toThrow(RuntimeException::class, 'Synthetic bookmark Undo failure.');
    $this->getJson(($this->stateUrl)())->assertJsonPath('bookmarked', true)->assertJsonPath('version', $saved->json('version'));
    $this->assertDatabaseCount('hub_bookmarks', 1);
    Notification::assertNothingSent();
});
