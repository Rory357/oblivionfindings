<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AlertsEventsControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $viewer;

    private User $noPerms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());

        // Support worker does NOT have events.view.
        $this->viewer = User::factory()->create();
        $this->viewer->roles()->attach(Role::where('name', 'support_worker')->first());

        $this->noPerms = User::factory()->create();
    }

    // ── Authorization ─────────────────────────────────────────────

    public function test_index_requires_authentication(): void
    {
        $this->get('/security-devices/alerts-events')->assertRedirect('/login');
    }

    public function test_index_requires_events_view_permission(): void
    {
        $this->actingAs($this->viewer)
            ->get('/security-devices/alerts-events')
            ->assertForbidden();
    }

    public function test_index_accessible_with_permission(): void
    {
        $this->actingAs($this->admin)
            ->get('/security-devices/alerts-events')
            ->assertOk();
    }

    // ── Index returns correct structure ───────────────────────────

    public function test_index_returns_expected_props(): void
    {
        $this->actingAs($this->admin)
            ->get('/security-devices/alerts-events')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('security-devices/alerts-events')
                ->has('stats')
                ->has('events.data')
                ->has('events.meta')
                ->has('filters')
                ->has('filterOptions')
            );
    }

    // ── Stats ─────────────────────────────────────────────────────

    public function test_stats_count_last_24h(): void
    {
        $device = Device::factory()->create();

        // Recent events (within 24h).
        DeviceEvent::create([
            'device_id' => $device->id, 'event_type' => 'alarm_trigger',
            'severity' => 'critical', 'occurred_at' => now()->subHours(2),
        ]);
        DeviceEvent::create([
            'device_id' => $device->id, 'event_type' => 'battery_low',
            'severity' => 'warning', 'occurred_at' => now()->subHours(6),
        ]);
        DeviceEvent::create([
            'device_id' => $device->id, 'event_type' => 'heartbeat',
            'severity' => 'info', 'occurred_at' => now()->subHours(1),
        ]);

        // Old event (outside 24h).
        DeviceEvent::create([
            'device_id' => $device->id, 'event_type' => 'heartbeat',
            'severity' => 'info', 'occurred_at' => now()->subDays(3),
        ]);

        $response = $this->actingAs($this->admin)->get('/security-devices/alerts-events');

        $response->assertInertia(fn ($page) => $page
            ->where('stats.total24h', 3)
            ->where('stats.critical24h', 1)
            ->where('stats.warning24h', 1)
        );
    }

    public function test_stats_count_unprocessed(): void
    {
        $device = Device::factory()->create();

        DeviceEvent::create([
            'device_id' => $device->id, 'event_type' => 'heartbeat',
            'severity' => 'info', 'occurred_at' => now(),
            'processed_at' => null,
        ]);
        DeviceEvent::create([
            'device_id' => $device->id, 'event_type' => 'heartbeat',
            'severity' => 'info', 'occurred_at' => now(),
            'processed_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->get('/security-devices/alerts-events');

        $response->assertInertia(fn ($page) => $page
            ->where('stats.unprocessed', 1)
        );
    }

    // ── Severity filter ───────────────────────────────────────────

    public function test_filter_by_severity(): void
    {
        $device = Device::factory()->create();

        DeviceEvent::create([
            'device_id' => $device->id, 'event_type' => 'alarm_trigger',
            'severity' => 'critical', 'occurred_at' => now(),
        ]);
        DeviceEvent::create([
            'device_id' => $device->id, 'event_type' => 'heartbeat',
            'severity' => 'info', 'occurred_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get('/security-devices/alerts-events?severity=critical');

        $response->assertInertia(fn ($page) => $page
            ->has('events.data', 1)
        );
    }

    // ── Event type filter ─────────────────────────────────────────

    public function test_filter_by_event_type(): void
    {
        $device = Device::factory()->create();

        DeviceEvent::create([
            'device_id' => $device->id, 'event_type' => 'alarm_trigger',
            'severity' => 'critical', 'occurred_at' => now(),
        ]);
        DeviceEvent::create([
            'device_id' => $device->id, 'event_type' => 'heartbeat',
            'severity' => 'info', 'occurred_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get('/security-devices/alerts-events?event_type=alarm_trigger');

        $response->assertInertia(fn ($page) => $page
            ->has('events.data', 1)
        );
    }

    // ── Device scoping ────────────────────────────────────────────

    public function test_filter_by_device_id(): void
    {
        $deviceA = Device::factory()->create();
        $deviceB = Device::factory()->create();

        DeviceEvent::create([
            'device_id' => $deviceA->id, 'event_type' => 'heartbeat',
            'severity' => 'info', 'occurred_at' => now(),
        ]);
        DeviceEvent::create([
            'device_id' => $deviceB->id, 'event_type' => 'heartbeat',
            'severity' => 'info', 'occurred_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get("/security-devices/alerts-events?device_id={$deviceA->id}");

        $response->assertInertia(fn ($page) => $page
            ->has('events.data', 1)
        );
    }

    // ── Domain filter ─────────────────────────────────────────────

    public function test_filter_by_domain(): void
    {
        $secDevice = Device::factory()->security()->create();
        $itDevice = Device::factory()->itInfrastructure()->create();

        DeviceEvent::create([
            'device_id' => $secDevice->id, 'event_type' => 'alarm_trigger',
            'severity' => 'critical', 'occurred_at' => now(),
        ]);
        DeviceEvent::create([
            'device_id' => $itDevice->id, 'event_type' => 'heartbeat',
            'severity' => 'info', 'occurred_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get('/security-devices/alerts-events?domain=security');

        $response->assertInertia(fn ($page) => $page
            ->has('events.data', 1)
        );
    }

    // ── Date range filter ─────────────────────────────────────────

    public function test_filter_by_date_range(): void
    {
        $device = Device::factory()->create();

        DeviceEvent::create([
            'device_id' => $device->id, 'event_type' => 'heartbeat',
            'severity' => 'info', 'occurred_at' => now()->subDays(10),
        ]);
        DeviceEvent::create([
            'device_id' => $device->id, 'event_type' => 'alarm_trigger',
            'severity' => 'critical', 'occurred_at' => now()->subDays(2),
        ]);
        DeviceEvent::create([
            'device_id' => $device->id, 'event_type' => 'heartbeat',
            'severity' => 'info', 'occurred_at' => now(),
        ]);

        $from = now()->subDays(5)->format('Y-m-d');
        $to = now()->subDays(1)->format('Y-m-d');

        $response = $this->actingAs($this->admin)
            ->get("/security-devices/alerts-events?from={$from}&to={$to}");

        $response->assertInertia(fn ($page) => $page
            ->has('events.data', 1) // only the one from 2 days ago
        );
    }

    // ── Processed filter ──────────────────────────────────────────

    public function test_filter_unprocessed_events(): void
    {
        $device = Device::factory()->create();

        DeviceEvent::create([
            'device_id' => $device->id, 'event_type' => 'heartbeat',
            'severity' => 'info', 'occurred_at' => now(),
            'processed_at' => null,
        ]);
        DeviceEvent::create([
            'device_id' => $device->id, 'event_type' => 'heartbeat',
            'severity' => 'info', 'occurred_at' => now(),
            'processed_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get('/security-devices/alerts-events?processed=no');

        $response->assertInertia(fn ($page) => $page
            ->has('events.data', 1)
        );
    }

    // ── Search ────────────────────────────────────────────────────

    public function test_search_by_event_type(): void
    {
        $device = Device::factory()->create();

        DeviceEvent::create([
            'device_id' => $device->id, 'event_type' => 'alarm_trigger',
            'severity' => 'critical', 'occurred_at' => now(),
        ]);
        DeviceEvent::create([
            'device_id' => $device->id, 'event_type' => 'heartbeat',
            'severity' => 'info', 'occurred_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)
            ->get('/security-devices/alerts-events?search=alarm');

        $response->assertInertia(fn ($page) => $page
            ->has('events.data', 1)
        );
    }

    // ── Filter options ────────────────────────────────────────────

    public function test_filter_options_include_event_types(): void
    {
        $device = Device::factory()->create();

        DeviceEvent::create([
            'device_id' => $device->id, 'event_type' => 'alarm_trigger',
            'severity' => 'critical', 'occurred_at' => now(),
        ]);
        DeviceEvent::create([
            'device_id' => $device->id, 'event_type' => 'heartbeat',
            'severity' => 'info', 'occurred_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->get('/security-devices/alerts-events');

        $response->assertInertia(function ($page) {
            $types = $page->toArray()['props']['filterOptions']['eventTypes'];
            $this->assertContains('alarm_trigger', $types);
            $this->assertContains('heartbeat', $types);
        });
    }

    public function test_events_stats_and_filter_options_cover_the_single_application_registry_for_all_sites_users(): void
    {

        $primaryDevice = Device::factory()->create(['name' => 'Primary sensor']);
        $unrelatedDevice = Device::factory()->create(['name' => 'Unrelated sensor']);

        DeviceEvent::create([
            'device_id' => $primaryDevice->id,
            'event_type' => 'primary_event',
            'severity' => 'critical',
            'source' => 'primary-source',
            'occurred_at' => now(),
        ]);
        DeviceEvent::create([
            'device_id' => $unrelatedDevice->id,
            'event_type' => 'unrelated_event',
            'severity' => 'critical',
            'source' => 'unrelated-source',
            'occurred_at' => now(),
        ]);

        $response = $this->actingAs($this->admin)->get('/security-devices/alerts-events');

        $response->assertInertia(function ($page) {
            $props = $page->toArray()['props'];

            $this->assertSame(2, $props['stats']['total24h']);
            $this->assertSame(2, $props['stats']['critical24h']);
            $this->assertEqualsCanonicalizing(
                ['Primary sensor', 'Unrelated sensor'],
                collect($props['events']['data'])->pluck('device_name')->all(),
            );
            $this->assertSame(['primary_event', 'unrelated_event'], $props['filterOptions']['eventTypes']);
            $this->assertSame(['primary-source', 'unrelated-source'], $props['filterOptions']['sources']);
        });
    }

    // ── Events ordered newest first ───────────────────────────────

    public function test_events_ordered_newest_first(): void
    {
        $device = Device::factory()->create();

        $older = DeviceEvent::create([
            'device_id' => $device->id, 'event_type' => 'heartbeat',
            'severity' => 'info', 'occurred_at' => now()->subHours(5),
        ]);
        $newer = DeviceEvent::create([
            'device_id' => $device->id, 'event_type' => 'alarm_trigger',
            'severity' => 'critical', 'occurred_at' => now()->subMinutes(10),
        ]);

        $response = $this->actingAs($this->admin)->get('/security-devices/alerts-events');

        $response->assertInertia(function ($page) use ($newer, $older) {
            $data = $page->toArray()['props']['events']['data'];
            $this->assertEquals($newer->id, $data[0]['id']);
            $this->assertEquals($older->id, $data[1]['id']);
        });
    }
}
