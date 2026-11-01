<?php

use App\Domain\Hr\Models\HrAttendanceSession;
use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Hr\Models\HrTimeEntry;
use App\Domain\SecurityDevices\Access\Models\AccessConsole;
use App\Domain\SecurityDevices\Access\Models\AccessDoor;
use App\Domain\SecurityDevices\Access\Models\AccessEvent;
use App\Domain\SecurityDevices\Access\Models\AccessPerson;
use App\Domain\SecurityDevices\DoorClockins\DoorClockinEngine;
use App\Domain\SecurityDevices\DoorClockins\DoorClockinSetup;
use App\Domain\SecurityDevices\DoorClockins\Models\DoorClockin;
use App\Domain\SecurityDevices\DoorClockins\Models\DoorClockinDoor;
use App\Domain\SecurityDevices\DoorClockins\Models\DoorClockinNotice;
use App\Domain\SecurityDevices\DoorClockins\Models\DoorClockinTold;
use App\Domain\SecurityDevices\DoorClockins\Screens\AccessClockinsPresenter;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ServiceContext;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Notifications\DoorClockinNotification;
use App\Notifications\QueuedDoorClockinNotification;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

/*
 * S&D Phase H, decision D15: a badge near a rostered start suggests a clock-in; the person
 * confirms it. Off by default; a door counts only from its notice's start date; faces,
 * fingerprints, residents' fobs and unmatched people never count; one suggestion per shift;
 * confirming clocks in through AttendanceService at the badge time with the source "door_badge";
 * another Site's rows never show; Access › Clock-ins needs attendance.doorClockIns.review.
 *
 * Times: Thu 29 Oct 2026 in NZ daylight time (UTC+13). Shift 7:00 am – 3:00 pm, badge 6:52 am.
 */

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    $this->seed(SecurityDevicesPermissionsSeeder::class);
    // 6:58 am NZ: inside the window, before the 15-minute gap check.
    $this->travelTo(CarbonImmutable::parse('2026-10-28 17:58:00', 'UTC'));
    Notification::fake();

    $site = fn (string $name): Site => Site::factory()->create(['name' => $name, 'is_active' => true, 'archived' => false, 'archived_at' => null]);
    $this->home = $site('Kauri House');
    $this->other = $site('Rātā Lodge');

    $this->person = function (array $keys, Site $at, array $attributes = [], ?string $role = null): User {
        $user = User::factory()->create(['approved_at' => now(), ...$attributes]);
        if ($role !== null && ($model = Role::query()->where('name', $role)->first()) !== null) {
            $user->roles()->syncWithoutDetaching([$model->id]);
        }
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id, 'primary_site_id' => $at->id, 'secondary_site_ids' => [],
            'is_active' => true, 'start_date' => now()->subYear()->toDateString(), 'end_date' => null,
        ]);
        foreach ($keys as $key) {
            $user->permissionOverrides()->attach(Permission::query()->where('key', $key)->firstOrFail()->id, ['allowed' => true]);
        }

        return $user->fresh();
    };
    $this->worker = ($this->person)(['shifts.viewAssigned', 'timesheets.create'], $this->home, ['name' => 'Sione Fifita', 'role' => 'support_worker'], 'support_worker');
    $this->manager = ($this->person)(['attendance.doorClockIns.review', 'securityDevices.accessControl.manage', 'timesheets.approve'], $this->home, ['name' => 'Tama Hēnare']);

    $console = fn (Site $at, string $address): AccessConsole => AccessConsole::query()->create(['site_id' => $at->id, 'address' => $address, 'port' => 12445, 'state' => 'ok', 'last_synced_at' => now(), 'events_until' => now()]);
    $this->console = $console($this->home, '10.82.0.40');
    $this->otherConsole = $console($this->other, '10.83.0.40');
    $door = fn (AccessConsole $at, string $id, string $name): AccessDoor => AccessDoor::query()->create([
        'console_id' => $at->id, 'external_id' => $id, 'name' => $name, 'lock_state' => 'locked', 'door_state' => 'closed', 'hub_bound' => true, 'readers' => [], 'seen_at' => now(),
    ]);
    $this->front = $door($this->console, 'door-front', 'Front door');
    $this->office = $door($this->console, 'door-office', 'Staff office');
    $this->otherFront = $door($this->otherConsole, 'door-other-front', 'Front door');

    $accessPerson = fn (AccessConsole $at, string $id, array $attributes): AccessPerson => AccessPerson::query()->create(['console_id' => $at->id, 'external_id' => $id, 'status' => 'active', 'seen_at' => now(), ...$attributes]);
    $this->staffCard = $accessPerson($this->console, 'user-sione', ['first_name' => 'Sione', 'last_name' => 'Fifita', 'kind' => 'staff', 'match_state' => 'matched', 'user_id' => $this->worker->id]);
    $this->resident = $accessPerson($this->console, 'user-rewa', ['first_name' => 'Rewa', 'last_name' => 'Resident', 'kind' => 'resident', 'match_state' => 'matched']);
    $this->unmatched = $accessPerson($this->console, 'user-sam', ['first_name' => 'Sam', 'last_name' => 'Unknown', 'kind' => 'unknown', 'match_state' => 'unmatched']);

    $this->shiftFor = function (User $user, Site $at, string $startsUtc, string $endsUtc): Shift {
        $client = Client::factory()->create(['site_id' => $at->id]);
        $shift = Shift::query()->create([
            'site_id' => $at->id, 'client_id' => $client->id, 'service_context_id' => ServiceContext::factory()->create()->id,
            'user_id' => $user->id, 'starts_at' => $startsUtc, 'ends_at' => $endsUtc, 'status' => 'scheduled', 'created_by' => $user->id,
        ]);
        $shift->forceFill(['published_at' => now()->subDays(3)])->save();

        return $shift->fresh();
    };
    $this->shift = ($this->shiftFor)($this->worker, $this->home, '2026-10-28 18:00:00', '2026-10-29 02:00:00');

    // A door that counts, with its notice a week before, told to the staff matched to a card there then.
    $this->count = function (AccessDoor $door, string $from = '2026-10-19'): DoorClockinDoor {
        $siteId = (int) $door->console->site_id;
        $sent = CarbonImmutable::parse($from.' 00:00:00', 'Pacific/Auckland')->subDays(7)->utc();
        $notice = DoorClockinNotice::query()->create([
            'body' => 'Synthetic notice: your badge near a rostered start suggests a clock-in.', 'by_email' => false,
            'site_ids' => [$siteId], 'door_ids' => [$door->id], 'starts_on' => $from, 'sent_at' => $sent, 'recipients' => 0,
        ]);
        AccessPerson::query()->whereHas('console', fn ($query) => $query->where('site_id', $siteId))
            ->where('kind', 'staff')->where('match_state', 'matched')->whereNotNull('user_id')->pluck('user_id')->unique()
            ->each(fn (mixed $userId) => DoorClockinTold::query()->create(['notice_id' => $notice->id, 'site_id' => $siteId, 'user_id' => (int) $userId, 'told_at' => $sent]));

        return DoorClockinDoor::query()->create(['door_id' => $door->id, 'site_id' => $siteId, 'notice_id' => $notice->id, 'starts_on' => $from]);
    };
    $this->card = fn (User $user, string $id): AccessPerson => AccessPerson::query()->create([
        'console_id' => $this->console->id, 'external_id' => $id, 'status' => 'active', 'seen_at' => now(), 'kind' => 'staff', 'match_state' => 'matched', 'user_id' => $user->id,
    ]);
    $this->badge = fn (array $attributes = []): AccessEvent => AccessEvent::query()->create([
        'console_id' => $this->console->id, 'event_key' => hash('sha256', (string) Str::uuid()), 'door_id' => $this->front->id,
        'person_id' => $this->staffCard->id, 'kind' => 'opened', 'method' => 'card', 'actor_name' => 'Sione Fifita',
        'occurred_at' => '2026-10-28 17:52:00', ...$attributes,
    ]);
    $this->tick = fn (?string $at = null): array => app(DoorClockinEngine::class)->tick($at !== null ? CarbonImmutable::parse($at, 'UTC') : null);
    $this->suggested = fn (): int => DoorClockin::query()->whereNotNull('badge_at')->whereNotNull('suggested_at')->count();
});

