<?php

use App\Domain\CompanyHub\Models\HubMedia;
use App\Domain\CompanyHub\Models\HubPage;
use App\Domain\CompanyHub\Services\HubContent;
use App\Domain\CompanyHub\Services\HubDirectory;
use App\Domain\CompanyHub\Services\HubFeed;
use App\Domain\CompanyHub\Services\HubIdeas;
use App\Domain\CompanyHub\Services\HubSavedEvents;
use App\Domain\Hr\Models\HrCalendarEvent;
use App\Domain\Hr\Models\HrDepartment;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CompanyHubPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Notification::fake();
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    $this->site = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->outside = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $this->staff = function (string $role = 'support_worker', ?Site $site = null): User {
        $user = User::factory()->create(['role' => $role, 'approved_at' => now()]);
        $user->roles()->attach(Role::where('name', $role)->sole());
        ensureCanonicalHrStaffProfile($user, $site ?? $this->site);

        return $user;
    };
    $this->reader = ($this->staff)();
    $this->editor = ($this->staff)('hr');
    $this->travelTo(Carbon::parse('2026-10-16T00:00:00Z'));
    $this->actingAs($this->reader);
});

test('idea discovery reaches history beyond two hundred with stable pages and complete pre-page filters', function () {
    $team = HrDepartment::create(['name' => 'Visible improvement team', 'is_active' => true]);
    $team->sites()->attach($this->site->id);
    $privateTeam = HrDepartment::create(['name' => 'Unshared routing label', 'is_active' => true]);
    $privateTeam->sites()->attach($this->outside->id);
    $rows = [];
    for ($index = 0; $index < 205; $index++) {
        $rows[] = ['user_id' => $this->reader->id, 'title' => 'Staff idea '.$index, 'body' => 'Public improvement text',
            'category' => 'Better ways of working', 'status' => 'open', 'version' => 1,
            'department_id' => null, 'owner_id' => null, 'created_at' => now()->subDays(205 - $index), 'updated_at' => now()->subDays(205 - $index)];
    }
    DB::table('hub_ideas')->insert($rows);
    $oldest = (int) DB::table('hub_ideas')->min('id');
    DB::table('hub_ideas')->where('id', $oldest)->update(['department_id' => $team->id, 'owner_id' => $this->editor->id, 'status' => 'planned']);
    DB::table('hub_idea_updates')->insert(['hub_idea_id' => $oldest, 'status' => 'planned', 'note' => 'An older searchable progress decision',
        'posted_by_user_id' => $this->editor->id, 'owner_id' => $this->editor->id, 'department_id' => $team->id, 'source_version' => 1, 'created_at' => now()]);
    DB::table('hub_idea_supports')->insert(['hub_idea_id' => $oldest, 'user_id' => $this->editor->id, 'created_at' => now(), 'updated_at' => now()]);
    $ideas = app(HubIdeas::class);
    $first = $ideas->browse($this->reader);
    $second = $ideas->browse($this->reader, ['page' => 2]);
    $last = $ideas->browse($this->reader, ['page' => 11]);
    expect($first['data'])->toHaveCount(20)->and($first['pagination'])->toBe(['page' => 1, 'per_page' => 20, 'has_more' => true, 'next_page' => 2]);
    expect(array_intersect(array_column($first['data'], 'id'), array_column($second['data'], 'id')))->toBe([]);
    expect($last['data'])->toHaveCount(5)->and($last['pagination']['has_more'])->toBeFalse()->and($last['pagination']['next_page'])->toBeNull();
    expect($last['data'][4]['id'])->toBe($oldest)->and($ideas->selected($this->reader, $oldest)['id'])->toBe($oldest);
    expect($ideas->browse($this->reader, ['sort' => 'most_supported'])['data'][0]['id'])->toBe($oldest);
    expect($ideas->browse($this->reader, ['sort' => 'oldest'])['data'][0]['id'])->toBe($oldest);
    $this->getJson('/company-hub/discovery/ideas?'.http_build_query(['search' => 'older searchable', 'team_id' => $team->id, 'status' => 'planned', 'category' => 'Better ways of working']))
        ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $oldest)->assertJsonPath('pagination.has_more', false);
    $this->getJson('/company-hub/discovery/ideas?per_page=51')->assertUnprocessable()->assertJsonValidationErrors('per_page');
    $this->getJson('/company-hub/discovery/ideas?sort=unsupported')->assertUnprocessable()->assertJsonValidationErrors('sort');
    DB::table('hub_ideas')->where('id', $oldest)->update(['department_id' => $privateTeam->id]);
    expect($ideas->browse($this->reader, ['team_id' => $privateTeam->id])['data'])->toBe([]);
    expect($ideas->browse($this->reader, ['search' => $privateTeam->name])['data'])->toBe([]);
    DB::table('hub_ideas')->where('id', $oldest)->update(['hidden_at' => now()]);
    expect($ideas->selected($this->reader, $oldest))->toBeNull();
    $this->reader->permissionOverrides()->attach(Permission::where('key', 'company_hub.view')->sole(), ['allowed' => false]);
    $this->getJson('/company-hub/discovery/ideas')->assertForbidden();
});

