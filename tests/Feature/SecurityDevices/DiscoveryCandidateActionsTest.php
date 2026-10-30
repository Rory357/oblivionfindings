<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Monitoring\Discovery\Models\DiscoveryCandidate;
use App\Domain\Monitoring\Discovery\Models\DiscoveryRun;
use App\Domain\Monitoring\Discovery\Models\DiscoveryScope;
use App\Domain\SecurityDevices\Enums\AssignmentType;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiscoveryCandidateActionsTest extends TestCase
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

    private function createRunForSite(Site $site): DiscoveryRun
    {
        $scope = DiscoveryScope::factory()->create([
            'site_id' => $site->id,
            'collector_id' => null,
            'cidrs' => ['172.16.0.0/24'],
            'exclusions' => [],
            'status' => 'active',
        ]);

        return DiscoveryRun::factory()->create([
            'discovery_scope_id' => $scope->id,
            'status' => 'completed',
        ]);
    }

    public function test_candidate_presenter_exposes_safe_network_summary_without_raw_evidence_leakage(): void
    {
        $site = Site::factory()->create(['name' => 'Harbour House', 'is_active' => true]);
        $run = $this->createRunForSite($site);

        DiscoveryCandidate::factory()->create([
            'discovery_run_id' => $run->id,
            'decision' => 'proposed',
            'confidence' => 65,
            'reasons' => ['new_address'],
            'evidence_snapshot' => [
                'provider' => 'icmp',
                'hostname' => 'lobby-cam-01.local',
                'addresses' => ['172.16.0.50'],
                'mac_addresses' => ['aabbcc112233'],
                'snmp_community' => 'super-secret-community-string',
            ],
        ]);

        $response = $this->actingAs($this->admin)
            ->get('/security-devices/discovery?tab=candidates')
            ->assertOk();

        $json = json_encode($response->viewData('page')['props']);
        $this->assertStringNotContainsString('super-secret-community-string', (string) $json);

        $candidatePayload = $response->viewData('page')['props']['workspace']['candidates'][0];
        $this->assertSame('lobby-cam-01.local', $candidatePayload['hostname']);
        $this->assertSame('172.16.0.50', $candidatePayload['ip_address']);
        $this->assertSame('AA:BB:CC:11:22:33', $candidatePayload['mac_address']);
        $this->assertNotNull($candidatePayload['actions']['adopt_url']);
        $this->assertNotNull($candidatePayload['actions']['same_as_url']);
        $this->assertNotNull($candidatePayload['actions']['ignore_url']);
    }

    public function test_adopt_same_as_ignore_and_adopt_all_workflow(): void
    {
        $siteA = Site::factory()->create(['name' => 'Harbour House', 'is_active' => true]);
        $siteB = Site::factory()->create(['name' => 'Kauri Cottage', 'is_active' => true]);
        $runA = $this->createRunForSite($siteA);

        $adoptCandidate = DiscoveryCandidate::factory()->create([
            'discovery_run_id' => $runA->id,
            'decision' => 'proposed',
            'confidence' => 70,
            'reasons' => ['new_address'],
            'evidence_snapshot' => [
                'provider' => 'icmp',
                'hostname' => 'harbour-switch-01',
                'addresses' => ['172.16.0.10'],
                'mac_addresses' => ['001122334455'],
            ],
        ]);

        // 1. Add as device
        $adoptRes = $this->actingAs($this->admin)
            ->postJson("/security-devices/discovery/candidates/{$adoptCandidate->id}/adopt", [
                'name' => 'Harbour Switch 01',
                'domain' => 'it_infrastructure',
                'category' => 'network',
                'manufacturer' => 'Ubiquiti',
                'model' => 'USW-24-PoE',
            ])
            ->assertOk();

        $adoptedDevice = Device::query()->findOrFail($adoptRes->json('device_id'));
        $this->assertSame('Harbour Switch 01', $adoptedDevice->name);
        $this->assertSame('172.16.0.10', $adoptedDevice->ip_address);
        $this->assertSame('00:11:22:33:44:55', $adoptedDevice->mac_address);
        $this->assertSame('adopted', $adoptCandidate->fresh()->review_action);

        // 2. Same as existing device (including cross-Site guard)
        $existingAtSiteA = Device::factory()->itInfrastructure()->create([
            'name' => 'Harbour Printer',
            'ip_address' => null,
            'mac_address' => null,
        ]);
        DeviceAssignment::query()->create([
            'device_id' => $existingAtSiteA->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $siteA->id,
            'assignment_type' => AssignmentType::Permanent,
            'assigned_at' => now(),
        ]);

        $existingAtSiteB = Device::factory()->itInfrastructure()->create([
            'name' => 'Kauri Printer',
        ]);
        DeviceAssignment::query()->create([
            'device_id' => $existingAtSiteB->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $siteB->id,
            'assignment_type' => AssignmentType::Permanent,
            'assigned_at' => now(),
        ]);

        $sameAsCandidate = DiscoveryCandidate::factory()->create([
            'discovery_run_id' => $runA->id,
            'decision' => 'review',
            'confidence' => 55,
            'reasons' => ['ambiguous_match'],
            'evidence_snapshot' => [
                'provider' => 'icmp',
                'addresses' => ['172.16.0.21'],
                'mac_addresses' => ['66778899aabb'],
            ],
        ]);

        $this->actingAs($this->admin)
            ->postJson("/security-devices/discovery/candidates/{$sameAsCandidate->id}/same-as", [
                'device_id' => $existingAtSiteB->id,
            ])
            ->assertStatus(422);

        $this->actingAs($this->admin)
            ->postJson("/security-devices/discovery/candidates/{$sameAsCandidate->id}/same-as", [
                'device_id' => $existingAtSiteA->id,
            ])
            ->assertOk();

        $existingAtSiteA->refresh();
        $this->assertSame('172.16.0.21', $existingAtSiteA->ip_address);
        $this->assertSame('66:77:88:99:AA:BB', $existingAtSiteA->mac_address);
        $this->assertSame('same_as', $sameAsCandidate->fresh()->review_action);

        // 3. Ignore candidate
        $ignoreCandidate = DiscoveryCandidate::factory()->create([
            'discovery_run_id' => $runA->id,
            'decision' => 'proposed',
            'confidence' => 40,
            'reasons' => ['ephemeral_host'],
            'evidence_snapshot' => [
                'provider' => 'icmp',
                'addresses' => ['172.16.0.199'],
            ],
        ]);

        $this->actingAs($this->admin)
            ->postJson("/security-devices/discovery/candidates/{$ignoreCandidate->id}/ignore")
            ->assertOk()
            ->assertJson(['review_action' => 'ignored']);

        // 4. Add all remaining unreviewed proposed candidates (leaving ambiguous 'review' candidates for manual review)
        DiscoveryCandidate::factory()->create([
            'discovery_run_id' => $runA->id,
            'decision' => 'proposed',
            'confidence' => 80,
            'reasons' => ['new_address'],
            'evidence_snapshot' => [
                'provider' => 'icmp',
                'hostname' => 'harbour-ap-02',
                'addresses' => ['172.16.0.32'],
            ],
        ]);
        DiscoveryCandidate::factory()->create([
            'discovery_run_id' => $runA->id,
            'decision' => 'proposed',
            'confidence' => 75,
            'reasons' => ['new_address'],
            'evidence_snapshot' => [
                'provider' => 'icmp',
                'hostname' => 'harbour-ap-03',
                'addresses' => ['172.16.0.33'],
            ],
        ]);
        $ambiguousCandidate = DiscoveryCandidate::factory()->create([
            'discovery_run_id' => $runA->id,
            'decision' => 'review',
            'confidence' => 60,
            'reasons' => ['ambiguous_match'],
            'evidence_snapshot' => [
                'provider' => 'icmp',
                'hostname' => 'harbour-ap-dup',
                'addresses' => ['172.16.0.34'],
            ],
        ]);

        $this->actingAs($this->admin)
            ->postJson('/security-devices/discovery/candidates/adopt-all', [
                'site_id' => $siteA->id,
            ])
            ->assertOk()
            ->assertJson(['adopted_count' => 2]);

        $this->assertTrue(Device::query()->where('ip_address', '172.16.0.32')->exists());
        $this->assertTrue(Device::query()->where('ip_address', '172.16.0.33')->exists());
        $this->assertNull($ambiguousCandidate->fresh()->reviewed_at);
    }
}