it('suggests nothing while it is off, and never replays those badges once it is turned on', function (): void {
    $event = ($this->badge)();
    ($this->tick)();

    expect(DoorClockin::query()->count())->toBe(0);
    Notification::assertNothingSent();

    ($this->count)($this->front);
    ($this->tick)();
    expect(($this->suggested)())->toBe(0)
        ->and(app(DoorClockinSetup::class)->config()->cursor_event_id)->toBe($event->id);
});

it('suggests nothing before the door’s start date', function (): void {
    ($this->count)($this->front, '2026-10-30');
    ($this->badge)();
    ($this->tick)();

    expect(DoorClockin::query()->count())->toBe(0);
    Notification::assertNothingSent();
});

it('never counts faces, fingerprints, exit buttons, residents’ fobs, unmatched people or uncounted doors', function (): void {
    ($this->count)($this->front);
    ($this->badge)(['method' => 'face']);
    ($this->badge)(['method' => 'fingerprint']);
    ($this->badge)(['method' => 'button']);
    ($this->badge)(['kind' => 'denied']);
    ($this->badge)(['person_id' => $this->resident->id]);
    ($this->badge)(['person_id' => $this->unmatched->id]);
    ($this->badge)(['door_id' => $this->office->id]);
    ($this->tick)();

    expect(($this->suggested)())->toBe(0)
        ->and(DoorClockin::query()->where('kind', DoorClockin::NOT_ROSTERED)->count())->toBe(0);
    Notification::assertNothingSent();
});

