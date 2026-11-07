<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Care\ConsentEndings;
use App\Domain\SecurityDevices\Care\DevicePersonLinks;
use App\Domain\SecurityDevices\Care\Models\DevicePersonLink;
use App\Domain\SecurityDevices\Care\SensorMonitoringConsent;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Client;
use App\Models\ConsentType;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use App\Notifications\SecurityDeviceConsentNotification;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\Support\AuthoritativeConsentFixture;

/*
 * Safety fix round (7 Nov 2026), F2: when a resident's consent to monitoring sensors ends, their
 * sensor's care alerts stop. The house manager is warned 14 and 3 days before, and told when it has
 * ended; the sensor page and the house page say "Care alerts stopped: consent ended". The resident
 * is named only to someone who may see their record.
 */

beforeEach(function (): void {
    $this->at = function (string $iso): void {
        CarbonImmutable::setTestNow($iso);
        Carbon::setTestNow($iso);
    };
    ($this->at)('2026-11-07T01:00:00Z');
    Notification::fake();
    $this->seed(RbacSeeder::class);
    $this->seed(SecurityDevicesPermissionsSeeder::class);
    $this->site = Site::factory()->create(['name' => 'Synthetic House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
    $this->other = Site::factory()->create(['name' => 'Synthetic Lodge', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
    $this->person = function (array $keys, ?Site $site = null): User {
        $user = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create(['user_id' => $user->id, 'primary_site_id' => ($site ?? $this->site)->id, 'secondary_site_ids' => [], 'is_active' => true]);
        foreach ($keys as $key) {
            $user->permissionOverrides()->attach(Permission::query()->where('key', $key)->firstOrFail()->id, ['allowed' => true]);
        }

        return $user->fresh();
    };
    $keys = ['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.devices.update', 'securityDevices.people.link'];
    // The house manager who may see the resident's record, one who may not, and one at another house.
    $this->manager = ($this->person)([...$keys, 'clients.viewAny']);
    $this->keeper = ($this->person)($keys);
    $this->elsewhere = ($this->person)($keys, $this->other);
    $this->bed = Device::factory()->create(['name' => 'Synthetic bed sensor', 'domain' => 'iot_healthcare', 'category' => 'environmental', 'manufacturer' => 'Milesight', 'model' => 'EM300-TH', 'last_seen_at' => now()]);
    DeviceAssignment::query()->create([
        'device_id' => $this->bed->id, 'assignable_type' => DeviceAssignment::TARGET_SITE, 'assignable_id' => $this->site->id,
        'assignment_type' => 'permanent', 'assigned_at' => now()->subWeek(),
    ]);
    $this->resident = Client::factory()->create(['site_id' => $this->site->id, 'status' => 'active', 'first_name' => 'Synthetic', 'preferred_name' => null]);
    AuthoritativeConsentFixture::manualSelf(
        $this->resident,
        ConsentType::query()->where('name', SensorMonitoringConsent::TYPE)->firstOrFail(),
        $this->keeper,
        ['status' => 'given', 'given_at' => now()->subMonths(6), 'expires_at' => CarbonImmutable::parse('2026-11-21T01:00:00Z')],
    );
    $this->actingAs($this->keeper)->postJson("/security-devices/devices/{$this->bed->id}/care-link", [
        'client_id' => $this->resident->id, 'purpose' => 'falls', 'rule' => ['minutes' => 10, 'from' => '22:00', 'to' => '06:00'],
    ])->assertCreated();
    $this->notices = fn (User $user): \Illuminate\Support\Collection => Notification::sent($user, SecurityDeviceConsentNotification::class);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    Carbon::setTestNow();
});

it('warns the house manager 14 days and 3 days before consent ends, once each, and nobody at another house', function (): void {
    $endings = app(ConsentEndings::class);

    expect($endings->warn())->toBe(1)
        ->and($endings->warn())->toBe(0);
    $first = ($this->notices)($this->manager)->sole();
    expect($first->what)->toBe('ending')
        ->and($first->title($this->manager))->toBe('Synthetic’s sensor consent ends on Sat 21 Nov 2026')
        // Names follow record access.
        ->and(($this->notices)($this->keeper)->sole()->title($this->keeper))->toBe('A resident’s sensor consent ends on Sat 21 Nov 2026')
        ->and(($this->notices)($this->elsewhere))->toHaveCount(0);

    ($this->at)('2026-11-12T01:00:00Z');
    expect($endings->warn())->toBe(0);

    ($this->at)('2026-11-18T01:00:00Z');
    expect($endings->warn())->toBe(1)
        ->and(($this->notices)($this->manager))->toHaveCount(2)
        ->and($endings->warn())->toBe(0);
});

it('tells the house manager when consent has ended and shows "care alerts stopped" on the sensor and the house', function (): void {
    ($this->at)('2026-11-21T02:00:00Z');

    expect(app(DevicePersonLinks::class)->sweepConsents())->toBe(1)
        ->and(DevicePersonLink::query()->sole()->unlink_reason)->toBe('consent_ended');
    $ended = ($this->notices)($this->manager)->last();
    expect($ended->what)->toBe('ended')
        ->and($ended->title($this->manager))->toBe('Care alerts stopped: Synthetic’s consent ended')
        ->and($ended->toArray($this->manager)['url'])->toBe('/security-devices/devices/'.$this->bed->id)
        ->and(($this->notices)($this->keeper)->last()->title($this->keeper))->toBe('Care alerts stopped: A resident’s consent ended');

    $this->actingAs($this->manager)->get('/security-devices/devices/'.$this->bed->id)->assertOk()
        ->assertInertia(fn ($page) => $page->where('care.link', null)->where('care.stopped.person', 'Synthetic'));
    $this->actingAs($this->keeper)->get('/security-devices/devices/'.$this->bed->id)->assertOk()
        ->assertInertia(fn ($page) => $page->where('care.stopped.person', 'A resident'));
    $this->actingAs($this->manager)->get('/security-devices/sites/'.$this->site->id)->assertOk()
        ->assertInertia(fn ($page) => $page->where('overview.care_stopped.0.device', 'Synthetic bed sensor')
            ->where('overview.care_stopped.0.person', 'Synthetic'));

    // After 30 days the note goes.
    ($this->at)('2026-12-22T02:00:00Z');
    expect(app(ConsentEndings::class)->recentStops($this->manager, $this->site->id))->toBe([]);
});
