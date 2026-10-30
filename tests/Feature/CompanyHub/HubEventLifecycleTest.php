<?php

use App\Domain\Hr\Models\HrCalendarEvent;
use App\Domain\Hr\Notifications\CalendarPlaceAvailableNotification;
use App\Domain\Hr\Services\HrCalendarAggregator;
use App\Domain\Hr\Services\HrEventRegistrationService;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\CompanyHubPermissionsSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SeedHrPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\Support\CommittedFixtureCleanup;

beforeEach(function () {
    $this->seed([RbacSeeder::class, SeedHrPermissionsSeeder::class, CompanyHubPermissionsSeeder::class]);
    $this->editor = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $this->editor->roles()->attach(Role::where('name', 'hr')->firstOrFail());
    $this->editor->roles()->first()->permissions()->syncWithoutDetaching(Permission::where('key', 'calendar.manage_recurring')->pluck('id')->all());
    $this->site = ensureCanonicalHrStaffProfile($this->editor);
    $this->readers = collect(range(1, 2))->map(function () {
        $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
        $user->roles()->attach(Role::where('name', 'support_worker')->firstOrFail());
        ensureCanonicalHrStaffProfile($user, $this->site);

        return $user;
    });
    $this->event = HrCalendarEvent::create(['title' => 'Learning together', 'event_type' => 'company', 'site_id' => $this->site->id,
        'starts_at' => now()->addDays(3)->setTime(10, 0), 'ends_at' => now()->addDays(3)->setTime(11, 0), 'rrule' => 'FREQ=WEEKLY;COUNT=4', 'created_by' => $this->editor->id]);
    $this->date = $this->event->starts_at->toDateString();
    $this->registration = app(HrEventRegistrationService::class);
    $this->registration->configure($this->editor, $this->event, ['enabled' => true, 'capacity' => 1, 'version' => 1]);
    Notification::fake();
});

test('a promoted place has a stable queue position and exactly one private in-app receipt', function () {
    $this->registration->respond($this->readers[0], $this->event, $this->date, 'going');
    $waiting = $this->registration->respond($this->readers[1], $this->event, $this->date, 'going');
    expect($waiting['queue_position'])->toBe(1);
    $this->registration->respond($this->editor, $this->event, $this->date, 'going');
    $this->registration->respond($this->readers[1], $this->event, $this->date, 'going');
    expect($this->registration->summary($this->editor, $this->event, $this->date)['queue_position'])->toBe(2);
    $this->registration->respond($this->readers[0], $this->event, $this->date, 'declined');
    $this->registration->respond($this->readers[0], $this->event, $this->date, 'declined');
    Notification::assertSentToTimes($this->readers[1], CalendarPlaceAvailableNotification::class, 1);
    Notification::assertSentTo($this->readers[1], CalendarPlaceAvailableNotification::class, function ($notice, $channels) {
        expect($channels)->toBe(['database'])->and(json_encode($notice->toArray($this->readers[1])))->not->toContain('Learning together');

        return true;
    });
});

test('attendance is organiser-only occurrence-bound versioned and cannot be recorded early', function () {
    $this->registration->respond($this->readers[0], $this->event, $this->date, 'going');
    $this->registration->respond($this->readers[1], $this->event, $this->date, 'going');
    $row = DB::table('hr_calendar_registrations')->where('user_id', $this->readers[0]->id)->sole();
    $waiting = DB::table('hr_calendar_registrations')->where('user_id', $this->readers[1]->id)->sole();
    $url = '/company-hub/events/'.$this->event->id.'/attendance';
    $payload = ['date' => $this->date, 'registration_id' => $row->id, 'attendance' => 'attended', 'version' => $row->version];
    $this->actingAs($this->readers[0])->getJson($url.'?date='.$this->date)->assertNotFound();
    $this->putJson($url, $payload)->assertNotFound();
    $this->actingAs($this->editor)->putJson($url, $payload)->assertUnprocessable();
    $this->travelTo($this->event->starts_at->copy()->addHour());
    $this->getJson($url.'?date='.$this->date)->assertOk()->assertJsonPath('can_record', true)->assertJsonCount(2, 'registrations');
    $this->putJson($url, [...$payload, 'registration_id' => $waiting->id])->assertUnprocessable();
    $this->putJson($url, [...$payload, 'date' => $this->event->starts_at->copy()->addWeek()->toDateString()])->assertUnprocessable();
    $this->putJson($url, $payload)->assertOk()->assertJsonPath('registrations.0.attendance', 'attended');
    $this->putJson($url, [...$payload, 'attendance' => 'no_show'])->assertConflict();
    $this->putJson($url, [...$payload, 'attendance' => null, 'version' => 2])->assertOk()->assertJsonPath('registrations.0.attendance', null);
    $outside = User::factory()->create(['role' => 'hr', 'approved_at' => now()]);
    $outside->roles()->attach(Role::where('name', 'hr')->firstOrFail());
    ensureCanonicalHrStaffProfile($outside, Site::factory()->create());
    $this->actingAs($outside)->getJson($url.'?date='.$this->date)->assertNotFound();
});

