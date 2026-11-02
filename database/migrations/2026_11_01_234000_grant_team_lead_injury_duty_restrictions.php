<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Decision 10 (Stephan, 1 Nov 2026; G4 board G4-8): a team lead coordinating someone's return to work sees their duty
 * restrictions — what they can and can't do at work, until when, and who set them — never the medical reason, treatment,
 * notes, fitness checks, ACC claim number or medical files. One new, narrow read key granted to team_lead only, and it
 * only shows on an injury the person coordinates and can already open (InjuryWorkspaceService::dutyRestrictions).
 *
 * Access-widening (read): lands with its own tests (InjuryDutyRestrictionsTest). Additive and idempotent. down() removes
 * only team_lead's grant; the catalogue row stays (as other key migrations do).
 */
return new class extends Migration
{
    private const KEY = 'injuries.dutyRestrictions.view';

    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('role_permission')) {
            return;
        }
        DB::table('permissions')->insertOrIgnore([
            'key' => self::KEY,
            'description' => 'See the duty restrictions (never medical details) for a workplace injury you coordinate',
            'group' => 'hazards',
            'module' => 'Compliance',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $role = DB::table('roles')->where('name', 'team_lead')->value('id');
        $permission = DB::table('permissions')->where('key', self::KEY)->value('id');
        if ($role !== null && $permission !== null) {
            DB::table('role_permission')->insertOrIgnore(['role_id' => $role, 'permission_id' => $permission]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('role_permission')) {
            return;
        }
        $role = DB::table('roles')->where('name', 'team_lead')->value('id');
        $permission = DB::table('permissions')->where('key', self::KEY)->value('id');
        if ($role !== null && $permission !== null) {
            DB::table('role_permission')->where('role_id', $role)->where('permission_id', $permission)->delete();
        }
    }
};
