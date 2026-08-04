<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteCalendarEvent;
use App\Models\SiteCalendarEventException;
use App\Models\User;
use App\Services\Sites\SiteCalendarService;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

function calendarManager(Site $site): User
{
    $user = User::factory()->create(['role' => 'team_lead', 'approved_at' => now()]);
    $role = Role::query()->where('name', 'team_lead')->first();
    if ($role) {
        $user->roles()->syncWithoutDetaching([$role->id]);
    }

    HrEmployeeProfile::query()->create([
        'user_id' => $user->id,
        'employee_number' => 'EMP-CALWF-'.$user->id,
        'work_email' => $user->email,
        'position_title' => 'Team Lead',
        'position_role' => 'team_lead',
        'employment_type' => 'full_time',
        'start_date' => now()->subMonth()->toDateString(),
        'is_active' => true,
        'primary_site_id' => $site->id,
        'secondary_site_ids' => [],
    ]);

    return $user;
}

/**
 * @return array{hidden: User, ended: User, unapproved: User, portal: User}
 */
function calendarIneligiblePeople(Site $hiddenSite): array
{
    $hidden = User::factory()->create(['role' => 'team_lead', 'approved_at' => now()]);
    HrEmployeeProfile::query()->create([
        'user_id' => $hidden->id,
        'employee_number' => 'EMP-CALWF-HIDDEN-'.$hidden->id,
        'work_email' => $hidden->email,
        'position_title' => 'Hidden Site Lead',
        'position_role' => 'team_lead',
        'employment_type' => 'full_time',
        'start_date' => now()->subMonth()->toDateString(),
        'is_active' => true,
        'primary_site_id' => $hiddenSite->id,
        'secondary_site_ids' => [],
    ]);

    $ended = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    HrEmployeeProfile::query()->create([
        'user_id' => $ended->id,
        'employee_number' => 'EMP-CALWF-ENDED-'.$ended->id,
        'work_email' => $ended->email,
        'position_title' => 'Former Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subYear()->toDateString(),
        'end_date' => now()->subDay()->toDateString(),
        'is_active' => true,
        'primary_site_id' => $hiddenSite->id,
        'secondary_site_ids' => [],
    ]);

    $unapproved = User::factory()->create(['role' => 'support_worker', 'approved_at' => null]);
    HrEmployeeProfile::query()->create([
        'user_id' => $unapproved->id,
        'employee_number' => 'EMP-CALWF-UNAPPROVED-'.$unapproved->id,
        'work_email' => $unapproved->email,
        'position_title' => 'Pending Support Worker',
        'position_role' => 'support_worker',
        'employment_type' => 'full_time',
        'start_date' => now()->subMonth()->toDateString(),
        'is_active' => true,
        'primary_site_id' => $hiddenSite->id,
        'secondary_site_ids' => [],
    ]);

    $portal = User::factory()->create(['role' => 'client', 'approved_at' => now()]);

    return compact('hidden', 'ended', 'unapproved', 'portal');
}

test('an approver can approve a pending event', function () {
    $site = Site::factory()->create(['type' => 'house']);
    $user = calendarManager($site);

    $event = SiteCalendarEvent::create([
        'site_id' => $site->id,
        'event_type' => 'maintenance',
        'title' => 'Boiler service',
        'start_at' => Carbon::parse('2026-05-20 10:00'),
        'created_by_user_id' => $user->id,
        'status' => 'draft',
        'approval_status' => 'pending',
    ]);

    $this->actingAs($user)
        ->post("/sites/{$site->id}/calendar/events/{$event->id}/approve")
        ->assertRedirect();

    $event->refresh();
    expect($event->approval_status)->toBe('approved');
    expect($event->status)->toBe('approved');
    expect($event->approved_by_user_id)->toBe($user->id);
});

test('a partial update reschedules without touching other fields', function () {
    $site = Site::factory()->create(['type' => 'house']);
    $user = calendarManager($site);

    $event = SiteCalendarEvent::create([
        'site_id' => $site->id,
        'event_type' => 'general',
        'title' => 'House meeting',
        'start_at' => Carbon::parse('2026-05-10 09:00'),
        'end_at' => Carbon::parse('2026-05-10 10:00'),
        'created_by_user_id' => $user->id,
        'status' => 'approved',
        'approval_status' => 'not_required',
    ]);

    $this->actingAs($user)
        ->put("/sites/{$site->id}/calendar/events/{$event->id}", [
            'start_at' => '2026-05-12T14:00:00',
            'end_at' => '2026-05-12T15:00:00',
        ])
        ->assertRedirect();

    $event->refresh();
    $tz = config('app.worker_timezone', 'Pacific/Auckland');
    // The 14:00 entered is business-timezone wall-clock; it is stored as UTC.
    expect($event->start_at->copy()->timezone($tz)->format('Y-m-d H:i'))->toBe('2026-05-12 14:00');
    expect($event->end_at->copy()->timezone($tz)->format('Y-m-d H:i'))->toBe('2026-05-12 15:00');
    expect($event->start_at->copy()->utc()->format('Y-m-d H:i'))->toBe('2026-05-12 02:00');
    expect($event->title)->toBe('House meeting');
});

