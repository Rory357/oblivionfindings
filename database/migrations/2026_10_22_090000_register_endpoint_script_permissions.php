<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Registers the approved Windows script permissions. Deploys run migrations
 * but not seeders, so without this the keys only existed where someone ran
 * EndpointScriptPermissionsSeeder by hand, and Settings › Roles had nothing
 * to grant.
 *
 * Deliberately no role grants: uploading, reviewing, running scripts and
 * reading their output are assigned to named people. The keys are listed in
 * RbacSeeder::RESTRICTED_INDEPENDENT_AUTHORITY, and this migration also
 * removes them from the admin role in case an older backfill handed them out.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'securityDevices.endpoints.scriptUpload' => 'Upload immutable Windows scripts for review',
        'securityDevices.endpoints.scriptReview' => 'Independently review or withdraw immutable Windows scripts',
        'securityDevices.endpoints.scriptPolicyManage' => 'Configure computer script policy',
        'securityDevices.endpoints.scriptRun' => 'Request independently approved computer scripts',
        'securityDevices.endpoints.scriptUnattended' => 'Use unattended computer script policy',
        'securityDevices.endpoints.scriptOutputView' => 'Read private retained computer script output',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        $now = now();
        DB::table('permissions')->insertOrIgnore(array_map(
            fn (string $key, string $description): array => [
                'key' => $key,
                'description' => $description,
                'group' => 'security_devices',
                'module' => 'Security & Devices',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            array_keys(self::PERMISSIONS),
            self::PERMISSIONS,
        ));

        $adminId = Schema::hasTable('roles') ? DB::table('roles')->where('name', 'admin')->value('id') : null;
        if ($adminId && Schema::hasTable('role_permission')) {
            $permissionIds = DB::table('permissions')->whereIn('key', array_keys(self::PERMISSIONS))->pluck('id');
            DB::table('role_permission')->where('role_id', $adminId)->whereIn('permission_id', $permissionIds)->delete();
        }
    }

    public function down(): void
    {
        // Preserve the permission identities and any explicit assignments.
    }
};
