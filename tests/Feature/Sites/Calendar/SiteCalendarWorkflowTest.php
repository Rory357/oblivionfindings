<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\Role;
use App\Models\Site;
use App\Models\SiteCalendarEvent;
use App\Models\SiteCalendarEventException;
use App\Models\User;
use App\Services\Sites\SiteCalendarService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(\Database\Seeders\RbacSeeder::class);
});

function calendarManager(Site $site): User
{
    $user = User::factory()->create(['role' => 'team_lead', 'approved_at' => now()]);
    $role = Role::query()->where('name', 'team_lead')->first();
    if ($role) {
        $user->roles()->syncWithoutDetaching([$role->id]);
    }

    HrEmployeeProfile::query()->create([
        'tenant_id' => 1,
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

test('an approver can approve a pending event', function () {
    $site = Site::factory()->create(['type' => 'house']);
    $user = calendarManager($site);

    $event = SiteCalendarEvent::create([
        'site_id' => $site->id,
        'tenant_id' => $site->tenant_id,
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
        'tenant_id' => $site->tenant_id,
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
        'tenant_id' => $site->tenant_id,
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
