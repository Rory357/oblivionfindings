<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Self-service IT ticketing: the new it.request key ("raise and track your
 * own IT tickets") must reach every STAFF role — the whole point is that a
 * support worker whose phone dies mid-shift can raise a ticket themselves.
 *
 * Deploys run migrations and skip seeders (house rule), so this mirrors the
 * idempotent grant pattern of 2026_07_02_100002_grant_it_permissions. Unlike
 * that migration the grantee list is dynamic — every role EXCEPT the external
 * portal personas (client, next_of_kin), who are not staff and must never see
 * the internal helpdesk.
 */
return new class extends Migration
{
    private const EXCLUDED_ROLES = ['client', 'next_of_kin'];

    public function up(): void
    {
        $permId = DB::table('permissions')->where('key', 'it.request')->value('id');

        if (! $permId) {
            $row = [
                'key' => 'it.request',
                'description' => 'Raise and track your own IT tickets',
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if (Schema::hasColumn('permissions', 'group')) {
                $row['group'] = 'it';
            }
            if (Schema::hasColumn('permissions', 'module')) {
                $row['module'] = 'Operations';
            }
            $permId = DB::table('permissions')->insertGetId($row);
        }

        $roleIds = DB::table('roles')
            ->whereNotIn('name', self::EXCLUDED_ROLES)
            ->pluck('id');

        foreach ($roleIds as $roleId) {
            $exists = DB::table('role_permission')
                ->where('role_id', $roleId)
                ->where('permission_id', $permId)
                ->exists();
            if (! $exists) {
                DB::table('role_permission')->insert([
                    'role_id' => $roleId,
                    'permission_id' => $permId,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Only detach the grants; the permission row is owned by RbacSeeder
        // once seeded and may be referenced elsewhere.
        $permId = DB::table('permissions')->where('key', 'it.request')->value('id');

        if ($permId) {
            DB::table('role_permission')->where('permission_id', $permId)->delete();
        }
    }
};
