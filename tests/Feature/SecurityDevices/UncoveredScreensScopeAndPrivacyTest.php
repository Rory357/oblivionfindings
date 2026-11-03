<?php

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Discovery\Models\DiscoveryCandidate;
use App\Domain\Monitoring\Discovery\Models\DiscoveryRun;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Alerts\Models\DeviceAlert;
use App\Domain\SecurityDevices\Alerts\Models\DeviceAlertEvent;
use App\Domain\SecurityDevices\Care\Models\DevicePersonLink;
use App\Domain\SecurityDevices\Care\SensorMonitoringConsent;
use App\Domain\SecurityDevices\Enums\RelationshipType;
use App\Domain\SecurityDevices\Iot\Relay\Models\SensorGatewayLogin;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\Models\DeviceMaintenanceRecord;
use App\Domain\SecurityDevices\Models\DeviceRelationship;
use App\Models\Client;
use App\Models\ConsentType;
use App\Models\Permission;
use App\Models\Shift;
use App\Models\Site;
use App\Models\User;
use App\Notifications\SecurityDeviceAlertNotification;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\AuthoritativeConsentFixture;

/**
 * S&D Task 10: Site-scope and resident-privacy coverage for previously uncovered S&D screens
 * and services (SensorGatewayOverview, WorkerOverviewPresenter care privacy, FoundDevicesController,
 * and ListExportsController).
 */
beforeEach(function (): void {
    $this->seed(RbacSeeder::class);
    $this->seed(SecurityDevicesPermissionsSeeder::class);

    $this->home = Site::factory()->create(['name' => 'Totara House', 'is_active' => true, 'archived' => false, 'archived_at' => null]);
    $this->other = Site::factory()->create(['name' => 'Kauri Lodge', 'is_active' => true, 'archived' => false, 'archived_at' => null]);

    $this->staffWith = function (array $keys, ?Site $site = null): User {
        $user = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => ($site ?? $this->home)->id,
            'secondary_site_ids' => [],
            'is_active' => true,
        ]);
        foreach ($keys as $key) {
            $user->permissionOverrides()->attach(Permission::query()->where('key', $key)->firstOrFail()->id, ['allowed' => true]);
        }

        return $user->fresh();
    };
});

