<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Alerts\DeviceAlertEngine;
use App\Domain\SecurityDevices\Alerts\Models\DeviceAlert;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Screens\Http\CareAlertController;
use App\Models\Client;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Notifications\SecurityDeviceAlertNotification;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Support\Str;

/*
 * Safety fix round (7 Nov 2026, Stephan: "Open just that alert"). With one narrow key, a support
 * worker or team lead opens ONLY a care or safety alert that was sent to them, at a house they work
 * at, and says they're attending. No snooze, no other alerts, no Alerts page. The push opens it.
 * The key also lets them reach their own house's Overview (pg-sdr-1c), and nothing else in S&D.
 */

beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    $this->seed(SecurityDevicesPermissionsSeeder::class);
    $this->site = Site::factory()->create(['name' => 'Synthetic House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
    $this->other = Site::factory()->create(['name' => 'Synthetic Lodge', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
    $this->person = function (array $keys, ?Site $site = null, ?string $name = null): User {
        $user = User::factory()->create(['approved_at' => now(), 'name' => $name ?? 'Synthetic '.Str::random(6)]);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => ($site ?? $this->site)->id, 'secondary_site_ids' => [], 'is_active' => true]);
        foreach ($keys as $key) {
            $user->permissionOverrides()->attach(Permission::query()->where('key', $key)->firstOrFail()->id, ['allowed' => true]);
        }

        return $user->fresh();
    };
    $this->onShift = function (User $user, ?Site $site = null): void {
        $site ??= $this->site;
        $client = Client::factory()->create(['site_id' => $site->id]);
        Shift::factory()->create([
            'client_id' => $client->id, 'site_id' => $site->id, 'user_id' => $user->id,
            'starts_at' => now()->subHour(), 'ends_at' => now()->addHours(6), 'status' => 'scheduled',
        ]);
    };
    $this->device = function (string $name, ?Site $site = null): Device {
        $device = Device::factory()->create(['name' => $name, 'domain' => 'security', 'category' => 'alarm']);
        DeviceAssignment::query()->create([
            'device_id' => $device->id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => ($site ?? $this->site)->id,
            'assignment_type' => 'permanent', 'assigned_at' => now()->subWeek(),
        ]);

        return $device;
    };
    $this->worker = ($this->person)([CareAlertController::KEY], null, 'Synthetic Worker');
    ($this->onShift)($this->worker);
    $this->button = ($this->device)('Synthetic lounge button');
    app(DeviceAlertEngine::class)->onSafetyEvent($this->button, 'panic', CarbonImmutable::now('UTC'));
    $this->alert = DeviceAlert::query()->where('kind', 'panic')->sole();
});

it('gives support workers and team leads the key, and nobody else by default', function (): void {
    $holders = Role::query()->whereHas('permissions', fn ($permissions) => $permissions->where('key', CareAlertController::KEY))->pluck('name')->all();

    expect($holders)->toContain('support_worker')->toContain('team_lead');
});

it('pushes a link to the one alert, and opens it with what happened and when the on-call manager is told', function (): void {
    $sent = $this->worker->notifications()->where('type', SecurityDeviceAlertNotification::class)->sole();
    expect($sent->data['url'])->toBe('/security-devices/care-alerts/'.$this->alert->id)
        ->and($sent->data['context']['alert_id'])->toBe((int) $this->alert->id);

    $this->actingAs($this->worker)->get('/security-devices/care-alerts/'.$this->alert->id)->assertOk()
        ->assertInertia(fn ($page) => $page->component('security-devices/care-alert')
            ->where('alert.id', (int) $this->alert->id)
            ->where('alert.title', 'Synthetic lounge button: panic button pressed')
            ->where('alert.site', 'Synthetic House')
            ->where('alert.active', true)
            ->where('alert.attending', null)
            ->where('alert.escalation.state', 'will')
            ->where('alert.escalation.minutes', 2)
            ->missing('alert.can'));
});

