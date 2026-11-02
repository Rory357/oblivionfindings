<?php

namespace Tests\Unit\SecurityDevices;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Database\Seeders\SecurityDevicesPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SecurityDevicesPermissionsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * All permission keys that must exist after seeding.
     */
    private const ALL_KEYS = [
        'securityDevices.viewAny',
        'securityDevices.devices.view',
        'securityDevices.devices.viewAllSites',
        'securityDevices.devices.viewUnassigned',
        'securityDevices.devices.create',
        'securityDevices.devices.update',
        'securityDevices.devices.delete',
        'securityDevices.devices.assign',
        'securityDevices.groups.manage',
        'securityDevices.events.view',
        'securityDevices.cctv.media.view',
        'securityDevices.accessControl.view',
        'securityDevices.accessControl.manage',
        'securityDevices.maintenance.view',
        'securityDevices.maintenance.manage',
        'securityDevices.integrations.view',
        'securityDevices.integrations.manage',
        'securityDevices.monitoring.manage',
        'securityDevices.reports.view',
        'securityDevices.commands.observe',
        'securityDevices.commands.operate',
        'securityDevices.commands.manage',
        'securityDevices.commands.control',
        'securityDevices.commands.approve',
        'securityDevices.commands.admin',
    ];

    private const RESTRICTED_SCRIPT_KEYS = [
        'securityDevices.endpoints.scriptUpload',
        'securityDevices.endpoints.scriptReview',
        'securityDevices.endpoints.scriptPolicyManage',
        'securityDevices.endpoints.scriptRun',
        'securityDevices.endpoints.scriptUnattended',
        'securityDevices.endpoints.scriptOutputView',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // RbacSeeder creates roles. SecurityDevicesPermissionsSeeder creates
        // permissions and attaches them to roles.
        $this->seed(RbacSeeder::class);
        $this->seed(SecurityDevicesPermissionsSeeder::class);
    }

    // ── Permission keys exist ─────────────────────────────────────

    public function test_all_permission_keys_are_created(): void
    {
        foreach (self::ALL_KEYS as $key) {
            // Third arg is the connection name in modern Laravel; just rely on
            // the default assertion failure message instead.
            $this->assertDatabaseHas('permissions', ['key' => $key]);
        }
    }

    public function test_exactly_66_security_devices_permissions(): void
    {
        $count = Permission::where('key', 'like', 'securityDevices.%')->count();

        // 50 before the S&D rebuild, plus its 16 new securityDevices.* keys (SecurityDevicesRebuildPermissions,
        // granted by 2026_11_01_000100_grant_security_devices_rebuild_permissions).
        $this->assertEquals(66, $count);
    }

    public function test_permissions_have_correct_group_and_module(): void
    {
        $perms = Permission::where('key', 'like', 'securityDevices.%')->get();

        foreach ($perms as $perm) {
            $this->assertEquals('security_devices', $perm->group, "Permission '{$perm->key}' has wrong group.");
            $this->assertEquals('Security & Devices', $perm->module, "Permission '{$perm->key}' has wrong module.");
        }
    }

    // ── Admin gets full set ───────────────────────────────────────

    public function test_admin_has_all_security_devices_permissions(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('name', 'admin')->first());

        foreach (self::ALL_KEYS as $key) {
            $this->assertTrue($admin->canDo($key), "Admin should have '{$key}'.");
        }
        foreach (self::RESTRICTED_SCRIPT_KEYS as $key) {
            $this->assertFalse($admin->canDo($key), "Admin must not receive restricted script permission '{$key}' by default.");
        }
    }

    // ── IT Manager gets full set ──────────────────────────────────

    public function test_it_manager_has_all_security_devices_permissions(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', 'it_manager')->first());

        foreach (self::ALL_KEYS as $key) {
            $this->assertTrue($user->canDo($key), "IT Manager should have '{$key}'.");
        }
        foreach (self::RESTRICTED_SCRIPT_KEYS as $key) {
            $this->assertFalse($user->canDo($key), "IT Manager must not receive restricted script permission '{$key}' by default.");
        }

        $this->assertTrue($user->canDo('it.view'));
        $this->assertTrue($user->canDo('it.manage'));
        $this->assertFalse($user->canDo('it.viewSensitive'));
        $this->assertFalse($user->canDo('it.organisationWide'));
    }

    // ── Provider Manager gets scoped subset ───────────────────────

    public function test_provider_manager_has_correct_subset(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', 'provider_manager')->first());

        $expected = [
            'securityDevices.viewAny',
            'securityDevices.devices.view',
            'securityDevices.devices.assign',
            'securityDevices.events.view',
            'securityDevices.maintenance.view',
            'securityDevices.reports.view',
            'securityDevices.commands.observe',
            'securityDevices.commands.operate',
            'securityDevices.accessControl.view',
        ];
        $denied = [
            'securityDevices.devices.create',
            'securityDevices.devices.update',
            'securityDevices.devices.delete',
            'securityDevices.groups.manage',
            'securityDevices.maintenance.manage',
            'securityDevices.integrations.view',
            'securityDevices.integrations.manage',
            'securityDevices.monitoring.manage',
            'securityDevices.commands.manage',
            'securityDevices.commands.control',
            'securityDevices.commands.approve',
            'securityDevices.commands.admin',
            'securityDevices.accessControl.manage',
        ];

        foreach ($expected as $key) {
            $this->assertTrue($user->canDo($key), "Provider Manager should have '{$key}'.");
        }
        foreach ($denied as $key) {
            $this->assertFalse($user->canDo($key), "Provider Manager should NOT have '{$key}'.");
        }
    }

    // ── Support Worker gets minimal read-only ─────────────────────

    public function test_support_worker_has_minimal_permissions(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', 'support_worker')->first());

        $expected = [
            'securityDevices.viewAny',
            'securityDevices.devices.view',
        ];
        $denied = [
            'securityDevices.devices.create',
            'securityDevices.devices.update',
            'securityDevices.devices.delete',
            'securityDevices.devices.assign',
            'securityDevices.groups.manage',
            'securityDevices.events.view',
            'securityDevices.maintenance.view',
            'securityDevices.maintenance.manage',
            'securityDevices.integrations.view',
            'securityDevices.integrations.manage',
            'securityDevices.monitoring.manage',
            'securityDevices.reports.view',
            'securityDevices.commands.observe',
            'securityDevices.commands.operate',
            'securityDevices.commands.manage',
            'securityDevices.commands.control',
            'securityDevices.commands.approve',
            'securityDevices.commands.admin',
            'securityDevices.accessControl.view',
            'securityDevices.accessControl.manage',
        ];

        foreach ($expected as $key) {
            $this->assertTrue($user->canDo($key), "Support Worker should have '{$key}'.");
        }
        foreach ($denied as $key) {
            $this->assertFalse($user->canDo($key), "Support Worker should NOT have '{$key}'.");
        }
    }

    // ── Coordinator gets mid-level read access ────────────────────

    public function test_coordinator_has_correct_subset(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', 'coordinator')->first());

        $expected = [
            'securityDevices.viewAny',
            'securityDevices.devices.view',
            'securityDevices.events.view',
            'securityDevices.maintenance.view',
            'securityDevices.commands.observe',
        ];
        $denied = [
            'securityDevices.devices.create',
            'securityDevices.devices.delete',
            'securityDevices.groups.manage',
            'securityDevices.integrations.manage',
            'securityDevices.monitoring.manage',
            'securityDevices.commands.operate',
            'securityDevices.commands.manage',
            'securityDevices.commands.control',
            'securityDevices.commands.approve',
            'securityDevices.commands.admin',
            'securityDevices.accessControl.view',
            'securityDevices.accessControl.manage',
        ];

        foreach ($expected as $key) {
            $this->assertTrue($user->canDo($key), "Coordinator should have '{$key}'.");
        }
        foreach ($denied as $key) {
            $this->assertFalse($user->canDo($key), "Coordinator should NOT have '{$key}'.");
        }
    }

    // ── Facilities Manager gets broad operational access ───────────

    public function test_facilities_manager_has_correct_subset(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', 'facilities_manager')->first());

        $expected = [
            'securityDevices.viewAny',
            'securityDevices.devices.view',
            'securityDevices.devices.create',
            'securityDevices.devices.update',
            'securityDevices.devices.assign',
            'securityDevices.maintenance.view',
            'securityDevices.maintenance.manage',
            'securityDevices.integrations.view',
            'securityDevices.reports.view',
            'securityDevices.commands.observe',
            'securityDevices.commands.operate',
            'securityDevices.commands.manage',
            'securityDevices.accessControl.view',
            'securityDevices.accessControl.manage',
        ];
        $denied = [
            'securityDevices.devices.delete',
            'securityDevices.groups.manage',
            'securityDevices.integrations.manage',
            'securityDevices.monitoring.manage',
            'securityDevices.commands.control',
            'securityDevices.commands.approve',
            'securityDevices.commands.admin',
        ];

        foreach ($expected as $key) {
            $this->assertTrue($user->canDo($key), "Facilities Manager should have '{$key}'.");
        }
        foreach ($denied as $key) {
            $this->assertFalse($user->canDo($key), "Facilities Manager should NOT have '{$key}'.");
        }
    }

    // ── Auditor gets read-only reporting access ───────────────────

    public function test_auditor_has_correct_subset(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', 'auditor')->first());

        $expected = [
            'securityDevices.viewAny',
            'securityDevices.devices.view',
            'securityDevices.events.view',
            'securityDevices.reports.view',
            'securityDevices.commands.observe',
            'securityDevices.accessControl.view',
        ];
        $denied = [
            'securityDevices.devices.create',
            'securityDevices.devices.update',
            'securityDevices.devices.delete',
            'securityDevices.devices.assign',
            'securityDevices.maintenance.manage',
            'securityDevices.integrations.manage',
            'securityDevices.monitoring.manage',
            'securityDevices.commands.operate',
            'securityDevices.commands.manage',
            'securityDevices.commands.control',
            'securityDevices.commands.approve',
            'securityDevices.commands.admin',
            'securityDevices.accessControl.manage',
        ];

        foreach ($expected as $key) {
            $this->assertTrue($user->canDo($key), "Auditor should have '{$key}'.");
        }
        foreach ($denied as $key) {
            $this->assertFalse($user->canDo($key), "Auditor should NOT have '{$key}'.");
        }
    }

    // ── Auditors read alerts but never act on them (Stephan, 2 Nov: "Leave auditors out") ──

    public function test_alerts_acknowledge_leaves_auditors_out_in_the_seeder_and_the_grant_migration(): void
    {
        $acknowledge = 'securityDevices.alerts.acknowledge';
        $auditorRole = Role::where('name', 'auditor')->firstOrFail();
        $auditor = User::factory()->create();
        $auditor->roles()->attach($auditorRole);
        $coordinator = User::factory()->create();
        $coordinator->roles()->attach(Role::where('name', 'coordinator')->firstOrFail());

        $this->assertTrue($auditor->fresh()->canDo('securityDevices.events.view'));
        $this->assertFalse($auditor->fresh()->canDo($acknowledge), 'The seeder gave auditors alerts.acknowledge.');
        $this->assertTrue($coordinator->fresh()->canDo($acknowledge), 'Coordinators keep alerts.acknowledge.');

        // The deploy path: the grant migration on a database that has no acknowledge grants yet.
        $permissionId = Permission::where('key', $acknowledge)->value('id');
        DB::table('role_permission')->where('permission_id', $permissionId)->delete();
        $eventsView = Permission::where('key', 'securityDevices.events.view')->value('id');
        $auditorWithOverride = User::factory()->create();
        $auditorWithOverride->roles()->attach($auditorRole);
        $plainWithOverride = User::factory()->create();
        foreach ([$auditorWithOverride, $plainWithOverride] as $person) {
            DB::table('permission_user')->insert(['user_id' => $person->id, 'permission_id' => $eventsView, 'allowed' => true]);
        }

        (require base_path('database/migrations/2026_11_01_000100_grant_security_devices_rebuild_permissions.php'))->up();

        $this->assertFalse(DB::table('role_permission')->where('role_id', $auditorRole->id)->where('permission_id', $permissionId)->exists());
        $this->assertFalse($auditor->fresh()->canDo($acknowledge), 'The grant migration gave the auditor role alerts.acknowledge.');
        $this->assertFalse($auditorWithOverride->fresh()->canDo($acknowledge), 'An auditor got alerts.acknowledge through a copied override.');
        $this->assertTrue($coordinator->fresh()->canDo($acknowledge), 'The grant migration left coordinators out.');
        $this->assertTrue($plainWithOverride->fresh()->canDo($acknowledge), 'A personal events.view allow should still be copied.');
    }

    // ── Idempotent re-run ─────────────────────────────────────────

    public function test_seeder_is_idempotent(): void
    {
        $countBefore = Permission::where('key', 'like', 'securityDevices.%')->count();

        // Run a second time.
        $this->seed(SecurityDevicesPermissionsSeeder::class);

        $countAfter = Permission::where('key', 'like', 'securityDevices.%')->count();

        $this->assertEquals($countBefore, $countAfter, 'Re-running the seeder should not create duplicates.');
    }

    // ── Route protection ──────────────────────────────────────────

    public function test_unauthenticated_user_cannot_access_security_devices(): void
    {
        $this->get('/security-devices')->assertRedirect('/login');
    }

    public function test_user_without_permission_gets_403(): void
    {
        $user = User::factory()->create();
        // No roles, no permissions.

        $this->actingAs($user)
            ->get('/security-devices')
            ->assertForbidden();
    }

    public function test_user_with_view_any_can_access_index(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', 'support_worker')->first());

        $this->actingAs($user)
            ->get('/security-devices')
            ->assertOk();
    }

    public function test_reports_route_requires_reports_permission(): void
    {
        // Support worker has viewAny but not reports.view.
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', 'support_worker')->first());

        $this->actingAs($user)
            ->get('/security-devices/reports')
            ->assertForbidden();
    }

    public function test_reports_route_accessible_with_reports_permission(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', 'auditor')->first());

        $this->actingAs($user)
            ->get('/security-devices/reports')
            ->assertOk();
    }

    public function test_maintenance_route_requires_maintenance_view(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', 'support_worker')->first());

        $this->actingAs($user)
            ->get('/security-devices/maintenance-health')
            ->assertForbidden();
    }

    public function test_alerts_events_route_requires_events_view(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', 'support_worker')->first());

        $this->actingAs($user)
            ->get('/security-devices/alerts-events')
            ->assertForbidden();
    }

    public function test_legacy_category_pages_redirect_with_module_and_explicit_all_sites_access(): void
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('name', 'support_worker')->first());
        $allSitesPermission = Permission::query()
            ->where('key', 'securityDevices.devices.viewAllSites')
            ->firstOrFail();
        $user->permissionOverrides()->attach($allSitesPermission->id, ['allowed' => true]);

        $categoryRoutes = [
            '/security-devices/alarms',
            '/security-devices/cctv',
            '/security-devices/tracking-devices',
            '/security-devices/smart-iot-healthcare',
            '/security-devices/access-control',
        ];

        foreach ($categoryRoutes as $route) {
            $this->actingAs($user)
                ->get($route)
                ->assertRedirect();
        }
    }
}