it('hides another Sites gateway and redacts resident names on SensorGatewayOverview without client access', function (): void {
    $gateway = Device::factory()->create([
        'name' => 'Totara LoRaWAN gateway',
        'domain' => 'iot_healthcare',
        'category' => 'iot_gateway',
        'subcategory' => 'lorawan_gateway',
        'manufacturer' => 'Milesight',
        'model' => 'UG65',
    ]);
    DeviceAssignment::query()->create([
        'device_id' => $gateway->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $this->home->id,
        'assigned_at' => now()->subDays(7),
    ]);

    $otherGateway = Device::factory()->create([
        'name' => 'Kauri LoRaWAN gateway',
        'domain' => 'iot_healthcare',
        'category' => 'iot_gateway',
        'subcategory' => 'lorawan_gateway',
    ]);
    DeviceAssignment::query()->create([
        'device_id' => $otherGateway->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $this->other->id,
        'assigned_at' => now()->subDays(7),
    ]);

    $bedSensor = Device::factory()->create([
        'name' => 'Room 2 bed sensor',
        'domain' => 'iot_healthcare',
        'category' => 'environmental',
    ]);
    DeviceAssignment::query()->create([
        'device_id' => $bedSensor->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $this->home->id,
        'assigned_at' => now()->subDays(7),
    ]);
    $keeper = ($this->staffWith)(['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.people.link']);
    DeviceRelationship::query()->create([
        'parent_device_id' => $gateway->id,
        'child_device_id' => $bedSensor->id,
        'relationship_type' => RelationshipType::ConnectedTo,
        'created_by_user_id' => $keeper->id,
    ]);

    $resident = Client::factory()->create([
        'site_id' => $this->home->id,
        'status' => 'active',
        'first_name' => 'Mereana',
        'last_name' => 'Tahu',
        'preferred_name' => null,
    ]);
    $consent = AuthoritativeConsentFixture::manualSelf(
        $resident,
        ConsentType::query()->where('name', SensorMonitoringConsent::TYPE)->firstOrFail(),
        $keeper,
        ['status' => 'given', 'given_at' => now()->subDay(), 'expires_at' => now()->addMonths(6)],
    );
    DevicePersonLink::query()->create([
        'device_id' => $bedSensor->id,
        'client_id' => $resident->id,
        'consent_id' => $consent->id,
        'purpose' => 'falls',
        'rule' => ['minutes' => 10, 'from' => '22:00', 'to' => '06:00'],
        'linked_by_user_id' => $keeper->id,
        'linked_at' => now()->subDays(3),
    ]);

    SensorGatewayLogin::query()->create([
        'site_id' => $this->home->id,
        'device_id' => $gateway->id,
        'label' => 'Totara gateway broker login',
        'username' => 'gw-totara-secret-user',
        'password_hash' => bcrypt('not-shown'),
        'topic_prefix' => 'sites/totara/ug65/',
        'created_by_user_id' => $keeper->id,
    ]);

    // Care alert at this Site vs an older alert opened while the gateway sat at Kauri Lodge.
    DeviceAlert::query()->create([
        'device_id' => $bedSensor->id,
        'site_id' => $this->home->id,
        'kind' => 'care_out_of_bed',
        'severity' => 'critical',
        'title' => 'Mereana Tahu out of bed for over 10 minutes',
        'state' => DeviceAlert::STATE_OPEN,
        'opened_at' => now()->subMinutes(15),
    ]);
    DeviceAlert::query()->create([
        'device_id' => $gateway->id,
        'site_id' => $this->other->id,
        'kind' => 'offline',
        'severity' => 'critical',
        'title' => 'Kauri Lodge prior gateway outage',
        'state' => DeviceAlert::STATE_DONE,
        'opened_at' => now()->subDays(20),
        'closed_at' => now()->subDays(20)->addMinutes(12),
        'close_reason' => 'recovered',
    ]);

    $itOnly = ($this->staffWith)(['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.events.view']);
    $clinical = ($this->staffWith)(['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.events.view', 'clients.viewAny']);

    $this->actingAs($itOnly)->get("/security-devices/devices/{$otherGateway->id}")->assertNotFound();

    $itResponse = $this->actingAs($itOnly)->get("/security-devices/devices/{$gateway->id}");
    $itResponse->assertOk()->assertInertia(function ($page): void {
        $recent = collect($page->toArray()['props']['overview']['gateway']['recent']);
        $titles = $recent->pluck('title')->all();
        expect($titles)
            ->toContain('A person out of bed for over 10 minutes')
            ->not->toContain('Kauri Lodge prior gateway outage');
        $page->where('overview.gateway.route.logins.0.username', null)
            ->where('overview.gateway.route.logins.0.topic', null);
    });
    expect($itResponse->getContent())
        ->not->toContain('Mereana')
        ->not->toContain('Tahu')
        ->not->toContain('Kauri Lodge')
        ->not->toContain('gw-totara-secret-user');

    $this->actingAs($clinical)->get("/security-devices/devices/{$gateway->id}")
        ->assertOk()
        ->assertInertia(function ($page): void {
            $titles = collect($page->toArray()['props']['overview']['gateway']['recent'])->pluck('title')->all();
            expect($titles)->toContain('Mereana Tahu out of bed for over 10 minutes');
        });
});

