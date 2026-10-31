<?php

namespace Tests\Feature\Monitoring;

use App\Domain\Monitoring\Discovery\Contracts\DiscoveryAdapter;
use App\Domain\Monitoring\Discovery\Data\DiscoveryProbeResult;
use App\Domain\Monitoring\Discovery\Data\DiscoveryTarget;
use App\Domain\Monitoring\Discovery\Models\DiscoveryCandidate;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\Monitoring\Discovery\Services\DiscoveryRunner;
use App\Domain\Monitoring\Models\MonitoringCollector;
use App\Domain\SecurityDevices\Models\Device;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class Phase3DiscoverySweepAndReviewTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        $this->admin = User::factory()->create(['approved_at' => now()]);
        $this->admin->roles()->attach(Role::query()->where('name', 'admin')->firstOrFail());
    }

    public function test_creates_extended_ten_protocol_scope_without_mandatory_snmp_reference(): void
    {
        $site = Site::factory()->create([
            'name' => 'Harbour House',
            'is_active' => true,
            'archived' => false,
            'archived_at' => null,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson('/security-devices/discovery/scopes', [
                'site_id' => $site->id,
                'name' => 'Harbour LAN full sweep',
                'cidrs' => ['172.16.0.0/24'],
                'protocols' => [
                    'icmp',
                    'arp',
                    'tcp',
                    'dns',
                    'http',
                    'tls',
                    'snmp',
                    'mdns',
                    'ssdp',
                    'onvif',
                ],
                'port_bounds' => [
                    'tcp' => [22, 80, 443, 554, 8000, 9100],
                    'dns' => [53],
                    'http' => [80, 443, 8000],
                    'tls' => [443],
                    'snmp' => [161],
                    'mdns' => [5353],
                    'ssdp' => [1900],
                    'onvif' => [3702],
                ],
                'max_targets_per_run' => 256,
                'packets_per_second' => 25,
            ]);

        $response->assertCreated();
        $scopeId = (int) $response->json('scope.id');
        $scope = DiscoveryScope::query()->findOrFail($scopeId);

        $this->assertSame(
            ['icmp', 'arp', 'tcp', 'dns', 'http', 'tls', 'snmp', 'mdns', 'ssdp', 'onvif'],
            $scope->protocols,
        );
        $this->assertNull($scope->snmp_credential_reference);
    }

    public function test_records_multi_signal_collector_results_exposes_fingerprint_summary_and_adopts_with_detected_type(): void
    {
        Queue::fake();

        $site = Site::factory()->create([
            'name' => 'Harbour House',
            'is_active' => true,
            'archived' => false,
            'archived_at' => null,
        ]);

        $collector = MonitoringCollector::factory()->create([
            'site_id' => $site->id,
            'status' => 'online',
        ]);

        $scope = DiscoveryScope::factory()->create([
            'site_id' => $site->id,
            'collector_id' => $collector->id,
            'cidrs' => ['172.16.0.0/24'],
            'protocols' => ['icmp', 'arp', 'tcp', 'onvif', 'snmp', 'mdns'],
            'port_bounds' => [
                'tcp' => [22, 80, 443, 554, 8000, 9100],
                'onvif' => [3702],
                'snmp' => [161],
                'mdns' => [5353],
            ],
            'exclusions' => [],
            'max_targets_per_run' => 8,
            'packets_per_second' => 25,
            'status' => 'active',
        ]);

        $planner = new class implements DiscoveryAdapter
        {
            public function begin(DiscoveryScope $scope): void {}

            public function targets(DiscoveryScope $scope): iterable
            {
                return [
                    new DiscoveryTarget('172.16.0.1', 'cidr'),
                    new DiscoveryTarget('172.16.0.55', 'cidr'),
                ];
            }

            public function discover(DiscoveryScope $scope, DiscoveryTarget $target): DiscoveryProbeResult
            {
                return DiscoveryProbeResult::unresolved('not_called_for_collector');
            }
        };

        $this->app->instance(DiscoveryAdapter::class, $planner);

        $runner = $this->app->make(DiscoveryRunner::class);
        $run = $runner->start($scope, "manual:user:{$this->admin->id}");
        $runner->execute($run->id);

        $runner->recordCollectorResult($collector, [
            'item_type' => 'discovery_result',
            'run_id' => $run->run_uuid,
            'target' => '172.16.0.1',
            'observed_at' => now()->toAtomString(),
            'outcome' => 'found',
            'identity' => [
                'hostname' => 'ux7-harbour',
                'addresses' => ['172.16.0.1'],
                'mac_addresses' => ['24:5A:4C:99:88:77'],
                'certificate_fingerprint' => null,
                'fingerprint' => 'network:snmp,tcp:22,80,443',
                'signals' => [
                    'snmp_sys_descr' => 'Ubiquiti UniFi Express 7 4.0.6',
                    'snmp_sys_object_id' => '1.3.6.1.4.1.41112.1.4',
                    'open_ports' => [22, 53, 80, 443],
                ],
            ],
        ]);

        $runner->recordCollectorResult($collector, [
            'item_type' => 'discovery_result',
            'run_id' => $run->run_uuid,
            'target' => '172.16.0.55',
            'observed_at' => now()->toAtomString(),
            'outcome' => 'found',
            'identity' => [
                'hostname' => 'front-gate-cam.local',
                'addresses' => ['172.16.0.55'],
                'mac_addresses' => ['44:19:B6:11:22:33'],
                'certificate_fingerprint' => null,
                'fingerprint' => 'network:onvif,tcp:80,554,8000',
                'signals' => [
                    'onvif_types' => 'dn:NetworkVideoTransmitter',
                    'onvif_hardware' => 'DS-2CD2387G2-LU',
                    'onvif_scopes' => ['onvif://www.onvif.org/name/Hikvision'],
                    'open_ports' => [80, 554, 8000],
                ],
            ],
        ]);

        $runner->complete($run->id);

        $pageResponse = $this->actingAs($this->admin)
            ->get('/security-devices/discovery?tab=candidates')
            ->assertOk();

        /** @var list<array<string, mixed>> $candidatesPayload */
        $candidatesPayload = $pageResponse->viewData('page')['props']['workspace']['candidates'];
        $this->assertCount(2, $candidatesPayload);

        $byIp = collect($candidatesPayload)->keyBy('ip_address');
        $camPayload = $byIp->get('172.16.0.55');
        $this->assertNotNull($camPayload);
        $this->assertSame('ONVIF camera', $camPayload['type_label']);
        $this->assertSame('security', $camPayload['domain']);
        $this->assertSame('cctv', $camPayload['category']);
        $this->assertSame('ip_camera', $camPayload['subcategory']);
        $this->assertSame('Hikvision', $camPayload['manufacturer']);
        $this->assertSame('DS-2CD2387G2-LU', $camPayload['model']);
        $this->assertStringContainsString('Hikvision', (string) $camPayload['mac_vendor']);
        $this->assertStringContainsString('ONVIF', (string) $camPayload['identification_reason']);
        $this->assertSame([80, 554, 8000], $camPayload['open_ports']);

        // Adopt all unreviewed candidates at Harbour House and verify fingerprinted fields persist onto Device records.
        $this->actingAs($this->admin)
            ->postJson('/security-devices/discovery/candidates/adopt-all', [
                'site_id' => $site->id,
            ])
            ->assertOk();

        $cameraDevice = Device::query()->where('ip_address', '172.16.0.55')->firstOrFail();
        $this->assertSame('security', $cameraDevice->domain);
        $this->assertSame('cctv', $cameraDevice->category);
        $this->assertSame('ip_camera', $cameraDevice->subcategory);
        $this->assertSame('Hikvision', $cameraDevice->manufacturer);
        $this->assertSame('DS-2CD2387G2-LU', $cameraDevice->model);

        $gatewayDevice = Device::query()->where('ip_address', '172.16.0.1')->firstOrFail();
        $this->assertSame('it_infrastructure', $gatewayDevice->domain);
        $this->assertSame('network', $gatewayDevice->category);
        $this->assertSame('Ubiquiti', $gatewayDevice->manufacturer);

        $this->assertSame(
            0,
            DiscoveryCandidate::query()->whereNull('reviewed_at')->count(),
        );
    }
}
