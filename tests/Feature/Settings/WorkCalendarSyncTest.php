<?php

use App\Jobs\SyncWorkCalendarsJob;
use App\Models\Client;
use App\Models\Role;
use App\Models\Shift;
use App\Models\User;
use App\Models\WorkCalendarEventLink;
use App\Services\WorkCalendar\WorkCalendarProvider;
use App\Services\WorkCalendar\WorkCalendarSettings;
use App\Services\WorkCalendar\WorkCalendarSyncService;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config(['inertia.ssr.enabled' => false]);
    $this->seed(RbacSeeder::class);
    Http::preventStrayRequests();
    config(['work_calendar.microsoft' => [
        'directory_id' => '11111111-1111-1111-1111-111111111111',
        'client_id' => 'calendar-test-client', 'client_secret' => 'calendar-test-secret',
    ]]);
});

function workCalendarAdmin(): User
{
    $user = User::factory()->create(['role' => 'admin', 'approved_at' => now()]);
    $user->roles()->attach(Role::where('name', 'admin')->first());

    return $user;
}

function workCalendarWorker(string $mailbox = 'worker@work.example'): User
{
    $user = User::factory()->create(['role' => 'support_worker', 'approved_at' => now()]);
    ensureCanonicalHrStaffProfile($user, overrides: ['work_email' => $mailbox]);

    return $user;
}

function enableWorkCalendar(): void
{
    app(WorkCalendarSettings::class)->save([
        'provider' => 'microsoft', 'domain' => 'work.example', 'enabled' => true,
        'checked_at' => now()->toIso8601String(),
        'checked_fingerprint' => app(WorkCalendarProvider::class)->fingerprint('microsoft', 'work.example'),
    ]);
}

test('staff cannot configure check or trigger organisation work calendar sync', function () {
    $worker = workCalendarWorker();
    $this->actingAs($worker)->put('/settings/calendar-sync/work-calendar', [])->assertForbidden();
    $this->post('/settings/calendar-sync/work-calendar/check', ['user_id' => $worker->id])->assertForbidden();
    $this->post('/settings/calendar-sync/work-calendar/sync')->assertForbidden();
    Http::assertNothingSent();
});

test('settings expose readiness but never organisation secrets or credential fingerprints', function () {
    $this->actingAs(workCalendarAdmin())->get('/settings/calendar-sync')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('workCalendar.providers.0.configured', true)
            ->where('workCalendar.settings.enabled', false)
            ->missing('workCalendar.settings.checked_fingerprint'))
        ->assertDontSee('calendar-test-secret');
});

test('enabling sync requires a successful check for the exact provider credentials and domain', function () {
    $admin = workCalendarAdmin();
    $this->actingAs($admin)->put('/settings/calendar-sync/work-calendar', [
        'provider' => 'microsoft', 'domain' => 'work.example', 'enabled' => true,
    ])->assertSessionHasErrors('work_calendar');
    enableWorkCalendar();
    config(['work_calendar.microsoft.client_secret' => 'changed-secret']);
    $this->put('/settings/calendar-sync/work-calendar', [
        'provider' => 'microsoft', 'domain' => 'work.example', 'enabled' => true,
    ])->assertSessionHasErrors('work_calendar');
    Http::assertNothingSent();
});

test('an admin checks only an eligible staff work mailbox without writing events', function () {
    $worker = workCalendarWorker();
    $other = workCalendarWorker('outside@personal.example');
    app(WorkCalendarSettings::class)->save(['domain' => 'work.example']);
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'test-token', 'expires_in' => 3600]),
        'graph.microsoft.com/*' => Http::response(['value' => []]),
    ]);
    $this->actingAs(workCalendarAdmin())->post('/settings/calendar-sync/work-calendar/check', ['user_id' => $other->id])->assertUnprocessable();
    Http::assertNothingSent();
    $this->post('/settings/calendar-sync/work-calendar/check', ['user_id' => $worker->id])->assertSessionHasNoErrors();
    Http::assertSent(fn ($request) => $request->method() === 'GET' && str_contains($request->url(), 'worker%40work.example/calendar/events'));
    Http::assertNotSent(fn ($request) => $request->method() !== 'GET' && str_contains($request->url(), 'graph.microsoft.com'));
    expect(app(WorkCalendarSettings::class)->load()['checked_at'])->not->toBeNull();
});

test('duplicate work addresses and former staff are excluded from automatic enrolment', function () {
    workCalendarWorker();
    workCalendarWorker('WORKER@work.example');
    $former = workCalendarWorker('former@work.example');
    $former->hrEmployeeProfile->update(['is_active' => false]);
    expect(app(WorkCalendarSettings::class)->recipients('work.example'))->toBeEmpty();
});