test('a single-occurrence override moves only that occurrence', function () {
    $site = Site::factory()->create(['type' => 'house']);
    $tz = config('app.worker_timezone', 'Pacific/Auckland');

    // Seed the way the controller stores: business-tz wall-clock converted to UTC
    // (->utc(), since Eloquent formats a Carbon in its own zone without converting).
    // An afternoon NZ start keeps the stored UTC date on the same calendar day as
    // the NZ date, so exception-date keying lines up.
    $event = SiteCalendarEvent::create([
        'site_id' => $site->id,
        'event_type' => 'general',
        'title' => 'Weekly meeting',
        'start_at' => Carbon::parse('2026-05-04 14:00', $tz)->utc(),
        'end_at' => Carbon::parse('2026-05-04 15:00', $tz)->utc(),
        'recurrence_rule' => 'FREQ=WEEKLY',
        'created_by_user_id' => User::factory()->create()->id,
        'status' => 'approved',
        'approval_status' => 'not_required',
    ]);

    SiteCalendarEventException::create([
        'parent_event_id' => $event->id,
        'exception_date' => '2026-05-11',
        'is_cancelled' => false,
        'overridden_fields' => [
            'start_at' => '2026-05-11T16:00:00',
            'end_at' => '2026-05-11T17:00:00',
        ],
    ]);

    $occurrences = app(SiteCalendarService::class)->getEventsForRange(
        [$site->id],
        null,
        Carbon::parse('2026-05-01'),
        Carbon::parse('2026-05-31'),
    );

    // The overridden occurrence reads back at its business-timezone 16:00.
    $moved = collect($occurrences)->first(
        fn ($o) => Carbon::parse($o['start_at'])->timezone($tz)->format('Y-m-d H:i') === '2026-05-11 16:00'
    );
    expect($moved)->not->toBeNull();

    // The un-overridden occurrences keep the series' 14:00 NZ start.
    $normal = collect($occurrences)->first(
        fn ($o) => Carbon::parse($o['start_at'])->timezone($tz)->format('Y-m-d H:i') === '2026-05-18 14:00'
    );
    expect($normal)->not->toBeNull();
});

test('creating then editing an event keeps its business-timezone time (no +12h drift)', function () {
    $site = Site::factory()->create(['type' => 'house']);
    $user = calendarManager($site);
    $tz = config('app.worker_timezone', 'Pacific/Auckland');

    // Create at 10:00–12:00 NZ (June is NZST, UTC+12).
    $this->actingAs($user)
        ->post("/sites/{$site->id}/calendar/events", [
            'event_type' => 'general',
            'title' => 'Morning handover',
            'start_at' => '2026-06-05T10:00',
            'end_at' => '2026-06-05T12:00',
        ])
        ->assertRedirect();

    $event = SiteCalendarEvent::where('site_id', $site->id)->latest('id')->first();
    expect($event)->not->toBeNull();
    // Stored as the correct UTC instant, reads back as 10:00 NZ — not 10pm.
    expect($event->start_at->copy()->timezone($tz)->format('Y-m-d H:i'))->toBe('2026-06-05 10:00');
    expect($event->start_at->copy()->utc()->format('Y-m-d H:i'))->toBe('2026-06-04 22:00');

    // The JSON feed the calendar renders from also reads 10:00 NZ.
    $feed = $this->actingAs($user)
        ->getJson("/sites/{$site->id}/calendar/events?start=2026-06-01T00:00:00Z&end=2026-06-30T00:00:00Z")
        ->assertOk()
        ->json('events');

    $item = collect($feed)->firstWhere('title', 'Morning handover');
    expect($item)->not->toBeNull();
    expect(Carbon::parse($item['start'])->timezone($tz)->format('Y-m-d H:i'))->toBe('2026-06-05 10:00');

    // Edit re-submits the pre-filled wall-clock (10:00 NZ) plus a new title —
    // the stored instant must NOT move (the compounding-drift symptom).
    $this->actingAs($user)
        ->put("/sites/{$site->id}/calendar/events/{$event->id}", [
            'event_type' => 'general',
            'title' => 'Morning handover (updated)',
            'start_at' => '2026-06-05T10:00',
            'end_at' => '2026-06-05T12:00',
        ])
        ->assertRedirect();

    $event->refresh();
    expect($event->title)->toBe('Morning handover (updated)');
    expect($event->start_at->copy()->timezone($tz)->format('Y-m-d H:i'))->toBe('2026-06-05 10:00');
    expect($event->start_at->copy()->utc()->format('Y-m-d H:i'))->toBe('2026-06-04 22:00');
});