it('leaves resident-linked sensors out of WorkerOverview around-the-house and redacts resident names without client access', function (): void {
    $bedSensor = Device::factory()->create([
        'name' => 'Room 5 pad sensor',
        'domain' => 'iot_healthcare',
        'category' => 'environmental',
        'location_description' => 'Room 5',
        'meta' => ['observed' => ['sensor_readings' => ['temperature' => ['value' => 20.5, 'state' => 'healthy']]]],
    ]);
    $hallSensor = Device::factory()->create([
        'name' => 'Hallway temperature sensor',
        'domain' => 'iot_healthcare',
        'category' => 'environmental',
        'location_description' => 'Hallway',
        'meta' => ['observed' => ['sensor_readings' => ['temperature' => ['value' => 21.0, 'state' => 'healthy']]]],
    ]);
    foreach ([$bedSensor, $hallSensor] as $sensor) {
        DeviceAssignment::query()->create([
            'device_id' => $sensor->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $this->home->id,
            'assigned_at' => now()->subDays(5),
        ]);
    }

    $resident = Client::factory()->create([
        'site_id' => $this->home->id,
        'status' => 'active',
        'first_name' => 'Nikau',
        'last_name' => 'Wiremu',
        'preferred_name' => null,
    ]);
    $keeper = ($this->staffWith)(['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.people.link']);
    $consent = AuthoritativeConsentFixture::manualSelf(
        $resident,
        ConsentType::query()->where('name', SensorMonitoringConsent::TYPE)->firstOrFail(),
        $keeper,
        ['status' => 'given', 'given_at' => now()->subDay(), 'expires_at' => now()->addMonths(6)],
    );
    DevicePersonLink::query()->create([
        'device_id' => $bedSensor->id,
        'client_id' => $resident->id,
        'consent_id' => $consent->id,
        'purpose' => 'falls',
        'rule' => ['minutes' => 10, 'from' => '22:00', 'to' => '06:00'],
        'linked_by_user_id' => $keeper->id,
        'linked_at' => now()->subDays(2),
    ]);

    $alert = DeviceAlert::query()->create([
        'device_id' => $bedSensor->id,
        'site_id' => $this->home->id,
        'kind' => 'care_out_of_bed',
        'severity' => 'critical',
        'title' => 'Nikau Wiremu out of bed for over 10 minutes',
        'state' => DeviceAlert::STATE_OPEN,
        'opened_at' => now()->subMinutes(8),
    ]);
    DeviceAlertEvent::query()->create([
        'alert_id' => $alert->id,
        'kind' => 'opened',
        'text' => 'From Nikau Wiremu’s falls plan',
        'detail' => 'Room 5 pad sensor',
        'at' => now()->subMinutes(8),
    ]);

    $workerNoClients = ($this->staffWith)(['securityDevices.viewAny', 'securityDevices.devices.view']);
    $workerWithClients = ($this->staffWith)(['securityDevices.viewAny', 'securityDevices.devices.view', 'clients.viewAny']);
    $shiftClient = Client::factory()->create(['site_id' => $this->home->id, 'first_name' => 'House', 'last_name' => 'Roster']);

    foreach ([$workerNoClients, $workerWithClients] as $worker) {
        Shift::factory()->create([
            'user_id' => $worker->id,
            'site_id' => $this->home->id,
            'client_id' => $shiftClient->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHours(4),
            'status' => 'scheduled',
        ]);
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'type' => SecurityDeviceAlertNotification::class,
            'notifiable_type' => $worker->getMorphClass(),
            'notifiable_id' => $worker->id,
            'data' => json_encode([
                'type' => 'security_device_alert',
                'severity' => 'critical',
                'title' => 'Nikau Wiremu out of bed for over 10 minutes',
                'body' => 'From Nikau Wiremu’s falls plan',
                'context' => ['alert_id' => $alert->id],
            ]),
            'read_at' => null,
            'created_at' => now()->subMinutes(5),
            'updated_at' => now()->subMinutes(5),
        ]);
    }

    $redactedResponse = $this->actingAs($workerNoClients)->get('/security-devices');
    $redactedResponse->assertOk()->assertInertia(function ($page): void {
        $aroundNames = collect($page->toArray()['props']['around'])->pluck('name')->all();
        expect($aroundNames)->toContain('Hallway')->not->toContain('Room 5');
        $page->where('safety.0.title', 'A person out of bed for over 10 minutes')
            ->where('told.0.title', 'A person out of bed for over 10 minutes');
    });
    expect($redactedResponse->getContent())
        ->not->toContain('Nikau')
        ->not->toContain('Wiremu');

    $this->actingAs($workerWithClients)->get('/security-devices')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('safety.0.title', 'Nikau Wiremu out of bed for over 10 minutes')
            ->where('told.0.title', 'Nikau Wiremu out of bed for over 10 minutes'));
});