test('saved events retain past far future and recurring identities while rechecking audience sites and exceptions', function () {
    $create = function (string $title, string $start, array $extra = []): HrCalendarEvent {
        $event = HrCalendarEvent::create(['title' => $title, 'event_type' => 'company', 'created_by' => $this->editor->id,
            'site_id' => $this->site->id, 'starts_at' => $start, 'ends_at' => Carbon::parse($start)->addHour(), ...$extra]);
        if (! $event->recurrence_parent_id) {
            $event->attendees()->create(['audience_type' => 'org', 'rsvp_status' => 'none']);
        }

        return $event;
    };
    $past = $create('Saved historic gathering', '2024-01-05 03:00:00');
    $future = $create('Saved distant gathering', '2028-04-05 03:00:00');
    $ended = $create('Saved ended series', '2024-01-01 03:00:00', ['rrule' => 'FREQ=WEEKLY;COUNT=3']);
    $series = $create('Saved future series', '2028-06-01 03:00:00', ['rrule' => 'FREQ=WEEKLY;COUNT=3']);
    $moved = $create('Moved saved series date', '2028-05-20 03:00:00', ['recurrence_parent_id' => $series->id, 'is_exception' => true, 'exception_date' => '2028-06-01']);
    foreach ([$past, $future, $ended, $series] as $event) {
        DB::table('hub_bookmarks')->insert(['user_id' => $this->reader->id, 'subject_type' => 'event', 'subject_id' => $event->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    $saved = app(HubSavedEvents::class);
    $rows = collect($saved->forUser($this->reader))->keyBy('extendedProps.savedSubjectId');
    expect($rows)->toHaveCount(4)->and($rows[$past->id]['start'])->toStartWith('2024-01-05')->and($rows[$future->id]['start'])->toStartWith('2028-04-05');
    expect($rows[$ended->id]['extendedProps']['occurrenceDate'])->toBe('2024-01-15');
    expect($rows[$series->id]['extendedProps']['eventId'])->toBe($moved->id)->and($rows[$series->id]['extendedProps']['recurrenceParentId'])->toBe($series->id);
    $this->get('/company-hub/events/'.$series->id.'?date=2028-06-01')->assertOk();
    $moved->update(['archived_at' => now()]);
    $rows = collect($saved->forUser($this->reader))->keyBy('extendedProps.savedSubjectId');
    expect($rows[$series->id]['extendedProps']['eventId'])->toBe($series->id)->and($rows[$series->id]['extendedProps']['occurrenceDate'])->toBe('2028-06-08');
    $past->update(['site_id' => $this->outside->id]);
    $future->attendees()->delete();
    $future->attendees()->create(['audience_type' => 'person', 'user_id' => $this->editor->id, 'rsvp_status' => 'none']);
    $ended->update(['archived_at' => now()]);
    expect(array_column($saved->forUser($this->reader), 'title'))->toBe(['Saved future series']);
    $series->update(['archived_at' => now()]);
    expect($saved->forUser($this->reader))->toBe([]);
    $this->assertDatabaseCount('hub_bookmarks', 4);
});

test('reader feed excludes unpublished draft bodies and batches referenced file media without dropping author drafts', function () {
    $createPage = function (string $slug, bool $published = true): HubPage {
        $content = [...HubContent::defaults('page'), 'title' => 'Published '.$slug];
        $page = HubPage::create(['slug' => $slug, 'kind' => 'page', 'owner_id' => $this->editor->id, 'draft' => [...$content, 'summary' => str_repeat('private draft ', 1000)], 'draft_version' => 2]);
        if ($published) {
            $blocks = [];
            for ($index = 0; $index < 3; $index++) {
                $media = HubMedia::create(['hub_page_id' => $page->id, 'uploaded_by' => $this->editor->id, 'path' => 'hub/'.$slug.'/'.$index.'.pdf', 'name' => 'Guide '.$index.'.pdf', 'mime' => 'application/pdf', 'size' => 100]);
                $blocks[] = ['id' => 'file-'.$index, 'type' => 'file', 'media_id' => $media->id, 'title' => 'Reader guide '.$index, 'body' => 'Published file'];
            }
            $content['sections'] = [['id' => 'files', 'columns' => 1, 'blocks' => $blocks]];
            $page->revisions()->create(['created_by' => $this->editor->id, 'draft_version' => 1, 'content' => $content, 'state' => 'approved', 'approved_by' => $this->editor->id, 'approved_at' => now()->subDay(), 'publish_at' => now()->subDay()]);
        }

        return $page;
    };
    $one = $createPage('batch-one');
    $two = $createPage('batch-two');
    $draft = $createPage('unpublished-draft', false);
    DB::enableQueryLog();
    $feed = app(HubFeed::class)->forUser($this->reader);
    $queries = collect(DB::getQueryLog())->pluck('query');
    DB::disableQueryLog();
    expect(array_column($feed['pages'], 'id'))->toEqualCanonicalizing([$one->id, $two->id])->and($feed['resourceItems'])->toHaveCount(6)->and($feed['editablePages'])->toBe([]);
    expect(json_encode($feed))->not->toContain('private draft')->not->toContain('unpublished-draft');
    $fileQueries = $queries->filter(fn ($sql) => str_starts_with(strtolower($sql), 'select') && str_contains($sql, 'from `hub_media`') && str_contains($sql, '`id` in'));
    expect($fileQueries)->toHaveCount(1);
    $pageQuery = $queries->first(fn ($sql) => str_contains($sql, "'{}' AS draft"));
    expect($pageQuery)->not->toBeNull()->and($pageQuery)->toContain('exists')->not->toContain('`hub_pages`.*');
    expect($queries->filter(fn ($sql) => str_starts_with(strtolower($sql), 'select') && str_contains($sql, 'from `hub_pages`')))->toHaveCount(1);
    $authorFeed = app(HubFeed::class)->forUser($this->editor);
    expect(array_column($authorFeed['editablePages'], 'id'))->toContain($draft->id, $one->id, $two->id);
});

test('directory avatars use the protected owned photo route and refreshed work permissions', function () {
    Storage::fake('private');
    Storage::fake('public');
    $profile = $this->editor->hrEmployeeProfile()->sole();
    $path = 'hr/photos/'.$profile->id.'/work-photo.png';
    Storage::disk('private')->put($path, 'private-photo-bytes');
    $profile->update(['profile_photo_path' => $path]);
    $directory = app(HubDirectory::class);
    $card = $directory->person($this->reader, $profile->id);
    expect($card['avatar_url'])->toBe(route('hr.directory.photo', ['profile' => $profile->id]))->and($card['chat_url'])->toBeNull();
    expect(json_encode($card))->not->toContain($path)->not->toContain('/storage/');
    $this->get($card['avatar_url'])->assertOk();
    $profile->update(['profile_photo_path' => 'hr/photos/'.$this->reader->hrEmployeeProfile->id.'/someone-else.png']);
    expect($directory->person($this->reader, $profile->id)['avatar_url'])->toBeNull();
    $profile->update(['profile_photo_path' => $path, 'primary_site_id' => $this->outside->id]);
    expect(array_column($directory->people($this->reader), 'id'))->not->toContain($profile->id);
    $this->get($card['avatar_url'])->assertNotFound();
    $this->reader->load(['roles.permissions', 'permissionOverrides']);
    $this->reader->permissionOverrides()->attach(Permission::where('key', 'hr.recognition.give')->sole(), ['allowed' => false]);
    $self = $directory->person($this->reader, $this->reader->hrEmployeeProfile->id);
    expect($self['can_recognise'])->toBeFalse()->and($self['recognition_user_id'])->toBeNull();
});
