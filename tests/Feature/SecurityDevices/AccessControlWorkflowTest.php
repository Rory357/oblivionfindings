<?php

namespace Tests\Feature\SecurityDevices;

use App\Domain\Hr\Models\HrEmployeeProfile;
use App\Domain\SecurityDevices\AccessControl\Models\AccessControlCredential;
use App\Domain\SecurityDevices\AccessControl\Models\AccessControlSchedule;
use App\Domain\SecurityDevices\Models\Device;
use App\Domain\SecurityDevices\Models\DeviceAssignment;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Site;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AccessControlWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([RbacSeeder::class, SecurityDevicesPermissionsSeeder::class]);

        $this->admin = $this->userWithRole('admin');
    }

    public function test_manager_can_create_schedule_issue_and_revoke_safe_credential_metadata_with_history(): void
    {
        $site = Site::factory()->create(['name' => 'Harbour House']);
        $holder = User::factory()->create(['name' => 'Taylor Worker', 'approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $holder->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
        ]);
        $reader = $this->accessDeviceAt($site, 'Staff entrance reader');

        $this->actingAs($this->admin)->post('/security-devices/access-control/schedules', [
            'site_id' => $site->id,
            'name' => 'Weekday staff access',
            'days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'],
            'starts_at' => '08:00',
            'ends_at' => '18:00',
        ])->assertRedirect();

        $schedule = AccessControlSchedule::query()->firstOrFail();
        $this->actingAs($this->admin)->post('/security-devices/access-control/credentials', [
            'site_id' => $site->id,
            'access_schedule_id' => $schedule->id,
            'label' => 'Taylor weekday badge',
            'holder_type' => 'staff',
            'holder_id' => $holder->id,
            'reference_key' => 'unifi:credential/taylor-001',
            'device_ids' => [$reader->id],
            'valid_from' => now()->startOfDay()->toIso8601String(),
            'valid_until' => now()->addYear()->toIso8601String(),
        ])->assertRedirect();

        $credential = AccessControlCredential::query()->with('devices')->firstOrFail();
        $this->assertSame([$reader->id], $credential->devices->modelKeys());
        $this->assertSame('unifi:credential/taylor-001', $credential->reference_key);
        $this->assertFalse(Schema::hasColumn('access_control_credentials', 'card_number'));
        $this->assertFalse(Schema::hasColumn('access_control_credentials', 'pin'));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'access_control.schedule.created',
            'auditable_type' => AccessControlSchedule::class,
            'auditable_id' => $schedule->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'access_control.credential.issued',
            'auditable_type' => AccessControlCredential::class,
            'auditable_id' => $credential->id,
        ]);

        $this->actingAs($this->admin)
            ->get('/security-devices/security?tab=access-control')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('securityWorkspace.activeTab.accessControl.restricted', false)
                ->where('securityWorkspace.activeTab.accessControl.summary.activeCredentials', 1)
                ->where('securityWorkspace.activeTab.accessControl.summary.activeSchedules', 1)
                ->where('securityWorkspace.activeTab.accessControl.summary.coveredDoors', 1)
                ->where('securityWorkspace.activeTab.accessControl.credentials.0.holderLabel', 'Taylor Worker')
                ->where('securityWorkspace.activeTab.accessControl.credentials.0.referenceKey', 'unifi:credential/taylor-001')
                ->where('securityWorkspace.activeTab.accessControl.history.0.action', 'Credential issued'));

        $this->actingAs($this->admin)->post(
            "/security-devices/access-control/credentials/{$credential->id}/revoke",
            ['reason' => 'Employment ended'],
        )->assertRedirect();

        $this->assertDatabaseHas('access_control_credentials', [
            'id' => $credential->id,
            'status' => 'revoked',
            'revocation_reason' => 'Employment ended',
            'revoked_by_user_id' => $this->admin->id,
        ]);
        $this->assertSame(1, AuditLog::query()
            ->where('action', 'access_control.credential.revoked')
            ->where('auditable_id', $credential->id)
            ->count());
    }

    public function test_site_scoped_manager_cannot_use_or_revoke_other_site_records(): void
    {
        $allowedSite = Site::factory()->create();
        $otherSite = Site::factory()->create();
        $manager = $this->userWithRole('facilities_manager');
        HrEmployeeProfile::factory()->create([
            'user_id' => $manager->id,
            'primary_site_id' => $allowedSite->id,
            'secondary_site_ids' => [],
            'is_active' => true,
        ]);
        $holder = User::factory()->create(['approved_at' => now()]);
        HrEmployeeProfile::factory()->create([
            'user_id' => $holder->id,
            'primary_site_id' => $otherSite->id,
            'secondary_site_ids' => [],
            'is_active' => true,
        ]);
        $otherDevice = $this->accessDeviceAt($otherSite, 'Other Site reader');
        $schedule = AccessControlSchedule::query()->create([
            'site_id' => $otherSite->id,
            'name' => 'Other schedule',
            'timezone' => 'Pacific/Auckland',
            'days' => ['monday'],
            'starts_at' => '08:00',
            'ends_at' => '18:00',
            'is_active' => true,
        ]);
        $credential = AccessControlCredential::query()->create([
            'site_id' => $otherSite->id,
            'access_schedule_id' => $schedule->id,
            'label' => 'Other credential',
            'holder_type' => 'staff',
            'holder_id' => $holder->id,
            'reference_key' => 'unifi:credential/other-001',
            'status' => 'active',
        ]);
        $credential->devices()->attach($otherDevice->id);

        $this->actingAs($manager)->post(
            "/security-devices/access-control/credentials/{$credential->id}/revoke",
            ['reason' => 'Attempted cross-Site access'],
        )->assertNotFound();

        $this->actingAs($manager)->post('/security-devices/access-control/credentials', [
            'site_id' => $allowedSite->id,
            'access_schedule_id' => $schedule->id,
            'label' => 'Invalid cross-Site credential',
            'holder_type' => 'staff',
            'holder_id' => $holder->id,
            'reference_key' => 'unifi:credential/invalid-001',
            'device_ids' => [$otherDevice->id],
        ])->assertNotFound();

        $this->assertSame('active', $credential->fresh()->status);
    }

    public function test_general_device_view_does_not_reveal_or_mutate_physical_access_records(): void
    {
        $site = Site::factory()->create();
        $worker = $this->userWithRole('support_worker');
        HrEmployeeProfile::factory()->create([
            'user_id' => $worker->id,
            'primary_site_id' => $site->id,
            'secondary_site_ids' => [],
            'is_active' => true,
        ]);
        $this->accessDeviceAt($site, 'Visible reader');

        $this->actingAs($worker)
            ->get('/security-devices/security?tab=access-control')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('securityWorkspace.activeTab.inventoryTotal', 1)
                ->where('securityWorkspace.activeTab.accessControl.restricted', true)
                ->where('securityWorkspace.activeTab.accessControl.credentials', [])
                ->where('securityWorkspace.activeTab.accessControl.history', []));

        $this->actingAs($worker)->post('/security-devices/access-control/schedules', [
            'site_id' => $site->id,
            'name' => 'Unauthorised schedule',
            'days' => ['monday'],
            'starts_at' => '08:00',
            'ends_at' => '18:00',
        ])->assertForbidden();
    }

    public function test_credential_projection_rechecks_current_device_site_visibility(): void
    {
        $allowedSite = Site::factory()->create();
        $otherSite = Site::factory()->create();
        $manager = $this->userWithRole('facilities_manager');
        HrEmployeeProfile::factory()->create([
            'user_id' => $manager->id,
            'primary_site_id' => $allowedSite->id,
            'secondary_site_ids' => [],
            'is_active' => true,
        ]);
        $device = $this->accessDeviceAt($allowedSite, 'Reader moved elsewhere');
        $schedule = AccessControlSchedule::query()->create([
            'site_id' => $allowedSite->id,
            'name' => 'Current schedule',
            'timezone' => 'Pacific/Auckland',
            'days' => ['monday'],
            'starts_at' => '08:00',
            'ends_at' => '18:00',
            'is_active' => true,
        ]);
        $credential = AccessControlCredential::query()->create([
            'site_id' => $allowedSite->id,
            'access_schedule_id' => $schedule->id,
            'label' => 'Credential needing review',
            'holder_type' => 'staff',
            'holder_id' => $manager->id,
            'reference_key' => 'unifi:credential/review-001',
            'status' => 'active',
        ]);
        $credential->devices()->attach($device->id);

        $device->assignments()->active()->update(['released_at' => now()]);
        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $otherSite->id,
            'assignment_type' => 'permanent',
            'assigned_at' => now(),
        ]);

        $this->actingAs($manager)
            ->get('/security-devices/security?tab=access-control')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('securityWorkspace.activeTab.accessControl.summary.activeCredentials', 1)
                ->where('securityWorkspace.activeTab.accessControl.summary.coveredDoors', 0)
                ->where('securityWorkspace.activeTab.accessControl.credentials.0.id', $credential->id)
                ->where('securityWorkspace.activeTab.accessControl.credentials.0.devices', [])
                ->where('securityWorkspace.activeTab.accessControl.deviceOptions', []));
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['approved_at' => now()]);
        $user->roles()->attach(Role::query()->where('name', $role)->firstOrFail());

        return $user;
    }

    private function accessDeviceAt(Site $site, string $name): Device
    {
        $device = Device::factory()->create([
            'domain' => 'security',
            'category' => 'access_control',
            'subcategory' => 'card_reader',
            'name' => $name,
        ]);
        DeviceAssignment::query()->create([
            'device_id' => $device->id,
            'assignable_type' => DeviceAssignment::TARGET_SITE,
            'assignable_id' => $site->id,
            'assignment_type' => 'permanent',
            'assigned_at' => now(),
        ]);

        return $device;
    }
}