it('scopes FoundDevicesController scan runs, candidate matching and range checks strictly to visible Sites', function (): void {
    $homeDevice = Device::factory()->itInfrastructure()->create(['name' => 'Totara switch', 'ip_address' => '10.40.0.2']);
    DeviceAssignment::query()->create([
        'device_id' => $homeDevice->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $this->home->id,
        'assigned_at' => now()->subDays(5),
    ]);
    $otherDevice = Device::factory()->itInfrastructure()->create(['name' => 'Kauri secret switch', 'ip_address' => '10.40.0.9']);
    DeviceAssignment::query()->create([
        'device_id' => $otherDevice->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $this->other->id,
        'assigned_at' => now()->subDays(5),
    ]);

    $homeScope = DiscoveryScope::factory()->create(['site_id' => $this->home->id, 'cidrs' => ['10.40.0.0/24']]);
    $otherScope = DiscoveryScope::factory()->create(['site_id' => $this->other->id, 'cidrs' => ['10.40.0.0/24']]);
    $homeRun = DiscoveryRun::factory()->create(['discovery_scope_id' => $homeScope->id, 'status' => 'completed']);
    $otherRun = DiscoveryRun::factory()->create(['discovery_scope_id' => $otherScope->id, 'status' => 'running']);
    DiscoveryCandidate::factory()->create([
        'discovery_run_id' => $otherRun->id,
        'evidence_snapshot' => [
            'addresses' => ['10.40.0.99'],
            'hostname' => 'kauri-secret-cam',
            'mac_addresses' => ['dd228e312168'],
            'provider' => 'snmp',
            'provider_id' => null,
            'serial_number' => null,
        ],
    ]);

    // A scope at Totara whose collector belongs to Kauri Lodge must also 404.
    $foreignCollector = MonitoringCollector::factory()->create(['site_id' => $this->other->id]);
    $crossCollectorScope = DiscoveryScope::factory()->create([
        'site_id' => $this->home->id,
        'collector_id' => $foreignCollector->id,
        'cidrs' => ['10.41.0.0/24'],
    ]);
    $crossCollectorRun = DiscoveryRun::factory()->create(['discovery_scope_id' => $crossCollectorScope->id]);

    $manager = ($this->staffWith)([
        'securityDevices.viewAny',
        'securityDevices.devices.view',
        'securityDevices.integrations.view',
        'securityDevices.integrations.manage',
    ]);

    $this->actingAs($manager)->get("/security-devices/scans/{$otherRun->id}")->assertNotFound();
    $this->actingAs($manager)->getJson("/security-devices/scans/{$otherRun->id}/progress")->assertNotFound();
    $this->actingAs($manager)->postJson("/security-devices/scans/{$otherRun->id}/stop")->assertNotFound();
    $this->actingAs($manager)->get("/security-devices/scans/{$crossCollectorRun->id}")->assertNotFound();

    // Partial reload of matchDevices only returns devices at Totara House.
    $response = $this->actingAs($manager)
        ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'security-devices/devices/found', 'X-Inertia-Partial-Data' => 'matchDevices'])
        ->get("/security-devices/scans/{$homeRun->id}");
    $response->assertOk();
    expect($response->getContent())
        ->toContain('Totara switch')
        ->not->toContain('Kauri secret switch');

    // Range-check refuses an unapproved Site and never names Kauri Lodge even though Kauri uses 10.40.0.0/24.
    $this->actingAs($manager)
        ->getJson("/security-devices/networks/range-check?site_id={$this->other->id}&range=10.40.0.0/24")
        ->assertNotFound();

    $rangeCheck = $this->actingAs($manager)
        ->getJson("/security-devices/networks/range-check?site_id={$this->home->id}&range=10.40.0.0/24")
        ->assertOk();
    expect($rangeCheck->getContent())->not->toContain('Kauri Lodge');
});

