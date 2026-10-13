<?php

use App\Domain\Hr\Models\HrCalendarEvent;
use App\Domain\Hr\Models\HrDepartment;
use App\Domain\Hr\Notifications\CalendarReminderNotification;
use App\Domain\Hr\Services\HrCalendarReminderDelivery;
use App\Domain\Hr\Services\HrCalendarReminderOccurrences;
use App\Domain\Hr\Services\HrCalendarReminderPreferences;
use App\Domain\Hr\Services\HrCalendarReminderRecipients;
use App\Domain\Hr\Services\HrCalendarSeriesService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\RoleNotificationPreference;
use App\Models\Site;
use App\Models\User;
use App\Models\UserNotificationPreference;
use Database\Seeders\CompanyHubPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\Process\Process;
use Tests\Support\CommittedFixtureCleanup;

beforeEach(function () {
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    $this->editor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $role = Role::where('name', 'hr')->firstOrFail();
    $role->permissions()->syncWithoutDetaching(Permission::where('key', 'calendar.manage_recurring')->pluck('id'));
    $this->editor->roles()->attach($role);
    $this->site = ensureCanonicalHrStaffProfile($this->editor);
    $this->reader = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $this->reader->roles()->attach(Role::where('name', 'support_worker')->firstOrFail());
    ensureCanonicalHrStaffProfile($this->reader, $this->site);
    $this->travelTo(Carbon::parse('2026-10-13T00:00:00Z'));
    $this->event = HrCalendarEvent::create(['created_by' => $this->editor->id, 'title' => 'Private event details', 'event_type' => 'company', 'site_id' => $this->site->id,
        'starts_at' => now()->addMinutes(50), 'ends_at' => now()->addHours(2)]);
    $this->event->attendees()->create(['audience_type' => 'person', 'user_id' => $this->reader->id, 'rsvp_status' => 'yes']);
    $this->event->reminders()->create(['offset_minutes' => 60, 'channel' => 'notification']);
    $this->delivery = app(HrCalendarReminderDelivery::class);
    config(['mail.default' => 'smtp']);
    Notification::fake();
});

test('delayed runs recover due reminders exactly once per channel without exposing event details', function () {
    UserNotificationPreference::create(['user_id' => $this->reader->id, 'key' => 'hr.calendar.reminder', 'enabled' => true, 'channel_inapp' => true, 'channel_email' => true]);
    $this->event->reminders()->create(['offset_minutes' => 60, 'channel' => 'email']);
    $this->artisan('hr:dispatch-calendar-reminders')->assertSuccessful();
    $this->artisan('hr:dispatch-calendar-reminders')->assertSuccessful();
    Notification::assertSentToTimes($this->reader, CalendarReminderNotification::class, 2);
    Notification::assertSentTo($this->reader, CalendarReminderNotification::class, fn ($notice, $channels) => $channels === ['mail'] && ! str_contains(json_encode($notice->toMail($this->reader)->toArray()), 'Private event details'));
    Notification::assertSentTo($this->reader, CalendarReminderNotification::class, fn ($notice, $channels) => $channels === ['database']);
    Notification::assertNotSentTo($this->editor, CalendarReminderNotification::class);
    expect(DB::table(HrCalendarReminderDelivery::TABLE)->where('state', 'delivered')->count())->toBe(2);
});

test('channel preferences use a personal choice before role defaults and respect email off', function () {
    expect(app(HrCalendarReminderPreferences::class)->allows($this->reader, 'notification'))->toBeTrue()
        ->and(app(HrCalendarReminderPreferences::class)->allows($this->reader, 'email'))->toBeFalse();
    $this->event->reminders()->create(['offset_minutes' => 60, 'channel' => 'email']);
    RoleNotificationPreference::create(['role_id' => $this->reader->roles()->first()->id, 'key' => 'hr.calendar.reminder', 'enabled' => true, 'channel_inapp' => false, 'channel_email' => true]);
    $preferences = app(HrCalendarReminderPreferences::class);
    expect($preferences->allows($this->reader, 'notification'))->toBeFalse()->and($preferences->allows($this->reader, 'email'))->toBeTrue();
    UserNotificationPreference::create(['user_id' => $this->reader->id, 'key' => 'hr.calendar.reminder', 'enabled' => true, 'channel_inapp' => true, 'channel_email' => false]);
    expect($preferences->allows($this->reader, 'notification'))->toBeTrue()->and($preferences->allows($this->reader, 'email'))->toBeFalse();
    $this->delivery->stage(now());
    $this->delivery->deliver(now());
    Notification::assertSentToTimes($this->reader, CalendarReminderNotification::class, 1);
    Notification::assertSentTo($this->reader, CalendarReminderNotification::class, fn ($notice, $channels) => $channels === ['database']);
    $this->reader->update(['email_digest_frequency' => 'off']);
    expect($preferences->allows($this->reader->fresh(), 'email'))->toBeFalse();
});

