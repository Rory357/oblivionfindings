<?php

namespace Tests\Feature\ControlRoom;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Models\ControlRoom\Device as ControlRoomDevice;
use App\Models\ControlRoom\SignalSource;
use App\Models\Permission;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Monitoring › Coverage is read only and follows the Signals list's boundary:
 * controlRoom.viewAny, the operator's own Sites, and the projection scope.
 */
class ControlRoomCoverageControllerTest extends TestCase
{
    use RefreshDatabase;

    private Site $kowhai;

    private Site $totara;

    private Site $hidden;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        $this->kowhai = Site::factory()->create(['name' => 'Kowhai Lodge']);
        $this->totara = Site::factory()->create(['name' => 'Totara House']);
        $this->hidden = Site::factory()->create(['name' => 'Rimu Hidden House']);

        $this->operator = $this->userWith(['controlRoom.viewAny']);
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->operator->id,
            'primary_site_id' => $this->kowhai->id,
            'secondary_site_ids' => [$this->totara->id],
        ]);
    }

    /** @param list<string> $keys */
    private function userWith(array $keys): User
    {
        $user = User::factory()->create(['approved_at' => now()]);
        $permissions = Permission::query()->whereIn('key', $keys)->get();
        $this->assertCount(count($keys), $permissions);
        $user->permissionOverrides()->sync(
            $permissions->mapWithKeys(fn (Permission $permission): array => [
                $permission->id => ['allowed' => true],
            ]),
        );

        return $user;
    }

    private function device(Site $site, string $type, mixed $lastSignalAt, ?int $sourceId = null): ControlRoomDevice
    {
        return ControlRoomDevice::query()->create([
            'name' => "{$site->name} {$type}",
            'type' => $type,
            'site_id' => $site->id,
            'signal_source_id' => $sourceId,
            'last_signal_at' => $lastSignalAt,
        ]);
    }

    public function test_operator_sees_one_row_per_house_and_source_with_plain_states(): void
    {
        $gateway = SignalSource::query()->create([
            'name' => 'Milesight gateway',
            'slug' => 'milesight-gateway',
            'vendor' => 'Milesight',
            'status' => 'active',
        ]);
        $this->device($this->kowhai, ControlRoomDevice::TYPE_SENSOR, now()->subMinutes(5), $gateway->id);
        $this->device($this->kowhai, ControlRoomDevice::TYPE_SENSOR, now()->subHour(), $gateway->id);
        // One of two bed sensors went quiet: the row is quiet.
        $this->device($this->kowhai, ControlRoomDevice::TYPE_BED_SENSOR, now()->subMinutes(10));
        $this->device($this->kowhai, ControlRoomDevice::TYPE_BED_SENSOR, now()->subDays(3));
        $this->device($this->kowhai, ControlRoomDevice::TYPE_CAMERA, null);
        // Another Site's source must not show, nor make its house appear.
        $this->device($this->hidden, ControlRoomDevice::TYPE_ALARM_PANEL, now());

        $this->actingAs($this->operator)
            ->get('/control-room/coverage')
            ->assertOk()
            ->assertInertia(function ($page): void {
                $page->component('control-room/coverage');
                $props = $page->toArray()['props'];
                $rows = collect($props['rows']);

                // Problems first: quiet, never reported, no monitoring, then reporting.
                $this->assertSame(
                    ['quiet', 'never', 'none', 'reporting'],
                    $rows->pluck('state')->all(),
                );

                $quiet = $rows->firstWhere('state', 'quiet');
                $this->assertSame('Kowhai Lodge', $quiet['site_name']);
                $this->assertSame(ControlRoomDevice::TYPE_BED_SENSOR, $quiet['type']);
                $this->assertSame(2, $quiet['devices']);
                $this->assertSame(1, $quiet['quiet']);
                $this->assertSame(1, $quiet['reporting']);

                $reporting = $rows->firstWhere('state', 'reporting');
                $this->assertSame(ControlRoomDevice::TYPE_SENSOR, $reporting['type']);
                $this->assertSame(['Milesight gateway'], $reporting['sources']);
                $this->assertNotNull($reporting['last_signal_at']);

                $this->assertNull($rows->firstWhere('state', 'never')['last_signal_at']);

                $none = $rows->firstWhere('state', 'none');
                $this->assertSame($this->totara->id, $none['site_id']);
                $this->assertNull($none['type']);

                $this->assertSame([
                    'houses' => 2,
                    'houses_covered' => 1,
                    'sources' => 3,
                    'reporting' => 1,
                    'quiet' => 1,
                    'never' => 1,
                    'no_monitoring' => 1,
                ], $props['stats']);
                $this->assertSame(24, $props['quiet_after_hours']);
                $this->assertSame(
                    [$this->kowhai->id, $this->totara->id],
                    collect($props['sites'])->pluck('id')->all(),
                );
                $this->assertFalse($props['can']['view_canonical_devices']);
                $this->assertNull($props['canonicalIndexUrl']);

                $encoded = json_encode($props, JSON_THROW_ON_ERROR);
                $this->assertStringNotContainsString('Rimu Hidden House', $encoded);
                $this->assertStringNotContainsString(ControlRoomDevice::TYPE_ALARM_PANEL, json_encode($props['rows'], JSON_THROW_ON_ERROR));
            });
    }

    public function test_filters_narrow_the_rows_but_the_numbers_stay_for_the_house_and_source(): void
    {
        $this->device($this->kowhai, ControlRoomDevice::TYPE_SENSOR, now());
        $this->device($this->kowhai, ControlRoomDevice::TYPE_BED_SENSOR, now()->subDays(2));
        $this->device($this->totara, ControlRoomDevice::TYPE_BED_SENSOR, now());

        $this->actingAs($this->operator)
            ->get('/control-room/coverage?state=quiet')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('rows', 1)
                ->where('rows.0.state', 'quiet')
                ->where('rows.0.site_id', $this->kowhai->id)
                ->where('stats.reporting', 2)
                ->where('stats.quiet', 1)
                ->where('filters.state', 'quiet')
            );

        // A source kind: houses without it read as having no monitoring of that kind.
        $this->actingAs($this->operator)
            ->get("/control-room/coverage?type=sensor&site_id={$this->totara->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('rows', 1)
                ->where('rows.0.state', 'none')
                ->where('rows.0.site_id', $this->totara->id)
                ->where('stats.houses', 1)
                ->where('stats.no_monitoring', 1)
            );
    }

    public function test_security_and_devices_link_only_for_people_who_can_open_it(): void
    {
        $manager = $this->userWith(['controlRoom.viewAny', 'securityDevices.devices.view']);
        HrEmployeeProfile::factory()->create([
            'user_id' => $manager->id,
            'primary_site_id' => $this->kowhai->id,
            'secondary_site_ids' => [],
        ]);

        $this->actingAs($manager)
            ->get('/control-room/coverage')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can.view_canonical_devices', true)
                ->where('canonicalIndexUrl', '/security-devices/devices')
            );
    }

    public function test_people_without_control_room_access_are_refused(): void
    {
        $outsider = User::factory()->create(['approved_at' => now()]);

        $this->actingAs($outsider)
            ->get('/control-room/coverage')
            ->assertForbidden();
    }

    public function test_another_sites_coverage_cannot_be_asked_for(): void
    {
        $this->device($this->hidden, ControlRoomDevice::TYPE_CAMERA, now());

        $this->actingAs($this->operator)
            ->get("/control-room/coverage?site_id={$this->hidden->id}")
            ->assertForbidden();

        $this->actingAs($this->operator)
            ->get('/control-room/coverage?state=everything')
            ->assertSessionHasErrors('state');
    }
}
