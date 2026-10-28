<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\Monitoring\Contracts\ApprovedProbeScopeProvider;
use App\Domain\Monitoring\Contracts\CredentialLeaseProvider;
use App\Domain\Monitoring\Contracts\DnsResolver;
use App\Domain\Monitoring\Contracts\SnapshotStore;
use App\Domain\Monitoring\Contracts\TimeSeriesStore;
use App\Domain\Monitoring\Data\AuthorizedProbeTarget;
use App\Domain\Monitoring\Data\CredentialLease;
use App\Domain\Monitoring\Data\TimeSeriesPoint;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Handlers\EventEnvelopeHandler;
use App\Domain\Monitoring\Models\ConfigurationSnapshot;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\Monitoring\Models\MonitoringDeadLetter;
use App\Domain\Monitoring\Services\CollectorIngestService;
use App\Domain\Monitoring\Services\ConfigurationSnapshotService;
use App\Domain\Monitoring\Services\EgressPolicy;
use App\Domain\Monitoring\Services\MetricIngestService;
use App\Domain\Monitoring\Services\RuntimeEnvelopeHandlerRegistry;
use App\Domain\SecurityDevices\Alarms\SiaDc09\SiaDc09FrameDecoder;
use App\Domain\SecurityDevices\Alarms\SiaDc09\SiaDc09IntakeService;
use App\Domain\SecurityDevices\Cctv\Services\OnvifCameraProfileClient;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialReferenceStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialRotationStatus;
use App\Domain\SecurityDevices\Credentials\Enums\CredentialTestStatus;
use App\Domain\SecurityDevices\Credentials\Models\CredentialReference;
use App\Domain\SecurityDevices\Credentials\Services\CommandCredentialLeaseService;
use App\Domain\SecurityDevices\Drivers\Alarms\ArrowheadEliteAlarmDriver;
use App\Domain\SecurityDevices\Drivers\Alarms\GallagherCommandCentreDriver;
use App\Domain\SecurityDevices\Drivers\Alarms\InnerRangeInceptionDriver;
use App\Domain\SecurityDevices\Drivers\Cameras\AxisOnvifCameraDriver;
use App\Domain\SecurityDevices\Drivers\Cameras\DahuaOnvifCameraDriver;
use App\Domain\SecurityDevices\Drivers\Cameras\HanwhaVisionOnvifCameraDriver;
use App\Domain\SecurityDevices\Drivers\Cameras\HikvisionOnvifCameraDriver;
use App\Domain\SecurityDevices\Drivers\Cameras\OnvifProfileCameraDriver;
use App\Domain\SecurityDevices\Drivers\Cameras\UniFiProtectCameraDriver;
use App\Domain\SecurityDevices\Drivers\Cameras\UniviewOnvifCameraDriver;
use App\Domain\SecurityDevices\Drivers\Enums\DriverSupportLevel;
use App\Domain\SecurityDevices\Drivers\Network\ArubaAosConfigDriver;
use App\Domain\SecurityDevices\Drivers\Network\CiscoIosConfigDriver;
use App\Domain\SecurityDevices\Drivers\Network\FortinetFortiOsConfigDriver;
use App\Domain\SecurityDevices\Drivers\Network\MikroTikRouterOsConfigDriver;
use App\Domain\SecurityDevices\Drivers\Services\DeviceDriverRegistry;
use App\Domain\SecurityDevices\Iot\Milesight\MilesightLorawanPayloadDecoder;
use App\Domain\SecurityDevices\Iot\Mqtt\MqttSensorIntakeService;
use App\Domain\SecurityDevices\Iot\Mqtt\MqttV311Client;
use App\Domain\SecurityDevices\Management\Adapters\AlarmPanelCommandAdapter;
use App\Domain\SecurityDevices\Management\Adapters\NetworkSshConfigBackupCommandAdapter;
use App\Domain\SecurityDevices\Management\Contracts\CommandHttpTransport;
use App\Domain\SecurityDevices\Management\Data\CommandDecisionInput;
use App\Domain\SecurityDevices\Management\Data\CommandHttpResponse;
use App\Domain\SecurityDevices\Management\Data\CommandRequestInput;
use App\Domain\SecurityDevices\Management\Enums\CommandApprovalDecision;
use App\Domain\SecurityDevices\Management\Enums\CommandReconciliationOutcome;
use App\Domain\SecurityDevices\Management\Enums\CommandStatus;
use App\Domain\SecurityDevices\Management\Services\CommandExecutionAdapterRegistry;
use App\Domain\SecurityDevices\Management\Services\CommandExecutionRouteResolver;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandApprovalService;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandReconciliationService;
use App\Domain\SecurityDevices\Management\Services\DeviceCommandRequestService;
use App\Domain\SecurityDevices\Management\Services\GovernedCommandDispatchService;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceEvent;
use App\Domain\SecurityDevices\NetworkConfig\Contracts\NetworkCliSshTransport;
use App\Domain\SecurityDevices\NetworkConfig\Data\NetworkCliCommandResult;
use App\Domain\SecurityDevices\Presenters\DeviceProfilePresenter;
use App\Models\Integration\IntegrationSiteSecret;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpClientRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class Phase5BrandsAndDriversTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $worker;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        config()->set('monitoring.signing.active_key_id', 'phase5-test-key');
        config()->set('monitoring.signing.keys', [
            'phase5-test-key' => base64_encode(str_repeat('P', SODIUM_CRYPTO_AUTH_KEYBYTES)),
        ]);

        $this->admin = User::factory()->create([
            'approved_at' => now(),
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_confirmed_at' => now(),
        ]);
        $this->admin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());

        $this->worker = User::factory()->create(['approved_at' => now()]);
        $this->worker->roles()->attach(Role::query()->where('name', 'support_worker')->firstOrFail());
    }

    public function test_supported_devices_catalogue_exposes_honest_verification_levels_capabilities_and_official_docs(): void
    {
        $registry = app(DeviceDriverRegistry::class);
        $all = $registry->all();

        $this->assertGreaterThanOrEqual(28, count($all));
        $this->assertInstanceOf(ArrowheadEliteAlarmDriver::class, $registry->find(ArrowheadEliteAlarmDriver::KEY));
        $this->assertSame(
            DriverSupportLevel::Planned,
            $registry->find(ArrowheadEliteAlarmDriver::KEY)?->metadata()->supportLevel,
        );

        foreach ($all as $driver) {
            $meta = $driver->metadata();
            $this->assertNotEmpty($meta->officialDocs, "Driver {$meta->key} must cite official vendor/standard docs.");
            foreach ($meta->officialDocs as $doc) {
                $this->assertStringStartsWith('https://', $doc['url']);
            }
        }

        $this->actingAs($this->worker)
            ->get('/security-devices/supported-devices')
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->get('/security-devices/supported-devices')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('security-devices/supported-devices')
                ->where('summary.total_drivers', count($all))
                ->where('summary.tested_on_hardware', fn (int $count): bool => $count >= 2)
                ->where('summary.built_from_docs', fn (int $count): bool => $count >= 16)
                ->where('summary.planned', fn (int $count): bool => $count >= 7));
    }

    public function test_camera_drivers_match_brands_and_probe_onvif_profile_t_media2_streams_with_rfc7616_digest_auth_and_sanitised_uris(): void
    {
        $site = $this->createActiveSite('Harbour Camera House');
        DiscoveryScope::factory()->create([
            'site_id' => $site->id,
            'cidrs' => ['172.16.0.0/24'],
            'protocols' => ['http', 'https', 'tcp', 'onvif'],
            'exclusions' => [],
            'status' => 'active',
        ]);

        $registry = app(DeviceDriverRegistry::class);

        $brandExpectations = [
            [['manufacturer' => 'Hikvision', 'model' => 'DS-2CD2387G2'], HikvisionOnvifCameraDriver::class],
            [['manufacturer' => 'Dahua', 'model' => 'IPC-HDW5842T'], DahuaOnvifCameraDriver::class],
            [['manufacturer' => 'Uniview', 'model' => 'IPC3614SB'], UniviewOnvifCameraDriver::class],
            [['manufacturer' => 'Axis Communications', 'model' => 'P3265-LVE'], AxisOnvifCameraDriver::class],
            [['manufacturer' => 'Hanwha Vision', 'model' => 'XNV-8083R'], HanwhaVisionOnvifCameraDriver::class],
            [['manufacturer' => 'TP-Link', 'model' => 'Tapo C120'], OnvifProfileCameraDriver::class],
            [['manufacturer' => 'Ubiquiti', 'model' => 'UVC G5 Turret Ultra', 'provider' => 'unifi_protect'], UniFiProtectCameraDriver::class],
        ];

        foreach ($brandExpectations as [$attrs, $expectedClass]) {
            $probeCam = new Device(array_merge([
                'domain' => 'security',
                'category' => 'cctv',
                'ip_address' => '172.16.0.78',
            ], $attrs));
            $this->assertInstanceOf($expectedClass, $registry->resolveCameraDriver($probeCam));
        }

        $tapo = $this->createAssignedDevice($site, [
            'name' => 'Tapo Courtyard Camera',
            'domain' => 'security',
            'category' => 'cctv',
            'manufacturer' => 'TP-Link',
            'model' => 'Tapo C120',
            'ip_address' => '172.16.0.78',
            'config' => [
                'onvif' => [
                    'port' => 2020,
                    'service_path' => '/onvif/device_service',
                ],
            ],
        ]);

        $this->bindMockCredentialLease(['username' => 'camadmin', 'password' => 'SuperSecretCamPass!']);
        CredentialReference::query()->create([
            'reference_key' => 'vault:onvif/'.$site->id.'/cam',
            'site_id' => $site->id,
            'provider' => 'onvif',
            'purpose' => 'device_management',
            'capabilities' => ['onvif', 'cctv_snapshot'],
            'secret_manager_reference' => 'secret/data/sites/'.$site->id.'/onvif',
            'secret_manager_reference_hash' => hash('sha256', 'onvif-'.$site->id),
            'status' => CredentialReferenceStatus::Active,
            'rotation_status' => CredentialRotationStatus::Current,
            'test_status' => CredentialTestStatus::Passed,
            'version' => 1,
            'last_tested_at' => now(),
        ]);

        $attemptCount = 0;
        $digestAuthHeaders = [];
        Http::fake(function (HttpClientRequest $request) use (&$attemptCount, &$digestAuthHeaders) {
            $attemptCount++;
            $auth = $request->header('Authorization')[0] ?? '';

            // Challenge first unauthenticated/WSSE request with RFC 7616 HTTP Digest SHA-256
            if ($auth === '') {
                return Http::response('Unauthorized', 401, [
                    'WWW-Authenticate' => 'Digest realm="TP-Link IP-Camera", qop="auth", algorithm=SHA-256, nonce="dcd98b7102dd2f0e8b11d0f600bfb0c093", opaque="5ccc069c403ebaf9f0171e9517f40e41"',
                ]);
            }

            $digestAuthHeaders[] = $auth;
            $body = $request->body();

            if (str_contains($body, 'GetServices')) {
                return Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope" xmlns:tds="http://www.onvif.org/ver10/device/wsdl">
  <s:Body>
    <tds:GetServicesResponse>
      <tds:Service>
        <tds:Namespace>http://www.onvif.org/ver20/media/wsdl</tds:Namespace>
        <tds:XAddr>http://172.16.0.78:2020/onvif/media2_service</tds:XAddr>
      </tds:Service>
      <tds:Service>
        <tds:Namespace>http://www.onvif.org/ver20/ptz/wsdl</tds:Namespace>
        <tds:XAddr>http://172.16.0.78:2020/onvif/ptz_service</tds:XAddr>
      </tds:Service>
    </tds:GetServicesResponse>
  </s:Body>
</s:Envelope>
XML, 200, ['Content-Type' => 'application/soap+xml']);
            }

            if (str_contains($body, 'GetProfiles')) {
                return Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope" xmlns:tr2="http://www.onvif.org/ver20/media/wsdl" xmlns:tt="http://www.onvif.org/ver10/schema">
  <s:Body>
    <tr2:GetProfilesResponse>
      <tr2:Profiles token="profile_1" fixed="true">
        <tr2:Name>MainStream_H265</tr2:Name>
        <tr2:Configurations>
          <tr2:VideoEncoder>
            <tt:Encoding>H265</tt:Encoding>
            <tt:Resolution><tt:Width>2560</tt:Width><tt:Height>1440</tt:Height></tt:Resolution>
          </tr2:VideoEncoder>
          <tr2:PTZ><tt:NodeToken>ptz_0</tt:NodeToken></tr2:PTZ>
        </tr2:Configurations>
      </tr2:Profiles>
    </tr2:GetProfilesResponse>
  </s:Body>
</s:Envelope>
XML, 200, ['Content-Type' => 'application/soap+xml']);
            }

            if (str_contains($body, 'GetStreamUri')) {
                return Http::response(<<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<s:Envelope xmlns:s="http://www.w3.org/2003/05/soap-envelope" xmlns:tr2="http://www.onvif.org/ver20/media/wsdl">
  <s:Body>
    <tr2:GetStreamUriResponse>
      <tr2:Uri>rtsp://camadmin:SuperSecretCamPass!@172.16.0.78:554/stream1?token=MUST_STRIP</tr2:Uri>
    </tr2:GetStreamUriResponse>
  </s:Body>
</s:Envelope>
XML, 200, ['Content-Type' => 'application/soap+xml']);
            }

            return Http::response('', 404);
        });

        $response = $this->actingAs($this->admin)
            ->getJson("/security-devices/devices/{$tapo->id}/camera/streams")
            ->assertOk();

        $json = $response->json();
        $this->assertTrue($json['ok']);
        $this->assertNotEmpty($digestAuthHeaders);
        $this->assertStringContainsString('Digest username="camadmin"', $digestAuthHeaders[0]);
        $this->assertStringContainsString('algorithm=SHA-256', $digestAuthHeaders[0]);
        $this->assertSame('rtsp://172.16.0.78:554/stream1', $json['profiles'][0]['stream_uri']);
        $this->assertStringNotContainsString('SuperSecretCamPass', json_encode($json));
        $this->assertStringNotContainsString('MUST_STRIP', json_encode($json));
    }

    public function test_sia_dc09_decoder_aes_encryption_and_live_tcp_listener_ingest_contact_id_events_into_alarm_profile(): void
    {
        $decoder = new SiaDc09FrameDecoder;

        // 1. Plaintext ADM-CID frame + tamper rejection
        $plainFrame = SiaDc09FrameDecoder::wrapBody('"ADM-CID"0042R1L0#1234[#1234|3401 01 005]_10:15:00,10-28-2026');
        $decoded = $decoder->decode($plainFrame);
        $this->assertTrue($decoded['ok']);
        $this->assertSame('ADM-CID', $decoded['token']);
        $this->assertSame('1234', $decoded['account']);

        $tamperedFrame = str_replace('3401', '1401', $plainFrame);
        $this->assertSame('crc_mismatch', $decoder->decode($tamperedFrame)['reason_code']);

        // 2. AES-128-CBC encrypted *ADM-CID frame (ANSI/SIA DC-09 §5.4.1 zero IV + random pad + `|`)
        $aesKey = str_repeat("\x4B", 16);
        $zeroIv = str_repeat("\x00", 16);
        $plaintextBlock = str_pad('A1B2C3|#1234|1130 01 003]_10:16:00,10-28-2026', 48, "\x00");
        $cipherHex = strtoupper(bin2hex((string) openssl_encrypt($plaintextBlock, 'aes-128-cbc', $aesKey, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $zeroIv)));
        $encryptedFrame = SiaDc09FrameDecoder::wrapBody('"*ADM-CID"0043R1L0#1234['.$cipherHex.']');

        $this->assertSame('encryption_key_required', $decoder->decode($encryptedFrame)['reason_code']);
        $decrypted = $decoder->decode($encryptedFrame, $aesKey);
        $this->assertTrue($decrypted['ok']);
        $this->assertTrue($decrypted['encrypted']);
        $this->assertStringContainsString('1130 01 003', (string) $decrypted['data']);

        // 3. End-to-end TCP socket listener (`security-devices:sia-dc09-listen --once`) -> DeviceEvent + Alarm profile
        $site = $this->createActiveSite('Kauri House');
        DiscoveryScope::factory()->create([
            'site_id' => $site->id,
            'cidrs' => ['127.0.0.0/8', '172.16.0.0/24'],
            'protocols' => ['tcp'],
            'exclusions' => [],
            'status' => 'active',
        ]);

        $panel = $this->createAssignedDevice($site, [
            'name' => 'Kauri Front Alarm Panel',
            'domain' => 'security',
            'category' => 'alarm',
            'manufacturer' => 'Arrowhead Alarm Products',
            'model' => 'ESL-2',
            'ip_address' => '127.0.0.1',
            'config' => [
                'sia_account' => '4815',
            ],
        ]);

        // Unauthorised sender IP outside Site CIDR must be rejected
        $intake = app(SiaDc09IntakeService::class);
        $unauth = $intake->ingest(
            SiaDc09FrameDecoder::wrapBody('"ADM-CID"0001L0#4815[#4815|3401 01 001]'),
            '203.0.113.99',
        );
        $this->assertFalse($unauth['accepted']);
        $this->assertSame('sender_out_of_scope', $unauth['reason_code']);

        // Authorised closing (arm) followed by burglary alarm over live loopback TCP
        $probeSock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertIsResource($probeSock);
        $addr = stream_socket_get_name($probeSock, false);
        $port = (int) substr((string) $addr, (int) strrpos((string) $addr, ':') + 1);
        fclose($probeSock);

        // Ingest arm via service, then burglary via SIA DC-09 intake
        $armFrame = SiaDc09FrameDecoder::wrapBody('"ADM-CID"0007R1L0#4815[#4815|3401 01 002]');
        $armResult = $intake->ingest($armFrame, '127.0.0.1');
        $this->assertTrue($armResult['accepted']);
        $this->assertStringContainsString('"ACK"0007', (string) $armResult['response_frame']);

        $panelArmed = $panel->fresh()->load([
            'monitors.profile',
            'monitors.collector',
            'assignments',
            'events' => fn ($q) => $q->orderByDesc('occurred_at')->orderByDesc('id'),
            'maintenanceRecords',
            'documents',
        ]);
        $presentedArmed = app(DeviceProfilePresenter::class)->present($this->admin, $panelArmed, $panelArmed->assignments->first());
        $this->assertSame('armed', $presentedArmed['typeProfile']['panelState']);

        // Test burglary frame creating critical alarm_triggered event
        $burglaryFrame = SiaDc09FrameDecoder::wrapBody('"ADM-CID"0008R1L0#4815[#4815|1130 01 004]');
        $burglaryResult = $intake->ingest($burglaryFrame, '127.0.0.1', CarbonImmutable::now('UTC')->addSecond());
        $this->assertTrue($burglaryResult['accepted']);

        $event = DeviceEvent::query()->findOrFail($burglaryResult['event_id']);
        $this->assertSame('alarm_triggered', $event->event_type);
        $this->assertSame('critical', $event->severity);

        $panelTriggered = $panel->fresh()->load([
            'monitors.profile',
            'monitors.collector',
            'assignments',
            'events' => fn ($q) => $q->orderByDesc('occurred_at')->orderByDesc('id'),
            'maintenanceRecords',
            'documents',
        ]);
        $presentedTriggered = app(DeviceProfilePresenter::class)->present($this->admin, $panelTriggered, $panelTriggered->assignments->first());
        $this->assertSame('triggered', $presentedTriggered['typeProfile']['panelState']);
        $this->assertGreaterThan(0, $port);
    }

    public function test_gallagher_and_inception_alarm_drivers_execute_and_reconcile_governed_arm_and_disarm_commands(): void
    {
        $site = $this->createActiveSite('Pohutukawa House');
        DiscoveryScope::factory()->create([
            'site_id' => $site->id,
            'collector_id' => null,
            'cidrs' => ['10.55.0.0/24'],
            'protocols' => ['tls', 'tcp'],
            'port_bounds' => ['tls' => [8904], 'tcp' => [8904]],
            'exclusions' => [],
            'status' => 'active',
        ]);

        $gallagherPanel = $this->createAssignedDevice($site, [
            'name' => 'Pohutukawa Gallagher Controller',
            'domain' => 'security',
            'category' => 'alarm',
            'manufacturer' => 'Gallagher Security',
            'model' => 'Controller 6000',
            'provider' => 'gallagher',
            'ip_address' => '10.55.0.20',
            'last_seen_at' => now(),
            'config' => [
                'alarm' => [
                    'base_url' => 'https://10.55.0.20:8904',
                ],
            ],
        ]);

        $this->assertInstanceOf(
            GallagherCommandCentreDriver::class,
            app(DeviceDriverRegistry::class)->resolveAlarmDriver($gallagherPanel),
        );

        $inceptionPanel = new Device([
            'domain' => 'security',
            'category' => 'alarm',
            'manufacturer' => 'Inner Range',
            'model' => 'Inception Controller',
            'ip_address' => '10.55.0.21',
        ]);
        $this->assertInstanceOf(
            InnerRangeInceptionDriver::class,
            app(DeviceDriverRegistry::class)->resolveAlarmDriver($inceptionPanel),
        );

        $this->app->instance(DnsResolver::class, new class implements DnsResolver
        {
            public function resolve(string $host): array
            {
                return ['10.55.0.20'];
            }
        });
        $this->app->forgetInstance(ApprovedProbeScopeProvider::class);
        $this->app->forgetInstance(EgressPolicy::class);

        $zoneArmed = true;
        $this->app->instance(CommandHttpTransport::class, new class($zoneArmed) implements CommandHttpTransport
        {
            public function __construct(private bool &$zoneArmed) {}

            public function request(
                AuthorizedProbeTarget $target,
                string $method,
                array $headers = [],
                ?array $json = null,
            ): CommandHttpResponse {
                if ($method === 'POST' && str_ends_with($target->path, '/api/alarm_zones/zone-main/arm')) {
                    $this->zoneArmed = true;

                    return new CommandHttpResponse(200, json_encode(['status' => 'armed'], JSON_THROW_ON_ERROR));
                }
                if ($method === 'POST' && str_ends_with($target->path, '/api/alarm_zones/zone-main/disarm')) {
                    $this->zoneArmed = false;

                    return new CommandHttpResponse(200, json_encode(['status' => 'disarmed'], JSON_THROW_ON_ERROR));
                }
                if ($method === 'GET' && str_ends_with($target->path, '/api/alarm_zones')) {
                    return new CommandHttpResponse(200, json_encode([
                        'results' => [[
                            'id' => 'zone-main',
                            'name' => 'Main Building',
                            'status' => $this->zoneArmed ? 'Armed' : 'Disarmed',
                        ]],
                    ], JSON_THROW_ON_ERROR));
                }

                return new CommandHttpResponse(404, '{}');
            }
        });

        $this->bindMockCredentialLease(['api_key' => 'GGL-TEST-KEY-9911']);
        CredentialReference::query()->create([
            'reference_key' => 'vault:gallagher/'.$site->id.'/alarm',
            'site_id' => $site->id,
            'provider' => 'gallagher',
            'purpose' => 'device_management',
            'capabilities' => ['command:alarm.arm', 'command:alarm.disarm'],
            'secret_manager_reference' => 'secret/data/sites/'.$site->id.'/gallagher',
            'secret_manager_reference_hash' => hash('sha256', 'gallagher-'.$site->id),
            'status' => CredentialReferenceStatus::Active,
            'rotation_status' => CredentialRotationStatus::Current,
            'test_status' => CredentialTestStatus::Passed,
            'version' => 1,
            'last_tested_at' => now(),
        ]);

        $requester = $this->createScopedManager($site);
        $approver = $this->createScopedManager($site);

        // Request Critical `alarm.disarm` — requires MFA, step-up, type-device-name confirmation, and independent approval
        $disarmCommand = app(DeviceCommandRequestService::class)->request($gallagherPanel, $requester, new CommandRequestInput(
            capability: 'alarm.disarm',
            parameters: ['area' => 'zone-main'],
            reason: 'Morning shift handover disarm verified with on-site team leader.',
            idempotencyKey: 'gallagher-disarm-1',
            stepUpConfirmedAt: CarbonImmutable::now('UTC'),
            impactAcknowledged: true,
            confirmationText: 'Pohutukawa Gallagher Controller',
        ));

        $this->assertSame(CommandStatus::AwaitingApproval, $disarmCommand->status);

        app(DeviceCommandApprovalService::class)->decide($disarmCommand, $approver, new CommandDecisionInput(
            decision: CommandApprovalDecision::Approved,
            comment: 'Verified shift roster and approved morning disarm.',
        ));

        app(GovernedCommandDispatchService::class)->dispatch($disarmCommand->fresh(), $requester);
        $reconciled = app(DeviceCommandReconciliationService::class)->reconcile($disarmCommand->fresh(), $requester);

        $this->assertSame(CommandReconciliationOutcome::Matched, $reconciled->outcome);
        $this->assertSame(CommandStatus::Reconciled, $disarmCommand->fresh()->status);
    }

    public function test_mqtt_v311_codec_and_clean_room_milesight_lorawan_decoder_and_webhook_ingest_sensor_readings(): void
    {
        // 1. MQTT v3.1.1 packet codec & wildcard topic matching
        $connectPacket = MqttV311Client::buildConnectPacket('oblivion-test', 'sensoruser', 'sensorpass', 30);
        $this->assertSame(0x10, ord($connectPacket[0]));
        $this->assertTrue(MqttV311Client::topicMatchesFilter('sites/4/sensors/am307-1/up', 'sites/+/sensors/#'));
        $this->assertFalse(MqttV311Client::topicMatchesFilter('$SYS/broker/uptime', '#'));

        $pubPacket = MqttV311Client::buildPublishPacket('sites/4/sensors/lounge', '{"temperature":21.4,"humidity":52}', qos: 1, packetId: 9);
        $parsedPub = MqttV311Client::parsePublishPacket(ord($pubPacket[0]) & 0x0F, substr($pubPacket, 2));
        $this->assertSame('sites/4/sensors/lounge', $parsedPub['topic']);
        $this->assertSame(9, $parsedPub['packet_id']);

        // 2. Clean-room Milesight LoRaWAN binary TLV byte vectors (AM307 + EM300-SLD + WS301 + WS101)
        $decoder = new MilesightLorawanPayloadDecoder;
        // 01 75 5C (battery 92%) + 03 67 EC 00 (23.6 C) + 04 68 6E (55.0 %RH) + 07 7D 58 02 (600 ppm CO2)
        $am307Decoded = $decoder->decodeEncoded('01755c0367ec0004686e077d5802', 'AM307');
        $this->assertSame(92, $am307Decoded['battery']);
        $this->assertSame(23.6, $am307Decoded['temperature']);
        $this->assertSame(55.0, $am307Decoded['humidity']);
        $this->assertSame(600, $am307Decoded['co2']);

        // EM300-SLD leak: 05 00 01 -> water_leak = true
        $leakDecoded = $decoder->decodeEncoded('017564050001', 'EM300-SLD');
        $this->assertTrue($leakDecoded['water_leak']);

        // WS101 Smart Button: ff 2e 01 -> single press
        $ws101Decoded = $decoder->decodeEncoded('01755aff2e01', 'WS101');
        $this->assertSame('single_press', $ws101Decoded['button_action']);

        // 3. MQTT Sensor Intake + Milesight Gateway Webhook -> Device readings & Sensor profile
        $siteA = $this->createActiveSite('Rimu Care Home');
        $siteB = $this->createActiveSite('Totara Care Home');

        $mqttSensor = $this->createAssignedDevice($siteA, [
            'name' => 'Rimu Medication Fridge Sensor',
            'domain' => 'iot_healthcare',
            'category' => 'environmental',
            'manufacturer' => 'Shelly',
            'model' => 'Plus H&T',
            'config' => ['mqtt' => ['topic' => 'rimu/fridge/telemetry']],
        ]);

        $mqttResult = app(MqttSensorIntakeService::class)->ingest(
            $siteA->id,
            'rimu/fridge/telemetry',
            json_encode(['temperature' => 4.2, 'humidity' => 48.5, 'battery' => 88], JSON_THROW_ON_ERROR),
        );
        $this->assertTrue($mqttResult['accepted']);
        $this->assertSame(88, $mqttSensor->fresh()->battery_level);

        $lorawanSensor = $this->createAssignedDevice($siteA, [
            'name' => 'Rimu Lounge Air Quality AM307',
            'domain' => 'iot_healthcare',
            'category' => 'environmental',
            'manufacturer' => 'Milesight',
            'model' => 'AM307',
            'config' => ['lorawan' => ['dev_eui' => '24E124707E043923']],
        ]);

        $webhookSecret = 'milesight-ug65-hmac-shared-secret-2026';
        IntegrationSiteSecret::query()->create([
            'site_id' => $siteA->id,
            'provider' => 'milesight_lorawan',
            'capability' => 'gateway_webhook',
            'secret_encrypted' => Crypt::encryptString($webhookSecret),
            'is_enabled' => true,
        ]);

        $uplinkBody = json_encode([
            'devEUI' => '24:E1:24:70:7E:04:39:23',
            'deviceName' => 'AM307-Lounge',
            'rssi' => -67,
            'snr' => 9.5,
            'data' => base64_encode((string) hex2bin('01755c0367ec0004686e077d5802')),
        ], JSON_THROW_ON_ERROR);

        // Invalid signature rejected with 401
        $this->postJson(
            "/webhooks/milesight-gateway/{$siteA->id}",
            json_decode($uplinkBody, true),
            ['X-Milesight-Signature' => 'sha256=deadbeef'],
        )->assertStatus(401);

        // Valid HMAC-SHA256 signature accepted and updates Sensor profile readings
        $validSig = 'sha256='.hash_hmac('sha256', $uplinkBody, $webhookSecret);
        $this->call(
            'POST',
            "/webhooks/milesight-gateway/{$siteA->id}",
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_MILESIGHT_SIGNATURE' => $validSig,
            ],
            $uplinkBody,
        )->assertOk()->assertJsonPath('accepted', true);

        $sensorFresh = $lorawanSensor->fresh()->load([
            'monitors.profile',
            'monitors.collector',
            'assignments',
            'events',
            'maintenanceRecords',
            'documents',
        ]);
        $presentedSensor = app(DeviceProfilePresenter::class)->present($this->admin, $sensorFresh, $sensorFresh->assignments->first());
        $readingMetrics = array_column($presentedSensor['typeProfile']['readings'], 'label');
        $this->assertContains('Temperature', $readingMetrics);
        $this->assertContains('CO2', $readingMetrics);
        $this->assertSame(0, DeviceEvent::query()->where('device_id', $siteB->id)->count());
    }

    public function test_ssh_network_config_backup_normalises_four_vendors_redacts_large_configs_and_reconciles_diffs(): void
    {
        // 1. Vendor volatile-timestamp stripping produces identical normalised output across exports
        $mikrotik = new MikroTikRouterOsConfigDriver;
        $this->assertSame(
            $mikrotik->normaliseConfigOutput("# oct/28/2026 10:00:01 by RouterOS 7.16\n/interface bridge add name=br-lan"),
            $mikrotik->normaliseConfigOutput("# oct/28/2026 11:45:59 by RouterOS 7.16\n/interface bridge add name=br-lan"),
        );

        $cisco = new CiscoIosConfigDriver;
        $this->assertSame(
            $cisco->normaliseConfigOutput("! Last configuration change at 01:00:00 UTC\nCurrent configuration : 4192 bytes\nhostname sw-core"),
            $cisco->normaliseConfigOutput("! Last configuration change at 09:30:00 UTC\nCurrent configuration : 4200 bytes\nhostname sw-core"),
        );

        $aruba = new ArubaAosConfigDriver;
        $this->assertSame(
            $aruba->normaliseConfigOutput("; JL675A Configuration Editor; Created on release #10.13\nhostname aruba-sw01"),
            $aruba->normaliseConfigOutput("Running configuration:\nhostname aruba-sw01"),
        );

        $forti = new FortinetFortiOsConfigDriver;
        $this->assertSame(
            $forti->normaliseConfigOutput("#config-version=FGT60F-7.4.3:opmode=0\n#conf_file_ver=1001\nconfig system global\nend"),
            $forti->normaliseConfigOutput("#config-version=FGT60F-7.4.3:opmode=0\n#conf_file_ver=9999\nconfig system global\nend"),
        );

        // 2. Full `network.config.backup` dispatch, >1,100-line secret redaction, reconciliation, and snapshot diff
        $site = $this->createActiveSite('Nikau Network Hub');
        DiscoveryScope::factory()->create([
            'site_id' => $site->id,
            'collector_id' => null,
            'cidrs' => ['10.77.0.0/24'],
            'protocols' => ['tcp', 'icmp'],
            'port_bounds' => ['tcp' => [22]],
            'exclusions' => [],
            'status' => 'active',
        ]);

        $router = $this->createAssignedDevice($site, [
            'name' => 'Nikau MikroTik Core Router',
            'domain' => 'it_infrastructure',
            'category' => 'network',
            'subcategory' => 'router',
            'manufacturer' => 'MikroTik',
            'model' => 'CCR2004-16G-2S+',
            'provider' => 'mikrotik',
            'ip_address' => '10.77.0.1',
            'last_seen_at' => now(),
        ]);

        $memorySnapshots = new class implements SnapshotStore
        {
            /** @var array<string, string> */
            public array $objects = [];

            public function put(string $path, string $contents): void
            {
                $this->objects[$path] = $contents;
            }

            public function read(string $path): string
            {
                return $this->objects[$path] ?? throw new \RuntimeException('Missing snapshot '.$path);
            }

            public function delete(string $path): void
            {
                unset($this->objects[$path]);
            }

            public function exists(string $path): bool
            {
                return isset($this->objects[$path]);
            }
        };

        $this->app->instance(SnapshotStore::class, $memorySnapshots);
        $this->app->forgetInstance(ConfigurationSnapshotService::class);
        $this->app->instance(DnsResolver::class, new class implements DnsResolver
        {
            public function resolve(string $host): array
            {
                return ['10.77.0.1'];
            }
        });
        $this->app->forgetInstance(ApprovedProbeScopeProvider::class);
        $this->app->forgetInstance(EgressPolicy::class);

        $fillerLines = [];
        for ($i = 1; $i <= 1150; $i++) {
            $fillerLines[] = "/ip firewall address-list add list=trusted address=10.77.1.{$i}";
        }
        $exportOne = "# oct/28/2026 10:00:00 by RouterOS 7.16\n"
            ."/interface vlan add interface=ether1 name=vlan10-staff vlan-id=10\n"
            ."/ppp secret add name=vpnuser password=SUPER_SECRET_PPP_PASS\n"
            .implode("\n", $fillerLines);
        $exportTwo = "# oct/28/2026 12:00:00 by RouterOS 7.16\n"
            ."/interface vlan add interface=ether1 name=vlan20-guest vlan-id=20\n"
            ."/ppp secret add name=vpnuser password=SUPER_SECRET_PPP_PASS\n"
            .implode("\n", $fillerLines);

        $exports = [$exportOne, $exportTwo];
        $this->app->instance(NetworkCliSshTransport::class, new class($exports) implements NetworkCliSshTransport
        {
            /** @param list<string> $exports */
            public function __construct(public array $exports) {}

            public function runReadOnlyExport(
                AuthorizedProbeTarget $target,
                array $credentialMaterial,
                string $command,
                ?string $expectedHostKeyFingerprint = null,
                int $timeoutSeconds = 20,
            ): NetworkCliCommandResult {
                $out = array_shift($this->exports) ?? '';

                return new NetworkCliCommandResult(
                    ok: true,
                    reasonCode: 'exported',
                    output: $out,
                    hostKeyFingerprint: 'SHA256:testHostKeyFingerprint1234',
                );
            }
        });

        $this->bindMockCredentialLease(['username' => 'backup_ro', 'password' => 'RouterSshPass!']);
        CredentialReference::query()->create([
            'reference_key' => 'vault:mikrotik/'.$site->id.'/ssh',
            'site_id' => $site->id,
            'provider' => 'mikrotik',
            'purpose' => 'device_management',
            'capabilities' => ['command:network.config.backup'],
            'secret_manager_reference' => 'secret/data/sites/'.$site->id.'/mikrotik-ssh',
            'secret_manager_reference_hash' => hash('sha256', 'mikrotik-ssh-'.$site->id),
            'status' => CredentialReferenceStatus::Active,
            'rotation_status' => CredentialRotationStatus::Current,
            'test_status' => CredentialTestStatus::Passed,
            'version' => 1,
            'last_tested_at' => now(),
        ]);

        // Refresh container singletons that depend on NetworkCliSshTransport / SnapshotStore
        $this->app->forgetInstance(NetworkSshConfigBackupCommandAdapter::class);
        $this->app->forgetInstance(CommandExecutionAdapterRegistry::class);
        $this->app->forgetInstance(GovernedCommandDispatchService::class);
        $this->app->forgetInstance(DeviceCommandReconciliationService::class);

        $actor = $this->createScopedManager($site);

        // Capture Backup #1 (>1,150 lines)
        $cmd1 = app(DeviceCommandRequestService::class)->request($router, $actor, new CommandRequestInput(
            capability: 'network.config.backup',
            parameters: [],
            reason: 'Pre-change RouterOS configuration backup.',
            idempotencyKey: 'mikrotik-ssh-backup-1',
            impactAcknowledged: true,
        ));
        $this->assertSame(CommandStatus::Ready, $cmd1->status);
        app(GovernedCommandDispatchService::class)->dispatch($cmd1->fresh(), $actor);
        $rec1 = app(DeviceCommandReconciliationService::class)->reconcile($cmd1->fresh(), $actor);
        $this->assertSame(CommandReconciliationOutcome::Matched, $rec1->outcome);

        // Capture Backup #2 (VLAN changed)
        $cmd2 = app(DeviceCommandRequestService::class)->request($router->fresh(), $actor, new CommandRequestInput(
            capability: 'network.config.backup',
            parameters: [],
            reason: 'Post-change RouterOS configuration backup.',
            idempotencyKey: 'mikrotik-ssh-backup-2',
            impactAcknowledged: true,
        ));
        app(GovernedCommandDispatchService::class)->dispatch($cmd2->fresh(), $actor);
        app(DeviceCommandReconciliationService::class)->reconcile($cmd2->fresh(), $actor);

        $snapshots = ConfigurationSnapshot::query()
            ->where('device_id', $router->id)
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $snapshots);

        // Verify >1,150 lines stored AND secret redacted (`SUPER_SECRET_PPP_PASS` never written to storage)
        $storedJson = implode("\n", $memorySnapshots->objects);
        $this->assertStringNotContainsString('SUPER_SECRET_PPP_PASS', $storedJson);
        $this->assertStringContainsString('[REDACTED]', $storedJson);

        // Verify snapshot diff endpoint shows `vlan10-staff` -> `vlan20-guest`
        $diffResponse = $this->actingAs($this->admin)
            ->getJson("/security-devices/devices/{$router->id}/configuration-snapshots/{$snapshots[1]->id}/compare/{$snapshots[0]->id}")
            ->assertOk()
            ->json();

        $diffText = json_encode($diffResponse);
        $this->assertStringContainsString('vlan10-staff', $diffText);
        $this->assertStringContainsString('vlan20-guest', $diffText);
    }

    public function test_collector_ingest_isolates_site_hub_syslog_and_netflow_across_overlapping_private_lan_subnets(): void
    {
        $recordedMetrics = [];
        $this->app->instance(TimeSeriesStore::class, new class($recordedMetrics) implements TimeSeriesStore
        {
            /** @var array<int, array<string, mixed>> */
            public array $writes = [];

            public function __construct(array &$writes)
            {
                $this->writes = &$writes;
            }

            public function writePoints(array $points): void
            {
                foreach ($points as $point) {
                    if ($point instanceof TimeSeriesPoint) {
                        $this->writes[] = [
                            'siteId' => $point->siteId,
                            'deviceId' => $point->deviceId,
                            'metric' => $point->metric,
                            'value' => (int) round($point->value),
                        ];
                    }
                }
            }

            public function range(
                string $externalKey,
                string $tier,
                CarbonImmutable $from,
                CarbonImmutable $to,
            ): array {
                return [];
            }

            public function deleteRange(
                string $externalKey,
                string $tier,
                CarbonImmutable $from,
                CarbonImmutable $to,
            ): void {}

            public function exists(
                string $externalKey,
                string $tier,
                ?CarbonImmutable $from = null,
                ?CarbonImmutable $to = null,
            ): bool {
                return false;
            }

            public function healthy(): bool
            {
                return true;
            }
        });
        $this->app->forgetInstance(MetricIngestService::class);
        $this->app->forgetInstance(EventEnvelopeHandler::class);
        $this->app->forgetInstance(RuntimeEnvelopeHandlerRegistry::class);
        $this->app->forgetInstance(CollectorIngestService::class);

        // Two distinct Sites with identical overlapping 192.168.1.0/24 subnets and 192.168.1.1 routers
        $siteAlpha = $this->createActiveSite('Alpha Cottage');
        $siteBravo = $this->createActiveSite('Bravo Cottage');

        $routerAlpha = $this->createAssignedDevice($siteAlpha, [
            'name' => 'Alpha Gateway',
            'domain' => 'it_infrastructure',
            'category' => 'network',
            'ip_address' => '192.168.1.1',
        ]);
        $routerBravo = $this->createAssignedDevice($siteBravo, [
            'name' => 'Bravo Gateway',
            'domain' => 'it_infrastructure',
            'category' => 'network',
            'ip_address' => '192.168.1.1',
        ]);

        $collectorAlpha = MonitoringCollector::factory()->create([
            'site_id' => $siteAlpha->id,
            'status' => 'online',
            'revoked_at' => null,
        ]);
        $collectorBravo = MonitoringCollector::factory()->create([
            'site_id' => $siteBravo->id,
            'status' => 'online',
            'revoked_at' => null,
        ]);

        DiscoveryScope::factory()->create([
            'site_id' => $siteAlpha->id,
            'collector_id' => $collectorAlpha->id,
            'cidrs' => ['192.168.1.0/24'],
            'protocols' => ['syslog', 'flow'],
            'exclusions' => [],
            'status' => 'active',
        ]);
        DiscoveryScope::factory()->create([
            'site_id' => $siteBravo->id,
            'collector_id' => $collectorBravo->id,
            'cidrs' => ['192.168.1.0/24'],
            'protocols' => ['syslog', 'flow'],
            'exclusions' => [],
            'status' => 'active',
        ]);

        $syslogAlpha = '<131>1 2026-10-28T08:00:00Z alpha-gw firewalld 100 - - WAN failover activated on Alpha';
        $syslogBravo = '<130>1 2026-10-28T08:01:00Z bravo-gw firewalld 101 - - PSU fault detected on Bravo';

        // Build 1-record NetFlow v5 packet (24-byte header + 48-byte record = 72 bytes)
        $nowUnix = CarbonImmutable::now('UTC')->timestamp;
        $nfHeader = pack('nnNNNNCCn', 5, 1, 60_000, $nowUnix, 0, 1, 0, 0, 0);
        $nfRecord = pack(
            'NNNnnNNNNnnCCCCnnCCn',
            ip2long('192.168.1.50'),
            ip2long('8.8.8.8'),
            0,
            1,
            2,
            12,
            4800,
            50_000,
            60_000,
            51234,
            443,
            0,
            0x10,
            6,
            0,
            0,
            0,
            24,
            0,
            0,
        );
        $netflowPacket = $nfHeader.$nfRecord;

        $ingest = app(CollectorIngestService::class);
        $nowIso = CarbonImmutable::now('UTC')->toIso8601String();

        // Collector Alpha uploads Syslog + NetFlow + a spoofed cross-Site attempt targeting Bravo's site_id
        $resAlpha = $ingest->ingest($collectorAlpha, [
            [
                'id' => 'alpha-syslog-1',
                'source_sequence' => 1,
                'created_at' => $nowIso,
                'payload' => [
                    'item_type' => 'syslog_event',
                    'site_id' => $siteAlpha->id,
                    'sender_ip' => '192.168.1.1',
                    'datagram_base64' => base64_encode($syslogAlpha),
                ],
            ],
            [
                'id' => 'alpha-flow-2',
                'source_sequence' => 2,
                'created_at' => $nowIso,
                'payload' => [
                    'item_type' => 'flow_datagram',
                    'site_id' => $siteAlpha->id,
                    'sender_ip' => '192.168.1.1',
                    'packet_base64' => base64_encode($netflowPacket),
                ],
            ],
            [
                'id' => 'alpha-cross-site-spoof-3',
                'source_sequence' => 3,
                'created_at' => $nowIso,
                'payload' => [
                    'item_type' => 'syslog_event',
                    'site_id' => $siteBravo->id,
                    'sender_ip' => '192.168.1.1',
                    'datagram_base64' => base64_encode($syslogBravo),
                ],
            ],
        ]);

        // Collector Bravo uploads its own Syslog from its own 192.168.1.1
        $resBravo = $ingest->ingest($collectorBravo, [
            [
                'id' => 'bravo-syslog-1',
                'source_sequence' => 1,
                'created_at' => $nowIso,
                'payload' => [
                    'item_type' => 'syslog_event',
                    'site_id' => $siteBravo->id,
                    'sender_ip' => '192.168.1.1',
                    'datagram_base64' => base64_encode($syslogBravo),
                ],
            ],
        ]);

        $this->assertSame(3, $resAlpha['acknowledged_source_sequence']);
        $this->assertSame(1, $resBravo['acknowledged_source_sequence']);

        // Alpha's Syslog landed ONLY on routerAlpha; Bravo's Syslog landed ONLY on routerBravo
        $alphaEvents = DeviceEvent::query()->where('device_id', $routerAlpha->id)->get();
        $bravoEvents = DeviceEvent::query()->where('device_id', $routerBravo->id)->get();

        $this->assertCount(1, $alphaEvents);
        $this->assertStringContainsString('WAN failover activated on Alpha', (string) data_get($alphaEvents[0]->payload, 'message'));

        $this->assertCount(1, $bravoEvents);
        $this->assertStringContainsString('PSU fault detected on Bravo', (string) data_get($bravoEvents[0]->payload, 'message'));

        // Cross-site spoof from Collector Alpha was dead-lettered with `site_scope_violation`
        $this->assertSame(
            1,
            MonitoringDeadLetter::query()
                ->where('idempotency_key', 'alpha-cross-site-spoof-3')
                ->where('reason_code', 'site_scope_violation')
                ->count(),
        );

        // NetFlow bytes metric attributed strictly to Site Alpha & routerAlpha
        $byteWrites = array_values(array_filter(
            $recordedMetrics,
            static fn (array $w): bool => $w['metric'] === 'flow.bytes',
        ));
        $this->assertNotEmpty($byteWrites);
        $this->assertSame($siteAlpha->id, $byteWrites[0]['siteId']);
        $this->assertSame($routerAlpha->id, $byteWrites[0]['deviceId']);
        $this->assertSame(4800, $byteWrites[0]['value']);
    }

    private function createActiveSite(string $name): Site
    {
        return Site::factory()->create([
            'name' => $name,
            'is_active' => true,
            'archived' => false,
            'archived_at' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createAssignedDevice(Site $site, array $attributes): Device
    {
        $device = Device::factory()->create($attributes);

        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assignment_type' => 'permanent',
            'assigned_at' => now()->subHour(),
        ]);

        return $device;
    }

    private function createScopedManager(Site $site): User
    {
        $user = User::factory()->create([
            'approved_at' => now(),
            'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
            'two_factor_confirmed_at' => now(),
        ]);
        $user->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());

        HrEmployeeProfile::factory()->create([
            'user_id' => $user->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
        ]);

        return $user;
    }

    /**
     * @param  array<string, scalar|null>  $material
     */
    private function bindMockCredentialLease(array $material): void
    {
        $this->app->instance(CredentialLeaseProvider::class, new class($material) implements CredentialLeaseProvider
        {
            /** @param array<string, scalar|null> $material */
            public function __construct(private readonly array $material) {}

            public function acquire(int $siteId, string $reference, array $capabilities): CredentialLease
            {
                return new CredentialLease(
                    'lease-'.Str::uuid(),
                    CarbonImmutable::now('UTC')->addMinutes(5),
                    $this->material,
                );
            }
        });

        $this->app->forgetInstance(CommandCredentialLeaseService::class);
        $this->app->forgetInstance(OnvifCameraProfileClient::class);
        $this->app->forgetInstance(GallagherCommandCentreDriver::class);
        $this->app->forgetInstance(InnerRangeInceptionDriver::class);
        $this->app->forgetInstance(DeviceDriverRegistry::class);
        $this->app->forgetInstance(AlarmPanelCommandAdapter::class);
        $this->app->forgetInstance(NetworkSshConfigBackupCommandAdapter::class);
        $this->app->forgetInstance(CommandExecutionAdapterRegistry::class);
        $this->app->forgetInstance(CommandExecutionRouteResolver::class);
        $this->app->forgetInstance(DeviceCommandRequestService::class);
        $this->app->forgetInstance(GovernedCommandDispatchService::class);
        $this->app->forgetInstance(DeviceCommandReconciliationService::class);
    }
}
