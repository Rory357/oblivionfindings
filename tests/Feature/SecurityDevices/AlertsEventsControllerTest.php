<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Site;
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

        $this->deviceEvent([
            'device_id' => $device->id, 'event_type' => 'heartbeat',
            'severity' => 'info', 'occurred_at' => now(),
            'processed_at' => null,
        ]);
        $this->deviceEvent([
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

        $this->deviceEvent([
            'device_id' => $device->id, 'event_type' => 'heartbeat',
            'severity' => 'info', 'occurred_at' => now(),
            'processed_at' => null,
        ]);
        $this->deviceEvent([
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

    public function test_rows_stats_vocabularies_search_and_forced_device_follow_occurred_at_custody_after_transfer(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $viewerA = $this->siteViewer($siteA, ['securityDevices.events.view']);
        $viewerB = $this->siteViewer($siteB, ['securityDevices.events.view']);
        $device = Device::factory()->create(['name' => 'Transferred sensor']);
        $transferAt = now()->subHours(2)->startOfSecond();

        $this->siteAssignment($device, $siteA, now()->subHours(6), $transferAt);
        $this->siteAssignment($device, $siteB, $transferAt);

        $eventA = $this->deviceEvent([
            'device_id' => $device->id,
            'event_type' => 'site_a_only_event',
            'severity' => 'critical',
            'source' => 'site-a-private-source',
            'occurred_at' => $transferAt->copy()->subHour(),
        ]);
        $eventB = $this->deviceEvent([
            'device_id' => $device->id,
            'event_type' => 'site_b_only_event',
            'severity' => 'warning',
            'source' => 'site-b-private-source',
            'occurred_at' => $transferAt->copy()->addHour(),
        ]);

        $responseA = $this->actingAs($viewerA)
            ->get('/security-devices/alerts-events?search=site_a_only');
        $responseA->assertOk()->assertInertia(function ($page) use ($eventA): void {
            $props = $page->toArray()['props'];

            $this->assertSame(1, $props['stats']['total24h']);
            $this->assertSame(1, $props['stats']['critical24h']);
            $this->assertSame(0, $props['stats']['warning24h']);
            $this->assertSame(1, $props['stats']['unprocessed']);
            $this->assertSame(1, $props['events']['meta']['total']);
            $this->assertSame([$eventA->id], collect($props['events']['data'])->pluck('id')->all());
            $this->assertSame(['site_a_only_event'], $props['filterOptions']['eventTypes']);
            $this->assertSame(['site-a-private-source'], $props['filterOptions']['sources']);
        });
        $responseA->assertDontSee('site_b_only_event')->assertDontSee('site-b-private-source');

        $this->actingAs($viewerA)
            ->get("/security-devices/alerts-events?device_id={$device->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('events.meta.total', 1)
                ->where('events.data.0.id', $eventA->id));

        $responseB = $this->actingAs($viewerB)
            ->get("/security-devices/alerts-events?device_id={$device->id}");
        $responseB->assertOk()->assertInertia(function ($page) use ($eventB): void {
            $props = $page->toArray()['props'];

            $this->assertSame(1, $props['stats']['total24h']);
            $this->assertSame(0, $props['stats']['critical24h']);
            $this->assertSame(1, $props['stats']['warning24h']);
            $this->assertSame(1, $props['stats']['unprocessed']);
            $this->assertSame([$eventB->id], collect($props['events']['data'])->pluck('id')->all());
            $this->assertSame(['site_b_only_event'], $props['filterOptions']['eventTypes']);
            $this->assertSame(['site-b-private-source'], $props['filterOptions']['sources']);
        });
        $responseB->assertDontSee('site_a_only_event')->assertDontSee('site-a-private-source');
    }

    public function test_temporal_custody_is_applied_before_pagination_totals(): void
    {
        $visibleSite = Site::factory()->create();
        $foreignSite = Site::factory()->create();
        $viewer = $this->siteViewer($visibleSite, ['securityDevices.events.view']);
        $visibleDevice = Device::factory()->create();
        $foreignDevice = Device::factory()->create();
        $occurredAt = now()->subHour()->startOfSecond();

        $this->siteAssignment($visibleDevice, $visibleSite, now()->subDay());
        $this->siteAssignment($foreignDevice, $foreignSite, now()->subDay());

        for ($index = 1; $index <= 51; $index++) {
            $this->deviceEvent([
                'device_id' => $visibleDevice->id,
                'event_type' => 'visible-page-event',
                'severity' => 'info',
                'source' => 'visible-page-source',
                'occurred_at' => $occurredAt->copy()->addSeconds($index),
            ]);
        }
        $this->deviceEvent([
            'device_id' => $foreignDevice->id,
            'event_type' => 'foreign-page-event',
            'severity' => 'critical',
            'source' => 'foreign-page-source',
            'occurred_at' => $occurredAt,
        ]);

        $response = $this->actingAs($viewer)->get('/security-devices/alerts-events?page=2');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->where('stats.total24h', 51)
            ->where('stats.critical24h', 0)
            ->where('stats.unprocessed', 51)
            ->where('events.meta.current_page', 2)
            ->where('events.meta.last_page', 2)
            ->where('events.meta.total', 51)
            ->has('events.data', 1)
            ->where('events.data.0.event_type', 'visible-page-event'));
        $response->assertDontSee('foreign-page-event')->assertDontSee('foreign-page-source');
    }

    public function test_no_site_fails_closed_and_global_scope_never_replaces_the_events_action(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $deviceA = Device::factory()->create();
        $deviceB = Device::factory()->create();
        $this->siteAssignment($deviceA, $siteA, now()->subDay());
        $this->siteAssignment($deviceB, $siteB, now()->subDay());
        $this->deviceEvent([
            'device_id' => $deviceA->id,
            'event_type' => 'global-site-a-event',
            'severity' => 'info',
            'source' => 'global-site-a-source',
            'occurred_at' => now()->subHour(),
        ]);
        $this->deviceEvent([
            'device_id' => $deviceB->id,
            'event_type' => 'global-site-b-event',
            'severity' => 'warning',
            'source' => 'global-site-b-source',
            'occurred_at' => now()->subHour(),
        ]);

        $actionOnly = $this->permissionViewer(['securityDevices.events.view']);
        $this->actingAs($actionOnly)
            ->get('/security-devices/alerts-events')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('stats.total24h', 0)
                ->where('stats.warning24h', 0)
                ->where('events.meta.total', 0)
                ->has('filterOptions.eventTypes', 0)
                ->has('filterOptions.sources', 0));

        $globalOnly = $this->permissionViewer(['securityDevices.devices.viewAllSites']);
        $this->actingAs($globalOnly)
            ->get('/security-devices/alerts-events')
            ->assertForbidden();

        $globalWithAction = $this->permissionViewer([
            'securityDevices.devices.viewAllSites',
            'securityDevices.events.view',
        ]);
        $this->actingAs($globalWithAction)
            ->get('/security-devices/alerts-events')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('stats.total24h', 2)
                ->where('stats.warning24h', 1)
                ->where('stats.unprocessed', 2)
                ->where('events.meta.total', 2));
    }

    public function test_foreign_missing_malformed_and_mixed_custody_forced_device_ids_are_concealed(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $viewerA = $this->siteViewer($siteA, ['securityDevices.events.view']);
        $viewerB = $this->siteViewer($siteB, ['securityDevices.events.view']);
        $foreignDevice = Device::factory()->create();
        $mixedDevice = Device::factory()->create();
        $deletedDevice = Device::factory()->create();
        $assignedAt = now()->subDay();
        $mixedOccurredAt = now()->subHour()->startOfSecond();
        $mixedReleasedAt = $mixedOccurredAt->copy()->addMinutes(30);

        $this->siteAssignment($foreignDevice, $siteB, $assignedAt);
        $this->siteAssignment($mixedDevice, $siteA, $assignedAt, $mixedReleasedAt);
        $this->siteAssignment($mixedDevice, $siteB, $assignedAt, $mixedReleasedAt);
        $this->siteAssignment($deletedDevice, $siteA, $assignedAt);
        DeviceEvent::query()->create([
            'device_id' => $foreignDevice->id,
            'event_type' => 'foreign-direct-secret',
            'severity' => 'critical',
            'source' => 'foreign-direct-source',
            'occurred_at' => now()->subHour(),
        ]);
        DeviceEvent::query()->create([
            'device_id' => $mixedDevice->id,
            'event_type' => 'mixed-custody-secret',
            'severity' => 'critical',
            'source' => 'mixed-custody-source',
            'occurred_at' => $mixedOccurredAt,
        ]);
        DeviceEvent::query()->create([
            'device_id' => $deletedDevice->id,
            'event_type' => 'deleted-device-secret',
            'severity' => 'critical',
            'source' => 'deleted-device-source',
            'occurred_at' => now()->subHour(),
        ]);
        $deletedDevice->delete();

        foreach ([$foreignDevice->id, $mixedDevice->id, $deletedDevice->id, 999999999] as $concealedId) {
            $this->actingAs($viewerA)
                ->get("/security-devices/alerts-events?device_id={$concealedId}")
                ->assertNotFound();
        }
        $this->actingAs($viewerA)
            ->get('/security-devices/alerts-events?device_id=not-an-id')
            ->assertNotFound();
        $this->actingAs($viewerB)
            ->get("/security-devices/alerts-events?device_id={$mixedDevice->id}")
            ->assertNotFound();

        $response = $this->actingAs($viewerA)->get('/security-devices/alerts-events');
        $response->assertOk()->assertInertia(fn ($page) => $page
            ->where('stats.total24h', 0)
            ->where('stats.critical24h', 0)
            ->where('events.meta.total', 0)
            ->has('filterOptions.eventTypes', 0)
            ->has('filterOptions.sources', 0));
        $response->assertDontSee('foreign-direct-secret')
            ->assertDontSee('foreign-direct-source')
            ->assertDontSee('mixed-custody-secret')
            ->assertDontSee('mixed-custody-source')
            ->assertDontSee('deleted-device-secret')
            ->assertDontSee('deleted-device-source');
    }

    public function test_quarantine_history_requires_explicit_unknown_stock_authority_without_erasing_owned_history(): void
    {
        $site = Site::factory()->create();
        $siteViewer = $this->siteViewer($site, ['securityDevices.events.view']);
        $historicalDevice = Device::factory()->create(['status' => DeviceStatus::Quarantined]);
        $unknownDevice = Device::factory()->create(['status' => DeviceStatus::Quarantined]);
        $releasedAt = now()->subHours(2)->startOfSecond();
        $this->siteAssignment($historicalDevice, $site, now()->subDay(), $releasedAt);
        $ownedEvent = DeviceEvent::query()->create([
            'device_id' => $historicalDevice->id,
            'event_type' => 'owned-before-quarantine',
            'severity' => 'warning',
            'source' => 'owned-quarantine-source',
            'occurred_at' => $releasedAt->copy()->subHour(),
        ]);
        DeviceEvent::query()->create([
            'device_id' => $unknownDevice->id,
            'event_type' => 'unknown-quarantine-event',
            'severity' => 'critical',
            'source' => 'unknown-quarantine-source',
            'occurred_at' => now()->subHour(),
        ]);

        $siteResponse = $this->actingAs($siteViewer)->get('/security-devices/alerts-events');
        $siteResponse->assertOk()->assertInertia(fn ($page) => $page
            ->where('stats.total24h', 1)
            ->where('stats.critical24h', 0)
            ->where('events.meta.total', 1)
            ->where('events.data.0.id', $ownedEvent->id));
        $siteResponse->assertDontSee('unknown-quarantine-event')->assertDontSee('unknown-quarantine-source');

        $globalWithoutUnknownStock = $this->permissionViewer([
            'securityDevices.devices.viewAllSites',
            'securityDevices.events.view',
        ]);
        $this->actingAs($globalWithoutUnknownStock)
            ->get('/security-devices/alerts-events')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('stats.total24h', 1)
                ->where('events.meta.total', 1));

        $globalWithUnknownStock = $this->permissionViewer([
            'securityDevices.devices.viewAllSites',
            'securityDevices.devices.viewUnassigned',
            'securityDevices.events.view',
        ]);
        $this->actingAs($globalWithUnknownStock)
            ->get('/security-devices/alerts-events')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('stats.total24h', 2)
                ->where('stats.critical24h', 1)
                ->where('events.meta.total', 2));
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

    /** @param list<string> $permissions */
    private function siteViewer(Site $site, array $permissions): User
    {
        $viewer = $this->permissionViewer($permissions);
        HrEmployeeProfile::factory()->create([
            'user_id' => $viewer->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
        ]);

        return $viewer;
    }

    /** @param list<string> $permissions */
    private function permissionViewer(array $permissions): User
    {
        $permissions = array_values(array_unique([
            'securityDevices.viewAny',
            ...$permissions,
        ]));
        $viewer = User::factory()->create(['approved_at' => now()]);
        $permissionModels = Permission::query()->whereIn('key', $permissions)->get();
        $this->assertCount(count($permissions), $permissionModels, 'A required permission was not seeded.');
        $viewer->permissionOverrides()->syncWithoutDetaching(
            $permissionModels->mapWithKeys(fn (Permission $permission): array => [
                $permission->id => ['allowed' => true],
            ]),
        );

        return $viewer;
    }

    /** @param array<string, mixed> $attributes */
    private function deviceEvent(array $attributes): DeviceEvent
    {
        return DeviceEvent::withoutEvents(
            fn (): DeviceEvent => DeviceEvent::query()->create($attributes),
        );
    }

    private function siteAssignment(
        Device $device,
        Site $site,
        \DateTimeInterface $assignedAt,
        ?\DateTimeInterface $releasedAt = null,
    ): DeviceAssignment {
        return DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'custody_site_id' => $site->id,
            'assigned_at' => $assignedAt,
            'released_at' => $releasedAt,
        ]);
    }
}
