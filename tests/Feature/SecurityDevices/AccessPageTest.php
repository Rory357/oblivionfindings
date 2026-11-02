<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Access\Models\AccessConsole;
use App\Domain\SecurityDevices\Access\Models\AccessDoor;
use App\Domain\SecurityDevices\Access\Models\AccessEvent;
use App\Domain\SecurityDevices\Access\Models\AccessPerson;
use App\Domain\SecurityDevices\Access\Models\AccessPolicy;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/*
 * S&D Phase H (pg-sdr-19, 19b–19d, 19f, pop-up 87): the Access page. Only the viewer's Sites show;
 * only staff matched at that Site are named on door events, and only for 90 days (D14); residents
 * are "Resident fob" (D19). Who can open a door is counted from the door groups people are in.
 */

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    $this->seed(SecurityDevicesPermissionsSeeder::class);

    $this->home = Site::factory()->create(['name' => 'Tōtara House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
    $this->other = Site::factory()->create(['name' => 'Rātā Lodge', 'is_active' => true, 'archived' => false, 'archived_at' => null]);

    $this->person = function (array $keys, ?Site $site = null, array $attributes = []): User {
        $user = User::factory()->create(['approved_at' => now(), ...$attributes]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id, 'primary_site_id' => ($site ?? $this->home)->id, 'secondary_site_ids' => [],
            'is_active' => true, 'start_date' => now()->subYear()->toDateString(), 'end_date' => null,
        ]);
        foreach ($keys as $key) {
            $user->permissionOverrides()->attach(Permission::query()->where('key', $key)->firstOrFail()->id, ['allowed' => true]);
        }

        return $user->fresh();
    };
    $this->viewer = ($this->person)(['securityDevices.viewAny', 'securityDevices.accessControl.view']);

    $this->console = AccessConsole::query()->create(['site_id' => $this->home->id, 'address' => '10.82.0.40', 'port' => 12445, 'state' => 'ok', 'last_synced_at' => now(), 'events_until' => now()]);
    $this->otherConsole = AccessConsole::query()->create(['site_id' => $this->other->id, 'address' => '10.83.0.40', 'port' => 12445, 'state' => 'ok', 'last_synced_at' => now(), 'events_until' => now()]);

    AccessPolicy::query()->create(['console_id' => $this->console->id, 'external_id' => 'policy-staff', 'name' => 'House staff', 'door_ids' => ['door-front', 'door-back'], 'seen_at' => now()]);
    AccessPolicy::query()->create(['console_id' => $this->console->id, 'external_id' => 'policy-home', 'name' => 'Residents', 'door_ids' => ['door-front'], 'seen_at' => now()]);
    $door = fn (AccessConsole $console, string $id, string $name): AccessDoor => AccessDoor::query()->create([
        'console_id' => $console->id, 'external_id' => $id, 'name' => $name, 'lock_state' => 'locked', 'door_state' => 'closed', 'hub_bound' => true,
        'readers' => [['id' => 'reader-'.$id, 'name' => $name.' reader', 'type' => 'UA-G2']], 'seen_at' => now(),
    ]);
    $this->front = $door($this->console, 'door-front', 'Front door');
    $this->back = $door($this->console, 'door-back', 'Back door');
    $this->office = $door($this->console, 'door-office', 'Office door');
    $this->secret = $door($this->otherConsole, 'door-secret', 'Rātā secret door');

    $this->ana = ($this->person)([], null, ['name' => 'Ana Tane']);
    $person = fn (AccessConsole $console, string $id, array $attributes): AccessPerson => AccessPerson::query()->create([
        'console_id' => $console->id, 'external_id' => $id, 'status' => 'active', 'seen_at' => now(), ...$attributes,
    ]);
    $this->staff = $person($this->console, 'user-ana', ['first_name' => 'Ana', 'last_name' => 'T', 'kind' => 'staff', 'match_state' => 'matched', 'user_id' => $this->ana->id, 'policy_ids' => ['policy-staff']]);
    $this->resident = $person($this->console, 'user-rewa', ['first_name' => 'Rewa', 'last_name' => 'Resident', 'kind' => 'resident', 'match_state' => 'matched', 'policy_ids' => ['policy-home']]);
    $this->stranger = $person($this->console, 'user-sam', ['first_name' => 'Sam', 'last_name' => 'Unmatched', 'kind' => 'unknown', 'match_state' => 'unmatched', 'policy_ids' => ['policy-staff']]);
    $this->leaver = $person($this->console, 'user-ben', ['first_name' => 'Ben', 'last_name' => 'Gone', 'kind' => 'unknown', 'match_state' => 'unmatched', 'status' => 'deactivated', 'policy_ids' => ['policy-staff']]);
    $this->otherPerson = $person($this->otherConsole, 'user-other', ['first_name' => 'Hidden', 'last_name' => 'Elsewhere', 'kind' => 'unknown', 'match_state' => 'unmatched', 'policy_ids' => []]);

    $this->event = fn (AccessConsole $console, ?AccessDoor $at, ?AccessPerson $who, string $kind, string $method, ?string $name, $when): AccessEvent => AccessEvent::query()->create([
        'console_id' => $console->id, 'event_key' => hash('sha256', (string) Str::uuid()), 'door_id' => $at?->id, 'person_id' => $who?->id,
        'kind' => $kind, 'method' => $method, 'actor_name' => $name, 'occurred_at' => $when,
    ]);
});

