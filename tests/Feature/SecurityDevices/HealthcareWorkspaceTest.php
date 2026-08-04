<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\Enums\DeviceStatus;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Domain\SecurityDevices\Models\DeviceMaintenanceRecord;
use App\Models\Client;
use App\Models\ItTicket;
use App\Models\ItTicketLink;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthcareWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        $this->site = Site::factory()->create(['name' => 'Healthcare workspace Site']);
        $this->admin = $this->viewerWithRole('admin');
        HrEmployeeProfile::factory()->create([
            'user_id' => $this->admin->id,
            'employee_number' => 'SEC-HEALTHCARE-ADMIN',
            'position_role' => 'admin',
            'primary_site_id' => $this->site->id,
            'secondary_site_ids' => [],
            'start_date' => today()->subYear(),
            'end_date' => null,
            'is_active' => true,
            'created_by' => $this->admin->id,
            'updated_by' => $this->admin->id,
        ]);
    }

    public function test_overview_reconciles_authorised_client_shared_and_unassigned_healthcare_devices(): void
    {
        $site = Site::factory()->create(['name' => 'Kauri House']);
        $client = Client::factory()->create([

            'site_id' => $site->id,
            'preferred_name' => 'Mere',
        ]);

        $clientDevice = $this->healthcareDevice('Mere fall detector', [
            'status' => DeviceStatus::Offline,
        ]);
        $sharedDevice = $this->healthcareDevice('Shared nurse call');
        $unassignedDevice = $this->healthcareDevice('Spare bed sensor');
        $unrelatedDevice = $this->healthcareDevice('Unrelated monitor', []);

        $this->assign($clientDevice, DeviceAssignment::TARGET_CLIENT, $client->id);
        $this->assign($sharedDevice, DeviceAssignment::TARGET_SITE, $site->id, 'shared');

        $this->actingAs($this->admin)
            ->get('/security-devices/healthcare')
            ->assertOk()
            ->assertInertia(function ($page) use ($unrelatedDevice): void {
                $healthcare = $page->toArray()['props']['healthcareWorkspace'];

                $this->assertSame([
                    'total' => 4,
                    'client_assigned' => 1,
                    'shared_site' => 1,
                    'unassigned' => 2,
                ], $healthcare['overview']['inventory']);
                $this->assertSame(1, $healthcare['overview']['attention']['offline']);
                $this->assertSame(3, $healthcare['overview']['attention']['data_flow_issues']);
                $this->assertSame(4, $healthcare['activeTab']['inventoryTotal']);
                $this->assertContains(
                    $unrelatedDevice->id,
                    collect($healthcare['activeTab']['devices'])->pluck('id')->all(),
                );
                $this->assertSame(
                    ['offline_devices', 'data_flow_issues', 'overdue_calibration', 'maintenance_due'],
                    collect($healthcare['overview']['requiredActions'])->pluck('key')->all(),
                );
            });
    }

    public function test_client_devices_expose_minimum_identity_and_allowlisted_technical_support_context_only(): void
    {
        $site = $this->site;
        $keyWorker = User::factory()->create([

            'approved_at' => now(),
            'name' => 'Aroha Support',
        ]);
        $client = Client::factory()->create([

            'preferred_name' => 'Mere',
            'first_name' => 'Mererangi',
            'last_name' => 'Taonga',
            'nhi_number' => 'ZZZ9999',
            'key_worker_id' => $keyWorker->id,
            'site_id' => $site->id,
        ]);
        $device = $this->healthcareDevice('Mere wearable', [
            'battery_level' => 72,
            'battery_updated_at' => now()->subMinutes(5),
            'provider' => 'native',
            'config' => [
                'connectivity_state' => 'connected',
                'integration_state' => 'healthy',
                'last_successful_delivery_at' => now()->subMinutes(3)->toIso8601String(),
                'delivery_stale_after_minutes' => 30,
                'clinical_reading' => 'CLINICAL-READING-SENTINEL',
                'clinical_threshold' => 'CLINICAL-THRESHOLD-SENTINEL',
            ],
            'meta' => [
                'diagnosis' => 'DIAGNOSIS-SENTINEL',
                'medication' => 'MEDICATION-SENTINEL',
                'clinical_review' => 'CLINICAL-REVIEW-SENTINEL',
            ],
        ]);
        $this->assign($device, DeviceAssignment::TARGET_CLIENT, $client->id);

        $ticket = ItTicket::factory()->create([
            'requester_user_id' => $this->admin->id,
            'title' => 'Restore device delivery',
            'status' => 'open',
        ]);
        ItTicketLink::create([
            'ticket_id' => $ticket->id,
            'relationship' => 'affected_device',
            'linkable_type' => Device::class,
            'linkable_id' => $device->id,
            'created_by_user_id' => $this->admin->id,
        ]);

        $this->assertTrue($this->admin->canDo('clients.viewAny'));

        $this->actingAs($this->admin)
            ->get('/security-devices/healthcare?tab=client-devices')
            ->assertOk()
            ->assertInertia(function ($page) use ($client, $device, $ticket): void {
                $props = $page->toArray()['props'];
                $healthcare = $props['healthcareWorkspace'];
                $row = $healthcare['activeTab']['devices'][0];

                $this->assertSame('client-devices', $healthcare['activeTab']['key']);
                $this->assertSame([
                    'id' => $client->id,
                    'displayName' => 'Mere',
                    'href' => "/clients/{$client->id}",
                ], $row['client']);
                $this->assertSame('Aroha Support', $row['supportContact']['name']);
                $this->assertSame('key worker', $row['supportContact']['role']);
                $this->assertSame(72, $row['technical']['battery']['level']);
                $this->assertSame('connected', $row['technical']['connectivity']['state']);
                $this->assertSame('healthy', $row['technical']['integration']['state']);
                $this->assertSame('healthy', $row['technical']['flow']['state']);
                $this->assertSame([
                    'id' => $ticket->id,
                    'reference' => $ticket->reference,
                    'title' => 'Restore device delivery',
                    'status' => 'open',
                    'href' => "/it/tickets/{$ticket->id}",
                ], $row['itTickets'][0]);
                $this->assertSame($device->id, $row['id']);

                $payload = json_encode($props, JSON_THROW_ON_ERROR);
                foreach ([
                    'ZZZ9999',
                    'Taonga',
                    'CLINICAL-READING-SENTINEL',
                    'CLINICAL-THRESHOLD-SENTINEL',
                    'DIAGNOSIS-SENTINEL',
                    'MEDICATION-SENTINEL',
                    'CLINICAL-REVIEW-SENTINEL',
                ] as $forbidden) {
                    $this->assertStringNotContainsString($forbidden, $payload);
                }
                $this->assertArrayNotHasKey('config', $row);
                $this->assertArrayNotHasKey('meta', $row);
            });

        $this->actingAs($this->admin)
            ->get("/security-devices/devices/{$device->id}")
            ->assertOk()
            ->assertInertia(function ($page): void {
                $props = $page->toArray()['props'];
                $payload = json_encode($props, JSON_THROW_ON_ERROR);

                $this->assertArrayNotHasKey('config', $props['device']);
                $this->assertArrayNotHasKey('meta', $props['device']);
                $this->assertArrayNotHasKey('external_ref', $props['device']);
                foreach ([
                    'CLINICAL-READING-SENTINEL',
                    'CLINICAL-THRESHOLD-SENTINEL',
                    'DIAGNOSIS-SENTINEL',
                    'MEDICATION-SENTINEL',
                    'CLINICAL-REVIEW-SENTINEL',
                ] as $forbidden) {
                    $this->assertStringNotContainsString($forbidden, $payload);
                }
            });

        $export = $this->actingAs($this->admin)
            ->get("/security-devices/reports/devices.csv?ids={$device->id}")
            ->assertOk()
            ->streamedContent();
        foreach ([
            'CLINICAL-READING-SENTINEL',
            'CLINICAL-THRESHOLD-SENTINEL',
            'DIAGNOSIS-SENTINEL',
            'MEDICATION-SENTINEL',
            'CLINICAL-REVIEW-SENTINEL',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $export);
        }
    }

    public function test_assigned_support_worker_sees_only_their_clients_healthcare_devices_and_direct_urls(): void
    {
        $viewer = $this->viewerWithRole('support_worker');
        $site = Site::factory()->create([]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $viewer->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
        ]);
        $assignedClient = Client::factory()->create([

            'site_id' => $site->id,
            'preferred_name' => 'Assigned person',
        ]);
        $otherClient = Client::factory()->create([

            'site_id' => $site->id,
            'preferred_name' => 'Other person',
        ]);
        $unrelatedClient = Client::factory()->create([

            'preferred_name' => 'Unrelated person',
        ]);
        $viewer->assignedClients()->attach($assignedClient->id);

        $assignedDevice = $this->healthcareDevice('Assigned detector');
        $otherDevice = $this->healthcareDevice('Other detector');
        $unrelatedDevice = $this->healthcareDevice('Unrelated detector', []);
        $this->assign($assignedDevice, DeviceAssignment::TARGET_CLIENT, $assignedClient->id);
        $this->assign($otherDevice, DeviceAssignment::TARGET_CLIENT, $otherClient->id);
        $this->assign($unrelatedDevice, DeviceAssignment::TARGET_CLIENT, $unrelatedClient->id);

        $this->actingAs($viewer)
            ->get('/security-devices/healthcare?tab=client-devices')
            ->assertOk()
            ->assertInertia(function ($page) use ($assignedDevice): void {
                $props = $page->toArray()['props'];

                $this->assertSame('available', $props['workspace']['activeTabState']);
                $this->assertSame(1, $props['healthcareWorkspace']['overview']['inventory']['total']);
                $this->assertSame(
                    [$assignedDevice->id],
                    collect($props['healthcareWorkspace']['activeTab']['devices'])->pluck('id')->all(),
                );
                $this->assertSame([], $props['healthcareWorkspace']['activeTab']['devices'][0]['itTickets']);
            });

        $this->actingAs($viewer)
            ->get("/security-devices/devices/{$assignedDevice->id}")
            ->assertOk();
        $this->actingAs($viewer)
            ->get("/security-devices/devices/{$otherDevice->id}")
            ->assertNotFound();
        $this->actingAs($viewer)
            ->get("/security-devices/devices/{$unrelatedDevice->id}")
            ->assertNotFound();
    }

    public function test_client_tab_is_restricted_without_client_context_permission_and_leaks_no_counts(): void
    {
        $viewer = $this->viewerWithRole('team_lead');
        $client = Client::factory()->create();
        $device = $this->healthcareDevice('Restricted client device');
        $this->assign($device, DeviceAssignment::TARGET_CLIENT, $client->id);

        $this->actingAs($viewer)
            ->get('/security-devices/healthcare?tab=client-devices')
            ->assertOk()
            ->assertInertia(function ($page): void {
                $props = $page->toArray()['props'];

                $this->assertSame('restricted', $props['workspace']['activeTabState']);
                $this->assertTrue($props['healthcareWorkspace']['activeTab']['restricted']);
                $this->assertSame(0, $props['healthcareWorkspace']['activeTab']['inventoryTotal']);
                $this->assertSame([], $props['healthcareWorkspace']['activeTab']['devices']);
            });
    }

    public function test_integration_management_does_not_bypass_client_context_permission(): void
    {
        $viewer = $this->viewerWithRole('it_manager');
        $client = Client::factory()->create();
        $site = Site::factory()->create([]);
        $clientDevice = $this->healthcareDevice('Client device hidden from IT');
        $siteDevice = $this->healthcareDevice('Site device visible to IT');
        $this->assign($clientDevice, DeviceAssignment::TARGET_CLIENT, $client->id);
        $this->assign($siteDevice, DeviceAssignment::TARGET_SITE, $site->id, 'shared');

        $this->assertTrue($viewer->canDo('securityDevices.integrations.manage'));
        $this->assertFalse($viewer->canDo('clients.viewAny'));
        $this->assertFalse($viewer->canDo('clients.viewAssigned'));

        $this->actingAs($viewer)
            ->get('/security-devices/healthcare')
            ->assertOk()
            ->assertInertia(function ($page) use ($siteDevice): void {
                $healthcare = $page->toArray()['props']['healthcareWorkspace'];

                $this->assertSame(1, $healthcare['overview']['inventory']['total']);
                $this->assertSame(
                    [$siteDevice->id],
                    collect($healthcare['activeTab']['devices'])->pluck('id')->all(),
                );
            });

        $this->actingAs($viewer)
            ->get("/security-devices/devices/{$clientDevice->id}")
            ->assertNotFound();
        $this->actingAs($viewer)
            ->get("/security-devices/devices/{$siteDevice->id}")
            ->assertOk();

        $export = $this->actingAs($viewer)
            ->get('/security-devices/reports/devices.csv')
            ->assertOk()
            ->streamedContent();
        $this->assertStringNotContainsString('Client device hidden from IT', $export);
        $this->assertStringContainsString('Site device visible to IT', $export);
    }

    public function test_shared_site_devices_show_service_responsibility_without_implying_client_assignment(): void
    {
        $siteLead = User::factory()->create([

            'approved_at' => now(),
            'name' => 'Kauri Site Lead',
        ]);
        $site = Site::factory()->create([
            'name' => 'Kauri House',
            'primary_contact_user_id' => $siteLead->id,
        ]);
        $shared = $this->healthcareDevice('Shared occupancy sensor');
        $this->assign($shared, DeviceAssignment::TARGET_SITE, $site->id, 'shared');

        $this->actingAs($this->admin)
            ->get('/security-devices/healthcare?tab=shared-site-devices')
            ->assertOk()
            ->assertInertia(function ($page) use ($site, $shared): void {
                $row = $page->toArray()['props']['healthcareWorkspace']['activeTab']['devices'][0];

                $this->assertSame($shared->id, $row['id']);
                $this->assertNull($row['client']);
                $this->assertSame([
                    'id' => $site->id,
                    'name' => 'Kauri House',
                    'href' => "/sites/{$site->id}",
                ], $row['location']['site']);
                $this->assertSame('Shared at Kauri House', $row['assignment']['label']);
                $this->assertSame('Kauri Site Lead', $row['supportContact']['name']);
                $this->assertSame('site primary contact', $row['supportContact']['role']);
            });
    }

    public function test_connectivity_and_data_flow_use_explicit_evidence_and_never_infer_healthy(): void
    {
        $offline = $this->healthcareDevice('Offline device', [
            'status' => DeviceStatus::Offline,
            'config' => ['integration_state' => 'healthy', 'last_successful_delivery_at' => now()->toIso8601String()],
        ]);
        $failed = $this->healthcareDevice('Integration failed', [
            'config' => ['integration_state' => 'failed', 'last_successful_delivery_at' => now()->toIso8601String()],
        ]);
        $stale = $this->healthcareDevice('Stale delivery', [
            'config' => [
                'integration_state' => 'healthy',
                'last_successful_delivery_at' => now()->subHours(2)->toIso8601String(),
                'delivery_stale_after_minutes' => 30,
            ],
        ]);
        $unsupported = $this->healthcareDevice('Unsupported monitoring');
        $healthy = $this->healthcareDevice('Healthy flow', [
            'config' => [
                'connectivity_state' => 'connected',
                'integration_state' => 'healthy',
                'last_successful_delivery_at' => now()->subMinutes(3)->toIso8601String(),
                'delivery_stale_after_minutes' => 30,
            ],
        ]);

        $this->actingAs($this->admin)
            ->get('/security-devices/healthcare?tab=data-flow')
            ->assertOk()
            ->assertInertia(function ($page) use ($offline, $failed, $stale, $unsupported, $healthy): void {
                $devices = collect($page->toArray()['props']['healthcareWorkspace']['activeTab']['devices'])
                    ->keyBy('id');

                $this->assertSame('offline', $devices[$offline->id]['technical']['flow']['state']);
                $this->assertSame('integration_failure', $devices[$failed->id]['technical']['flow']['state']);
                $this->assertSame('stale_delivery', $devices[$stale->id]['technical']['flow']['state']);
                $this->assertSame('unsupported', $devices[$unsupported->id]['technical']['flow']['state']);
                $this->assertSame('healthy', $devices[$healthy->id]['technical']['flow']['state']);
                $this->assertSame([
                    'offline' => 1,
                    'integration_failure' => 1,
                    'stale_delivery' => 1,
                    'unsupported' => 1,
                    'healthy' => 1,
                ], collect($page->toArray()['props']['healthcareWorkspace']['activeTab']['flowGroups'])
                    ->mapWithKeys(fn (array $group) => [$group['state'] => $group['count']])
                    ->all());
            });
    }

    public function test_calibration_and_maintenance_are_reconciled_from_canonical_records(): void
    {
        $device = $this->healthcareDevice('Calibrated bed sensor', [
            'next_service_due' => now()->addDays(14),
        ]);
        $overdue = DeviceMaintenanceRecord::create([
            'device_id' => $device->id,
            'type' => 'calibration',
            'status' => 'scheduled',
            'description' => 'Annual calibration',
            'scheduled_for' => now()->subDay(),
            'vendor_reference' => 'CAL-100',
        ]);
        DeviceMaintenanceRecord::create([
            'device_id' => $device->id,
            'type' => 'firmware_update',
            'status' => 'scheduled',
            'description' => 'Supported firmware update',
            'scheduled_for' => now()->addDays(5),
        ]);
        DeviceMaintenanceRecord::create([
            'device_id' => $device->id,
            'type' => 'calibration',
            'status' => 'completed',
            'description' => 'Previous calibration',
            'completed_at' => now()->subYear(),
        ]);

        $this->actingAs($this->admin)
            ->get('/security-devices/healthcare?tab=calibration-maintenance')
            ->assertOk()
            ->assertInertia(function ($page) use ($device, $overdue): void {
                $healthcare = $page->toArray()['props']['healthcareWorkspace'];

                $this->assertSame(1, $healthcare['overview']['attention']['overdue_calibration']);
                $this->assertSame(2, $healthcare['overview']['attention']['maintenance_due']);
                $this->assertCount(3, $healthcare['activeTab']['maintenanceRecords']);
                $record = collect($healthcare['activeTab']['maintenanceRecords'])->firstWhere('id', $overdue->id);
                $this->assertSame($device->id, $record['device']['id']);
                $this->assertSame('calibration', $record['type']);
                $this->assertSame('Annual calibration', $record['description']);
                $this->assertSame('CAL-100', $record['vendorReference']);
                $this->assertTrue($record['overdue']);
            });
    }

    private function viewerWithRole(string $role): User
    {
        $viewer = User::factory()->create([
            'role' => $role,
            'approved_at' => now(),
        ]);
        $viewer->roles()->syncWithoutDetaching([
            Role::query()->where('name', $role)->firstOrFail()->id,
        ]);

        return $viewer;
    }

    private function healthcareDevice(string $name, array $attributes = []): Device
    {
        return Device::factory()->iotHealthcare()->create([
            'name' => $name,
            ...$attributes,
        ]);
    }

    private function assign(
        Device $device,
        string $type,
        int $id,
        string $assignmentType = 'permanent',
    ): void {
        DeviceAssignment::create([
            'device_id' => $device->id,
            'assignable_type' => $type,
            'assignable_id' => $id,
            'assignment_type' => $assignmentType,
            'assigned_at' => now(),
            'assigned_by_user_id' => $this->admin->id,
        ]);
    }
}
