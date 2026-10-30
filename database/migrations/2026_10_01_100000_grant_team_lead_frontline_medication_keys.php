<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * eMAR role baseline (decided 1 Oct 2026): every approved design package
 * (P01–P10) has house leads record doses, see and record controlled
 * medicines, and witness. On main the team_lead role held only
 * medications.view, orders.verify and witness_pin.reset, while support_worker
 * held the frontline keys. This gives team_lead the same five frontline keys.
 *
 * Competency, eligibility and the covering-shift check still decide who may
 * actually give a medicine; this only changes the role's capability set.
 *
 * Deploys don't run seeders, so this grants what RbacSeeder seeds. It only
 * adds: the permission rows already exist (support_worker holds them), other
 * roles and per-user overrides are left alone, and running it twice changes
 * nothing.
 *
 * down() removes these five keys from team_lead only. role_permission has no
 * provenance column, so a grant someone made by hand in Settings › Roles
 * before this ran can't be told apart from this one; on main the seeded
 * team_lead never held them.
 */
return new class extends Migration
{
    private const ROLE = 'team_lead';

    private const PERMISSIONS = [
        'medications.administer.record',
        'medications.administer.correct',
        'medications.controlled.view',
        'medications.controlled.record',
        'medications.controlled.witness',
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles') || ! Schema::hasTable('role_permission')) {
            return;
        }

        DB::transaction(function (): void {
            $roleId = DB::table('roles')->where('name', self::ROLE)->value('id');
            if ($roleId === null) {
                return;
            }

            foreach (DB::table('permissions')->whereIn('key', self::PERMISSIONS)->pluck('id') as $permissionId) {
                DB::table('role_permission')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles') || ! Schema::hasTable('role_permission')) {
            return;
        }

        DB::transaction(function (): void {
            $roleId = DB::table('roles')->where('name', self::ROLE)->value('id');
            if ($roleId === null) {
                return;
            }

            DB::table('role_permission')
                ->where('role_id', $roleId)
                ->whereIn('permission_id', DB::table('permissions')->whereIn('key', self::PERMISSIONS)->pluck('id'))
                ->delete();
        });
    }
};
