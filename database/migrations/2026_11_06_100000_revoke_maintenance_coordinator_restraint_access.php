<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Readiness audit B4 (Stephan, 6 Nov 2026, by pop-up via MASTER: "Remove it"). Maintenance coordinators got
 * restraints.view only because 2026_06_20_000002 copied it onto every role holding hazards.view. That opened the
 * restraint register (restrictive practice on named clients) and behaviour support plans to them, which nobody
 * decided. This removes every restraint register key from the role; RbacSeeder no longer copies them back.
 *
 * Kept: restraints.report and restraints.followups.own (the role never had them; reporting is not register access).
 * down() gives back only restraints.view, the one key the role held before.
 */
return new class extends Migration
{
    private const ROLE = 'maintenance_coordinator';

    private const KEYS = ['restraints.view', 'restraints.create', 'restraints.manage', 'restraints.review', 'restraints.approve'];

    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('role_permission') || ! Schema::hasTable('roles')) {
            return;
        }
        $role = DB::table('roles')->where('name', self::ROLE)->value('id');
        if ($role === null) {
            return;
        }
        $permissions = DB::table('permissions')->whereIn('key', self::KEYS)->pluck('id');
        DB::table('role_permission')->where('role_id', $role)->whereIn('permission_id', $permissions)->delete();
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('role_permission') || ! Schema::hasTable('roles')) {
            return;
        }
        $role = DB::table('roles')->where('name', self::ROLE)->value('id');
        $permission = DB::table('permissions')->where('key', 'restraints.view')->value('id');
        if ($role !== null && $permission !== null) {
            DB::table('role_permission')->insertOrIgnore(['role_id' => $role, 'permission_id' => $permission]);
        }
    }
};