test('creating an event rejects forged owners and attendees outside the current Site staff boundary', function () {
    $visibleSite = Site::factory()->create(['type' => 'house']);
    $hiddenSite = Site::factory()->create(['type' => 'house']);
    $manager = calendarManager($visibleSite);
    $people = calendarIneligiblePeople($hiddenSite);

    $this->actingAs($manager)
        ->from("/sites/{$visibleSite->id}/calendar")
        ->post("/sites/{$visibleSite->id}/calendar/events", [
            'event_type' => 'general',
            'title' => 'Forged recipient event',
            'start_at' => '2026-06-05T10:00',
            'owner_user_id' => $people['hidden']->id,
            'attendee_user_ids' => [
                $people['hidden']->id,
                $people['ended']->id,
                $people['unapproved']->id,
                $people['portal']->id,
            ],
        ])
        ->assertRedirect("/sites/{$visibleSite->id}/calendar")
        ->assertSessionHasErrors([
            'owner_user_id',
            'attendee_user_ids.0',
            'attendee_user_ids.1',
            'attendee_user_ids.2',
            'attendee_user_ids.3',
        ]);

    expect(SiteCalendarEvent::query()->where('title', 'Forged recipient event')->exists())->toBeFalse();
});

test('updating an event rejects forged owners and attendees outside the current Site staff boundary', function () {
    $visibleSite = Site::factory()->create(['type' => 'house']);
    $hiddenSite = Site::factory()->create(['type' => 'house']);
    $manager = calendarManager($visibleSite);
    $people = calendarIneligiblePeople($hiddenSite);

    $event = SiteCalendarEvent::query()->create([
        'site_id' => $visibleSite->id,
        'event_type' => 'general',
        'title' => 'Protected event',
        'start_at' => Carbon::parse('2026-06-05 10:00'),
        'created_by_user_id' => $manager->id,
        'owner_user_id' => $manager->id,
        'attendee_user_ids' => [$manager->id],
        'status' => 'approved',
        'approval_status' => 'not_required',
    ]);

    $this->actingAs($manager)
        ->from("/sites/{$visibleSite->id}/calendar")
        ->put("/sites/{$visibleSite->id}/calendar/events/{$event->id}", [
            'owner_user_id' => $people['hidden']->id,
            'attendee_user_ids' => [
                $people['hidden']->id,
                $people['ended']->id,
                $people['unapproved']->id,
                $people['portal']->id,
            ],
        ])
        ->assertRedirect("/sites/{$visibleSite->id}/calendar")
        ->assertSessionHasErrors([
            'owner_user_id',
            'attendee_user_ids.0',
            'attendee_user_ids.1',
            'attendee_user_ids.2',
            'attendee_user_ids.3',
        ]);

    $event->refresh();
    expect($event->owner_user_id)->toBe($manager->id)
        ->and($event->attendee_user_ids)->toBe([$manager->id]);
});

test('a manual event persists and round-trips its room/location', function () {
    $site = Site::factory()->create(['type' => 'house']);
    $user = calendarManager($site);

    $this->actingAs($user)
        ->post("/sites/{$site->id}/calendar/events", [
            'event_type' => 'general',
            'title' => 'Lounge games',
            'room' => 'Main Lounge',
            'start_at' => '2026-06-10T14:00',
            'end_at' => '2026-06-10T15:00',
        ])
        ->assertRedirect();

    $event = SiteCalendarEvent::where('site_id', $site->id)->latest('id')->first();
    expect($event->room)->toBe('Main Lounge');

    // The room reaches the rendered feed (formatOccurrence → CalendarItem).
    $feed = $this->actingAs($user)
        ->getJson("/sites/{$site->id}/calendar/events?start=2026-06-01T00:00:00Z&end=2026-06-30T00:00:00Z")
        ->assertOk()
        ->json('events');

    $item = collect($feed)->firstWhere('title', 'Lounge games');
    expect($item['room'])->toBe('Main Lounge');
});

test('a recurring event can carry an UNTIL end date in its rule', function () {
    $site = Site::factory()->create(['type' => 'house']);
    $user = calendarManager($site);

    $this->actingAs($user)
        ->post("/sites/{$site->id}/calendar/events", [
            'event_type' => 'general',
            'title' => 'Weekly catch-up',
            'start_at' => '2026-06-01T09:00',
            'end_at' => '2026-06-01T09:30',
            'recurrence_rule' => 'FREQ=WEEKLY;UNTIL=20260630T000000Z',
        ])
        ->assertRedirect();

    $event = SiteCalendarEvent::where('site_id', $site->id)->latest('id')->first();
    expect($event->recurrence_rule)->toContain('UNTIL=20260630T000000Z');

    // Expansion stops at the UNTIL bound — no July occurrences.
    $july = $this->actingAs($user)
        ->getJson("/sites/{$site->id}/calendar/events?start=2026-07-01T00:00:00Z&end=2026-07-31T00:00:00Z")
        ->assertOk()
        ->json('events');
    expect(collect($july)->where('title', 'Weekly catch-up'))->toBeEmpty();
});