it('lets them say they’re attending, and everyone told sees it', function (): void {
    $this->actingAs($this->worker)->postJson('/security-devices/care-alerts/'.$this->alert->id.'/attend', ['note' => 'On my way to the lounge'])
        ->assertOk()->assertJsonPath('message', 'Everyone told knows you’re on it.');

    $alert = $this->alert->fresh();
    expect((int) $alert->acknowledged_by_user_id)->toBe($this->worker->id)
        ->and($alert->acknowledgement_note)->toBe('On my way to the lounge');
    $this->actingAs($this->worker)->get('/security-devices/care-alerts/'.$this->alert->id)->assertOk()
        ->assertInertia(fn ($page) => $page->where('alert.attending.you', true));

    // Done: no more attending.
    app(DeviceAlertEngine::class)->done($alert, ($this->person)(['securityDevices.viewAny', 'securityDevices.alerts.acknowledge']), 'Checked');
    $this->actingAs($this->worker)->postJson('/security-devices/care-alerts/'.$this->alert->id.'/attend')->assertStatus(409);
});

it('gives them nothing else: no snooze, no Alerts page, no other Security & devices page', function (): void {
    $this->actingAs($this->worker)->postJson("/security-devices/alerts/{$this->alert->id}/snooze", ['until' => now()->addMinutes(10)->toIso8601String()])->assertForbidden();
    $this->actingAs($this->worker)->postJson("/security-devices/alerts/{$this->alert->id}/acknowledge", ['remind' => false])->assertForbidden();
    $this->actingAs($this->worker)->get('/security-devices/alerts')->assertForbidden();
    $this->actingAs($this->worker)->get('/security-devices/devices')->assertForbidden();
    $this->actingAs($this->worker)->get('/security-devices/sites/'.$this->site->id)->assertForbidden();
});

it('opens only alerts sent to them, at their own houses, of the safety and care kinds', function (): void {
    // Someone else with the key, at the same house, who wasn't told.
    $notTold = ($this->person)([CareAlertController::KEY]);
    $this->actingAs($notTold)->get('/security-devices/care-alerts/'.$this->alert->id)->assertNotFound();
    $this->actingAs($notTold)->postJson('/security-devices/care-alerts/'.$this->alert->id.'/attend')->assertNotFound();

    // A tamper alert sent to them is an ordinary device alert: not this page.
    app(DeviceAlertEngine::class)->onSafetyEvent($this->button, 'tamper', CarbonImmutable::now('UTC'));
    $tamper = DeviceAlert::query()->where('kind', 'tamper')->sole();
    expect($this->worker->notifications()->where('data->context->alert_id', $tamper->id)->exists())->toBeTrue();
    $this->actingAs($this->worker)->get('/security-devices/care-alerts/'.$tamper->id)->assertNotFound();

    // A notice about another house's alert doesn't open it.
    $far = ($this->device)('Synthetic lodge button', $this->other);
    app(DeviceAlertEngine::class)->onSafetyEvent($far, 'panic', CarbonImmutable::now('UTC'));
    $farAlert = DeviceAlert::query()->where('device_id', $far->id)->sole();
    $this->worker->notifications()->create([
        'id' => (string) Str::uuid(), 'type' => SecurityDeviceAlertNotification::class,
        'data' => ['context' => ['alert_id' => (int) $farAlert->id]],
    ]);
    $this->actingAs($this->worker)->get('/security-devices/care-alerts/'.$farAlert->id)->assertNotFound();

    // No such alert.
    $this->actingAs($this->worker)->get('/security-devices/care-alerts/999999')->assertNotFound();
});

it('refuses people without the key, and sends people who can open alerts to the Alerts page', function (): void {
    $noKey = ($this->person)([]);
    $this->actingAs($noKey)->get('/security-devices/care-alerts/'.$this->alert->id)->assertForbidden();

    $manager = ($this->person)(['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.events.view', 'securityDevices.alerts.acknowledge']);
    $this->actingAs($manager)->get('/security-devices/care-alerts/'.$this->alert->id)
        ->assertRedirect('/security-devices/alerts?alert='.$this->alert->id);
});

it('lets them reach their own house’s Overview, with the alert opening on its own page', function (): void {
    $this->actingAs($this->worker)->get('/security-devices')->assertOk()
        ->assertInertia(fn ($page) => $page->where('view', 'worker')
            ->where('safety.0.id', (int) $this->alert->id)
            ->where('safety.0.href', '/security-devices/care-alerts/'.$this->alert->id));

    $this->actingAs(($this->person)([]))->get('/security-devices')->assertForbidden();
});