it('is refused without the door access permission', function (): void {
    $looker = ($this->person)(['securityDevices.viewAny', 'securityDevices.devices.view']);

    $this->actingAs($looker)->get('/security-devices/access')->assertForbidden();
    $this->actingAs($looker)->get('/security-devices/access/sites/'.$this->home->id.'/roll-call')->assertForbidden();
});

it('never shows another Site’s console, doors, people or events', function (): void {
    ($this->event)($this->otherConsole, $this->secret, $this->otherPerson, 'forced', 'other', 'Hidden Elsewhere', now()->subMinutes(5));

    foreach (['doors', 'people', 'visitors', 'schedules', 'activity'] as $tab) {
        $response = $this->actingAs($this->viewer)->get('/security-devices/access?tab='.$tab)->assertOk();
        expect($response->getContent())->not->toContain('Rātā')->not->toContain('Hidden Elsewhere');
    }
    $this->actingAs($this->viewer)->get('/security-devices/access?site='.$this->other->id)
        ->assertInertia(fn (Assert $page) => $page->component('security-devices/access/index')->where('filters.site', 'all')->has('sites', 1)->has('consoles', 1));
    $this->actingAs($this->viewer)->get('/security-devices/access/sites/'.$this->other->id.'/roll-call')->assertNotFound();
});

it('counts who can open each door from the door groups people are in', function (): void {
    $this->actingAs($this->viewer)->get('/security-devices/access')
        ->assertOk()
        ->assertInertia(function (Assert $page): void {
            $page->component('security-devices/access/index')->where('tab', 'doors')->where('counts.doors', 3);
            $cards = collect($page->toArray()['props']['doors']['groups'])->flatMap(fn (array $group): array => $group['items'])->keyBy('name');
            // Front: Ana and Sam (staff group) and Rewa (residents); the turned-off leaver isn't counted.
            expect($cards['Front door']['can_open'])->toBe(3)
                ->and($cards['Front door']['value_sub'])->toBe('Closed · 3 can open it')
                ->and($cards['Back door']['can_open'])->toBe(2)
                ->and($cards['Office door']['can_open'])->toBe(0);
        });
});

it('puts doors forced or held open today under "Needs a look", problems first', function (): void {
    ($this->event)($this->console, $this->front, null, 'forced', 'other', null, now()->subMinutes(2));
    ($this->event)($this->console, $this->back, null, 'held_open', 'other', null, now()->subMinute());
    ($this->event)($this->console, $this->office, $this->staff, 'opened', 'card', 'Ana T', now()->subHours(2));

    $this->actingAs($this->viewer)->get('/security-devices/access?tab=doors')
        ->assertInertia(fn (Assert $page) => $page
            ->where('doors.groups.0.key', 'attention')
            ->where('doors.groups.0.count', 2)
            ->where('doors.groups.0.items.0.name', 'Back door')
            ->where('doors.groups.0.items.0.reason', 'held')
            ->where('doors.groups.0.items.1.name', 'Front door')
            ->where('doors.groups.0.items.1.reason', 'forced')
            ->where('doors.groups.0.items.1.badge.tone', 'critical')
            ->where('doors.groups.1.key', 'normal')
            ->where('doors.groups.1.items.0.name', 'Office door')
            ->where('doors.groups.1.items.0.graph.note.text', '1 in 24 hours')
            ->where('meters.held_open.count', 1));
});