test('settings do not display channels from a disabled reminder role preference', function () {
    RoleNotificationPreference::create(['role_id' => $this->reader->roles()->first()->id, 'key' => 'hr.calendar.reminder', 'enabled' => false, 'channel_inapp' => true, 'channel_email' => true, 'channel_push' => true]);
    $this->actingAs($this->reader)->get('/settings/notifications')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('settings/notifications')
        ->where('roleDefaults', fn ($defaults) => $defaults['hr.calendar.reminder'] === ['enabled' => false, 'inapp' => false, 'email' => false, 'push' => false]));
    expect(app(HrCalendarReminderPreferences::class)->allows($this->reader, 'notification'))->toBeFalse()
        ->and(app(HrCalendarReminderPreferences::class)->allows($this->reader, 'email'))->toBeFalse();
});

test('group recipients follow actual site department and team membership without adding managers', function () {
    $department = HrDepartment::create(['name' => 'Learning', 'is_active' => true]);
    $this->reader->hrEmployeeProfile->update(['department_id' => $department->id, 'team' => 'Learning team']);
    $outside = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    $outside->roles()->attach(Role::where('name', 'support_worker')->firstOrFail());
    ensureCanonicalHrStaffProfile($outside, Site::factory()->create(['is_active' => true, 'archived' => false]));
    $this->event->update(['department_id' => $department->id]);
    foreach ([['department', (string) $department->id], ['team', 'learning TEAM'], ['site', (string) $this->site->id], ['org', null]] as [$type, $ref]) {
        $this->event->attendees()->delete();
        $this->event->attendees()->create(['audience_type' => $type, 'audience_ref' => $ref]);
        $ids = app(HrCalendarReminderRecipients::class)->recipients($this->event->fresh(), $this->event->fresh(), now()->toDateString())->pluck('id')->all();
        expect($ids)->toContain($this->reader->id)->not->toContain($outside->id);
        if (in_array($type, ['department', 'team'])) {
            expect($ids)->not->toContain($this->editor->id);
        }
    }
    $this->reader->hrEmployeeProfile->update(['is_active' => false]);
    expect(app(HrCalendarReminderRecipients::class)->recipients($this->event->fresh(), $this->event->fresh(), now()->toDateString())->pluck('id')->all())->not->toContain($this->reader->id);
});

test('registration reminders exclude waiting and declined people and revoked access is checked at delivery', function () {
    $this->event->forceFill(['registration_enabled' => true])->save();
    foreach (['waiting', 'declined', 'going', 'maybe'] as $status) {
        DB::table('hr_calendar_registrations')->updateOrInsert(['event_id' => $this->event->id, 'user_id' => $this->reader->id, 'occurrence_date' => now()->toDateString()], ['status' => $status, 'created_at' => now(), 'updated_at' => now()]);
        expect(app(HrCalendarReminderRecipients::class)->eligible($this->reader->fresh(), $this->event->fresh(), $this->event->fresh(), now()->toDateString()))->toBe(in_array($status, ['going', 'maybe']));
    }
    $this->delivery->stage(now());
    $this->reader->hrEmployeeProfile->update(['is_active' => false]);
    expect($this->delivery->deliver(now()))->toBe(0);
    Notification::assertNothingSent();
    expect(DB::table(HrCalendarReminderDelivery::TABLE)->value('state'))->toBe('skipped');
});

test('quiet time defers delivery and an ended event is not sent after quiet time', function () {
    $this->reader->update(['dnd_enabled' => true, 'dnd_until' => now()->addMinutes(5)]);
    $this->delivery->stage(now());
    expect($this->delivery->deliver(now()))->toBe(0);
    $this->travel(6)->minutes();
    expect($this->delivery->deliver(now()))->toBe(1);
    $this->event->reminders()->create(['offset_minutes' => 45, 'channel' => 'notification']);
    $this->reader->update(['dnd_enabled' => true, 'dnd_until' => now()->addDay()]);
    $this->delivery->stage(now());
    expect($this->delivery->deliver(now()))->toBe(0);
    $this->travel(3)->hours();
    expect($this->delivery->deliver(now()))->toBe(0);
    Notification::assertSentToTimes($this->reader, CalendarReminderNotification::class, 1);
    expect(DB::table(HrCalendarReminderDelivery::TABLE)->where('state', 'pending')->count())->toBe(0);
});