test('splitting moves future registrations and exceptions while preserving count capacity reminders and independent files', function () {
    Storage::fake('private');
    Storage::disk('private')->put('calendar/original.txt', 'Event information');
    $file = $this->event->attachments()->create(['uploaded_by' => $this->editor->id, 'disk' => 'private', 'path' => 'calendar/original.txt', 'original_name' => 'agenda.txt', 'mime' => 'text/plain', 'size' => 17]);
    $this->event->attendees()->create(['audience_type' => 'org', 'rsvp_status' => 'none']);
    $this->event->reminders()->create(['offset_minutes' => 60, 'channel' => 'notification']);
    $split = $this->event->starts_at->copy()->addWeek();
    $later = $split->copy()->addWeek();
    $this->registration->respond($this->readers[0], $this->event, $this->date, 'going');
    $this->registration->respond($this->readers[0], $this->event, $split->toDateString(), 'going');
    $this->registration->respond($this->readers[1], $this->event, $split->toDateString(), 'going');
    $queueAt = DB::table('hr_calendar_registrations')->where('status', 'waiting')->value('queued_at');
    $child = HrCalendarEvent::create([...$this->event->only(['title', 'event_type', 'site_id', 'created_by']), 'starts_at' => $later->copy()->addHours(2), 'ends_at' => $later->copy()->addHours(3), 'recurrence_parent_id' => $this->event->id, 'is_exception' => true, 'exception_date' => $later]);
    $this->actingAs($this->editor)->put('/hr/calendar/events/'.$this->event->id, ['edit_version' => $this->event->fresh()->edit_version, 'scope' => 'following', 'occurrence_date' => $split->toDateString(), 'title' => 'Later learning dates', 'starts_at' => $split->copy()->addDay()->toIso8601String(), 'ends_at' => $split->copy()->addDay()->addHour()->toIso8601String()])->assertRedirect()->assertSessionHasNoErrors();
    $next = HrCalendarEvent::where('title', 'Later learning dates')->sole();
    expect($next->rrule)->toBe('FREQ=WEEKLY;COUNT=3')->and((int) $next->registration_capacity)->toBe(1)->and((bool) $next->registration_enabled)->toBeTrue();
    expect($child->fresh()->recurrence_parent_id)->toBe($next->id)->and($child->fresh()->exception_date->toDateString())->toBe($later->copy()->addDay()->toDateString());
    expect(DB::table('hr_calendar_registrations')->where('event_id', $next->id)->count())->toBe(2)->and(DB::table('hr_calendar_registrations')->where('event_id', $this->event->id)->count())->toBe(1);
    expect(DB::table('hr_calendar_registrations')->where('status', 'waiting')->value('queued_at'))->toBe($queueAt);
    expect($next->reminders()->count())->toBe(1)->and($next->attendees()->count())->toBe(1);
    $copy = $next->attachments()->sole();
    expect($copy->path)->not->toBe($file->path)->and(Storage::disk('private')->get($copy->path))->toBe('Event information');
    $feed = app(HrCalendarAggregator::class)->feed($this->date, $later->copy()->addWeeks(3)->toDateString(), ['event'], [], $this->readers[0]);
    expect($feed)->toHaveCount(4);
    // A request that bound the exception before the split must not write to its old root.
    try {
        $this->registration->respond($this->readers[0], $child, $later->toDateString(), 'going');
        $this->fail('A stale exception wrote a registration to the original series.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(409);
    }
    try {
        $this->registration->configure($this->editor, $child, ['enabled' => false, 'capacity' => 1, 'version' => $this->event->fresh()->registration_version]);
        $this->fail('A stale exception changed the original series settings.');
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(409);
    }
    expect((bool) $this->event->fresh()->registration_enabled)->toBeTrue();
});

test('invalid and destructive series edits are rejected without orphaning registrations', function () {
    $this->registration->respond($this->readers[0], $this->event, $this->event->starts_at->copy()->addWeeks(3)->toDateString(), 'going');
    $url = '/hr/calendar/events/'.$this->event->id;
    $this->actingAs($this->editor)->putJson($url, ['edit_version' => $this->event->fresh()->edit_version, 'scope' => 'following', 'occurrence_date' => $this->event->starts_at->copy()->addDay()->toDateString()])->assertUnprocessable();
    $this->putJson($url, ['edit_version' => $this->event->fresh()->edit_version, 'scope' => 'following', 'occurrence_date' => $this->date, 'rrule' => 'FREQ=WEEKLY;COUNT=1'])->assertUnprocessable();
    $this->putJson($url, ['edit_version' => $this->event->fresh()->edit_version, 'scope' => 'all', 'rrule' => null])->assertUnprocessable();
    expect(HrCalendarEvent::count())->toBe(1)->and(DB::table('hr_calendar_registrations')->where('event_id', $this->event->id)->count())->toBe(1);
});

test('one-date changes preserve private audiences and support independent reminders and attachments', function () {
    $this->event->attendees()->create(['audience_type' => 'person', 'user_id' => $this->readers[0]->id, 'rsvp_status' => 'none']);
    $this->event->reminders()->create(['offset_minutes' => 60, 'channel' => 'notification']);
    $url = '/hr/calendar/events/'.$this->event->id;
    $this->actingAs($this->editor)->putJson($url, ['edit_version' => $this->event->fresh()->edit_version, 'scope' => 'this', 'occurrence_date' => $this->date, 'audience_type' => 'org'])->assertUnprocessable();
    $this->putJson($url, ['edit_version' => $this->event->fresh()->edit_version, 'scope' => 'this', 'occurrence_date' => $this->date, 'site_id' => null])->assertUnprocessable();
    $this->put($url, ['edit_version' => $this->event->fresh()->edit_version, 'scope' => 'this', 'occurrence_date' => $this->date, 'title' => 'Specific date', 'reminders' => [['offset_minutes' => 15, 'channel' => 'notification']]])->assertRedirect()->assertSessionHasNoErrors();
    $child = HrCalendarEvent::whereNotNull('recurrence_parent_id')->sole();
    $child->attachments()->create(['uploaded_by' => $this->editor->id, 'disk' => 'private', 'path' => 'calendar/date.png', 'original_name' => 'date.png', 'mime' => 'image/png', 'size' => 1]);
    $feed = app(HrCalendarAggregator::class)->feed($this->date, $this->date, ['event'], [], $this->readers[0]);
    expect($feed)->toHaveCount(1)->and($feed[0]['extendedProps']['reminders'][0]['offset_minutes'])->toBe(15)->and($feed[0]['extendedProps']['attachments'])->toHaveCount(1);
    expect(app(HrCalendarAggregator::class)->feed($this->date, $this->date, ['event'], [], $this->readers[1]))->toBe([]);
    $child->update(['site_id' => Site::factory()->create()->id]);
    expect(app(HrCalendarAggregator::class)->feed($this->date, $this->date, ['event'], [], $this->readers[0]))->toBe([]);
});

test('two independent requests competing for the last place allocate one seat and one queue position', function () {
    expect(DB::connection()->getDriverName())->toBe('mysql');
    $this->beforeApplicationDestroyed(CommittedFixtureCleanup::capture()->restore(...));
    $token = Str::uuid()->toString();
    $go = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hub-seat-go-'.$token;
    $ready = [sys_get_temp_dir().DIRECTORY_SEPARATOR.'hub-seat-one-'.$token, sys_get_temp_dir().DIRECTORY_SEPARATOR.'hub-seat-two-'.$token];
    $workers = [];
    $code = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
$app = require $argv[1].'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
touch($argv[5]);
while (!is_file($argv[6])) { usleep(10000); }
$result = $app->make(App\Domain\Hr\Services\HrEventRegistrationService::class)->respond(App\Models\User::findOrFail($argv[2]), App\Domain\Hr\Models\HrCalendarEvent::findOrFail($argv[3]), $argv[4], 'going');
echo json_encode($result);
PHP;
    DB::connection()->commit();
    try {
        DB::connection()->beginTransaction();
        HrCalendarEvent::whereKey($this->event->id)->lockForUpdate()->firstOrFail();
        foreach ($this->readers as $i => $person) {
            $process = new Process([PHP_BINARY, '-r', $code, base_path(), (string) $person->id, (string) $this->event->id, $this->date, $ready[$i], $go], base_path(), ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => DB::connection()->getDatabaseName(), 'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array']);
            $process->setTimeout(45);
            $process->start();
            $workers[] = $process;
        }
        $deadline = microtime(true) + 25;
        while (! is_file($ready[0]) || ! is_file($ready[1])) {
            if (microtime(true) > $deadline) {
                throw new RuntimeException('Seat-allocation workers did not reach their barrier.');
            } usleep(10000);
        }
        touch($go);
        usleep(250000);
        foreach ($workers as $worker) {
            expect($worker->isRunning())->toBeTrue();
        }
        DB::connection()->commit();
        $statuses = [];
        foreach ($workers as $worker) {
            $worker->wait();
            expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());
            $statuses[] = json_decode($worker->getOutput(), true, flags: JSON_THROW_ON_ERROR)['mine'];
        }
        sort($statuses);
        expect($statuses)->toBe(['going', 'waiting'])->and(DB::table('hr_calendar_registrations')->where('event_id', $this->event->id)->where('status', 'going')->count())->toBe(1);
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