test('sync creates once updates changed shifts and removes cancelled copies without care data', function () {
    $worker = workCalendarWorker();
    $shift = Shift::factory()->create([
        'user_id' => $worker->id, 'site_id' => $worker->hrEmployeeProfile->primary_site_id,
        'client_id' => Client::factory()->create(['site_id' => $worker->hrEmployeeProfile->primary_site_id])->id, 'status' => 'scheduled',
        'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHours(8),
        'notes' => 'Private care notes', 'location' => 'Private client address',
    ]);
    enableWorkCalendar();
    Http::fake(function ($request) {
        if (str_contains($request->url(), 'login.microsoftonline.com')) {
            return Http::response(['access_token' => 'test-token', 'expires_in' => 3600]);
        }

        return match ($request->method()) {
            'GET' => Http::response(['value' => []]),
            'DELETE' => Http::response(null, 204),
            default => Http::response(['id' => 'remote-work-shift']),
        };
    });
    app(WorkCalendarSyncService::class)->run();
    expect(WorkCalendarEventLink::count())->toBe(1);
    expect(WorkCalendarEventLink::first()->external_id)->toBe('remote-work-shift');
    expect(app(WorkCalendarSettings::class)->load()['last_error'])->toBeNull();
    app(WorkCalendarSyncService::class)->run();
    $posts = Http::recorded(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), 'graph.microsoft.com'))->values();
    expect($posts)->toHaveCount(1);
    expect(json_encode($posts[0][0]->data()))->not->toContain('Private care notes', 'Private client address');
    expect($posts[0][0]['subject'])->toBe('Work shift');
    $shift->update(['ends_at' => $shift->ends_at->addHour()]);
    app(WorkCalendarSyncService::class)->run();
    Http::assertSent(fn ($request) => $request->method() === 'PATCH' && str_contains($request->url(), 'remote-work-shift'));
    $shift->update(['status' => 'cancelled']);
    app(WorkCalendarSyncService::class)->run();
    Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_contains($request->url(), 'remote-work-shift'));
    expect(WorkCalendarEventLink::count())->toBe(0);
});

test('a denied provider call leaves a retryable link and reports failure rather than a successful sync', function () {
    $worker = workCalendarWorker();
    Shift::factory()->create([
        'user_id' => $worker->id, 'site_id' => $worker->hrEmployeeProfile->primary_site_id,
        'client_id' => Client::factory()->create(['site_id' => $worker->hrEmployeeProfile->primary_site_id])->id, 'status' => 'scheduled',
        'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHours(8),
    ]);
    enableWorkCalendar();
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'test-token']),
        'graph.microsoft.com/*' => Http::response(['error' => 'private upstream error'], 403),
    ]);
    app(WorkCalendarSyncService::class)->run();
    expect(WorkCalendarEventLink::first()->external_id)->toBeNull();
    expect(app(WorkCalendarSettings::class)->load()['last_synced_at'])->toBeNull();
    expect(app(WorkCalendarSettings::class)->load()['last_error'])->not->toBeNull()->not->toContain('private upstream error');
});

test('disabled sync sends no provider requests and enabled sync can be queued by admin', function () {
    app(WorkCalendarSyncService::class)->run();
    Http::assertNothingSent();
    Queue::fake();
    enableWorkCalendar();
    $this->actingAs(workCalendarAdmin())->post('/settings/calendar-sync/work-calendar/sync')->assertRedirect();
    Queue::assertPushed(SyncWorkCalendarsJob::class);
});

test('Microsoft recovers an interrupted create by its private operation marker', function () {
    $link = WorkCalendarEventLink::create([
        'provider' => 'microsoft', 'user_id' => 1, 'shift_id' => 1, 'mailbox' => 'worker@work.example',
        'operation_id' => '11111111-1111-4111-8111-111111111111', 'ends_at' => now()->addDay(),
    ]);
    Http::fake(function ($request) {
        if (str_contains($request->url(), 'login.microsoftonline.com')) {
            return Http::response(['access_token' => 'test-token']);
        }

        return $request->method() === 'GET'
            ? Http::response(['value' => [['id' => 'recovered-copy']]])
            : Http::response(['id' => 'recovered-copy']);
    });
    expect(app(WorkCalendarProvider::class)->upsert($link, ['subject' => 'Work shift']))->toBe('recovered-copy');
    Http::assertNotSent(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), 'graph.microsoft.com'));
    Http::assertSent(fn ($request) => $request->method() === 'PATCH' && str_ends_with($request->url(), 'recovered-copy'));
});

test('withdrawn roster shifts are removed and never republished by calendar sync', function () {
    $worker = workCalendarWorker();
    $shift = Shift::factory()->create([
        'user_id' => $worker->id, 'site_id' => $worker->hrEmployeeProfile->primary_site_id,
        'client_id' => Client::factory()->create(['site_id' => $worker->hrEmployeeProfile->primary_site_id])->id, 'status' => 'scheduled',
        'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHours(8),
    ]);
    enableWorkCalendar();
    config(['features.rostering.publish' => true]);
    WorkCalendarEventLink::create([
        'provider' => 'microsoft', 'user_id' => $worker->id, 'shift_id' => $shift->id,
        'mailbox' => 'worker@work.example', 'operation_id' => (string) Str::uuid(),
        'external_id' => 'withdrawn-copy', 'ends_at' => $shift->ends_at,
    ]);
    Http::fake([
        'login.microsoftonline.com/*' => Http::response(['access_token' => 'test-token']),
        'graph.microsoft.com/*' => Http::response(null, 204),
    ]);
    app(WorkCalendarSyncService::class)->run();
    Http::assertSent(fn ($request) => $request->method() === 'DELETE' && str_ends_with($request->url(), 'withdrawn-copy'));
    expect(WorkCalendarEventLink::count())->toBe(0);
});
