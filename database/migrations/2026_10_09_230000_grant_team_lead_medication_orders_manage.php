<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * eMAR Lane C4 (EA-054), decided by Stephan on 9 Oct ("apply both"): house
 * leads (team_lead) enter order changes in Orders. The P04 amendment of
 * 1 Oct approved it; it was never applied.
 *
 * Every entry still gets P04's independent check by someone else with
 * medications.orders.verify (MedicationOrderWorkflow refuses the person who
 * entered or witnessed the version). The approved lone check keeps its
 * reason and its next-day second check.
 *
 * Deploys run migrations and skip seeders, so this is the grant. It only
 * adds the one row; other roles and per-user overrides are left alone, and
 * a second run changes nothing. A grant an admin later removes in
 * Settings › Roles stays removed: this migration runs once.
 *
 * Reseed note: RbacSeeder syncs team_lead to its own list, so a reseed
 * drops this grant until the seeder names the key (reported to Main; the
 * seeder is a collision file).
 */
return new class extends Migration
{
    private const ROLE = 'team_lead';

    private const PERMISSION = 'medications.orders.manage';

    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles') || ! Schema::hasTable('role_permission')) {
            return;
        }

        DB::transaction(function (): void {
            $roleId = DB::table('roles')->where('name', self::ROLE)->value('id');
            $permissionId = DB::table('permissions')->where('key', self::PERMISSION)->value('id');
            if ($roleId === null || $permissionId === null) {
                return;
            }

            DB::table('role_permission')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
        });
    }

    /**
     * Removes the team_lead grant only. role_permission has no provenance,
     * so a hand-made grant from before this ran can't be told apart; the
     * seeded team_lead never held this key.
     */
    public function down(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles') || ! Schema::hasTable('role_permission')) {
            return;
        }

        DB::transaction(function (): void {
            $roleId = DB::table('roles')->where('name', self::ROLE)->value('id');
            $permissionId = DB::table('permissions')->where('key', self::PERMISSION)->value('id');
            if ($roleId === null || $permissionId === null) {
                return;
            }

            DB::table('role_permission')->where('role_id', $roleId)->where('permission_id', $permissionId)->delete();
        });
    }
};
