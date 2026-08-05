<?php

namespace Tests\Feature\Queclink;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Http\Controllers\Integrations\QueclinkHubController;
use App\Domain\SecurityDevices\Management\Models\DeviceConfigurationProfile;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Services\QueclinkIntegrationAccessService;
use App\Domain\SecurityDevices\Services\SecurityDevicesAccessService;
use App\Http\Middleware\HandleInertiaRequests;
use App\Models\AppSetting;
use App\Models\Asset;
use App\Models\AssetTracker;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\ClientConsent;
use App\Models\ConsentType;
use App\Models\ConsentTypeVersion;
use App\Models\Permission;
use App\Models\Queclink\QueclinkAuditEvent;
use App\Models\Queclink\QueclinkDevice;
use App\Models\Queclink\QueclinkPendingCommand;
use App\Models\Queclink\QueclinkPreset;
use App\Models\Queclink\QueclinkRawFrame;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use App\Support\SafeOperationalData;
use Database\Seeders\QueclinkPresetSeeder;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class QueclinkHubControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->roles()->attach(Role::where('name', 'admin')->first());
    }

    private function pairedCanonicalGl30(string $imei): QueclinkDevice
    {
        $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $canonical = Device::factory()->tracking()->create([
            'provider' => 'queclink',
            'category' => 'personal_tracker',
            'imei' => $imei,
            'device_uid' => $imei,
        ]);
        DeviceAssignment::query()->create([
            'device_id' => $canonical->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assigned_at' => now(),
        ]);

        return QueclinkDevice::query()->create([
            'tenant_id' => 1,
            'imei' => $imei,
            'status' => QueclinkDevice::STATUS_PAIRED,
            'model_hint' => 'GL30MEU',
            'device_id' => $canonical->id,
        ]);
    }

    public function test_hub_page_renders_for_authorised_admin()
    {
        $response = $this->actingAs($this->admin)
            ->get('/security-devices/integrations/queclink');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('security-devices/integrations/queclink-hub')
            ->has('listener')
            ->has('devices.paired')
            ->has('devices.pending')
            ->has('devices.rejected')
            ->has('targets.vehicles')
            ->has('targets.staff')
            ->has('targets.clients')
        );
    }

    public function test_hub_page_exposes_only_bounded_configuration_state()
    {
        $device = QueclinkDevice::create([
            'tenant_id' => 1,
            'imei' => '867963069916998',
            'status' => QueclinkDevice::STATUS_PAIRED,
            'model_hint' => 'GL30MEU',
        ]);

        QueclinkRawFrame::create([
            'tenant_id' => 1,
            'queclink_device_id' => $device->id,
            'imei' => $device->imei,
            'direction' => 'inbound',
            'frame_type' => 'RESP',
            'command_word' => 'GTALM',
            'raw_frame' => '+RESP:GTALM,970204,867963069916998,GL30MEU,1,1,SRI,3,0,1,oblivionfindings.com,8090,oblivionfindings.com,8090,,5,1,0,30,0,,CFG,,GL30MEU,150,08E3,006F,1,30,,0,1200,,1,,,,1,1,0000,,,10,1,,1,2,1,0,20260518031500,0A10$',
            'parsed_payload' => [
                'event_type' => 'configuration_report',
                'config_total_packets' => 1,
                'config_current_packet' => 1,
                'config_text' => 'SRI,3,0,1,oblivionfindings.com,8090,oblivionfindings.com,8090,,5,1,0,30,0,,CFG,,GL30MEU,150,08E3,006F,1,30,,0,1200,,1,,,,1,1,0000,,,10,1,,1,2,1,0',
                'send_time' => '2026-05-18T03:15:00Z',
            ],
            'parse_ok' => true,
        ]);

        $this->actingAs($this->admin)
            ->get('/security-devices/integrations/queclink')
            ->assertInertia(fn ($page) => $page
                ->where('devices.paired.0.configuration.state', 'observed')
                ->missing('devices.paired.0.configuration.summary')
            );
    }

    public function test_target_only_partial_reload_never_serializes_devices_and_is_volume_independent(): void
    {
        $phase = 'small';
        $queries = ['small' => [], 'large' => []];
        DB::listen(function (QueryExecuted $query) use (&$phase, &$queries): void {
            if (str_starts_with(ltrim(strtolower($query->sql)), 'select')) {
                $queries[$phase][] = strtolower($query->sql);
            }
        });

        QueclinkDevice::create([
            'tenant_id' => 1,
            'imei' => '860000000000001',
            'status' => QueclinkDevice::STATUS_PENDING,
        ]);
        $this->partialTargetRequest('staff')
            ->assertOk()
            ->assertJsonStructure(['props' => ['targets']])
            ->assertJsonMissingPath('props.devices');

        $timestamp = now();
        DB::table('queclink_devices')->insert(collect(range(2, 101))->map(fn (int $number): array => [
            'tenant_id' => 1,
            'imei' => sprintf('860000000%06d', $number),
            'status' => QueclinkDevice::STATUS_PAIRED,
            'connection_state' => QueclinkDevice::CONN_DISCONNECTED,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ])->all());

        $phase = 'large';
        $this->partialTargetRequest('staff')
            ->assertOk()
            ->assertJsonStructure(['props' => ['targets']])
            ->assertJsonMissingPath('props.devices');

        $this->assertLessThanOrEqual(count($queries['small']), count($queries['large']));
        foreach ($queries as $phaseQueries) {
            $sql = implode('\n', $phaseQueries);
            foreach (['device_assignments', 'queclink_raw_frames', 'queclink_pending_commands'] as $table) {
                $this->assertStringNotContainsString($table, $sql);
            }
        }
    }

    public function test_device_pages_are_bounded_eager_loaded_searchable_and_second_page_is_reachable(): void
    {
        $timestamp = now();
        DB::table('queclink_devices')->insert([
            'tenant_id' => 1,
            'imei' => '861000000000001',
            'model_hint' => 'NeedleModel',
            'status' => QueclinkDevice::STATUS_PAIRED,
            'connection_state' => QueclinkDevice::CONN_DISCONNECTED,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $phase = 'small';
        $selectQueries = ['small' => [], 'large' => []];
        DB::listen(function (QueryExecuted $query) use (&$phase, &$selectQueries): void {
            if (str_starts_with(ltrim(strtolower($query->sql)), 'select')) {
                $selectQueries[$phase][] = strtolower($query->sql);
            }
        });
        $this->actingAs($this->admin)
            ->get('/security-devices/integrations/queclink')
            ->assertOk();

        DB::table('queclink_devices')->insert(collect(range(2, 65))->map(fn (int $number): array => [
            'tenant_id' => 1,
            'imei' => sprintf('861000000%06d', $number),
            'model_hint' => 'GV500CG',
            'status' => QueclinkDevice::STATUS_PAIRED,
            'connection_state' => QueclinkDevice::CONN_DISCONNECTED,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ])->all());

        $phase = 'large';
        $first = $this->actingAs($this->admin)->get('/security-devices/integrations/queclink');
        $first->assertOk()->assertInertia(fn ($page) => $page
            ->has('devices.paired', 25)
            ->where('devices.pagination.paired.current_page', 1)
            ->where('devices.pagination.paired.last_page', 3)
            ->where('devices.pagination.paired.total', 65)
            ->where('devices.total', 65));
        $firstIds = collect($first->viewData('page')['props']['devices']['paired'])->pluck('id');
        $this->assertLessThanOrEqual(
            count($selectQueries['small']) + 5,
            count($selectQueries['large']),
            'Queclink device queries grow with the number of devices.',
        );

        $second = $this->actingAs($this->admin)->get('/security-devices/integrations/queclink?paired_page=2');
        $second->assertOk()->assertInertia(fn ($page) => $page
            ->has('devices.paired', 25)
            ->where('devices.pagination.paired.current_page', 2));
        $this->assertEmpty($firstIds->intersect(collect($second->viewData('page')['props']['devices']['paired'])->pluck('id')));

        $this->actingAs($this->admin)
            ->get('/security-devices/integrations/queclink?device_search=NeedleModel')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('devices.paired', 1)
                ->where('devices.pagination.paired.total', 1)
                ->where('devices.search', 'NeedleModel'));
    }

    public function test_hub_and_frames_follow_canonical_site_access_and_redact_raw_provider_data(): void
    {
        $allowedSite = Site::factory()->create(['tenant_id' => 42]);
        $hiddenSite = Site::factory()->create(['tenant_id' => 77]);
        $viewer = $this->siteRestrictedViewer($allowedSite);
        $allowedCanonicalDevice = Device::factory()->tracking()->create(['tenant_id' => 77, 'provider' => 'queclink']);
        $hiddenCanonicalDevice = Device::factory()->tracking()->create(['tenant_id' => 42, 'provider' => 'queclink']);
        foreach ([[$allowedCanonicalDevice, $allowedSite], [$hiddenCanonicalDevice, $hiddenSite]] as [$device, $site]) {
            DeviceAssignment::create([
                'device_id' => $device->id,
                'assignable_type' => DeviceAssignment::TARGET_SITE,
                'assignable_id' => $site->id,
                'assigned_at' => now(),
            ]);
        }
        $own = QueclinkDevice::create([
            'tenant_id' => 77, 'device_id' => $allowedCanonicalDevice->id, 'imei' => 'RAW-OWN-IMEI',
            'status' => QueclinkDevice::STATUS_PAIRED,
            'connection_state' => QueclinkDevice::CONN_CONNECTED,
            'remote_address' => 'RAW-OWN-REMOTE',
        ]);
        $foreign = QueclinkDevice::create([
            'tenant_id' => 42, 'device_id' => $hiddenCanonicalDevice->id, 'imei' => 'RAW-FOREIGN-IMEI',
            'status' => QueclinkDevice::STATUS_PAIRED,
            'connection_state' => QueclinkDevice::CONN_CONNECTED,
            'remote_address' => 'RAW-FOREIGN-REMOTE',
        ]);
        foreach ([[$own, 42, 'RAW-OWN-FRAME'], [$foreign, 77, 'RAW-FOREIGN-FRAME']] as [$device, $tenant, $raw]) {
            QueclinkRawFrame::create([
                'tenant_id' => $tenant, 'queclink_device_id' => $device->id,
                'imei' => $device->imei, 'direction' => 'inbound', 'frame_type' => 'RESP',
                'command_word' => 'GTHBD', 'raw_frame' => $raw,
                'parsed_payload' => ['token' => $raw.'-PAYLOAD'], 'parse_ok' => false,
                'parse_error' => $raw.'-ERROR', 'remote_address' => $raw.'-REMOTE',
            ]);
        }
        QueclinkPendingCommand::create([
            'tenant_id' => 42, 'queclink_device_id' => $own->id, 'imei' => $own->imei,
            'command_word' => 'GTRTO', 'raw_command' => 'RAW-COMMAND', 'serial_number' => '0001',
            'status' => QueclinkPendingCommand::STATUS_FAILED, 'failed_reason' => 'RAW-FAILURE',
            'ack_response' => 'RAW-ACK', 'expires_at' => now()->addMinute(),
        ]);
        QueclinkPreset::create([
            'tenant_id' => 42, 'name' => 'Safe label', 'slug' => 'safe-label',
            'payload' => ['server' => ['host' => 'RAW-PRESET-HOST']], 'is_system' => false,
        ]);
        Asset::factory()->create(['site_id' => $hiddenSite->id, 'category' => 'vehicle', 'name' => 'RAW-FOREIGN-VEHICLE']);
        User::factory()->create(['organization_id' => 77, 'approved_at' => now(), 'name' => 'RAW-FOREIGN-STAFF']);
        Client::factory()->create(['organization_id' => 77, 'site_id' => $hiddenSite->id, 'first_name' => 'RAW-FOREIGN-CLIENT']);
        QueclinkPreset::create([
            'tenant_id' => 77, 'name' => 'Second safe label', 'slug' => 'second-safe-label',
            'payload' => ['server' => ['host' => 'RAW-FOREIGN-HOST']], 'is_system' => false,
        ]);

        $response = $this->actingAs($viewer)->get('/security-devices/integrations/queclink');
        $response->assertOk()->assertInertia(function ($page): void {
            $props = $page->toArray()['props'];
            $this->assertSame(1, $props['devices']['total']);
            $this->assertSame(1, $props['listener']['connected_count']);
            $this->assertSame(1, $props['statistics']['frames_last_hour']);
            $device = $props['devices']['paired'][0];
            foreach (['imei', 'remote_address'] as $key) {
                $this->assertArrayNotHasKey($key, $device);
            }
            foreach (['raw_command', 'serial_number', 'failed_reason', 'ack_response'] as $key) {
                $this->assertArrayNotHasKey($key, $device['recent_commands'][0]);
            }
            $this->assertArrayNotHasKey('payload', $props['presets'][0]);
            $encoded = json_encode($props, JSON_THROW_ON_ERROR);
            foreach (['RAW-', 'remote_address', 'raw_command', 'failed_reason', 'ack_response'] as $sentinel) {
                $this->assertStringNotContainsString($sentinel, $encoded);
            }
        });

        $this->actingAs($viewer)->get('/security-devices/integrations/queclink/frames')
            ->assertOk()->assertJsonCount(1, 'frames')
            ->assertJsonMissingPath('frames.0.raw_frame')
            ->assertJsonMissingPath('frames.0.imei')
            ->assertJsonMissingPath('frames.0.parse_error');
    }

    public function test_device_command_and_bulk_mutations_follow_canonical_site_access(): void
    {
        $ownSite = Site::factory()->create(['tenant_id' => 42]);
        $hiddenSite = Site::factory()->create(['tenant_id' => 77]);
        $viewer = $this->siteRestrictedViewer($ownSite);
        $ownVehicle = Asset::factory()->create(['site_id' => $ownSite->id, 'category' => 'vehicle']);
        $foreignPending = QueclinkDevice::create([
            'tenant_id' => 77, 'imei' => 'FOREIGN-PENDING',
            'status' => QueclinkDevice::STATUS_PENDING, 'model_hint' => 'GL30MEU',
        ]);
        $foreignReject = QueclinkDevice::create([
            'tenant_id' => 77, 'imei' => 'FOREIGN-REJECT',
            'status' => QueclinkDevice::STATUS_PENDING, 'model_hint' => 'GL30MEU',
        ]);
        $foreignRelease = QueclinkDevice::create([
            'tenant_id' => 77, 'imei' => 'FOREIGN-RELEASE',
            'status' => QueclinkDevice::STATUS_PAIRED, 'model_hint' => 'GL30MEU',
        ]);
        $foreignPaired = QueclinkDevice::create([
            'tenant_id' => 77, 'imei' => 'FOREIGN-PAIRED',
            'status' => QueclinkDevice::STATUS_PAIRED, 'model_hint' => 'GL30MEU',
        ]);
        foreach ([$foreignPending, $foreignReject, $foreignRelease, $foreignPaired] as $tracker) {
            $canonical = Device::factory()->tracking()->create(['tenant_id' => 42, 'provider' => 'queclink']);
            DeviceAssignment::create([
                'device_id' => $canonical->id,
                'assignable_type' => DeviceAssignment::TARGET_SITE,
                'assignable_id' => $hiddenSite->id,
                'assigned_at' => now(),
            ]);
            $tracker->update(['device_id' => $canonical->id]);
        }
        $systemPreset = QueclinkPreset::create([
            'tenant_id' => null, 'name' => 'System safe', 'slug' => 'system-safe',
            'is_system' => true, 'target_category' => 'personal_tracker',
            'payload' => ['dog' => ['mode' => 1, 'reboot_interval' => 1, 'reboot_time' => '0130', 'report_before_reboot' => 1, 'unit' => 0, 'send_failure_timeout' => 60]],
        ]);
        $applicationPreset = QueclinkPreset::create([
            'tenant_id' => 77, 'name' => 'Application preset', 'slug' => 'application-preset',
            'is_system' => false, 'target_category' => 'personal_tracker',
            'payload' => ['dog' => ['mode' => 1, 'reboot_interval' => 1, 'reboot_time' => '0130', 'report_before_reboot' => 1, 'unit' => 0, 'send_failure_timeout' => 60]],
        ]);

        $requests = [
            ["/security-devices/integrations/queclink/devices/{$foreignPending->id}/claim", ['pairing_type' => 'vehicle', 'target_id' => $ownVehicle->id]],
            ["/security-devices/integrations/queclink/devices/{$foreignReject->id}/reject", []],
            ["/security-devices/integrations/queclink/devices/{$foreignRelease->id}/release", []],
            ["/security-devices/integrations/queclink/devices/{$foreignPaired->id}/command", ['mode' => 'preset', 'preset' => 'request_location']],
            ["/security-devices/integrations/queclink/devices/{$foreignPaired->id}/configuration/read", ['section' => 'all']],
            ["/security-devices/integrations/queclink/devices/{$foreignPaired->id}/configuration/server/read", []],
            ["/security-devices/integrations/queclink/devices/{$foreignPaired->id}/configuration/server", ['command' => 'dog', 'mode' => 1, 'reboot_interval' => 1, 'reboot_time' => '0130', 'report_before_reboot' => 1, 'unit' => 0, 'send_failure_timeout' => 60]],
            ["/security-devices/integrations/queclink/devices/{$foreignPaired->id}/configuration/global", []],
            ["/security-devices/integrations/queclink/devices/{$foreignPaired->id}/configuration/tracking", ['command' => 'dog', 'mode' => 1, 'reboot_interval' => 1, 'reboot_time' => '0130', 'report_before_reboot' => 1, 'unit' => 0, 'send_failure_timeout' => 60]],
            ["/security-devices/integrations/queclink/devices/{$foreignPaired->id}/configuration/resident-safety-profile", []],
            ["/security-devices/integrations/queclink/devices/{$foreignPaired->id}/presets/{$systemPreset->id}/apply", []],
        ];
        foreach ($requests as [$url, $payload]) {
            $this->actingAs($viewer)->post($url, $payload)->assertNotFound();
        }

        $queued = QueclinkPendingCommand::create([
            'tenant_id' => 77, 'queclink_device_id' => $foreignPaired->id,
            'imei' => $foreignPaired->imei, 'command_word' => 'GTRTO',
            'raw_command' => 'AT+GTRTO=gl30,1,,,,,,0001$', 'serial_number' => '0001',
            'status' => QueclinkPendingCommand::STATUS_QUEUED, 'expires_at' => now()->addMinute(),
        ]);
        $this->actingAs($viewer)->post("/security-devices/integrations/queclink/commands/{$queued->id}/cancel")->assertNotFound();

        $ownCanonical = Device::factory()->tracking()->create(['tenant_id' => 77, 'provider' => 'queclink']);
        DeviceAssignment::create([
            'device_id' => $ownCanonical->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $ownSite->id,
            'assigned_at' => now(),
        ]);
        $ownDevice = QueclinkDevice::create([
            'tenant_id' => 42, 'device_id' => $ownCanonical->id, 'imei' => 'OWN-PAIRED',
            'status' => QueclinkDevice::STATUS_PAIRED, 'model_hint' => 'GL30MEU',
        ]);
        $before = QueclinkPendingCommand::query()->count();
        $this->actingAs($viewer)
            ->post("/security-devices/integrations/queclink/devices/{$ownDevice->id}/presets/{$applicationPreset->id}/apply")
            ->assertRedirect();
        $this->actingAs($viewer)->post('/security-devices/integrations/queclink/bulk', [
            'device_ids' => [$ownDevice->id],
            'action' => 'apply_preset',
            'preset_id' => $applicationPreset->id,
        ])->assertRedirect();
        $this->actingAs($viewer)
            ->delete("/security-devices/integrations/queclink/presets/{$applicationPreset->id}")
            ->assertRedirect();
        $this->assertDatabaseMissing('queclink_presets', ['id' => $applicationPreset->id]);
        $this->actingAs($viewer)->post('/security-devices/integrations/queclink/bulk', [
            'device_ids' => [$ownDevice->id, $foreignPaired->id],
            'action' => 'resident_safety_profile',
        ])->assertNotFound();
        $this->assertSame($before, QueclinkPendingCommand::query()->count());
        $this->assertSame(QueclinkDevice::STATUS_PENDING, $foreignPending->fresh()->status);
        $this->assertSame(QueclinkDevice::STATUS_PENDING, $foreignReject->fresh()->status);
        $this->assertSame(QueclinkDevice::STATUS_PAIRED, $foreignRelease->fresh()->status);
    }

    public function test_claim_rejects_targets_and_identity_collisions_outside_canonical_site_access(): void
    {
        $ownSite = Site::factory()->create(['tenant_id' => 42]);
        $foreignSite = Site::factory()->create(['tenant_id' => 77]);
        $viewer = $this->siteRestrictedViewer($ownSite);
        $foreignVehicle = Asset::factory()->create(['site_id' => $foreignSite->id, 'category' => 'vehicle']);
        $foreignStaff = User::factory()->create(['organization_id' => 77, 'approved_at' => now()]);
        $foreignClient = Client::factory()->create(['organization_id' => 77, 'site_id' => $foreignSite->id]);

        foreach ([
            ['vehicle', $foreignVehicle->id],
            ['staff', $foreignStaff->id],
            ['client', $foreignClient->id],
        ] as [$type, $targetId]) {
            $device = QueclinkDevice::create([
                'tenant_id' => 42, 'imei' => 'OWN-'.strtoupper($type),
                'status' => QueclinkDevice::STATUS_PENDING, 'model_hint' => 'GL30MEU',
            ]);
            $this->actingAs($viewer)->post("/security-devices/integrations/queclink/devices/{$device->id}/claim", [
                'pairing_type' => $type, 'target_id' => $targetId,
            ])->assertNotFound();
            $this->assertSame(QueclinkDevice::STATUS_PENDING, $device->fresh()->status);
        }

        $ownVehicle = Asset::factory()->create(['site_id' => $ownSite->id, 'category' => 'vehicle']);
        $collision = Device::factory()->create([
            'tenant_id' => 77, 'provider' => 'queclink',
            'imei' => 'COLLISION-IMEI', 'device_uid' => 'COLLISION-IMEI',
        ]);
        DeviceAssignment::create([
            'device_id' => $collision->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $foreignSite->id,
            'assigned_at' => now(),
        ]);
        $queclink = QueclinkDevice::create([
            'tenant_id' => 42, 'imei' => 'COLLISION-IMEI',
            'status' => QueclinkDevice::STATUS_PENDING, 'model_hint' => 'GV500CG',
        ]);
        $this->actingAs($viewer)->post("/security-devices/integrations/queclink/devices/{$queclink->id}/claim", [
            'pairing_type' => 'vehicle', 'target_id' => $ownVehicle->id,
        ])->assertNotFound();
        $this->assertNull($queclink->fresh()->device_id);
        $this->assertSame(QueclinkDevice::STATUS_PENDING, $queclink->fresh()->status);
        $this->assertSame(77, (int) $collision->fresh()->tenant_id);
        $this->assertSame(0, Device::query()->where('tenant_id', 42)->where('device_uid', 'COLLISION-IMEI')->count());
    }

    public function test_claim_ignores_historical_tracker_assets_outside_canonical_site_access(): void
    {
        $ownSite = Site::factory()->create(['tenant_id' => 42]);
        $foreignSite = Site::factory()->create(['tenant_id' => 77]);
        $viewer = $this->siteRestrictedViewer($ownSite);
        $target = Asset::factory()->create(['site_id' => $ownSite->id, 'category' => 'vehicle']);

        foreach ([
            Asset::factory()->create([
                'site_id' => $foreignSite->id,
                'home_site_id' => null,
                'category' => 'personal_tracker',
            ]),
            Asset::factory()->create([
                'site_id' => $foreignSite->id,
                'home_site_id' => null,
                'client_id' => null,
                'primary_driver_user_id' => null,
                'category' => 'personal_tracker',
            ]),
        ] as $index => $collisionAsset) {
            $imei = 'STRICT-COLLISION-'.$index;
            $tracker = AssetTracker::create([
                'asset_id' => $collisionAsset->id,
                'vendor' => 'queclink',
                'device_uid' => $imei,
                'imei' => $imei,
                'status' => 'paired',
                'paired_at' => now(),
            ]);
            $pending = QueclinkDevice::create([
                'tenant_id' => 42,
                'imei' => $imei,
                'status' => QueclinkDevice::STATUS_PENDING,
                'model_hint' => 'GV500CG',
            ]);

            $this->actingAs($viewer)
                ->post("/security-devices/integrations/queclink/devices/{$pending->id}/claim", [
                    'pairing_type' => 'vehicle',
                    'target_id' => $target->id,
                ])
                ->assertRedirect();

            $this->assertSame($collisionAsset->id, $tracker->fresh()->asset_id);
            $this->assertSame('paired', $tracker->fresh()->status);
            $this->assertSame(QueclinkDevice::STATUS_PAIRED, $pending->fresh()->status);
            $this->assertNotNull($pending->fresh()->device_id);
        }

        $this->assertSame(2, DeviceAssignment::query()
            ->where('assignable_type', DeviceAssignment::TARGET_VEHICLE)
            ->where('assignable_id', $target->id)
            ->active()
            ->count());
    }

    public function test_claim_rejects_a_hidden_site_personal_asset_found_by_global_staff_lookup(): void
    {
        $ownSite = Site::factory()->create(['tenant_id' => 42]);
        $foreignSite = Site::factory()->create(['tenant_id' => 77]);
        $viewer = $this->siteRestrictedViewer($ownSite);
        $staff = User::factory()->create(['organization_id' => 42, 'approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'tenant_id' => 42,
            'user_id' => $staff->id,
            'primary_site_id' => $ownSite->id,
            'secondary_site_ids' => [],
        ]);
        $foreignAsset = Asset::factory()->create([
            'site_id' => $foreignSite->id,
            'category' => 'personal_tracker',
            'primary_driver_user_id' => $staff->id,
        ]);
        $pending = QueclinkDevice::create([
            'tenant_id' => 42,
            'imei' => '867963069916997',
            'status' => QueclinkDevice::STATUS_PENDING,
        ]);

        $this->actingAs($viewer)
            ->post("/security-devices/integrations/queclink/devices/{$pending->id}/claim", [
                'pairing_type' => 'staff',
                'target_id' => $staff->id,
            ])
            ->assertNotFound();

        $this->assertSame(QueclinkDevice::STATUS_PENDING, $pending->fresh()->status);
        $this->assertDatabaseMissing('asset_trackers', ['asset_id' => $foreignAsset->id]);
        $this->assertSame(0, DeviceAssignment::query()->count());
    }

    public function test_release_uses_canonical_direct_asset_site_despite_legacy_partition_values(): void
    {
        $this->admin->forceFill(['organization_id' => 42])->save();
        $ownSite = Site::factory()->create(['tenant_id' => 42]);
        $foreignSite = Site::factory()->create(['tenant_id' => 77]);
        $asset = Asset::factory()->create([
            'site_id' => $ownSite->id,
            'home_site_id' => $foreignSite->id,
            'category' => 'vehicle',
        ]);
        $device = Device::factory()->create(['tenant_id' => 42, 'provider' => 'queclink']);
        $assignment = DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $ownSite->id,
            'assigned_at' => now(),
        ]);
        $queclink = QueclinkDevice::create([
            'tenant_id' => 42,
            'imei' => 'STRICT-RELEASE',
            'status' => QueclinkDevice::STATUS_PAIRED,
            'device_id' => $device->id,
        ]);
        $tracker = AssetTracker::create([
            'asset_id' => $asset->id,
            'vendor' => 'queclink',
            'device_uid' => $queclink->imei,
            'imei' => $queclink->imei,
            'status' => 'paired',
            'paired_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->post("/security-devices/integrations/queclink/devices/{$queclink->id}/release")
            ->assertRedirect();

        $this->assertNotNull($assignment->fresh()->released_at);
        $this->assertSame('paired', $tracker->fresh()->status);
        $this->assertNull($tracker->fresh()->unpaired_at);
        $this->assertSame(QueclinkDevice::STATUS_PENDING, $queclink->fresh()->status);
        $this->assertNull($queclink->fresh()->device_id);
    }

    #[DataProvider('hiddenReleaseAssignmentProvider')]
    public function test_release_rejects_every_hidden_site_active_assignment_target_before_any_mutation(
        string $targetType,
    ): void {
        $localSite = Site::factory()->create(['tenant_id' => 42]);
        $hiddenSite = Site::factory()->create(['tenant_id' => 77]);
        $viewer = $this->siteRestrictedViewer($localSite);
        $localAsset = Asset::factory()->create([
            'site_id' => $localSite->id,
            'category' => 'vehicle',
        ]);
        $hiddenTargetId = match ($targetType) {
            DeviceAssignment::TARGET_STAFF => (function () use ($hiddenSite): int {
                $staff = User::factory()->create([
                    'organization_id' => 77,
                    'approved_at' => now(),
                ]);
                HrEmployeeProfile::factory()->create([
                    'tenant_id' => 77,
                    'user_id' => $staff->id,
                    'primary_site_id' => $hiddenSite->id,
                    'is_active' => true,
                ]);

                return $staff->id;
            })(),
            DeviceAssignment::TARGET_VEHICLE => Asset::factory()->create([
                'site_id' => $hiddenSite->id,
                'category' => 'vehicle',
            ])->id,
        };
        $canonicalDevice = Device::factory()->create([
            'tenant_id' => 42,
            'provider' => 'queclink',
        ]);
        $localAssignment = DeviceAssignment::create([
            'device_id' => $canonicalDevice->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $localSite->id,
            'assigned_at' => now(),
        ]);
        $assignment = DeviceAssignment::create([
            'device_id' => $canonicalDevice->id,
            'assignable_type' => $targetType,
            'assignable_id' => $hiddenTargetId,
            'assigned_at' => now(),
        ]);
        $queclink = QueclinkDevice::create([
            'tenant_id' => 42,
            'imei' => $targetType === DeviceAssignment::TARGET_STAFF ? '770000000000001' : '770000000000002',
            'status' => QueclinkDevice::STATUS_PAIRED,
            'device_id' => $canonicalDevice->id,
        ]);
        $tracker = AssetTracker::create([
            'asset_id' => $localAsset->id,
            'vendor' => 'queclink',
            'device_uid' => $queclink->imei,
            'imei' => $queclink->imei,
            'status' => 'paired',
            'paired_at' => now(),
        ]);
        AuditLog::query()->delete();
        QueclinkAuditEvent::query()->delete();

        $this->actingAs($viewer)
            ->post("/security-devices/integrations/queclink/devices/{$queclink->id}/release")
            ->assertNotFound();

        $this->assertNull($assignment->fresh()->released_at);
        $this->assertNull($assignment->fresh()->released_by_user_id);
        $this->assertNull($localAssignment->fresh()->released_at);
        $this->assertNull($localAssignment->fresh()->released_by_user_id);
        $this->assertSame('paired', $tracker->fresh()->status);
        $this->assertNull($tracker->fresh()->unpaired_at);
        $this->assertSame(QueclinkDevice::STATUS_PAIRED, $queclink->fresh()->status);
        $this->assertSame($canonicalDevice->id, $queclink->fresh()->device_id);
        $this->assertSame(0, AuditLog::query()->count());
        $this->assertSame(0, QueclinkAuditEvent::query()->where('event_type', 'release')->count());
    }

    public static function hiddenReleaseAssignmentProvider(): array
    {
        return [
            'hidden staff target' => [DeviceAssignment::TARGET_STAFF],
            'hidden vehicle target' => [DeviceAssignment::TARGET_VEHICLE],
        ];
    }

    public function test_direct_target_authorization_and_search_are_not_limited_by_picker_cap(): void
    {
        $this->admin->forceFill(['organization_id' => 42])->save();
        $site = Site::factory()->create(['tenant_id' => 42]);
        $timestamp = now();

        DB::table('assets')->insert(collect(range(1, 501))->map(fn (int $number): array => [
            'site_id' => $site->id,
            'name' => sprintf('Vehicle %04d', $number),
            'category' => 'vehicle',
            'status' => 'active',
            'risk_level' => 'medium',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ])->all());
        DB::table('clients')->insert(collect(range(1, 501))->map(fn (int $number): array => [
            'organization_id' => 42,
            'site_id' => $site->id,
            'first_name' => sprintf('Client %04d', $number),
            'last_name' => 'Picker',
            'status' => 'active',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ])->all());

        $vehicle = Asset::query()->where('name', 'Vehicle 0501')->sole();
        $client = Client::query()->where('first_name', 'Client 0501')->sole();
        $access = app(QueclinkIntegrationAccessService::class);

        $this->assertSame($vehicle->id, $access->vehicle($this->admin, $vehicle->id)->id);
        $this->assertSame($client->id, $access->client($this->admin, $client->id)->id);

        $this->actingAs($this->admin)
            ->get('/security-devices/integrations/queclink?target_type=vehicle&target_search=Vehicle%200501&selected_target_id='.$vehicle->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('targets.vehicles.0.id', $vehicle->id));
        $this->actingAs($this->admin)
            ->get('/security-devices/integrations/queclink?target_type=client&target_search=Client%200501&selected_target_id='.$client->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('targets.clients.0.id', $client->id));
    }

    public function test_staff_search_and_direct_claim_are_not_limited_by_picker_cap(): void
    {
        $this->admin->forceFill(['organization_id' => 42])->save();
        $site = Site::factory()->create(['tenant_id' => 42]);
        User::factory()->count(501)->sequence(fn ($sequence): array => [
            'organization_id' => 42,
            'approved_at' => now(),
            'name' => sprintf('Worker %04d', $sequence->index + 1),
        ])->create();
        $staff = User::query()->where('name', 'Worker 0501')->sole();
        HrEmployeeProfile::factory()->create([
            'tenant_id' => 42,
            'user_id' => $staff->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
        ]);
        $foreign = User::factory()->create(['organization_id' => 77, 'approved_at' => now(), 'name' => 'Foreign Worker']);
        $device = QueclinkDevice::create([
            'tenant_id' => 42,
            'imei' => '862000000000501',
            'status' => QueclinkDevice::STATUS_PENDING,
            'pending_pairing_type' => QueclinkDevice::PAIRING_STAFF,
        ]);

        $access = app(QueclinkIntegrationAccessService::class);
        $this->assertSame($staff->id, $access->staff($this->admin, $staff->id)->id);

        $this->actingAs($this->admin)
            ->get('/security-devices/integrations/queclink?target_type=staff&target_search=Worker%200501&selected_target_id='.$staff->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('targets.staff.0.id', $staff->id)
                ->where('targets.staff.0.label', 'Worker 0501')
                ->where('targets.staff', fn ($targets): bool => ! collect($targets)->contains('id', $foreign->id)));

        $this->actingAs($this->admin)
            ->post("/security-devices/integrations/queclink/devices/{$device->id}/claim", [
                'pairing_type' => 'staff',
                'target_id' => $staff->id,
            ])
            ->assertRedirect();
        $this->assertDatabaseHas('device_assignments', [
            'device_id' => $device->fresh()->device_id,
            'assignable_type' => DeviceAssignment::TARGET_STAFF,
            'assignable_id' => $staff->id,
            'released_at' => null,
        ]);

        $foreignDevice = QueclinkDevice::create([
            'tenant_id' => 42,
            'imei' => '862000000000502',
            'status' => QueclinkDevice::STATUS_PENDING,
        ]);
        $this->actingAs($this->admin)
            ->post("/security-devices/integrations/queclink/devices/{$foreignDevice->id}/claim", [
                'pairing_type' => 'staff',
                'target_id' => $foreign->id,
            ])
            ->assertNotFound();
    }

    public function test_client_listing_and_claim_use_the_same_canonical_site_predicate(): void
    {
        $site = Site::factory()->create(['tenant_id' => 1]);
        $foreignSite = Site::factory()->create(['tenant_id' => 77]);
        $viewer = $this->siteRestrictedViewer($site);
        $supported = Client::factory()->create([
            'organization_id' => null,
            'site_id' => $site->id,
            'first_name' => 'Supported Legacy',
            'last_name' => 'Client',
        ]);
        $ambiguous = Client::factory()->create([
            'organization_id' => null,
            'site_id' => null,
            'first_name' => 'Ambiguous Legacy',
            'last_name' => 'Client',
        ]);
        $foreign = Client::factory()->create([
            'organization_id' => null,
            'site_id' => $foreignSite->id,
            'first_name' => 'Foreign Legacy',
            'last_name' => 'Client',
        ]);
        $consent = $this->trackingConsent($supported);
        $device = QueclinkDevice::create([
            'tenant_id' => 1,
            'imei' => '863000000000001',
            'status' => QueclinkDevice::STATUS_PENDING,
        ]);

        $response = $this->actingAs($viewer)
            ->get('/security-devices/integrations/queclink?target_type=client&target_search=Legacy');
        $response->assertOk()->assertInertia(function ($page) use ($supported, $ambiguous, $foreign): void {
            $ids = collect($page->toArray()['props']['targets']['clients'])->pluck('id');
            $this->assertContains($supported->id, $ids);
            $this->assertNotContains($ambiguous->id, $ids);
            $this->assertNotContains($foreign->id, $ids);
        });

        $access = app(QueclinkIntegrationAccessService::class);
        $this->assertSame($supported->id, $access->client($viewer, $supported->id)->id);
        foreach ([$ambiguous, $foreign] as $denied) {
            try {
                $access->client($viewer, $denied->id);
                $this->fail('A client outside canonical Site access passed direct authorization.');
            } catch (HttpException $exception) {
                $this->assertSame(404, $exception->getStatusCode());
            }
        }

        $this->actingAs($viewer)
            ->post("/security-devices/integrations/queclink/devices/{$device->id}/claim", [
                'pairing_type' => 'client',
                'target_id' => $supported->id,
                'consent_id' => $consent->id,
            ])
            ->assertRedirect();
        $this->assertDatabaseHas('device_assignments', [
            'device_id' => $device->fresh()->device_id,
            'assignable_type' => DeviceAssignment::TARGET_CLIENT,
            'assignable_id' => $supported->id,
            'released_at' => null,
        ]);
    }

    public function test_unlinked_pending_device_is_visible_and_claimable_from_global_intake(): void
    {
        $this->admin->forceFill(['organization_id' => 1])->save();
        $site = Site::factory()->create(['tenant_id' => 1]);
        $vehicle = Asset::factory()->create(['site_id' => $site->id, 'category' => 'vehicle']);
        $device = QueclinkDevice::create([
            'tenant_id' => null, 'imei' => 'UNSCOPED-DEVICE',
            'status' => QueclinkDevice::STATUS_PENDING,
        ]);

        $this->actingAs($this->admin)->get('/security-devices/integrations/queclink')->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('devices.total', 1)
                ->where('devices.pending.0.id', $device->id));
        $this->actingAs($this->admin)->post("/security-devices/integrations/queclink/devices/{$device->id}/claim", [
            'pairing_type' => 'vehicle', 'target_id' => $vehicle->id,
        ])->assertRedirect();
        $this->assertSame(QueclinkDevice::STATUS_PAIRED, $device->fresh()->status);
        $this->assertDatabaseHas('device_assignments', [
            'device_id' => $device->fresh()->device_id,
            'assignable_type' => DeviceAssignment::TARGET_VEHICLE,
            'assignable_id' => $vehicle->id,
            'released_at' => null,
        ]);
    }

    public function test_service_state_is_always_bounded_and_never_echoes_systemd_output(): void
    {
        $this->assertSame('active', SafeOperationalData::serviceState('active', 0));
        $this->assertSame('inactive', SafeOperationalData::serviceState('inactive', 3));
        $this->assertSame('unavailable', SafeOperationalData::serviceState('RAW-SYSTEMD-SENTINEL', 1));
        $this->assertNotContains('RAW-SYSTEMD-SENTINEL', [
            SafeOperationalData::serviceState('RAW-SYSTEMD-SENTINEL', 1),
        ]);
    }

    public function test_save_settings_persists_port_and_hostname()
    {
        $this->actingAs($this->admin)
            ->post('/security-devices/integrations/queclink/settings', [
                'port' => 9091,
                'public_hostname' => 'tracking.example.co.nz',
            ])
            ->assertRedirect();

        $this->assertSame('9091', (string) AppSetting::query()->where('key', 'queclink.listener.port')->value('value'));
        $this->assertSame('tracking.example.co.nz', AppSetting::query()->where('key', 'queclink.public_hostname')->value('value'));
    }

    public function test_save_settings_rejects_out_of_range_port()
    {
        $this->actingAs($this->admin)
            ->post('/security-devices/integrations/queclink/settings', [
                'port' => 80,  // privileged port, should be rejected
                'public_hostname' => 'oblivion.example.com',
            ])
            ->assertSessionHasErrors('port');
    }

    public function test_claim_pending_device_as_vehicle_creates_canonical_device_link_and_assignment()
    {
        $site = Site::factory()->create(['tenant_id' => 1]);
        $asset = Asset::factory()->create(['site_id' => $site->id, 'category' => 'vehicle']);
        $device = QueclinkDevice::create([
            'tenant_id' => 1,
            'imei' => '864696060004173',
            'status' => QueclinkDevice::STATUS_PENDING,
            'model_hint' => 'GV500CG',
        ]);

        $this->actingAs($this->admin)
            ->post("/security-devices/integrations/queclink/devices/{$device->id}/claim", [
                'pairing_type' => 'vehicle',
                'target_id' => $asset->id,
            ])
            ->assertRedirect();

        $device->refresh();
        $this->assertSame(QueclinkDevice::STATUS_PAIRED, $device->status);

        $this->assertDatabaseMissing('asset_trackers', [
            'vendor' => 'queclink',
            'device_uid' => $device->imei,
        ]);
        $this->assertDatabaseHas('devices', [
            'id' => $device->device_id,
            'provider' => 'queclink',
            'device_uid' => $device->imei,
        ]);
        $this->assertDatabaseHas('device_asset_links', [
            'device_id' => $device->device_id,
            'asset_id' => $asset->id,
            'unlinked_at' => null,
        ]);

        $this->assertDatabaseHas('device_assignments', [
            'device_id' => $device->fresh()->device_id,
            'assignable_type' => 'vehicle',
            'assignable_id' => $asset->id,
            'released_at' => null,
        ]);
    }

    public function test_claim_reuses_the_assignment_service_and_preserves_released_canonical_history(): void
    {
        $site = Site::factory()->create(['tenant_id' => 1]);
        $asset = Asset::factory()->create(['site_id' => $site->id, 'category' => 'vehicle']);
        $canonical = Device::factory()->tracking()->create([
            'provider' => 'queclink',
            'category' => 'vehicle_tracker',
            'imei' => '864696060004176',
            'device_uid' => '864696060004176',
        ]);
        $original = DeviceAssignment::query()->create([
            'device_id' => $canonical->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assigned_at' => now()->subDay(),
        ]);
        $providerDevice = QueclinkDevice::query()->create([
            'tenant_id' => 1,
            'imei' => '864696060004176',
            'status' => QueclinkDevice::STATUS_PENDING,
            'model_hint' => 'GV500CG',
        ]);
        $queries = collect();
        DB::listen(function (QueryExecuted $query) use ($queries): void {
            $queries->push(strtolower($query->sql));
        });

        $this->actingAs($this->admin)
            ->post("/security-devices/integrations/queclink/devices/{$providerDevice->id}/claim", [
                'pairing_type' => 'vehicle',
                'target_id' => $asset->id,
            ])
            ->assertRedirect();

        $this->assertNotNull($original->fresh()->released_at);
        $this->assertSame(2, $canonical->assignments()->count());
        $this->assertSame(1, $canonical->assignments()->active()->count());
        $this->assertDatabaseHas('device_assignments', [
            'device_id' => $canonical->id,
            'assignable_type' => DeviceAssignment::TARGET_VEHICLE,
            'assignable_id' => $asset->id,
            'released_at' => null,
        ]);
        $this->assertForUpdateQuery($queries, 'devices');
    }

    public function test_vehicle_claim_rechecks_the_locked_asset_after_stale_authorization_provenance_changes(): void
    {
        $localSite = Site::factory()->create(['tenant_id' => 1]);
        $foreignSite = Site::factory()->create(['tenant_id' => 2]);
        $viewer = $this->siteRestrictedViewer($localSite);
        $asset = Asset::factory()->create(['site_id' => $localSite->id, 'category' => 'vehicle']);
        $device = QueclinkDevice::create([
            'tenant_id' => 1,
            'imei' => '864696060004174',
            'status' => QueclinkDevice::STATUS_PENDING,
        ]);
        $queries = collect();
        DB::listen(function (QueryExecuted $query) use ($queries): void {
            $queries->push(strtolower($query->sql));
        });

        $access = new class(app(SecurityDevicesAccessService::class), $foreignSite->id) extends QueclinkIntegrationAccessService
        {
            public bool $provenanceChanged = false;

            public function __construct(
                SecurityDevicesAccessService $devices,
                private readonly int $foreignSiteId,
            ) {
                parent::__construct($devices);
            }

            public function vehicle(User $user, int $id, bool $lockForUpdate = false): Asset
            {
                $staleAuthorizedAsset = parent::vehicle($user, $id);
                DB::table('assets')->where('id', $id)->update(['site_id' => $this->foreignSiteId]);
                $this->provenanceChanged = true;

                return $lockForUpdate
                    ? parent::vehicle($user, $id, true)
                    : $staleAuthorizedAsset;
            }
        };
        $this->app->instance(QueclinkIntegrationAccessService::class, $access);

        $this->actingAs($viewer)
            ->post("/security-devices/integrations/queclink/devices/{$device->id}/claim", [
                'pairing_type' => 'vehicle',
                'target_id' => $asset->id,
            ])
            ->assertNotFound();

        $this->assertTrue($access->provenanceChanged);
        $this->assertTrue($queries->contains(fn (string $sql): bool => str_contains($sql, 'from `assets`')
            && str_contains($sql, 'for update')));
        $this->assertSame($localSite->id, $asset->fresh()->site_id);
        $this->assertSame(QueclinkDevice::STATUS_PENDING, $device->fresh()->status);
        $this->assertNull($device->fresh()->device_id);
        $this->assertDatabaseMissing('device_assignments', [
            'assignable_type' => DeviceAssignment::TARGET_VEHICLE,
            'assignable_id' => $asset->id,
        ]);
        $this->assertDatabaseMissing('asset_trackers', [
            'asset_id' => $asset->id,
            'device_uid' => $device->imei,
        ]);
        $this->assertDatabaseMissing('devices', [
            'provider' => 'queclink',
            'device_uid' => $device->imei,
        ]);
        $this->assertDatabaseMissing('queclink_audit_events', [
            'queclink_device_id' => $device->id,
            'event_type' => 'claim',
        ]);
    }

    public function test_stale_double_submit_rechecks_the_locked_device_and_keeps_one_active_assignment(): void
    {
        $site = Site::factory()->create(['tenant_id' => 1]);
        $asset = Asset::factory()->create(['site_id' => $site->id, 'category' => 'vehicle']);
        $device = QueclinkDevice::create([
            'tenant_id' => 1,
            'imei' => '864696060004175',
            'status' => QueclinkDevice::STATUS_PENDING,
        ]);
        $stale = QueclinkDevice::query()->findOrFail($device->id);

        $this->actingAs($this->admin)
            ->post("/security-devices/integrations/queclink/devices/{$device->id}/claim", [
                'pairing_type' => 'vehicle',
                'target_id' => $asset->id,
            ])
            ->assertRedirect();

        $request = Request::create(
            "/security-devices/integrations/queclink/devices/{$device->id}/claim",
            'POST',
            ['pairing_type' => 'vehicle', 'target_id' => $asset->id],
        );
        $request->setUserResolver(fn (): User => $this->admin);
        $queries = collect();
        DB::listen(function (QueryExecuted $query) use ($queries): void {
            $queries->push(strtolower($query->sql));
        });
        $failure = null;
        try {
            app(QueclinkHubController::class)->claimDevice($request, $stale);
        } catch (HttpException $exception) {
            $failure = $exception;
        }

        $this->assertNotNull($failure, 'A stale route model bypassed the persisted pending-state check.');
        $this->assertSame(422, $failure->getStatusCode());
        $this->assertForUpdateQuery($queries, 'queclink_devices');
        $this->assertSame(1, DeviceAssignment::query()
            ->where('device_id', $device->fresh()->device_id)
            ->whereNull('released_at')
            ->count());
    }

    public function test_claim_as_staff_auto_creates_personal_tracker_asset()
    {
        $site = Site::factory()->create(['tenant_id' => 1]);
        $staff = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'tenant_id' => 1,
            'user_id' => $staff->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
        ]);
        $device = QueclinkDevice::create([
            'tenant_id' => 1,
            'imei' => '864696060004173',
            'status' => QueclinkDevice::STATUS_PENDING,
        ]);

        $this->actingAs($this->admin)
            ->post("/security-devices/integrations/queclink/devices/{$device->id}/claim", [
                'pairing_type' => 'staff',
                'target_id' => $staff->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('assets', [
            'category' => 'personal_tracker',
            'site_id' => $site->id,
            'primary_driver_user_id' => $staff->id,
        ]);
    }

    public function test_claim_as_client_links_existing_valid_tracking_consent_when_not_supplied()
    {
        $site = Site::factory()->create(['tenant_id' => 1]);
        $client = Client::create(['organization_id' => 1, 'site_id' => $site->id, 'first_name' => 'Amelia', 'last_name' => 'Wilson']);
        $consentType = ConsentType::create([
            'name' => 'Personal Tracker (Wandering Risk)',
            'category' => 'safety',
            'description' => 'Personal tracker consent',
            'purpose' => 'Resident safety tracking',
            'legal_basis' => 'Consent',
            'version' => 1,
            'active' => true,
        ]);
        $consentVersion = ConsentTypeVersion::create([
            'consent_type_id' => $consentType->id,
            'version' => 1,
            'description' => 'Personal tracker consent v1',
            'purpose' => 'Resident safety tracking',
            'legal_basis' => 'Consent',
            'effective_from' => now()->subDay(),
        ]);
        $consent = ClientConsent::create([
            'client_id' => $client->id,
            'consent_type_id' => $consentType->id,
            'consent_type_version_id' => $consentVersion->id,
            'status' => 'given',
            'given_at' => now()->subDay(),
            'expires_at' => now()->addDays(30),
        ]);
        $device = QueclinkDevice::create([
            'tenant_id' => 1,
            'imei' => '867963069916998',
            'status' => QueclinkDevice::STATUS_PENDING,
            'model_hint' => 'GL30MEU',
        ]);

        $this->actingAs($this->admin)
            ->post("/security-devices/integrations/queclink/devices/{$device->id}/claim", [
                'pairing_type' => 'client',
                'target_id' => $client->id,
            ])
            ->assertRedirect();

        $this->assertSame($consent->id, DeviceAssignment::query()
            ->where('device_id', $device->fresh()->device_id)
            ->where('assignable_type', 'client')
            ->value('consent_id'));
        $this->assertDatabaseMissing('asset_trackers', [
            'vendor' => 'queclink',
            'device_uid' => $device->imei,
        ]);
    }

    public function test_reject_pending_device_marks_status_rejected()
    {
        $device = QueclinkDevice::create([
            'tenant_id' => 1,
            'imei' => '864696060004173',
            'status' => QueclinkDevice::STATUS_PENDING,
        ]);

        $this->actingAs($this->admin)
            ->post("/security-devices/integrations/queclink/devices/{$device->id}/reject")
            ->assertRedirect();

        $this->assertSame(QueclinkDevice::STATUS_REJECTED, $device->fresh()->status);
        $this->assertDatabaseHas('queclink_audit_events', [
            'queclink_device_id' => $device->id,
            'event_type' => 'reject',
        ]);
    }

    public function test_reject_is_a_locked_pending_only_transition_and_cannot_break_a_pairing(): void
    {
        $site = Site::factory()->create(['tenant_id' => 1]);
        $asset = Asset::factory()->create(['site_id' => $site->id, 'category' => 'vehicle']);
        $device = QueclinkDevice::create([
            'tenant_id' => 1,
            'imei' => '864696060004181',
            'status' => QueclinkDevice::STATUS_PENDING,
        ]);
        $stale = QueclinkDevice::query()->findOrFail($device->id);

        $this->actingAs($this->admin)->post("/security-devices/integrations/queclink/devices/{$device->id}/claim", [
            'pairing_type' => 'vehicle',
            'target_id' => $asset->id,
        ])->assertRedirect();

        $request = Request::create("/security-devices/integrations/queclink/devices/{$device->id}/reject", 'POST');
        $request->setUserResolver(fn (): User => $this->admin);

        try {
            app(QueclinkHubController::class)->rejectDevice($request, $stale);
            $this->fail('A stale pending model rejected a persisted paired device.');
        } catch (HttpException $exception) {
            $this->assertSame(422, $exception->getStatusCode());
        }

        $this->assertSame(QueclinkDevice::STATUS_PAIRED, $device->fresh()->status);
        $this->assertSame(1, DeviceAssignment::query()
            ->where('device_id', $device->fresh()->device_id)
            ->active()
            ->count());
        $this->assertDatabaseMissing('asset_trackers', ['device_uid' => $device->imei]);
    }

    public function test_rejected_devices_are_searchable_pageable_and_can_only_be_restored_from_rejected(): void
    {
        $timestamp = now();
        DB::table('queclink_devices')->insert(collect(range(1, 26))->map(fn (int $number): array => [
            'tenant_id' => 1,
            'imei' => sprintf('869000000%06d', $number),
            'status' => QueclinkDevice::STATUS_REJECTED,
            'model_hint' => $number === 26 ? 'RestoreNeedle' : 'GL30M',
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ])->all());

        $this->actingAs($this->admin)
            ->get('/security-devices/integrations/queclink?device_search=RestoreNeedle')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('devices.counts.rejected', 1)
                ->where('devices.rejected.0.model_hint', 'RestoreNeedle')
                ->where('devices.pagination.rejected.total', 1));

        $rejected = QueclinkDevice::query()->where('model_hint', 'RestoreNeedle')->sole();
        $this->actingAs($this->admin)
            ->post("/security-devices/integrations/queclink/devices/{$rejected->id}/restore")
            ->assertRedirect();
        $this->assertSame(QueclinkDevice::STATUS_PENDING, $rejected->fresh()->status);
        $this->assertDatabaseHas('queclink_audit_events', [
            'queclink_device_id' => $rejected->id,
            'event_type' => 'restore',
        ]);

        $this->actingAs($this->admin)
            ->post("/security-devices/integrations/queclink/devices/{$rejected->id}/restore")
            ->assertStatus(422);
        $this->assertSame(QueclinkDevice::STATUS_PENDING, $rejected->fresh()->status);

        $paired = QueclinkDevice::create([
            'tenant_id' => 1,
            'imei' => '864696060004292',
            'status' => QueclinkDevice::STATUS_PAIRED,
        ]);
        $this->actingAs($this->admin)
            ->post("/security-devices/integrations/queclink/devices/{$paired->id}/restore")
            ->assertStatus(422);
        $this->assertSame(QueclinkDevice::STATUS_PAIRED, $paired->fresh()->status);
    }

    public function test_restore_requires_the_integration_manage_permission(): void
    {
        $device = QueclinkDevice::create([
            'tenant_id' => 1,
            'imei' => '864696060004182',
            'status' => QueclinkDevice::STATUS_REJECTED,
        ]);
        $user = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);

        $this->actingAs($user)
            ->post("/security-devices/integrations/queclink/devices/{$device->id}/restore")
            ->assertForbidden();
        $this->assertSame(QueclinkDevice::STATUS_REJECTED, $device->fresh()->status);
    }

    public function test_claim_consent_is_scoped_after_client_authorization_and_never_becomes_an_existence_oracle(): void
    {
        $site = Site::factory()->create(['tenant_id' => 1]);
        $client = Client::factory()->create(['organization_id' => 1, 'site_id' => $site->id]);
        $otherClient = Client::factory()->create(['organization_id' => 1, 'site_id' => $site->id]);
        $foreignConsent = $this->trackingConsent($otherClient);
        $messages = [];

        foreach ([$foreignConsent->id, $foreignConsent->id + 999999] as $index => $consentId) {
            $device = QueclinkDevice::create([
                'tenant_id' => 1,
                'imei' => '86469606010418'.$index,
                'status' => QueclinkDevice::STATUS_PENDING,
            ]);
            $response = $this->actingAs($this->admin)
                ->from('/security-devices/integrations/queclink')
                ->post("/security-devices/integrations/queclink/devices/{$device->id}/claim", [
                    'pairing_type' => 'client',
                    'target_id' => $client->id,
                    'consent_id' => $consentId,
                ])
                ->assertRedirect('/security-devices/integrations/queclink')
                ->assertSessionHasErrors('consent_id');
            $messages[] = $response->getSession()->get('errors')->first('consent_id');
            $this->assertSame(QueclinkDevice::STATUS_PENDING, $device->fresh()->status);
        }

        $this->assertCount(1, array_unique($messages));
    }

    public function test_claim_rejects_non_tracking_consent_and_any_consent_for_non_client_targets(): void
    {
        $site = Site::factory()->create(['tenant_id' => 1]);
        $client = Client::factory()->create(['organization_id' => 1, 'site_id' => $site->id]);
        $type = ConsentType::factory()->create(['name' => 'Photography permission', 'active' => true]);
        $version = ConsentTypeVersion::create([
            'consent_type_id' => $type->id,
            'version' => 1,
            'description' => 'Photography permission v1',
            'purpose' => 'Photography',
            'legal_basis' => 'Consent',
            'effective_from' => now()->subDay(),
        ]);
        $consent = ClientConsent::create([
            'client_id' => $client->id,
            'consent_type_id' => $type->id,
            'consent_type_version_id' => $version->id,
            'status' => 'given',
            'given_at' => now()->subDay(),
            'expires_at' => now()->addDay(),
        ]);
        $clientDevice = QueclinkDevice::create([
            'tenant_id' => 1,
            'imei' => '864696060004183',
            'status' => QueclinkDevice::STATUS_PENDING,
        ]);

        $this->actingAs($this->admin)
            ->post("/security-devices/integrations/queclink/devices/{$clientDevice->id}/claim", [
                'pairing_type' => 'client',
                'target_id' => $client->id,
                'consent_id' => $consent->id,
            ])
            ->assertSessionHasErrors('consent_id');

        $vehicle = Asset::factory()->create(['site_id' => $site->id, 'category' => 'vehicle']);
        $vehicleDevice = QueclinkDevice::create([
            'tenant_id' => 1,
            'imei' => '864696060004184',
            'status' => QueclinkDevice::STATUS_PENDING,
        ]);
        $this->actingAs($this->admin)
            ->post("/security-devices/integrations/queclink/devices/{$vehicleDevice->id}/claim", [
                'pairing_type' => 'vehicle',
                'target_id' => $vehicle->id,
                'consent_id' => $consent->id,
            ])
            ->assertSessionHasErrors('consent_id');
        $this->assertSame(QueclinkDevice::STATUS_PENDING, $vehicleDevice->fresh()->status);
    }

    public function test_picker_and_direct_claim_require_canonical_staff_and_client_sites(): void
    {
        $site = Site::factory()->create(['tenant_id' => 1]);
        $staffWithoutProfile = User::factory()->create(['organization_id' => 1, 'approved_at' => now(), 'name' => 'No Profile Worker']);
        $staffWithoutSite = User::factory()->create(['organization_id' => 1, 'approved_at' => now(), 'name' => 'No Site Worker']);
        HrEmployeeProfile::factory()->create(['tenant_id' => 1, 'user_id' => $staffWithoutSite->id, 'primary_site_id' => null]);
        $validStaff = User::factory()->create(['organization_id' => 1, 'approved_at' => now(), 'name' => 'Valid Site Worker']);
        HrEmployeeProfile::factory()->create(['tenant_id' => 1, 'user_id' => $validStaff->id, 'primary_site_id' => $site->id]);
        $inactiveStaff = User::factory()->create(['organization_id' => 1, 'approved_at' => null, 'name' => 'Inactive Site Worker']);
        HrEmployeeProfile::factory()->create(['tenant_id' => 1, 'user_id' => $inactiveStaff->id, 'primary_site_id' => $site->id]);

        $clientWithoutSite = Client::factory()->create(['organization_id' => 1, 'site_id' => null, 'first_name' => 'No Site']);
        $legacyClient = Client::factory()->create(['organization_id' => null, 'site_id' => $site->id, 'first_name' => 'Valid Legacy']);

        $this->actingAs($this->admin)
            ->get('/security-devices/integrations/queclink?target_type=staff&target_search=Worker')
            ->assertInertia(function ($page) use ($validStaff, $inactiveStaff, $staffWithoutProfile, $staffWithoutSite): void {
                $ids = collect($page->toArray()['props']['targets']['staff'])->pluck('id');
                $this->assertContains($validStaff->id, $ids);
                $this->assertNotContains($inactiveStaff->id, $ids);
                $this->assertNotContains($staffWithoutProfile->id, $ids);
                $this->assertNotContains($staffWithoutSite->id, $ids);
            });
        $this->actingAs($this->admin)
            ->get('/security-devices/integrations/queclink?target_type=client')
            ->assertInertia(function ($page) use ($clientWithoutSite, $legacyClient): void {
                $ids = collect($page->toArray()['props']['targets']['clients'])->pluck('id');
                $this->assertNotContains($clientWithoutSite->id, $ids);
                $this->assertContains($legacyClient->id, $ids);
            });

        foreach ([['staff', $staffWithoutProfile->id], ['staff', $inactiveStaff->id], ['client', $clientWithoutSite->id]] as $index => [$typeName, $targetId]) {
            $device = QueclinkDevice::create([
                'tenant_id' => 1,
                'imei' => '86469606020418'.$index,
                'status' => QueclinkDevice::STATUS_PENDING,
            ]);
            $this->actingAs($this->admin)
                ->post("/security-devices/integrations/queclink/devices/{$device->id}/claim", [
                    'pairing_type' => $typeName,
                    'target_id' => $targetId,
                ])
                ->assertNotFound();
            $this->assertSame(QueclinkDevice::STATUS_PENDING, $device->fresh()->status);
        }
    }

    public function test_two_devices_cannot_claim_the_same_staff_personal_asset(): void
    {
        $site = Site::factory()->create(['tenant_id' => 1]);
        $staff = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);
        HrEmployeeProfile::factory()->create(['tenant_id' => 1, 'user_id' => $staff->id, 'primary_site_id' => $site->id]);
        $devices = collect([185, 186])->map(fn (int $suffix) => QueclinkDevice::create([
            'tenant_id' => 1,
            'imei' => '864696060004'.$suffix,
            'status' => QueclinkDevice::STATUS_PENDING,
        ]));
        $staleSecondDevice = QueclinkDevice::query()->findOrFail($devices[1]->id);

        $this->actingAs($this->admin)->post("/security-devices/integrations/queclink/devices/{$devices[0]->id}/claim", [
            'pairing_type' => 'staff',
            'target_id' => $staff->id,
        ])->assertRedirect();
        $request = Request::create(
            "/security-devices/integrations/queclink/devices/{$devices[1]->id}/claim",
            'POST',
            ['pairing_type' => 'staff', 'target_id' => $staff->id],
        );
        $request->setUserResolver(fn (): User => $this->admin);
        $queries = collect();
        DB::listen(function (QueryExecuted $query) use ($queries): void {
            $queries->push(strtolower($query->sql));
        });
        try {
            app(QueclinkHubController::class)->claimDevice($request, $staleSecondDevice);
            $this->fail('A stale target view allowed two active personal trackers.');
        } catch (HttpException $exception) {
            $this->assertSame(409, $exception->getStatusCode());
        }

        $this->assertForUpdateQuery($queries, 'queclink_devices');
        $this->assertForUpdateQuery($queries, 'users');
        $this->assertForUpdateQuery($queries, 'assets');
        $this->assertForUpdateQuery($queries, 'device_assignments');

        $assetIds = Asset::query()->where('category', 'personal_tracker')->where('primary_driver_user_id', $staff->id)->pluck('id');
        $this->assertCount(1, $assetIds);
        $this->assertSame(1, DB::table('device_asset_links')->whereIn('asset_id', $assetIds)->whereNull('unlinked_at')->count());
        $this->assertSame(0, AssetTracker::query()->whereIn('asset_id', $assetIds)->count());
        $this->assertSame(1, DeviceAssignment::query()->forTarget('staff', $staff->id)->active()->count());
        $this->assertSame(QueclinkDevice::STATUS_PENDING, $devices[1]->fresh()->status);
    }

    public function test_two_devices_cannot_claim_the_same_client_personal_asset(): void
    {
        $site = Site::factory()->create(['tenant_id' => 1]);
        $client = Client::factory()->create(['organization_id' => 1, 'site_id' => $site->id]);
        $consent = $this->trackingConsent($client);
        $devices = collect([187, 188])->map(fn (int $suffix) => QueclinkDevice::create([
            'tenant_id' => 1,
            'imei' => '864696060004'.$suffix,
            'status' => QueclinkDevice::STATUS_PENDING,
        ]));

        $this->actingAs($this->admin)->post("/security-devices/integrations/queclink/devices/{$devices[0]->id}/claim", [
            'pairing_type' => 'client',
            'target_id' => $client->id,
            'consent_id' => $consent->id,
        ])->assertRedirect();
        $queries = collect();
        DB::listen(function (QueryExecuted $query) use ($queries): void {
            $queries->push(strtolower($query->sql));
        });
        $this->actingAs($this->admin)->post("/security-devices/integrations/queclink/devices/{$devices[1]->id}/claim", [
            'pairing_type' => 'client',
            'target_id' => $client->id,
            'consent_id' => $consent->id,
        ])->assertStatus(409);

        $this->assertForUpdateQuery($queries, 'queclink_devices');
        $this->assertForUpdateQuery($queries, 'clients');
        $this->assertForUpdateQuery($queries, 'assets');
        $this->assertForUpdateQuery($queries, 'device_assignments');

        $assetIds = Asset::query()->where('category', 'personal_tracker')->where('client_id', $client->id)->pluck('id');
        $this->assertCount(1, $assetIds);
        $this->assertSame(1, DB::table('device_asset_links')->whereIn('asset_id', $assetIds)->whereNull('unlinked_at')->count());
        $this->assertSame(0, AssetTracker::query()->whereIn('asset_id', $assetIds)->count());
        $this->assertSame(1, DeviceAssignment::query()->forTarget('client', $client->id)->active()->count());
        $this->assertSame(QueclinkDevice::STATUS_PENDING, $devices[1]->fresh()->status);
    }

    public function test_personal_claim_ignores_multiple_historical_tracker_rows_and_preserves_them(): void
    {
        $site = Site::factory()->create(['tenant_id' => 1]);
        $staff = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);
        HrEmployeeProfile::factory()->create(['tenant_id' => 1, 'user_id' => $staff->id, 'primary_site_id' => $site->id]);
        $asset = Asset::factory()->create([
            'category' => 'personal_tracker',
            'site_id' => $site->id,
            'primary_driver_user_id' => $staff->id,
        ]);
        $device = QueclinkDevice::create([
            'tenant_id' => 1,
            'imei' => '864696060004189',
            'status' => QueclinkDevice::STATUS_PENDING,
        ]);
        $matching = AssetTracker::create([
            'asset_id' => $asset->id,
            'vendor' => 'queclink',
            'device_uid' => $device->imei,
            'imei' => $device->imei,
            'status' => 'paired',
            'paired_at' => now()->subDay(),
        ]);
        $mismatched = AssetTracker::create([
            'asset_id' => $asset->id,
            'vendor' => 'queclink',
            'device_uid' => '864696060004190',
            'imei' => '864696060004190',
            'status' => 'paired',
            'paired_at' => now()->subDay(),
        ]);
        $before = AssetTracker::query()->whereIn('id', [$matching->id, $mismatched->id])
            ->orderBy('id')
            ->get()
            ->map(fn (AssetTracker $tracker): array => $tracker->only([
                'id', 'asset_id', 'vendor', 'device_uid', 'imei', 'status', 'paired_at', 'unpaired_at', 'consent_id',
            ]))
            ->all();

        $this->assertLessThan($mismatched->id, $matching->id);
        $this->actingAs($this->admin)
            ->post("/security-devices/integrations/queclink/devices/{$device->id}/claim", [
                'pairing_type' => 'staff',
                'target_id' => $staff->id,
            ])
            ->assertRedirect();

        $this->assertEquals($before, AssetTracker::query()->whereIn('id', [$matching->id, $mismatched->id])
            ->orderBy('id')
            ->get()
            ->map(fn (AssetTracker $tracker): array => $tracker->only([
                'id', 'asset_id', 'vendor', 'device_uid', 'imei', 'status', 'paired_at', 'unpaired_at', 'consent_id',
            ]))
            ->all());
        $this->assertSame(QueclinkDevice::STATUS_PAIRED, $device->fresh()->status);
        $this->assertNotNull($device->fresh()->device_id);
        $this->assertSame(1, DeviceAssignment::query()->forTarget('staff', $staff->id)->active()->count());
        $this->assertDatabaseHas('devices', [
            'provider' => 'queclink',
            'device_uid' => $device->imei,
        ]);
        $this->assertDatabaseHas('queclink_audit_events', [
            'queclink_device_id' => $device->id,
            'event_type' => 'claim',
        ]);
    }

    public function test_personal_claim_preserves_one_historical_tracker_row_without_locking_it(): void
    {
        $site = Site::factory()->create(['tenant_id' => 1]);
        $staff = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);
        HrEmployeeProfile::factory()->create(['tenant_id' => 1, 'user_id' => $staff->id, 'primary_site_id' => $site->id]);
        $asset = Asset::factory()->create([
            'category' => 'personal_tracker',
            'site_id' => $site->id,
            'primary_driver_user_id' => $staff->id,
        ]);
        $device = QueclinkDevice::create([
            'tenant_id' => 1,
            'imei' => '864696060004191',
            'status' => QueclinkDevice::STATUS_PENDING,
        ]);
        $tracker = AssetTracker::create([
            'asset_id' => $asset->id,
            'vendor' => 'queclink',
            'device_uid' => $device->imei,
            'imei' => $device->imei,
            'status' => 'paired',
            'paired_at' => now()->subDay(),
        ]);

        $queries = collect();
        DB::listen(function (QueryExecuted $query) use ($queries): void {
            $queries->push(strtolower($query->sql));
        });

        $this->actingAs($this->admin)
            ->post("/security-devices/integrations/queclink/devices/{$device->id}/claim", [
                'pairing_type' => 'staff',
                'target_id' => $staff->id,
            ])
            ->assertRedirect();

        $this->assertForUpdateQuery($queries, 'queclink_devices');
        $this->assertForUpdateQuery($queries, 'users');
        $this->assertForUpdateQuery($queries, 'assets');
        $this->assertForUpdateQuery($queries, 'device_assignments');
        $this->assertFalse($queries->contains(fn (string $sql): bool => str_contains($sql, 'asset_trackers')));
        $this->assertSame(QueclinkDevice::STATUS_PAIRED, $device->fresh()->status);
        $this->assertSame(1, AssetTracker::query()->where('asset_id', $asset->id)->where('status', 'paired')->count());
        $this->assertSame($device->imei, $tracker->fresh()->device_uid);
        $this->assertNull($tracker->fresh()->unpaired_at);
        $this->assertSame(1, DeviceAssignment::query()->forTarget('staff', $staff->id)->active()->count());
    }

    public function test_release_paired_device_returns_it_to_pending_tray()
    {
        $site = Site::factory()->create(['tenant_id' => 1]);
        $asset = Asset::factory()->create(['site_id' => $site->id, 'category' => 'vehicle']);
        $device = QueclinkDevice::create([
            'tenant_id' => 1,
            'imei' => '864696060004173',
            'status' => QueclinkDevice::STATUS_PENDING,
        ]);

        // First pair it properly so canonical link and assignment records exist.
        $this->actingAs($this->admin)
            ->post("/security-devices/integrations/queclink/devices/{$device->id}/claim", [
                'pairing_type' => 'vehicle',
                'target_id' => $asset->id,
            ])
            ->assertRedirect();

        // Then release
        $this->actingAs($this->admin)
            ->post("/security-devices/integrations/queclink/devices/{$device->id}/release")
            ->assertRedirect();

        $this->assertSame(QueclinkDevice::STATUS_PENDING, $device->fresh()->status);
    }

    public function test_release_uses_historical_provenance_for_deapproved_and_deleted_staff_profiles(): void
    {
        $site = Site::factory()->create(['tenant_id' => 1]);

        foreach (['deapproved', 'profile_deleted'] as $index => $state) {
            $staff = User::factory()->create(['organization_id' => 1, 'approved_at' => now()]);
            $profile = HrEmployeeProfile::factory()->create([
                'tenant_id' => 1,
                'user_id' => $staff->id,
                'primary_site_id' => $site->id,
            ]);
            $device = QueclinkDevice::create([
                'tenant_id' => 1,
                'imei' => '86469606030418'.$index,
                'status' => QueclinkDevice::STATUS_PENDING,
            ]);
            $this->actingAs($this->admin)->post("/security-devices/integrations/queclink/devices/{$device->id}/claim", [
                'pairing_type' => 'staff',
                'target_id' => $staff->id,
            ])->assertRedirect();
            $assignment = DeviceAssignment::query()->where('device_id', $device->fresh()->device_id)->active()->sole();

            if ($state === 'deapproved') {
                $staff->forceFill(['approved_at' => null])->save();
            } else {
                $profile->delete();
            }

            $this->actingAs($this->admin)
                ->get('/security-devices/integrations/queclink?device_search='.substr($device->imei, -6))
                ->assertInertia(fn ($page) => $page->where('devices.paired.0.id', $device->id));

            $this->actingAs($this->admin)
                ->post("/security-devices/integrations/queclink/devices/{$device->id}/release")
                ->assertRedirect();

            $this->assertNotNull($assignment->fresh()->released_at);
            $this->assertSame(QueclinkDevice::STATUS_PENDING, $device->fresh()->status);
            $this->assertDatabaseHas('queclink_audit_events', [
                'queclink_device_id' => $device->id,
                'event_type' => 'release',
            ]);
        }
    }

    public function test_release_uses_historical_provenance_for_a_soft_deleted_client(): void
    {
        $site = Site::factory()->create(['tenant_id' => 1]);
        $client = Client::factory()->create(['organization_id' => 1, 'site_id' => $site->id]);
        $consent = $this->trackingConsent($client);
        $device = QueclinkDevice::create([
            'tenant_id' => 1,
            'imei' => '864696060004189',
            'status' => QueclinkDevice::STATUS_PENDING,
        ]);
        $this->actingAs($this->admin)->post("/security-devices/integrations/queclink/devices/{$device->id}/claim", [
            'pairing_type' => 'client',
            'target_id' => $client->id,
            'consent_id' => $consent->id,
        ])->assertRedirect();
        $assignment = DeviceAssignment::query()->where('device_id', $device->fresh()->device_id)->active()->sole();
        $client->delete();

        $this->actingAs($this->admin)
            ->get('/security-devices/integrations/queclink?device_search='.substr($device->imei, -6))
            ->assertInertia(fn ($page) => $page->where('devices.paired.0.id', $device->id));

        $this->actingAs($this->admin)
            ->post("/security-devices/integrations/queclink/devices/{$device->id}/release")
            ->assertRedirect();

        $this->assertNotNull($assignment->fresh()->released_at);
        $this->assertSame(QueclinkDevice::STATUS_PENDING, $device->fresh()->status);
        $this->assertDatabaseHas('queclink_audit_events', [
            'queclink_device_id' => $device->id,
            'event_type' => 'release',
        ]);
    }

    public function test_release_rolls_back_for_a_foreign_historical_assignment_while_claim_stays_live_only(): void
    {
        $foreignSite = Site::factory()->create(['tenant_id' => 77]);
        $foreignStaff = User::factory()->create(['organization_id' => 77, 'approved_at' => null]);
        HrEmployeeProfile::factory()->create([
            'tenant_id' => 77,
            'user_id' => $foreignStaff->id,
            'primary_site_id' => $foreignSite->id,
            'is_active' => false,
        ]);
        $canonical = Device::factory()->tracking()->create(['tenant_id' => 1, 'provider' => 'queclink']);
        $assignment = DeviceAssignment::create([
            'device_id' => $canonical->id,
            'assignable_type' => DeviceAssignment::TARGET_STAFF,
            'assignable_id' => $foreignStaff->id,
            'assigned_at' => now(),
        ]);
        $paired = QueclinkDevice::create([
            'tenant_id' => 1,
            'imei' => '864696060004190',
            'status' => QueclinkDevice::STATUS_PAIRED,
            'device_id' => $canonical->id,
        ]);

        $this->actingAs($this->admin)
            ->post("/security-devices/integrations/queclink/devices/{$paired->id}/release")
            ->assertNotFound();
        $this->assertSame(QueclinkDevice::STATUS_PAIRED, $paired->fresh()->status);
        $this->assertNull($assignment->fresh()->released_at);

        $pending = QueclinkDevice::create([
            'tenant_id' => 1,
            'imei' => '864696060004191',
            'status' => QueclinkDevice::STATUS_PENDING,
        ]);
        $this->actingAs($this->admin)->post("/security-devices/integrations/queclink/devices/{$pending->id}/claim", [
            'pairing_type' => 'staff',
            'target_id' => $foreignStaff->id,
        ])->assertNotFound();
        $this->assertSame(QueclinkDevice::STATUS_PENDING, $pending->fresh()->status);
    }

    public function test_release_route_persists_old_site_assignment_audit_after_the_device_moves(): void
    {
        $oldSite = Site::factory()->create(['tenant_id' => 1]);
        $newSite = Site::factory()->create(['tenant_id' => 1]);
        $asset = Asset::factory()->create(['site_id' => $oldSite->id, 'category' => 'vehicle']);
        $queclink = QueclinkDevice::create([
            'tenant_id' => 1,
            'imei' => '864696060004176',
            'status' => QueclinkDevice::STATUS_PENDING,
        ]);
        $this->actingAs($this->admin)->post("/security-devices/integrations/queclink/devices/{$queclink->id}/claim", [
            'pairing_type' => 'vehicle',
            'target_id' => $asset->id,
        ])->assertRedirect();
        $canonicalDeviceId = (int) $queclink->fresh()->device_id;
        $assignment = DeviceAssignment::query()->where('device_id', $canonicalDeviceId)->whereNull('released_at')->sole();
        AuditLog::query()->delete();

        $this->actingAs($this->admin)
            ->post("/security-devices/integrations/queclink/devices/{$queclink->id}/release")
            ->assertRedirect();

        $audit = AuditLog::query()
            ->where('action', 'deviceassignment.update')
            ->where('auditable_id', $assignment->id)
            ->sole();
        $this->assertSame($oldSite->id, data_get($audit->meta, 'scope.site_id'));
        $this->assertSame([$oldSite->id], data_get($audit->meta, 'scope.site_ids'));
        DeviceAssignment::create([
            'device_id' => $canonicalDeviceId,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $newSite->id,
            'assigned_at' => now(),
        ]);

        $oldViewer = $this->siteRestrictedViewer($oldSite);
        $newViewer = $this->siteRestrictedViewer($newSite);
        $this->actingAs($oldViewer)->get('/security-devices/settings')->assertOk()->assertInertia(function ($page) use ($assignment): void {
            $this->assertTrue(collect($page->toArray()['props']['audit']['entries'])
                ->where('action', 'deviceassignment.update')
                ->contains('record_reference', '#'.$assignment->id));
        });
        $this->actingAs($newViewer)->get('/security-devices/settings')->assertOk()->assertInertia(function ($page) use ($assignment): void {
            $this->assertFalse(collect($page->toArray()['props']['audit']['entries'])
                ->where('action', 'deviceassignment.update')
                ->contains('record_reference', '#'.$assignment->id));
        });

        $this->assertNotNull($assignment->fresh()->released_at);
        $this->assertDatabaseMissing('asset_trackers', ['device_uid' => $queclink->imei]);
        $this->assertSame(QueclinkDevice::STATUS_PENDING, $queclink->fresh()->status);
        $this->assertNull($queclink->fresh()->device_id);
    }

    public function test_location_command_hands_off_to_governed_device_management_without_queueing_provider_work()
    {
        $site = Site::factory()->create([
            'is_active' => true,
            'archived' => false,
            'archived_at' => null,
        ]);
        $canonicalDevice = Device::factory()->tracking()->create([
            'provider' => 'queclink',
            'imei' => '864696060004173',
            'device_uid' => '864696060004173',
        ]);
        DeviceAssignment::query()->create([
            'device_id' => $canonicalDevice->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assigned_at' => now(),
        ]);
        $device = QueclinkDevice::create([
            'tenant_id' => 1,
            'imei' => '864696060004173',
            'status' => QueclinkDevice::STATUS_PAIRED,
            'model_hint' => 'GV500CG',
            'device_id' => $canonicalDevice->id,
        ]);

        $this->actingAs($this->admin)
            ->post("/security-devices/integrations/queclink/devices/{$device->id}/command", [
                'mode' => 'preset',
                'preset' => 'request_location',
            ])
            ->assertRedirect("/security-devices/devices/{$canonicalDevice->id}?section=management&action=tracking.location_refresh");

        $this->assertDatabaseCount('queclink_pending_commands', 0);
    }

    public function test_provider_console_rejects_raw_and_interval_commands(): void
    {
        $device = QueclinkDevice::create([
            'tenant_id' => 1,
            'imei' => '864696060004173',
            'status' => QueclinkDevice::STATUS_PAIRED,
            'model_hint' => 'GV500CG',
        ]);

        foreach ([
            ['mode' => 'raw', 'raw' => 'AT+GTRTO=gv500cg,1,,,,,,0001$'],
            ['mode' => 'preset', 'preset' => 'set_interval', 'interval_seconds' => 30],
        ] as $payload) {
            $this->actingAs($this->admin)
                ->from('/security-devices/integrations/queclink?tab=debug')
                ->post("/security-devices/integrations/queclink/devices/{$device->id}/command", $payload)
                ->assertRedirect('/security-devices/integrations/queclink?tab=debug')
                ->assertSessionHasErrors('command');
        }

        $this->assertDatabaseCount('queclink_pending_commands', 0);
    }

    public function test_restart_hands_off_to_governed_device_management_without_provider_work(): void
    {
        $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $canonical = Device::factory()->tracking()->create([
            'provider' => 'queclink',
            'imei' => '864696060004179',
            'device_uid' => '864696060004179',
        ]);
        DeviceAssignment::query()->create([
            'device_id' => $canonical->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assigned_at' => now(),
        ]);
        $device = QueclinkDevice::create([
            'tenant_id' => 1,
            'imei' => '864696060004179',
            'status' => QueclinkDevice::STATUS_PAIRED,
            'model_hint' => 'GV500CG',
            'device_id' => $canonical->id,
        ]);

        $this->actingAs($this->admin)
            ->post("/security-devices/integrations/queclink/devices/{$device->id}/command", [
                'mode' => 'preset',
                'preset' => 'reboot',
            ])
            ->assertRedirect("/security-devices/devices/{$canonical->id}?section=management&action=device.reboot");

        $this->assertDatabaseCount('queclink_pending_commands', 0);
    }

    public function test_read_device_configuration_hands_off_to_governed_refresh_without_provider_work()
    {
        $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $canonical = Device::factory()->tracking()->create([
            'provider' => 'queclink',
            'imei' => '867963069916998',
            'device_uid' => '867963069916998',
        ]);
        DeviceAssignment::query()->create([
            'device_id' => $canonical->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assigned_at' => now(),
        ]);
        $device = QueclinkDevice::create([
            'tenant_id' => 1,
            'imei' => '867963069916998',
            'status' => QueclinkDevice::STATUS_PAIRED,
            'model_hint' => 'GL30MEU',
            'device_id' => $canonical->id,
        ]);

        $this->actingAs($this->admin)
            ->post("/security-devices/integrations/queclink/devices/{$device->id}/configuration/read", [
                'section' => 'all',
            ])
            ->assertRedirect("/security-devices/devices/{$canonical->id}?section=management&action=configuration.refresh&command_section=all");

        $this->assertDatabaseCount('queclink_pending_commands', 0);
    }

    public function test_per_section_read_hands_the_exact_section_to_governed_refresh()
    {
        $site = Site::factory()->create(['is_active' => true, 'archived' => false]);
        $canonical = Device::factory()->tracking()->create([
            'provider' => 'queclink',
            'imei' => '867963069916998',
            'device_uid' => '867963069916998',
        ]);
        DeviceAssignment::query()->create([
            'device_id' => $canonical->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assigned_at' => now(),
        ]);
        $device = QueclinkDevice::create([
            'tenant_id' => 1,
            'imei' => '867963069916998',
            'status' => QueclinkDevice::STATUS_PAIRED,
            'model_hint' => 'GL30MEU',
            'device_id' => $canonical->id,
        ]);

        $this->actingAs($this->admin)
            ->post("/security-devices/integrations/queclink/devices/{$device->id}/configuration/server/read")
            ->assertRedirect("/security-devices/devices/{$canonical->id}?section=management&action=configuration.refresh&command_section=SRI");

        $this->assertDatabaseCount('queclink_pending_commands', 0);
    }

    public function test_generic_section_update_authors_protected_profile_and_hands_off()
    {
        $device = $this->pairedCanonicalGl30('867963069916998');

        $this->actingAs($this->admin)
            ->post("/security-devices/integrations/queclink/devices/{$device->id}/configuration/server", [
                'command' => 'dog',
                'mode' => 1,
                'reboot_interval' => 1,
                'reboot_time' => '0130',
                'report_before_reboot' => 1,
                'unit' => 0,
                'send_failure_timeout' => 60,
            ])
            ->assertRedirectContains('/security-devices/devices/'.$device->device_id)
            ->assertRedirectContains('action=configuration.apply');

        $this->assertDatabaseCount('queclink_pending_commands', 0);
        $this->assertDatabaseHas('queclink_audit_events', [
            'queclink_device_id' => $device->id,
            'event_type' => 'configuration_profile_authored',
            'section' => 'server',
            'raw_command' => null,
            'imei' => null,
        ]);
        $profile = DeviceConfigurationProfile::query()->sole();
        $this->assertStringNotContainsString(
            '0130',
            (string) DB::table('device_configuration_profiles')->where('id', $profile->id)->value('encrypted_payload'),
        );
    }

    public function test_command_queue_cancel_is_audited_and_the_legacy_retry_shortcut_is_not_routable()
    {
        $device = QueclinkDevice::create([
            'tenant_id' => 1,
            'imei' => '867963069916998',
            'status' => QueclinkDevice::STATUS_PAIRED,
            'model_hint' => 'GL30MEU',
        ]);
        $command = QueclinkPendingCommand::create([
            'queclink_device_id' => $device->id,
            'imei' => $device->imei,
            'tenant_id' => 1,
            'command_word' => 'GTRTO',
            'raw_command' => 'AT+GTRTO=gl30,1,,,,,,0001$',
            'serial_number' => '0001',
            'status' => QueclinkPendingCommand::STATUS_QUEUED,
            'created_by_user_id' => $this->admin->id,
            'expires_at' => now()->addMinute(),
        ]);

        $this->actingAs($this->admin)
            ->post("/security-devices/integrations/queclink/commands/{$command->id}/cancel")
            ->assertRedirect();

        $this->assertSame(QueclinkPendingCommand::STATUS_CANCELLED, $command->fresh()->status);
        $this->assertNotNull($command->fresh()->cancelled_at);

        $this->assertFalse(Route::has('security-devices.integrations.queclink.commands.retry'));
        $this->actingAs($this->admin)
            ->post("/security-devices/integrations/queclink/commands/{$command->id}/retry")
            ->assertNotFound();

        $this->assertSame(1, QueclinkPendingCommand::query()->count());
        $this->assertDatabaseHas('queclink_audit_events', [
            'queclink_device_id' => $device->id,
            'event_type' => 'cancel',
        ]);
        $this->assertDatabaseMissing('queclink_audit_events', [
            'event_type' => 'retry',
        ]);
    }

    public function test_bulk_action_hands_all_selected_devices_to_governed_bulk_management()
    {
        $this->seed(QueclinkPresetSeeder::class);
        $devices = collect(range(1, 5))->map(fn (int $index) => $this->pairedCanonicalGl30('86796306991699'.$index));

        $this->actingAs($this->admin)
            ->post('/security-devices/integrations/queclink/bulk', [
                'device_ids' => $devices->pluck('id')->all(),
                'action' => 'resident_safety_profile',
            ])
            ->assertRedirectContains('/security-devices/tracking')
            ->assertRedirectContains('bulk_action=configuration.apply');

        $this->assertDatabaseCount('queclink_pending_commands', 0);
    }

    public function test_update_server_registration_authors_profile_without_provider_work()
    {
        $device = $this->pairedCanonicalGl30('867963069916998');

        $this->actingAs($this->admin)
            ->post("/security-devices/integrations/queclink/devices/{$device->id}/configuration/server", [
                'report_mode' => 3,
                'manual_netreg' => 0,
                'buffer_mode' => 1,
                'main_host' => 'oblivionfindings.com',
                'main_port' => 8090,
                'backup_host' => 'oblivionfindings.com',
                'backup_port' => 8090,
                'heartbeat_interval_minutes' => 5,
                'sack_enable' => 1,
                'sms_ack_enable' => 0,
                'psm_network_hold_time_seconds' => 30,
                'protocol_format' => 0,
            ])
            ->assertRedirectContains('action=configuration.apply');

        $this->assertDatabaseCount('queclink_pending_commands', 0);
        $this->assertStringNotContainsString(
            'oblivionfindings.com',
            (string) DB::table('device_configuration_profiles')->value('encrypted_payload'),
        );
    }

    public function test_update_global_configuration_authors_profile_without_provider_work()
    {
        $device = $this->pairedCanonicalGl30('867963069916998');

        $this->actingAs($this->admin)
            ->post("/security-devices/integrations/queclink/devices/{$device->id}/configuration/global", [
                'device_name' => 'GL30MEU',
                'gnss_timeout_seconds' => 150,
                'event_mask' => '08E3',
                'report_item_mask' => '006F',
                'mode_selection' => 1,
                'continuous_send_interval_seconds' => 30,
                'start_mode' => 0,
                'specified_time_of_day' => '1200',
                'wakeup_interval_hours' => 1,
                'gnss_enable' => 1,
                'agps_mode' => 1,
                'gsm_report' => '0000',
                'battery_low_percentage' => 10,
                'function_button_mode' => 1,
                'sos_report_mode' => 1,
                'wifi_report' => 2,
                'led_on' => 1,
                'charge_standby_mode' => 0,
            ])
            ->assertRedirectContains('action=configuration.apply');

        $this->assertDatabaseCount('queclink_pending_commands', 0);
        $this->assertDatabaseCount('device_configuration_profiles', 1);
    }

    public function test_resident_safety_profile_hands_off_without_provider_work()
    {
        $this->seed(QueclinkPresetSeeder::class);
        $device = $this->pairedCanonicalGl30('867963069916998');

        $this->actingAs($this->admin)
            ->post("/security-devices/integrations/queclink/devices/{$device->id}/configuration/resident-safety-profile")
            ->assertRedirectContains('action=configuration.apply');

        $this->assertDatabaseCount('queclink_pending_commands', 0);
    }

    public function test_update_global_configuration_rejects_invalid_short_gl30_interval()
    {
        $device = QueclinkDevice::create([
            'tenant_id' => 1,
            'imei' => '867963069916998',
            'status' => QueclinkDevice::STATUS_PAIRED,
            'model_hint' => 'GL30MEU',
        ]);

        $this->actingAs($this->admin)
            ->from('/security-devices/integrations/queclink?tab=settings')
            ->post("/security-devices/integrations/queclink/devices/{$device->id}/configuration/global", [
                'device_name' => 'GL30MEU',
                'gnss_timeout_seconds' => 150,
                'event_mask' => '08E3',
                'report_item_mask' => '006F',
                'mode_selection' => 1,
                'continuous_send_interval_seconds' => 2,
                'start_mode' => 0,
                'specified_time_of_day' => '1200',
                'wakeup_interval_hours' => 1,
                'gnss_enable' => 1,
                'agps_mode' => 1,
                'gsm_report' => '0000',
                'battery_low_percentage' => 10,
                'function_button_mode' => 1,
                'sos_report_mode' => 1,
                'wifi_report' => 2,
                'led_on' => 1,
                'charge_standby_mode' => 0,
            ])
            ->assertSessionHasErrors('continuous_send_interval_seconds');

        $this->assertDatabaseCount('queclink_pending_commands', 0);
    }

    public function test_personal_tracker_with_blank_model_still_hands_off_to_governed_management()
    {
        $site = Site::factory()->create([
            'is_active' => true,
            'archived' => false,
            'archived_at' => null,
        ]);
        $canonicalDevice = Device::factory()->tracking()->create([
            'provider' => 'queclink',
            'imei' => '867963069916998',
            'device_uid' => '867963069916998',
            'model' => null,
        ]);
        DeviceAssignment::query()->create([
            'device_id' => $canonicalDevice->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assigned_at' => now(),
        ]);
        $device = QueclinkDevice::create([
            'tenant_id' => 1,
            'imei' => '867963069916998',
            'status' => QueclinkDevice::STATUS_PAIRED,
            'device_id' => $canonicalDevice->id,
            'model_hint' => null,
        ]);

        $this->actingAs($this->admin)
            ->post("/security-devices/integrations/queclink/devices/{$device->id}/command", [
                'mode' => 'preset',
                'preset' => 'request_location',
            ])
            ->assertRedirect("/security-devices/devices/{$canonicalDevice->id}?section=management&action=tracking.location_refresh");

        $this->assertDatabaseCount('queclink_pending_commands', 0);
    }

    public function test_frames_endpoint_returns_paged_json_for_authorised_user()
    {
        $device = QueclinkDevice::create([
            'tenant_id' => 1,
            'imei' => '864696060004173',
            'status' => QueclinkDevice::STATUS_PENDING,
        ]);
        QueclinkRawFrame::create([
            'tenant_id' => 1,
            'queclink_device_id' => $device->id,
            'imei' => '864696060004173',
            'direction' => 'inbound',
            'frame_type' => 'RESP',
            'command_word' => 'GTHBD',
            'raw_frame' => '+RESP:GTHBD,8020090100,864696060004173,GV500CG,20230811075652,09CF$',
            'parse_ok' => true,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/security-devices/integrations/queclink/frames');

        $response->assertOk()
            ->assertJsonStructure(['frames' => [['id', 'direction', 'frame_type', 'command_word', 'parse_ok', 'failure_category', 'created_at']]])
            ->assertJsonMissingPath('frames.0.imei')
            ->assertJsonMissingPath('frames.0.raw_frame')
            ->assertJsonCount(1, 'frames');
    }

    public function test_frames_endpoint_filters_by_command_parse_status_and_search()
    {
        $heartbeatDevice = QueclinkDevice::create([
            'tenant_id' => 1,
            'imei' => '864696060004173',
            'status' => QueclinkDevice::STATUS_PENDING,
        ]);
        $alarmDevice = QueclinkDevice::create([
            'tenant_id' => 1,
            'imei' => '867963069916998',
            'status' => QueclinkDevice::STATUS_PENDING,
        ]);
        QueclinkRawFrame::create([
            'tenant_id' => 1,
            'queclink_device_id' => $heartbeatDevice->id,
            'imei' => '864696060004173',
            'direction' => 'inbound',
            'frame_type' => 'RESP',
            'command_word' => 'GTHBD',
            'raw_frame' => '+RESP:GTHBD,8020090100,864696060004173,GV500CG,20230811075652,09CF$',
            'parse_ok' => true,
        ]);

        QueclinkRawFrame::create([
            'tenant_id' => 1,
            'queclink_device_id' => $alarmDevice->id,
            'imei' => '867963069916998',
            'direction' => 'inbound',
            'frame_type' => 'RESP',
            'command_word' => 'GTALM',
            'raw_frame' => '+RESP:GTALM,bad-payload$',
            'parse_ok' => false,
            'parse_error' => 'bad payload',
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/security-devices/integrations/queclink/frames?command_word=GTALM&parse_status=error&search=bad');

        $response->assertOk()
            ->assertJsonCount(1, 'frames')
            ->assertJsonPath('frames.0.command_word', 'GTALM')
            ->assertJsonPath('frames.0.parse_ok', false);
    }

    public function test_provisioning_string_requires_hostname_setting()
    {
        config(['services.queclink.public_hostname' => null]);

        $response = $this->actingAs($this->admin)
            ->getJson('/security-devices/integrations/queclink/provisioning?family=gv500cg');

        $response->assertStatus(422)
            ->assertJsonPath('error', 'Set the public hostname under Listener settings first.');
    }

    public function test_provisioning_readiness_accepts_the_configured_environment_hostname_without_disclosing_it()
    {
        config(['services.queclink.public_hostname' => 'tracking.example.co.nz']);

        $response = $this->actingAs($this->admin)
            ->getJson('/security-devices/integrations/queclink/provisioning?family=gv500cg');

        $response->assertOk()
            ->assertJsonPath('state', 'ready_for_secure_provisioning')
            ->assertJsonPath('family', 'vehicle_tracker');

        $encoded = json_encode($response->json(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('tracking.example.co.nz', $encoded);
        $this->assertStringNotContainsString('AT+GT', $encoded);
    }

    public function test_provisioning_readiness_rejects_an_unknown_device_family()
    {
        AppSetting::create(['key' => 'queclink.public_hostname', 'value' => 'tracking.example.co.nz']);

        $this->actingAs($this->admin)
            ->getJson('/security-devices/integrations/queclink/provisioning?family=unknown-family')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('family')
            ->assertJsonMissingPath('state');
    }

    public function test_provisioning_readiness_never_returns_provider_target_or_raw_command()
    {
        AppSetting::create(['key' => 'queclink.public_hostname', 'value' => 'tracking.example.co.nz']);
        AppSetting::create(['key' => 'queclink.listener.port', 'value' => 8091]);

        $response = $this->actingAs($this->admin)
            ->getJson('/security-devices/integrations/queclink/provisioning?family=gv500cg');

        $response->assertOk();
        $response->assertJsonPath('state', 'ready_for_secure_provisioning');
        $encoded = json_encode($response->json(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('tracking.example.co.nz', $encoded);
        $this->assertStringNotContainsString('AT+GT', $encoded);
    }

    public function test_gl30m_provisioning_readiness_reports_only_bounded_family_state()
    {
        AppSetting::create(['key' => 'queclink.public_hostname', 'value' => 'tracking.example.co.nz']);
        AppSetting::create(['key' => 'queclink.listener.port', 'value' => 8091]);

        $response = $this->actingAs($this->admin)
            ->getJson('/security-devices/integrations/queclink/provisioning?family=gl30m');

        $response->assertOk()
            ->assertJsonPath('state', 'ready_for_secure_provisioning')
            ->assertJsonPath('family', 'personal_tracker')
            ->assertJsonMissingPath('config_string');
    }

    public function test_unauthorised_user_cannot_reach_hub()
    {
        $u = User::factory()->create();  // no permissions
        $this->actingAs($u)
            ->get('/security-devices/integrations/queclink')
            ->assertForbidden();
    }

    private function partialTargetRequest(string $targetType)
    {
        $version = app(HandleInertiaRequests::class)->version(request());

        return $this->actingAs($this->admin)->get(
            "/security-devices/integrations/queclink?target_type={$targetType}&target_search=Worker",
            [
                'X-Inertia' => 'true',
                'X-Inertia-Version' => $version,
                'X-Inertia-Partial-Component' => 'security-devices/integrations/queclink-hub',
                'X-Inertia-Partial-Data' => 'targets',
            ],
        );
    }

    private function trackingConsent(Client $client): ClientConsent
    {
        $type = ConsentType::create([
            'name' => 'Personal Tracker '.Str::random(8),
            'category' => 'safety',
            'description' => 'Personal tracker consent',
            'purpose' => 'Resident safety tracking',
            'legal_basis' => 'Consent',
            'version' => 1,
            'active' => true,
        ]);
        $version = ConsentTypeVersion::create([
            'consent_type_id' => $type->id,
            'version' => 1,
            'description' => 'Personal tracker consent v1',
            'purpose' => 'Resident safety tracking',
            'legal_basis' => 'Consent',
            'effective_from' => now()->subDay(),
        ]);

        return ClientConsent::create([
            'client_id' => $client->id,
            'consent_type_id' => $type->id,
            'consent_type_version_id' => $version->id,
            'status' => 'given',
            'given_at' => now()->subDay(),
            'expires_at' => now()->addDays(30),
        ]);
    }

    private function siteRestrictedViewer(Site $site): User
    {
        $viewer = User::factory()->create([
            'organization_id' => (int) $site->tenant_id,
            'approved_at' => now(),
        ]);
        $viewer->roles()->attach(Role::query()->where('name', 'facilities_manager')->firstOrFail());
        $permissionIds = Permission::query()->whereIn('key', [
            'securityDevices.integrations.manage',
            'assets.viewAny',
            'clients.viewAny',
            'fleet.viewAny',
            'hazards.view',
            'staff.viewAny',
        ])->pluck('id');
        $this->assertCount(6, $permissionIds);
        $viewer->permissionOverrides()->syncWithoutDetaching(
            $permissionIds->mapWithKeys(fn (int $id): array => [$id => ['allowed' => true]])->all(),
        );
        HrEmployeeProfile::factory()->create([
            'tenant_id' => (int) $site->tenant_id,
            'user_id' => $viewer->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
        ]);

        return $viewer;
    }

    /** @param Collection<int, string> $queries */
    private function assertForUpdateQuery(Collection $queries, string $table, int $minimum = 1): void
    {
        $matchingQueries = $queries->filter(fn (string $sql): bool => str_contains($sql, "from `{$table}`") && str_contains($sql, 'for update'));

        $this->assertGreaterThanOrEqual(
            $minimum,
            $matchingQueries->count(),
            "Expected at least {$minimum} SELECT ... FOR UPDATE query for {$table}.",
        );
    }
}
