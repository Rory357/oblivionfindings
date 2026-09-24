<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Central fleet oversight (Stephan, 24 September 2026): a Fleet Manager sees
 * every vehicle and its profile across Sites without other Site access.
 * Bookings, trips, drivers and Finance keep their own Site rules.
 *
 * Deploys don't run seeders, so this grants what RbacSeeder seeds. The Fleet
 * Manager role was only referenced by earlier grants, never seeded: it is
 * created here with RbacSeeder::FLEET_MANAGER_PERMISSIONS when missing. A role
 * that already exists (perhaps customised) only gains the new permission.
 */
return new class extends Migration
{
    private const PERMISSION = 'fleet.vehicles.viewAllSites';

    private const DESCRIPTION = 'See every vehicle and its profile across all Sites; bookings, trips, drivers and Finance keep their own Site rules';

    /**
     * Kept in step with RbacSeeder::FLEET_MANAGER_PERMISSIONS. Keys whose
     * permission row doesn't exist yet are granted by their own migration
     * (fleet.settings.manage: 2026_09_24_000400).
     */
    private const FLEET_MANAGER_PERMISSIONS = [
        'fleet.viewAny', 'fleet.manage', 'fleet.vehicles.viewAllSites', 'fleet.settings.manage',
        'fleet.driverSessions.manage', 'fleet.signals.view', 'fleet.trips.manage',
        'fleet.fuel.manage', 'fleet.reports.view', 'fleet.bookings.approve',
        'fleet.incidents.manage', 'fleet.maintenance.manage', 'fleet.maintenance.report',
        'fleet.mileage.approve', 'fleet.outings.manage', 'assets.documents.manage',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles') || ! Schema::hasTable('role_permission')) {
            return;
        }

        DB::transaction(function (): void {
            $now = now();
            DB::table('permissions')->insertOrIgnore([
                'key' => self::PERMISSION,
                'description' => self::DESCRIPTION,
                'group' => 'fleet',
                'module' => 'Resources',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $fleetManagerId = DB::table('roles')->where('name', 'fleet_manager')->value('id');
            if ($fleetManagerId === null) {
                $role = [
                    'name' => 'fleet_manager',
                    'label' => 'Fleet Manager',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                foreach ([
                    'level' => 63,
                    'type' => 'system',
                    'description' => 'Manages the vehicle fleet across all Sites',
                ] as $column => $value) {
                    if (Schema::hasColumn('roles', $column)) {
                        $role[$column] = $value;
                    }
                }
                DB::table('roles')->insertOrIgnore($role);
                $fleetManagerId = DB::table('roles')->where('name', 'fleet_manager')->value('id');
                $this->grant($fleetManagerId, self::FLEET_MANAGER_PERMISSIONS);
            }

            foreach (DB::table('roles')->whereIn('name', ['admin', 'fleet_manager'])->pluck('id') as $roleId) {
                $this->grant($roleId, [self::PERMISSION]);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        // The Fleet Manager role and its other grants are left in place: people
        // may hold it by now, and RbacSeeder seeds it.
        DB::transaction(function (): void {
            $permissionId = DB::table('permissions')->where('key', self::PERMISSION)->value('id');
            if ($permissionId === null) {
                return;
            }
            if (Schema::hasTable('role_permission')) {
                DB::table('role_permission')->where('permission_id', $permissionId)->delete();
            }
            if (Schema::hasTable('permission_user')) {
                DB::table('permission_user')->where('permission_id', $permissionId)->delete();
            }
            DB::table('permissions')->where('id', $permissionId)->delete();
        });
    }

    /** @param list<string> $keys */
    private function grant(mixed $roleId, array $keys): void
    {
        if ($roleId === null) {
            return;
        }
        foreach (DB::table('permissions')->whereIn('key', $keys)->pluck('id') as $permissionId) {
            DB::table('role_permission')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ]);
        }
    }
};
