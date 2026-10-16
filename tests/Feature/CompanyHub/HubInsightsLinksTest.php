<?php

use App\Domain\CompanyHub\Services\HubIdeas;
use App\Domain\CompanyHub\Services\HubPublishing;
use App\Domain\Hr\Models\HrCalendarEvent;
use App\Domain\Hr\Services\HrCalendarAggregator;
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
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Notification::fake();
    $this->travelTo(Carbon::parse('2026-10-14T00:00:00Z'));
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    $this->editor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->editor->roles()->attach(Role::where('name', 'hr')->sole());
    $this->site = ensureCanonicalHrStaffProfile($this->editor);
    $this->reader = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->reader->roles()->attach(Role::where('name', 'support_worker')->sole());
    ensureCanonicalHrStaffProfile($this->reader, $this->site);
    $this->publishing = app(HubPublishing::class);
    $this->pageWithPoll = function (string $kind = 'news', string $blockId = 'current-poll'): array {
        $page = $this->publishing->create($this->editor, $kind, 'Our shared poll');
        $content = $page->draft;
        $content['sections'] = [['id' => 'main', 'columns' => 1, 'blocks' => [
            ['id' => 'intro', 'type' => 'text', 'title' => 'Introduction', 'body' => 'A complete introduction for the shared poll.'],
            ['id' => $blockId, 'type' => 'poll', 'title' => 'Have your say', 'question' => 'Which shared activity?', 'options' => ['Walk', 'Lunch']],
        ]]];
        $page = $this->publishing->save($this->editor, $page, $content, 1);
        $revision = $this->publishing->publish($this->editor, $page, 2, null, null);

        return [$page, $revision];
    };
    $this->event = function (array $attributes = []): HrCalendarEvent {
        $event = HrCalendarEvent::create([
            'created_by' => $this->editor->id, 'title' => 'Our shared gathering', 'description' => 'Private operational notes are not aggregate data.',
            'event_type' => 'company', 'site_id' => $this->site->id,
            'starts_at' => now()->addWeek()->setTime(2, 0), 'ends_at' => now()->addWeek()->setTime(3, 0), ...$attributes,
        ]);
        $event->forceFill(['registration_enabled' => true])->save();

        return $event;
    };
});

test('insights link visible Ideas and the exact current poll without disclosing individual participation', function () {
    [$page] = ($this->pageWithPoll)();
    $ideas = app(HubIdeas::class);
    $idea = $ideas->create($this->reader, ['title' => 'A clearer welcome kit', 'body' => 'Discussion content stays outside aggregate insights.', 'category' => 'Learning']);
    $hidden = $ideas->create($this->reader, ['title' => 'Hidden suggestion', 'body' => 'Hidden discussion.', 'category' => 'Learning']);
    DB::table('hub_ideas')->where('id', $hidden)->update(['hidden_at' => now()]);
    $ideas->mutate($this->reader, $idea, ['action' => 'support', 'active' => true]);
    $response = $this->actingAs($this->editor)->getJson('/company-hub/editor/insights?days=7')->assertOk()
        ->assertJsonPath('ideas.0.url', '/company-hub?view=ideas&idea='.$idea)
        ->assertJsonPath('ideas.0.support_count', 1)->assertJsonPath('polls.0.response_count', null)
        ->assertJsonPath('polls.0.responses_in_period', null)->assertJsonPath('polls.0.url', '/company-hub/read/'.$page->slug.'#block-current-poll')
        ->assertDontSee('Hidden suggestion')->assertDontSee('Discussion content stays outside')->assertDontSee('user_id')->assertDontSee($this->reader->name);
    $this->get(explode('#', $response->json('polls.0.url'))[0])->assertOk()->assertInertia(fn (Assert $reader) => $reader
        ->where('selectedPage.id', $page->id)->where('selectedPage.content.sections.0.blocks.1.id', 'current-poll'));
    $this->get($response->json('ideas.0.url'))->assertOk()->assertInertia(fn (Assert $reader) => $reader
        ->where('ideaId', $idea)->where('selectedIdea.id', $idea)->where('ideaUnavailable', false));
    Notification::assertNothingSent();
});

