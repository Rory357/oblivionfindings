<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Q21 (Stephan, 2 Nov 2026, by pop-up via MASTER): three read grants, all additive and idempotent.
 *
 * 1. team_lead + incidents.viewAny — "Own Site only". The incident list and incident pages at the team lead's own
 *    Sites. incidents.viewAny is limited to the viewer's Sites by IncidentAccessService (team leads hold no all-Sites
 *    grant) and never writes: team leads still hold no incidents.update, owner, review, close or approve key.
 * 2. provider_manager + restraints.view — "Yes, their Sites". RestraintAccessService limits reads to the manager's
 *    own Sites (only healthSafety.viewAllSites widens it; sites.viewAll does not). Review and approval stay on the
 *    existing restraints.review / approve / manage keys, unchanged.
 * 3. compliance_lead + incidents.viewAny + incidents.create — "Read + report". A read-only incident list and pages for
 *    audits (their existing healthSafety.viewAllSites decides the Sites), and reporting like any staff member. No owner
 *    assign, edit, close or approve key is added.
 *
 * Access-widening: lands with RoleQ21AccessTest and a merge-request line naming each key. down() removes only these
 * grants; the permission catalogue rows already exist and stay.
 */
return new class extends Migration
{
    private const GRANTS = [
        'team_lead' => ['incidents.viewAny'],
        'provider_manager' => ['restraints.view'],
        'compliance_lead' => ['incidents.viewAny', 'incidents.create'],
    ];

    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('role_permission') || ! Schema::hasTable('roles')) {
            return;
        }
        foreach (self::GRANTS as $roleName => $keys) {
            $role = DB::table('roles')->where('name', $roleName)->value('id');
            if ($role === null) {
                continue;
            }
            foreach ($keys as $key) {
                $permission = DB::table('permissions')->where('key', $key)->value('id');
                if ($permission !== null) {
                    DB::table('role_permission')->insertOrIgnore(['role_id' => $role, 'permission_id' => $permission]);
                }
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('role_permission') || ! Schema::hasTable('roles')) {
            return;
        }
        foreach (self::GRANTS as $roleName => $keys) {
            $role = DB::table('roles')->where('name', $roleName)->value('id');
            if ($role === null) {
                continue;
            }
            $permissions = DB::table('permissions')->whereIn('key', $keys)->pluck('id');
            DB::table('role_permission')->where('role_id', $role)->whereIn('permission_id', $permissions)->delete();
        }
    }
};
