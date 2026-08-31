<?php

use App\Jobs\SendEventReminderJob;
use App\Models\Site;
use App\Models\SiteCalendarEvent;
use App\Models\User;
use App\Notifications\EventReminderNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * @param  array<string, mixed>  $overrides
 */
function siteCalendarReminderEvent(User $owner, array $overrides = []): SiteCalendarEvent
{
    $site = Site::factory()->create([
        'type' => 'house',
        'is_active' => true,
    ]);

    return SiteCalendarEvent::query()->create([
        'site_id' => $site->id,
        'event_type' => 'general',
        'title' => 'Medication room handover',
        'start_at' => now()->addHour(),
        'end_at' => now()->addHours(2),
        'timezone' => 'Pacific/Auckland',
        'created_by_user_id' => $owner->id,
        'owner_user_id' => $owner->id,
        'attendee_user_ids' => [],
        'status' => 'approved',
        'approval_status' => 'not_required',
        'reminder_minutes' => [60],
        ...$overrides,
    ]);
}

test('the cadence job sends only reminders whose trigger fell within the completed five minute window', function () {
    Carbon::setTestNow('2026-08-31 12:00:00');
    Notification::fake();

    $dueOwner = User::factory()->create();
    $earlyOwner = User::factory()->create();
    $due = siteCalendarReminderEvent($dueOwner);
    $early = siteCalendarReminderEvent($earlyOwner, [
        'title' => 'Early reminder must wait',
        'start_at' => now()->addMinutes(65),
    ]);

    (new SendEventReminderJob)->handle();

    Notification::assertSentToTimes($dueOwner, EventReminderNotification::class, 1);
    Notification::assertNotSentTo($earlyOwner, EventReminderNotification::class);
    expect($due->refresh()->last_reminder_sent_at?->equalTo(now()))->toBeTrue()
        ->and($early->refresh()->last_reminder_sent_at)->toBeNull();
});

test('zero minute reminders use the elapsed cadence window and exclude its exact prior boundary', function () {
    Carbon::setTestNow('2026-08-31 12:05:00');
    Notification::fake();

    $elapsedOwner = User::factory()->create();
    $boundaryOwner = User::factory()->create();
    $elapsed = siteCalendarReminderEvent($elapsedOwner, [
        'title' => 'Just-started event',
        'start_at' => now()->subMinutes(2),
        'reminder_minutes' => [0],
    ]);
    $boundary = siteCalendarReminderEvent($boundaryOwner, [
        'title' => 'Prior cadence boundary',
        'start_at' => now()->subMinutes(5),
        'reminder_minutes' => [0],
    ]);

    (new SendEventReminderJob)->handle();

    Notification::assertSentToTimes($elapsedOwner, EventReminderNotification::class, 1);
    Notification::assertNotSentTo($boundaryOwner, EventReminderNotification::class);
    expect($elapsed->refresh()->last_reminder_sent_at?->equalTo(now()->subMinutes(2)))->toBeTrue()
        ->and($boundary->refresh()->last_reminder_sent_at)->toBeNull();
});

test('distinct configured offsets advance chronologically without replaying a completed trigger', function () {
    Carbon::setTestNow('2026-08-31 12:00:00');
    Notification::fake();

    $owner = User::factory()->create();
    $event = siteCalendarReminderEvent($owner, [
        'reminder_minutes' => [10, 60],
    ]);

    (new SendEventReminderJob)->handle();
    (new SendEventReminderJob)->handle();

    Notification::assertSentToTimes($owner, EventReminderNotification::class, 1);
    Notification::assertSentTo(
        $owner,
        EventReminderNotification::class,
        fn (EventReminderNotification $notification): bool => $notification->toMail($owner)->subject
            === 'Reminder: Medication room handover in 60 minutes',
    );
    expect($event->refresh()->last_reminder_sent_at?->equalTo(now()))->toBeTrue();

    Carbon::setTestNow('2026-08-31 12:50:00');
    (new SendEventReminderJob)->handle();

    Notification::assertSentToTimes($owner, EventReminderNotification::class, 2);
    Notification::assertSentTo(
        $owner,
        EventReminderNotification::class,
        fn (EventReminderNotification $notification): bool => $notification->toMail($owner)->subject
            === 'Reminder: Medication room handover in 10 minutes',
    );
    expect($event->refresh()->last_reminder_sent_at?->equalTo(now()))->toBeTrue();
});

test('several triggers in one cadence window are delivered in chronological order', function () {
    Carbon::setTestNow('2026-08-31 12:05:00');
    Notification::fake();

    $owner = User::factory()->create();
    $event = siteCalendarReminderEvent($owner, [
        'start_at' => now()->addMinutes(5),
        'reminder_minutes' => [7, 9],
    ]);

    (new SendEventReminderJob)->handle();

    $subjects = Notification::sent($owner, EventReminderNotification::class)
        ->map(fn (EventReminderNotification $notification): string => $notification->toMail($owner)->subject)
        ->all();

    expect($subjects)->toBe([
        'Reminder: Medication room handover in 9 minutes',
        'Reminder: Medication room handover in 7 minutes',
    ])->and($event->refresh()->last_reminder_sent_at?->equalTo(now()->subMinutes(2)))->toBeTrue();

    (new SendEventReminderJob)->handle();

    Notification::assertSentToTimes($owner, EventReminderNotification::class, 2);
});

test('malformed numeric-looking offsets fail closed without suppressing a canonical offset', function () {
    Carbon::setTestNow('2026-08-31 12:05:00');
    Notification::fake();

    $owner = User::factory()->create();
    $event = siteCalendarReminderEvent($owner, [
        'start_at' => now()->addMinutes(2),
        'reminder_minutes' => [2.9, '1e2', str_repeat('9', 40), -1, '2'],
    ]);

    (new SendEventReminderJob)->handle();

    Notification::assertSentToTimes($owner, EventReminderNotification::class, 1);
    Notification::assertSentTo(
        $owner,
        EventReminderNotification::class,
        fn (EventReminderNotification $notification): bool => $notification->toMail($owner)->subject
            === 'Reminder: Medication room handover in 2 minutes',
    );
    expect($event->refresh()->last_reminder_sent_at?->equalTo(now()))->toBeTrue();
});

test('duplicate offsets and overlapping owner and attendee recipients send once each', function () {
    Carbon::setTestNow('2026-08-31 12:50:00');
    Notification::fake();

    $owner = User::factory()->create();
    $attendee = User::factory()->create();
    siteCalendarReminderEvent($owner, [
        'start_at' => now()->addMinutes(10),
        'reminder_minutes' => [10, 10],
        'attendee_user_ids' => [$owner->id, $attendee->id, $attendee->id],
    ]);

    (new SendEventReminderJob)->handle();

    Notification::assertSentToTimes($owner, EventReminderNotification::class, 1);
    Notification::assertSentToTimes($attendee, EventReminderNotification::class, 1);
});

test('events with no due deliverable reminder retain existing exclusion behavior', function (array $overrides) {
    Carbon::setTestNow('2026-08-31 12:00:00');
    Notification::fake();

    $owner = User::factory()->create();
    siteCalendarReminderEvent($owner, $overrides);

    (new SendEventReminderJob)->handle();

    Notification::assertNothingSent();
})->with([
    'cancelled' => [['status' => 'cancelled']],
    'completed' => [['status' => 'completed', 'completed_at' => now()]],
    'no reminder' => [['reminder_minutes' => null]],
    'no owner' => [['owner_user_id' => null, 'attendee_user_ids' => [1]]],
]);