test('poll insight links stay on the published snapshot and disappear with hidden expired archived or inaccessible polls', function () {
    [$page, $revision] = ($this->pageWithPoll)();
    $draft = $page->draft;
    $draft['sections'][0]['blocks'][1]['id'] = 'private-draft-poll';
    $draft['sections'][0]['blocks'][1]['question'] = 'Unpublished question must remain private';
    $page = $this->publishing->save($this->editor, $page, $draft, 2);
    $this->actingAs($this->editor)->getJson('/company-hub/editor/insights?days=7')->assertOk()
        ->assertJsonPath('polls.0.block_id', 'current-poll')->assertJsonPath('polls.0.url', '/company-hub/read/'.$page->slug.'#block-current-poll')
        ->assertDontSee('private-draft-poll')->assertDontSee('Unpublished question');
    $revision->update(['expires_at' => now()->subMinute()]);
    $this->getJson('/company-hub/editor/insights?days=7')->assertOk()->assertJsonPath('polls', []);
    $revision->update(['expires_at' => null]);
    $this->getJson('/company-hub/editor/insights?days=7')->assertOk()->assertJsonPath('polls.0.block_id', 'current-poll');
    $page->update(['archived_at' => now()]);
    $this->getJson('/company-hub/editor/insights?days=7')->assertOk()->assertJsonPath('polls', []);
    $page->update(['archived_at' => null]);
    $draft['sections'][0]['blocks'][1]['hidden'] = true;
    $this->publishing->save($this->editor, $page, $draft, 3);
    $this->publishing->publish($this->editor, $page->fresh(), 4, null, null);
    $this->getJson('/company-hub/editor/insights?days=7')->assertOk()->assertJsonPath('polls', []);

    [$scopedPage] = ($this->pageWithPoll)();
    $outside = Site::factory()->create(['is_active' => true, 'archived' => false]);
    $outsidePublisher = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $outsidePublisher->roles()->attach(Role::where('name', 'hr')->sole());
    ensureCanonicalHrStaffProfile($outsidePublisher, $outside);
    $outsideDraft = [...$scopedPage->draft, 'audience' => ['type' => 'sites', 'ids' => [$outside->id]]];
    $scopedPage = $this->publishing->save($outsidePublisher, $scopedPage, $outsideDraft, 2);
    $this->publishing->publish($outsidePublisher, $scopedPage, 3, null, null);
    // Editors may edit a broader draft while its immutable live edition remains private.
    $this->publishing->save($outsidePublisher, $scopedPage, [...$outsideDraft, 'audience' => ['type' => 'all', 'ids' => []]], 3);
    $this->getJson('/company-hub/editor/insights?days=7')->assertOk()->assertJsonPath('polls', []);
    expect($revision->fresh()->content['sections'][0]['blocks'][1]['id'])->toBe('current-poll');
    Notification::assertNothingSent();
});

test('Home poll insights link the current Home block rather than a different reading page', function () {
    [$page] = ($this->pageWithPoll)('home', 'home-poll');
    $this->actingAs($this->editor)->getJson('/company-hub/editor/insights?days=7')->assertOk()
        ->assertJsonPath('polls.0.page_id', $page->id)->assertJsonPath('polls.0.url', '/company-hub#block-home-poll');
    $this->get('/company-hub')->assertOk()->assertInertia(fn (Assert $reader) => $reader
        ->where('home.sections.0.blocks.1.id', 'home-poll'));
    Notification::assertNothingSent();
});

test('event insight links retain exact recurring occurrence keys including moved children', function () {
    $event = ($this->event)(['rrule' => 'FREQ=WEEKLY;COUNT=2']);
    $originalDate = $event->starts_at->toDateString();
    $movedStart = $event->starts_at->copy()->addDays(2);
    $child = HrCalendarEvent::create([
        ...$event->only(['created_by', 'event_type', 'site_id']), 'title' => 'Moved shared gathering',
        'starts_at' => $movedStart, 'ends_at' => $movedStart->copy()->addHour(),
        'recurrence_parent_id' => $event->id, 'is_exception' => true, 'exception_date' => $originalDate,
    ]);
    $response = $this->actingAs($this->editor)->getJson('/company-hub/editor/insights?days=7')->assertOk()->assertJsonCount(2, 'events')
        ->assertDontSee('Private operational notes')->assertDontSee('attendeeUserIds')->assertDontSee('user_id');
    $rows = collect($response->json('events'));
    expect($rows->firstWhere('id', 'event-'.$child->id)['url'])->toBe('/company-hub/events/'.$child->id.'?date='.$originalDate);
    $nextDate = $event->starts_at->copy()->addWeek()->toDateString();
    expect($rows->firstWhere('id', 'event-'.$event->id.'-'.$nextDate)['url'])->toBe('/company-hub/events/'.$event->id.'?date='.$nextDate);
    $this->get($rows->firstWhere('id', 'event-'.$child->id)['url'])->assertOk()->assertInertia(fn (Assert $reader) => $reader
        ->where('selectedEvent.extendedProps.eventId', $child->id)->where('selectedEvent.extendedProps.registration.date', $originalDate)
        ->where('selectedEvent.start', $movedStart->toIso8601String()));
    Notification::assertNothingSent();
});