it('scopes ListExportsController CSV downloads to visible Sites and temporal event custody', function (): void {
    $homeDevice = Device::factory()->itInfrastructure()->create(['name' => 'Totara UPS']);
    // Sat at Kauri Lodge 20-10 days ago, then moved to Totara House 10 days ago.
    DeviceAssignment::query()->create([
        'device_id' => $homeDevice->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $this->other->id,
        'assigned_at' => now()->subDays(20),
        'released_at' => now()->subDays(10),
    ]);
    DeviceAssignment::query()->create([
        'device_id' => $homeDevice->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $this->home->id,
        'assigned_at' => now()->subDays(10),
    ]);

    $otherDevice = Device::factory()->itInfrastructure()->create(['name' => 'Kauri confidential NVR']);
    DeviceAssignment::query()->create([
        'device_id' => $otherDevice->id,
        'assignable_type' => DeviceAssignment::TARGET_SITE,
        'assignable_id' => $this->other->id,
        'assigned_at' => now()->subDays(10),
    ]);

    DeviceEvent::query()->create([
        'device_id' => $homeDevice->id,
        'event_type' => 'kauri_era_power_loss',
        'severity' => 'warning',
        'source' => 'snmp',
        'occurred_at' => now()->subDays(15),
    ]);
    DeviceEvent::query()->create([
        'device_id' => $homeDevice->id,
        'event_type' => 'totara_battery_test_ok',
        'severity' => 'info',
        'source' => 'snmp',
        'occurred_at' => now()->subDays(2),
    ]);
    DeviceEvent::query()->create([
        'device_id' => $otherDevice->id,
        'event_type' => 'kauri_nvr_disk_fault',
        'severity' => 'critical',
        'source' => 'unifi',
        'occurred_at' => now()->subDays(1),
    ]);

    DeviceMaintenanceRecord::query()->create([
        'device_id' => $homeDevice->id,
        'type' => 'inspection',
        'status' => 'completed',
        'description' => 'Totara UPS battery inspection',
        'scheduled_for' => now()->subDays(3),
    ]);
    DeviceMaintenanceRecord::query()->create([
        'device_id' => $otherDevice->id,
        'type' => 'repair',
        'status' => 'scheduled',
        'description' => 'Kauri confidential NVR drive swap',
        'scheduled_for' => now()->addDay(),
    ]);

    $noReports = ($this->staffWith)(['securityDevices.viewAny', 'securityDevices.devices.view']);
    $this->actingAs($noReports)->get('/security-devices/exports/devices.csv')->assertForbidden();

    $reporter = ($this->staffWith)(['securityDevices.viewAny', 'securityDevices.devices.view', 'securityDevices.reports.view']);

    $devicesCsv = $this->actingAs($reporter)
        ->get('/security-devices/exports/devices.csv?ids='.$homeDevice->id.','.$otherDevice->id)
        ->assertOk()
        ->streamedContent();
    expect($devicesCsv)
        ->toContain('Totara UPS')
        ->not->toContain('Kauri confidential NVR');

    $eventsCsv = $this->actingAs($reporter)
        ->get('/security-devices/exports/events.csv')
        ->assertOk()
        ->streamedContent();
    expect($eventsCsv)
        ->toContain('totara_battery_test_ok')
        ->not->toContain('kauri_era_power_loss')
        ->not->toContain('kauri_nvr_disk_fault')
        ->not->toContain('Kauri confidential NVR');

    $upkeepCsv = $this->actingAs($reporter)
        ->get('/security-devices/exports/upkeep.csv')
        ->assertOk()
        ->streamedContent();
    expect($upkeepCsv)
        ->toContain('Totara UPS battery inspection')
        ->not->toContain('Kauri confidential NVR drive swap');

    $this->assertDatabaseHas('audit_logs', ['action' => 'security_devices.export.downloaded', 'user_id' => $reporter->id]);
});