test('daily email bundles ready reminders at eight New Zealand time and weekly scheduling uses Monday', function () {
    UserNotificationPreference::create(['user_id' => $this->reader->id, 'key' => 'hr.calendar.reminder', 'enabled' => true, 'channel_inapp' => true, 'channel_email' => true]);
    $this->travelTo(Carbon::parse('2026-10-12T18:55:00Z'));
    $this->reader->update(['email_digest_frequency' => 'daily']);
    $this->event->update(['starts_at' => now()->addMinutes(50), 'ends_at' => now()->addHours(2)]);
    $this->event->reminders()->delete();
    $this->event->reminders()->create(['offset_minutes' => 60, 'channel' => 'email']);
    $second = $this->event->replicate();
    $second->save();
    $second->attendees()->create(['audience_type' => 'person', 'user_id' => $this->reader->id]);
    $second->reminders()->create(['offset_minutes' => 60, 'channel' => 'email']);
    $this->delivery->stage(now());
    expect($this->delivery->deliver(now()))->toBe(0);
    $this->travel(5)->minutes();
    expect($this->delivery->deliver(now()))->toBe(2);
    Notification::assertSentToTimes($this->reader, CalendarReminderNotification::class, 1);
    Notification::assertSentTo($this->reader, CalendarReminderNotification::class, fn ($notice, $channels) => $notice->count === 2 && $channels === ['mail']);
    $this->reader->update(['email_digest_frequency' => 'weekly']);
    expect(app(HrCalendarReminderPreferences::class)->availableAt($this->reader->fresh(), 'email', now(), now())->format('Y-m-d H:i:s'))->toBe('2026-10-18 19:00:00');
});

test('failed delivery remains retryable and uses a persisted receipt without blocking other recipients', function () {
    $this->event->attendees()->create(['audience_type' => 'person', 'user_id' => $this->editor->id, 'rsvp_status' => 'yes']);
    $this->delivery->stage(now());
    Notification::shouldReceive('sendNow')->once()->withArgs(fn ($user) => $user->id === $this->reader->id)->andThrow(new RuntimeException('Transport unavailable'));
    Notification::shouldReceive('sendNow')->once()->withArgs(fn ($user) => $user->id === $this->editor->id)->andReturnNull();
    expect($this->delivery->deliver(now()))->toBe(1);
    $row = DB::table(HrCalendarReminderDelivery::TABLE)->where('user_id', $this->reader->id)->sole();
    expect($row->state)->toBe('pending')->and($row->attempts)->toBe(1)->and($row->outcome)->toBe('delivery_failed');
    Notification::fake();
    $this->travel(2)->minutes();
    expect($this->delivery->deliver(now()))->toBe(1);
    Notification::assertSentTo($this->reader, CalendarReminderNotification::class, fn ($notice) => $notice->id === $row->receipt_id);
    expect(DB::table(HrCalendarReminderDelivery::TABLE)->where('user_id', $this->reader->id)->value('attempts'))->toBe(2);
});

test('unconfigured email remains pending instead of reporting successful external delivery', function () {
    UserNotificationPreference::create(['user_id' => $this->reader->id, 'key' => 'hr.calendar.reminder', 'enabled' => true, 'channel_inapp' => true, 'channel_email' => true]);
    config(['mail.default' => 'log']);
    $this->event->reminders()->delete();
    $this->event->reminders()->create(['offset_minutes' => 60, 'channel' => 'email']);
    $this->delivery->stage(now());
    expect($this->delivery->deliver(now()))->toBe(0);
    expect(DB::table(HrCalendarReminderDelivery::TABLE)->value('outcome'))->toBe('email_not_configured');
    Notification::assertNothingSent();
});

test('email configuration rejects silent logging fallbacks and cyclic mailer chains', function () {
    $preferences = app(HrCalendarReminderPreferences::class);
    config(['mail.default' => 'failover', 'mail.mailers.failover' => ['transport' => 'failover', 'mailers' => ['smtp', 'log']]]);
    expect($preferences->emailConfigured())->toBeFalse();
    config(['mail.mailers.failover.mailers' => ['smtp', 'roundrobin'], 'mail.mailers.roundrobin' => ['transport' => 'roundrobin', 'mailers' => ['ses', 'postmark']]]);
    expect($preferences->emailConfigured())->toBeTrue();
    config(['mail.mailers.roundrobin.mailers' => ['failover']]);
    expect($preferences->emailConfigured())->toBeFalse();
});

test('recovery coalesces overdue offsets and uses local midnight for all day events', function () {
    $this->event->reminders()->create(['offset_minutes' => 1440, 'channel' => 'notification']);
    $this->delivery->stage(now());
    expect($this->delivery->deliver(now()))->toBe(1);
    expect(DB::table(HrCalendarReminderDelivery::TABLE)->where('state', 'skipped')->value('outcome'))->toBe('superseded_reminder');
    $this->event->update(['starts_at' => '2026-10-14 00:00:00', 'ends_at' => '2026-10-14 00:00:00', 'is_all_day' => true]);
    $rows = app(HrCalendarReminderOccurrences::class)->actionable($this->event->fresh(), 60, Carbon::parse('2026-10-13T10:00:00Z'));
    expect($rows)->toHaveCount(1)->and($rows[0]['start']->format('Y-m-d H:i:s'))->toBe('2026-10-13 11:00:00')->and($rows[0]['date'])->toBe('2026-10-14');
});