it('suggests nothing outside the window, or when the person is already clocked in', function (): void {
    ($this->count)($this->front);
    ($this->badge)(['occurred_at' => '2026-10-28 16:30:00']); // 5:30 am: 90 min before a 7:00 am start
    ($this->tick)();
    expect(($this->suggested)())->toBe(0)
        ->and(DoorClockin::query()->where('kind', DoorClockin::NOT_ROSTERED)->count())->toBe(0);

    HrAttendanceSession::query()->create([
        'user_id' => $this->worker->id, 'site_id' => $this->home->id, 'clock_in_at' => '2026-10-28 17:40:00', 'status' => 'open', 'source' => 'manual', 'created_by' => $this->worker->id,
    ]);
    ($this->badge)();
    ($this->tick)();
    expect(($this->suggested)())->toBe(0);
    Notification::assertNothingSent();
});

it('suggests one clock-in per shift, only to the person who badged', function (): void {
    ($this->count)($this->front);
    ($this->badge)();
    ($this->badge)(['occurred_at' => '2026-10-28 17:55:00']);
    ($this->tick)();

    $row = DoorClockin::query()->sole();
    expect($row->shift_id)->toBe($this->shift->id)
        ->and($row->badge_at->format('H:i'))->toBe('17:52')
        ->and($row->method)->toBe('card');
    Notification::assertSentToTimes($this->worker, DoorClockinNotification::class, 1);
    Notification::assertSentTo($this->worker, DoorClockinNotification::class, fn (DoorClockinNotification $notice): bool => $notice->kind === 'suggestion'
        && $notice->title === 'Clock in for your 7:00 am shift?'
        && str_contains($notice->body, 'You badged in at Kauri House front door at 6:52 am')
        && $notice->url === '/my-day?door_clockin='.$row->id);
    Notification::assertNotSentTo($this->manager, DoorClockinNotification::class);

    ($this->tick)();
    expect(($this->suggested)())->toBe(1);
});