it('never names a resident, or anyone not matched to staff, on a door event', function (): void {
    ($this->event)($this->console, $this->front, $this->resident, 'opened', 'card', 'Rewa Resident', now()->subMinutes(3));
    ($this->event)($this->console, $this->front, $this->stranger, 'opened', 'card', 'Sam Unmatched', now()->subMinutes(2));
    ($this->event)($this->console, $this->front, $this->staff, 'opened', 'card', 'Ana T', now()->subMinute());

    $response = $this->actingAs($this->viewer)->get('/security-devices/access?tab=activity&days=7');
    $response->assertInertia(fn (Assert $page) => $page
        ->where('activity.rows.0.who.text', 'Ana Tane')
        ->where('activity.rows.0.who.named', true)
        ->where('activity.rows.1.who.text', 'Not matched to staff')
        ->where('activity.rows.2.who.text', 'Resident fob')
        ->where('activity.rows.2.how', 'Fob'));
    expect($response->getContent())->not->toContain('Rewa Resident')->not->toContain('Sam Unmatched');

    // Searching for a hidden name finds nothing; a resident's door log can't be opened on its own.
    $this->actingAs($this->viewer)->get('/security-devices/access?tab=activity&days=7&q=Rewa')
        ->assertInertia(fn (Assert $page) => $page->where('activity.meta.total', 0));
    $this->actingAs($this->viewer)->get('/security-devices/access?tab=activity&days=7&person='.$this->resident->id)
        ->assertInertia(fn (Assert $page) => $page->where('activity.filters.person', null)->where('activity.meta.total', 3));

    // People: a resident's fob, never when they last came or went.
    $this->actingAs($this->viewer)->get('/security-devices/access?tab=people')
        ->assertInertia(function (Assert $page): void {
            $rows = collect($page->toArray()['props']['people']['rows'])->keyBy('id');
            expect($rows[$this->resident->id]['last_used']['text'])->toBe('Not shown for residents')
                ->and($rows[$this->resident->id]['gets_in'])->toBe('Resident fob')
                ->and($rows[$this->resident->id]['can_log'])->toBeFalse()
                ->and($rows[$this->stranger->id]['badge']['text'])->toBe('Not matched');
        });

    // The fire roll call lists the unmatched person without a name and leaves the resident out.
    $roll = $this->actingAs($this->viewer)->getJson('/security-devices/access/sites/'.$this->home->id.'/roll-call')->assertOk();
    expect($roll->getContent())->not->toContain('Rewa')->not->toContain('Sam Unmatched')
        ->and(collect($roll->json('sections'))->firstWhere('key', 'came_in')['people'][0]['name'] ?? null)->toBe('Someone not matched to staff');
});

it('shows no name on a door event older than 90 days, even if one is still stored', function (): void {
    ($this->event)($this->console, $this->front, $this->staff, 'opened', 'card', 'Ana T', now()->subDays(100));
    ($this->event)($this->console, $this->front, $this->staff, 'opened', 'card', 'Ana T', now()->subDays(2));

    $response = $this->actingAs($this->viewer)->get('/security-devices/access?tab=activity&days=all');
    $response->assertInertia(fn (Assert $page) => $page
        ->where('activity.meta.total', 2)
        ->where('activity.rows.0.who.text', 'Ana Tane')
        ->where('activity.rows.1.who.text', 'Name not kept after 90 days')
        ->where('activity.rows.1.who.named', false));

    // A search by name doesn't reach the old event either.
    $this->actingAs($this->viewer)->get('/security-devices/access?tab=activity&days=all&q=Ana')
        ->assertInertia(fn (Assert $page) => $page->where('activity.meta.total', 1));
});

it('offers changes only to people who manage door access', function (): void {
    $this->actingAs($this->viewer)->get('/security-devices/access')
        ->assertInertia(fn (Assert $page) => $page->where('can.manage', false));

    $manager = ($this->person)(['securityDevices.viewAny', 'securityDevices.accessControl.view', 'securityDevices.accessControl.manage']);
    $this->actingAs($manager)->get('/security-devices/access')
        ->assertInertia(fn (Assert $page) => $page->where('can.manage', true)->where('consoles.0.policies.0.name', 'House staff'));
});

it('opens on the Clock-ins tab alone for a manager who only sorts out clock-ins from doors (D15)', function (): void {
    $reviewer = ($this->person)(['attendance.doorClockIns.review']);

    $this->actingAs($reviewer)->get('/security-devices/access?tab=people')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('tab', 'clockins')
            ->where('tabs', ['clockins'])
            ->where('consoles', [])
            ->where('counts', ['doors' => 0, 'people' => 0, 'visitors' => 0])
            ->where('people', null)
            ->where('doors', null)
            ->where('activity', null)
            ->where('can.doors', false)
            ->where('can.roll_call', false)
            ->where('can.manage', false)
            ->where('emergency_actions', [])
            ->has('clockins.rows')
            ->has('clockins_header.meter'));

    // Still no door data elsewhere: the roll call names who may be in the house.
    $this->actingAs($reviewer)->get('/security-devices/access/sites/'.$this->home->id.'/roll-call')->assertForbidden();
});

it('adds the Clock-ins tab after the door views for door viewers who also sort out clock-ins', function (): void {
    $both = ($this->person)(['securityDevices.viewAny', 'securityDevices.accessControl.view', 'attendance.doorClockIns.review']);

    $this->actingAs($both)->get('/security-devices/access')
        ->assertInertia(fn (Assert $page) => $page
            ->where('tab', 'doors')
            ->where('tabs', ['doors', 'people', 'visitors', 'schedules', 'activity', 'clockins'])
            ->where('clockins', null)
            ->has('clockins_header.count'));

    $this->actingAs($this->viewer)->get('/security-devices/access?tab=clockins')
        ->assertInertia(fn (Assert $page) => $page
            ->where('tab', 'doors')
            ->where('tabs', ['doors', 'people', 'visitors', 'schedules', 'activity'])
            ->where('clockins_header', null));
});

it('is still refused to someone with neither door access nor clock-ins review', function (): void {
    $this->actingAs(($this->person)([]))->get('/security-devices/access')->assertForbidden();
});