test('an editable aggregate row cannot create a reader link for private archived missing or out of site events', function () {
    $this->reader->permissionOverrides()->syncWithoutDetaching([Permission::where('key', 'company_hub.write')->sole()->id => ['allowed' => true]]);
    $private = ($this->event)();
    $private->attendees()->create(['audience_type' => 'person', 'user_id' => $this->editor->id, 'rsvp_status' => 'none']);
    $archived = ($this->event)(['created_by' => $this->reader->id, 'archived_at' => now()]);
    $outside = ($this->event)(['site_id' => Site::factory()->create(['is_active' => true, 'archived' => false])->id]);
    $rows = collect([$private, $archived, $outside])->map(fn ($event) => [
        'id' => 'event-'.$event->id, 'title' => 'An existing managed aggregate', 'start' => $event->starts_at->toIso8601String(), 'editable' => true,
        'extendedProps' => ['eventId' => $event->id, 'registration' => ['enabled' => true, 'date' => $event->starts_at->toDateString(), 'going' => 0, 'waiting' => 0, 'capacity' => null]],
    ])->all();
    $rows[] = [...$rows[0], 'id' => 'event-2147483647', 'extendedProps' => ['eventId' => 2147483647, 'registration' => $rows[0]['extendedProps']['registration']]];
    $this->mock(HrCalendarAggregator::class, fn ($mock) => $mock->shouldReceive('feed')->andReturn($rows));
    $response = $this->actingAs($this->reader->fresh())->getJson('/company-hub/editor/insights?days=7')->assertOk()->assertJsonCount(4, 'events');
    expect(array_column($response->json('events'), 'url'))->toBe([null, null, null, null]);
    Notification::assertNothingSent();
});

test('exact Idea links resolve outside the list and search limits without guessing hidden or missing selections', function () {
    $idea = app(HubIdeas::class)->create($this->reader, ['title' => 'The earlier authorised suggestion', 'body' => 'This remains a visible public idea.', 'category' => 'Learning']);
    $bulk = collect(range(1, 201))->map(fn ($index) => [
        'user_id' => $this->reader->id, 'title' => 'Recent shared suggestion '.$index, 'body' => 'A visible recent staff suggestion.',
        'category' => 'Learning', 'status' => 'open', 'version' => 1, 'created_at' => now(), 'updated_at' => now()->addSecond(),
    ])->all();
    DB::table('hub_ideas')->insert($bulk);
    $before = DB::table('hub_ideas')->where('id', $idea)->first();
    $this->actingAs($this->reader)->get('/company-hub?view=ideas&idea='.$idea.'&search=Recent')->assertOk()->assertInertia(fn (Assert $reader) => $reader
        ->has('ideas', 200)->where('ideaId', $idea)->where('selectedIdea.id', $idea)->where('selectedIdea.title', 'The earlier authorised suggestion')
        ->where('ideaUnavailable', false)->where('ideas', fn ($rows) => ! collect($rows)->contains('id', $idea)));
    DB::table('hub_ideas')->where('id', $idea)->update(['hidden_at' => now()]);
    foreach ([$idea, 2147483647] as $id) {
        $this->get('/company-hub?view=ideas&idea='.$id)->assertOk()->assertInertia(fn (Assert $reader) => $reader
            ->where('ideaId', null)->where('selectedIdea', null)->where('ideaUnavailable', true))
            ->assertDontSee('The earlier authorised suggestion')->assertDontSee('This remains a visible public idea.');
    }
    $this->getJson('/company-hub?view=ideas&idea=2147483648')->assertUnprocessable()->assertJsonValidationErrors('idea');
    $this->getJson('/company-hub?view=ideas&idea=-1')->assertUnprocessable()->assertJsonValidationErrors('idea');
    $this->reader->hrEmployeeProfile()->update(['end_date' => today()->subDay()]);
    $this->get('/company-hub?view=ideas&idea='.$idea)->assertForbidden();
    expect(DB::table('hub_ideas')->count())->toBe(202)
        ->and(DB::table('hub_ideas')->where('id', $idea)->first()->body)->toBe($before->body);
    Notification::assertNothingSent();
});