it('clocks in at the badge time through attendance, with the source door_badge, only when the person chooses', function (): void {
    ($this->count)($this->front);
    ($this->badge)();
    ($this->tick)();
    $row = DoorClockin::query()->sole();
    expect(HrAttendanceSession::query()->where('user_id', $this->worker->id)->exists())->toBeFalse();

    $this->actingAs($this->manager)->postJson("/door-clockins/{$row->id}/answer", ['choice' => 'badge'])->assertNotFound();

    $this->actingAs($this->worker)->postJson("/door-clockins/{$row->id}/answer", ['choice' => 'badge'])
        ->assertOk()
        ->assertJsonPath('answer', 'badge');

    $session = HrAttendanceSession::query()->where('user_id', $this->worker->id)->sole();
    expect($session->source)->toBe('door_badge')
        ->and($session->shift_id)->toBe($this->shift->id)
        ->and($session->clock_in_at->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-28 17:52:00')
        ->and($session->meta['door_badge']['clockin_id'] ?? null)->toBe($row->id)
        ->and($session->meta['door_badge']['method'] ?? null)->toBe('card')
        ->and(HrTimeEntry::query()->where('attendance_session_id', $session->id)->exists())->toBeTrue()
        ->and($row->fresh()->answer)->toBe('badge')
        ->and($row->fresh()->attendance_session_id)->toBe($session->id);

    $this->actingAs($this->worker)->postJson("/door-clockins/{$row->id}/answer", ['choice' => 'badge'])->assertUnprocessable();
    expect(HrAttendanceSession::query()->where('user_id', $this->worker->id)->count())->toBe(1);
});

it('records “I’m not working” without clocking anyone in', function (): void {
    ($this->count)($this->front);
    ($this->badge)();
    ($this->tick)();
    $row = DoorClockin::query()->sole();

    $this->actingAs($this->worker)->postJson("/door-clockins/{$row->id}/answer", ['choice' => 'not_working'])->assertOk();

    expect($row->fresh()->answer)->toBe('not_working')
        ->and(HrAttendanceSession::query()->where('user_id', $this->worker->id)->exists())->toBeFalse();
});

it('tells the person and the house manager about gaps 15 minutes after the start', function (): void {
    ($this->count)($this->front);
    $wiremu = ($this->person)(['shifts.viewAssigned'], $this->home, ['name' => 'Wiremu Parata'], 'support_worker');
    ($this->shiftFor)($wiremu, $this->home, '2026-10-28 18:00:00', '2026-10-29 02:00:00');
    ($this->badge)();
    ($this->tick)('2026-10-28 17:58:00');
    ($this->tick)('2026-10-28 18:16:00');

    Notification::assertSentTo($this->worker, DoorClockinNotification::class, fn (DoorClockinNotification $notice): bool => $notice->kind === 'reminder');
    Notification::assertSentTo($this->manager, DoorClockinNotification::class, fn (DoorClockinNotification $notice): bool => $notice->kind === 'gap'
        && $notice->title === 'Wiremu Parata hasn’t clocked in'
        && str_contains($notice->body, 'no badge and no clock-in by 7:15 am'));
    $gap = DoorClockin::query()->where('user_id', $wiremu->id)->sole();
    expect($gap->manager_told_at)->not->toBeNull()
        ->and($gap->manager_told_user_ids)->toBe([$this->manager->id]);
});

it('keeps a badge from someone not on the roster for the house manager', function (): void {
    $daniel = ($this->person)([], $this->home, ['name' => 'Daniel Kim']);
    $card = ($this->card)($daniel, 'user-daniel');
    ($this->count)($this->front);
    ($this->badge)(['person_id' => $card->id, 'occurred_at' => '2026-10-28 17:55:00']);
    ($this->tick)('2026-10-28 17:58:00');

    $row = DoorClockin::query()->where('user_id', $daniel->id)->sole();
    expect($row->kind)->toBe(DoorClockin::NOT_ROSTERED)->and($row->suggested_at)->toBeNull();
    Notification::assertNotSentTo($daniel, DoorClockinNotification::class);

    ($this->tick)('2026-10-28 18:11:00');
    Notification::assertSentTo($this->manager, DoorClockinNotification::class, fn (DoorClockinNotification $notice): bool => $notice->kind === 'not_rostered');
});

it('shows only the viewer’s Sites, and Access › Clock-ins needs the review key', function (): void {
    ($this->count)($this->front);
    ($this->count)($this->otherFront);
    $hidden = ($this->person)(['shifts.viewAssigned'], $this->other, ['name' => 'Hidden Elsewhere'], 'support_worker');
    ($this->shiftFor)($hidden, $this->other, '2026-10-28 18:00:00', '2026-10-29 02:00:00');
    ($this->badge)();
    ($this->tick)('2026-10-28 18:05:00');
    $otherRow = DoorClockin::query()->where('site_id', $this->other->id)->sole();

    $tab = app(AccessClockinsPresenter::class)->present($this->manager);
    expect(collect($tab['rows'])->pluck('site.id')->unique()->values()->all())->toBe([$this->home->id])
        ->and(json_encode($tab))->not->toContain('Hidden Elsewhere');
    $this->actingAs($this->manager)->getJson("/security-devices/access/clockins/{$otherRow->id}/panel")->assertNotFound();
    $this->actingAs($this->manager)->postJson("/security-devices/access/clockins/{$otherRow->id}/review", ['choice' => 'ask'])->assertNotFound();

    $looker = ($this->person)(['securityDevices.viewAny', 'securityDevices.accessControl.view'], $this->home);
    $mine = DoorClockin::query()->where('user_id', $this->worker->id)->sole();
    expect(app(AccessClockinsPresenter::class)->present($looker))->toBeNull();
    $this->actingAs($looker)->getJson("/security-devices/access/clockins/{$mine->id}/panel")->assertForbidden();
});

it('never offers a clock-in time it can’t correct: keep the clock-in, or ask', function (): void {
    ($this->count)($this->front);
    ($this->badge)();
    ($this->tick)();
    $row = DoorClockin::query()->sole();
    HrAttendanceSession::query()->create([
        'user_id' => $this->worker->id, 'shift_id' => $this->shift->id, 'site_id' => $this->home->id, 'clock_in_at' => '2026-10-28 18:26:00',
        'status' => 'open', 'source' => 'manual', 'created_by' => $this->worker->id,
    ]);

    $panel = $this->actingAs($this->manager)->getJson("/security-devices/access/clockins/{$row->id}/panel")->assertOk();
    expect($panel->json('choices'))->toBe(['keep', 'ask'])
        ->and($panel->json('outcome'))->toBe('apart')
        ->and($panel->json('apart_minutes'))->toBe(34);

    $this->actingAs($this->manager)->postJson("/security-devices/access/clockins/{$row->id}/review", ['choice' => 'ask', 'note' => 'Did you start at 6:52 am?'])->assertOk();
    expect($row->fresh()->review)->toBe('asked');
    Notification::assertSentTo($this->worker, DoorClockinNotification::class, fn (DoorClockinNotification $notice): bool => $notice->kind === 'question' && str_contains($notice->body, 'Did you start at 6:52 am?'));
});

it('turns on only with the notice first, to matched staff, starting at least a week later', function (): void {
    $payload = ['door_ids' => [$this->front->id], 'before' => 60, 'after' => 30, 'email' => true, 'notice' => str_repeat('From Mon 9 Nov, your badge suggests a clock-in. ', 2)];

    $this->actingAs($this->worker)->putJson('/security-devices/setup/clockins/doors', [...$payload, 'starts_on' => '2026-11-09'])->assertForbidden();
    $this->actingAs($this->manager)->putJson('/security-devices/setup/clockins/doors', [...$payload, 'starts_on' => '2026-11-02'])->assertUnprocessable()->assertJsonValidationErrors('starts_on');
    $this->actingAs($this->manager)->putJson('/security-devices/setup/clockins/doors', [...$payload, 'notice' => null, 'starts_on' => '2026-11-09'])->assertUnprocessable()->assertJsonValidationErrors('notice');
    $this->actingAs($this->manager)->putJson('/security-devices/setup/clockins/doors', [...$payload, 'door_ids' => [$this->otherFront->id], 'starts_on' => '2026-11-09'])->assertUnprocessable()->assertJsonValidationErrors('door_ids');
    expect(DoorClockinDoor::query()->count())->toBe(0);

    $this->actingAs($this->manager)->putJson('/security-devices/setup/clockins/doors', [...$payload, 'starts_on' => '2026-11-09'])
        ->assertOk()
        ->assertJsonPath('told', 1);

    $notice = DoorClockinNotice::query()->sole();
    expect($notice->starts_on->format('Y-m-d'))->toBe('2026-11-09')
        ->and(DoorClockinDoor::query()->sole()->starts_on->format('Y-m-d'))->toBe('2026-11-09');
    // In the app first (that's what counts as told), the email from the queue.
    Notification::assertSentTo($this->worker, DoorClockinNotification::class, fn (DoorClockinNotification $sent, array $channels): bool => $sent->kind === 'notice' && $sent->body === $notice->body && $channels === ['database']);
    Notification::assertSentTo($this->worker, QueuedDoorClockinNotification::class, fn (QueuedDoorClockinNotification $sent, array $channels): bool => $sent->body === $notice->body && $channels === ['mail']);
    expect(DoorClockinTold::query()->where('user_id', $this->worker->id)->where('site_id', $this->home->id)->sole()->notice_id)->toBe($notice->id)
        ->and($notice->fresh()->recipients)->toBe(1);
    $this->assertDatabaseHas('audit_logs', ['action' => 'security_devices.settings.changed', 'user_id' => $this->manager->id]);
});

it('turning it off stops new suggestions and closes waiting ones; confirmed clock-ins stay', function (): void {
    ($this->count)($this->front);
    ($this->badge)();
    ($this->tick)();
    $row = DoorClockin::query()->sole();

    $this->actingAs($this->manager)->postJson('/security-devices/setup/clockins/off')->assertOk()->assertJsonPath('removed', 1);

    expect(DoorClockinDoor::query()->count())->toBe(0)
        ->and($row->fresh()->closed_at)->not->toBeNull();
    $this->actingAs($this->worker)->postJson("/door-clockins/{$row->id}/answer", ['choice' => 'badge'])->assertUnprocessable();
    expect(HrAttendanceSession::query()->where('user_id', $this->worker->id)->exists())->toBeFalse();
});

it('refuses the badge time once the shift has moved away from the badge, and drops that suggestion', function (): void {
    ($this->count)($this->front);
    ($this->badge)();
    ($this->tick)();
    $row = DoorClockin::query()->sole();

    // Moved to 1 pm – 9 pm NZ: the 6:52 am badge no longer belongs to it.
    $this->shift->forceFill(['starts_at' => '2026-10-29 00:00:00', 'ends_at' => '2026-10-29 08:00:00'])->save();
    $this->actingAs($this->worker)->postJson("/door-clockins/{$row->id}/answer", ['choice' => 'badge'])
        ->assertUnprocessable()->assertJsonValidationErrors('choice');
    expect(HrAttendanceSession::query()->where('user_id', $this->worker->id)->exists())->toBeFalse()
        ->and($row->fresh()->answer)->toBeNull();

    ($this->tick)();
    expect($row->fresh()->badge_at)->toBeNull()
        ->and($row->fresh()->suggested_at)->toBeNull();
});

it('gives a shift handed to someone else its own row, so their badge still suggests a clock-in', function (): void {
    $bob = ($this->person)(['shifts.viewAssigned', 'timesheets.create'], $this->home, ['name' => 'Bob Tane'], 'support_worker');
    $bobCard = ($this->card)($bob, 'user-bob');
    ($this->count)($this->front);
    ($this->tick)('2026-10-28 17:50:00');
    $first = DoorClockin::query()->sole();

    $this->shift->forceFill(['user_id' => $bob->id])->save();
    ($this->badge)(['person_id' => $bobCard->id]);
    ($this->tick)();

    expect($first->fresh()->close_reason)->toBe('shift_changed');
    $mine = DoorClockin::query()->where('user_id', $bob->id)->sole();
    expect($mine->shift_id)->toBe($this->shift->id)
        ->and($mine->badge_at?->format('H:i'))->toBe('17:52');
    Notification::assertSentTo($bob, DoorClockinNotification::class, fn (DoorClockinNotification $notice): bool => $notice->kind === 'suggestion');
});

it('counts nobody until a week after they were told: staff matched later get the notice then', function (): void {
    ($this->count)($this->front);
    $aroha = ($this->person)(['shifts.viewAssigned', 'timesheets.create'], $this->home, ['name' => 'Aroha Ngata'], 'support_worker');
    $card = ($this->card)($aroha, 'user-aroha');
    ($this->shiftFor)($aroha, $this->home, '2026-10-28 18:00:00', '2026-10-29 02:00:00');
    ($this->badge)(['person_id' => $card->id]);
    ($this->tick)();

    Notification::assertSentTo($aroha, DoorClockinNotification::class, fn (DoorClockinNotification $notice): bool => $notice->kind === 'notice'
        && $notice->title === 'Clock-ins from doors start on Thu 5 Nov'
        && str_contains($notice->body, 'For you, it starts on Thu 5 Nov'));
    Notification::assertNotSentTo($aroha, DoorClockinNotification::class, fn (DoorClockinNotification $notice): bool => $notice->kind === 'suggestion');
    expect(DoorClockin::query()->where('user_id', $aroha->id)->whereNotNull('badge_at')->exists())->toBeFalse()
        ->and(DoorClockinTold::query()->where('user_id', $aroha->id)->count())->toBe(1);

    ($this->tick)();
    Notification::assertSentToTimes($aroha, DoorClockinNotification::class, 1);
});

it('leaves a manager’s own clock-in to the other managers', function (): void {
    $card = ($this->card)($this->manager, 'user-tama');
    ($this->shiftFor)($this->manager, $this->home, '2026-10-28 18:00:00', '2026-10-29 02:00:00');
    ($this->count)($this->front);
    ($this->badge)(['person_id' => $card->id]);
    ($this->tick)();
    $own = DoorClockin::query()->where('user_id', $this->manager->id)->sole();

    expect($this->actingAs($this->manager)->getJson("/security-devices/access/clockins/{$own->id}/panel")->assertOk()->json('choices'))->toBe([]);
    $this->actingAs($this->manager)->postJson("/security-devices/access/clockins/{$own->id}/review", ['choice' => 'ask'])->assertUnprocessable();
    expect($own->fresh()->review)->toBeNull();
});

it('lets only someone who looks after every Site change what applies to every Site', function (): void {
    $this->actingAs($this->manager)->patchJson('/security-devices/setup/clockins/settings', ['changes' => ['badge_time' => false]])
        ->assertUnprocessable()->assertJsonValidationErrors('changes');
    $this->actingAs($this->manager)->putJson('/security-devices/setup/clockins/doors', [
        'door_ids' => [$this->front->id], 'before' => 90, 'after' => 30, 'email' => false, 'starts_on' => '2026-11-09',
        'notice' => str_repeat('From Mon 9 Nov, your badge suggests a clock-in. ', 2),
    ])->assertUnprocessable()->assertJsonValidationErrors('before');
    expect(app(DoorClockinSetup::class)->config()->badge_time)->toBeTrue();

    $area = ($this->person)(['attendance.doorClockIns.review', 'securityDevices.accessControl.manage', 'reports.viewAny'], $this->home, ['name' => 'Area Manager']);
    $this->actingAs($area)->patchJson('/security-devices/setup/clockins/settings', ['changes' => ['badge_time' => false]])->assertOk();
    expect(app(DoorClockinSetup::class)->config()->badge_time)->toBeFalse();
});

it('keeps only which suggestion was answered when someone clocks in now instead', function (): void {
    ($this->count)($this->front);
    ($this->badge)();
    ($this->tick)();
    $row = DoorClockin::query()->sole();

    $this->actingAs($this->worker)->postJson("/door-clockins/{$row->id}/answer", ['choice' => 'now'])->assertOk();

    $session = HrAttendanceSession::query()->where('user_id', $this->worker->id)->sole();
    expect($session->source)->toBe('manual')
        ->and($session->meta['door_badge_suggestion'] ?? null)->toBe(['clockin_id' => $row->id, 'chose' => 'now']);
});