test('changed events and recipients invalidate already staged work', function (string $change) {
    $this->delivery->stage(now());
    expect(DB::table(HrCalendarReminderDelivery::TABLE)->where('state', 'pending')->count())->toBe(1);
    match ($change) {
        'removed reminder' => $this->event->reminders()->delete(),
        'archived event' => $this->event->update(['archived_at' => now()]),
        'moved event' => $this->event->update(['starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour()]),
        'changed site' => $this->event->update(['site_id' => Site::factory()->create(['is_active' => true, 'archived' => false])->id]),
        'declined invitation' => $this->event->attendees()->update(['rsvp_status' => 'no']),
    };
    expect($this->delivery->deliver(now()))->toBe(0);
    Notification::assertNothingSent();
    expect(DB::table(HrCalendarReminderDelivery::TABLE)->value('state'))->toBe('skipped');
})->with(['removed reminder', 'archived event', 'moved event', 'changed site', 'declined invitation']);

test('following series edits carry pending and delivered reminders without duplicate sends', function () {
    $this->event->update(['rrule' => 'FREQ=WEEKLY;COUNT=3', 'starts_at' => now()->subWeek()->addMinutes(50), 'ends_at' => now()->subWeek()->addHours(2)]);
    $this->delivery->stage(now());
    expect($this->delivery->deliver(now()))->toBe(1);
    $date = now()->toDateString();
    $next = app(HrCalendarSeriesService::class)->change($this->editor, $this->event->fresh(), $date, [], true);
    expect(DB::table(HrCalendarReminderDelivery::TABLE)->value('event_id'))->toBe($next->id);
    $this->delivery->stage(now());
    expect($this->delivery->deliver(now()))->toBe(0);
    Notification::assertSentToTimes($this->reader, CalendarReminderNotification::class, 1);
});

test('two independent reminder dispatchers produce one durable in app notice', function () {
    expect(DB::connection()->getDriverName())->toBe('mysql');
    $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
    $token = (string) Str::uuid();
    $go = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hub-reminder-go-'.$token;
    $ready = [sys_get_temp_dir().DIRECTORY_SEPARATOR.'hub-reminder-one-'.$token, sys_get_temp_dir().DIRECTORY_SEPARATOR.'hub-reminder-two-'.$token];
    $workers = [];
    $code = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
Illuminate\Support\Carbon::setTestNow(Illuminate\Support\Carbon::parse($argv[2], 'UTC'));
touch($argv[3]);
while (!is_file($argv[4])) { usleep(10000); }
$delivery = $app->make(App\Domain\Hr\Services\HrCalendarReminderDelivery::class);
$delivery->stage(now());
echo $delivery->deliver(now());
PHP;
    DB::connection()->commit();
    try {
        DB::connection()->beginTransaction();
        HrCalendarEvent::whereKey($this->event->id)->lockForUpdate()->firstOrFail();
        foreach ($ready as $path) {
            $worker = new Process([PHP_BINARY, '-r', $code, base_path(), now()->format('Y-m-d H:i:s'), $path, $go], base_path(),
                ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => DB::connection()->getDatabaseName(), 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array']);
            $worker->setTimeout(45);
            $worker->start();
            $workers[] = $worker;
        }
        $deadline = microtime(true) + 25;
        while (! is_file($ready[0]) || ! is_file($ready[1])) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Reminder workers did not reach their barrier.');
            }
            usleep(10000);
        }
        touch($go);
        usleep(250000);
        foreach ($workers as $worker) {
            expect($worker->isRunning())->toBeTrue();
        }
        DB::connection()->commit();
        $sent = [];
        foreach ($workers as $worker) {
            $worker->wait();
            expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());
            $sent[] = (int) $worker->getOutput();
        }
        sort($sent);
        expect($sent)->toBe([0, 1])->and(DB::table(HrCalendarReminderDelivery::TABLE)->where('state', 'delivered')->count())->toBe(1)
            ->and(DB::table('notifications')->where('notifiable_id', $this->reader->id)->where('type', CalendarReminderNotification::class)->count())->toBe(1);
    } finally {
        if (DB::connection()->transactionLevel() > 0) {
            DB::connection()->rollBack();
        }
        foreach ($workers as $worker) {
            if ($worker->isRunning()) {
                $worker->stop();
            }
        }
        foreach ([...$ready, $go] as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
})->group('mysql-serial');
