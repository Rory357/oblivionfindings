<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Decision 4 (Stephan, 1 Nov 2026; G4 board G4-5): support workers can own restraint follow-ups, such as telling the
 * person's family/whānau. One new, narrow key granted to support_worker only. It shows them ONLY the follow-ups they own
 * (the follow-up's words, when it's due, its outcome) on "Your follow-ups", and lets them record the outcome on their own
 * follow-up. It never opens the restraint record, its plan or its other follow-ups, and current Site and person access
 * still apply (RestraintAccessService, RestraintOwnFollowupService).
 *
 * Access-widening: lands with its own tests (RestraintOwnFollowupTest). Additive and idempotent. down() removes only
 * support_worker's grant; the catalogue row stays (as other key migrations do).
 */
return new class extends Migration
{
    private const KEY = 'restraints.followups.own';

    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('role_permission')) {
            return;
        }
        DB::table('permissions')->insertOrIgnore([
            'key' => self::KEY,
            'description' => 'Own a restraint follow-up (such as telling the family/whānau) and record its outcome — only your own follow-ups, never the restraint record',
            'group' => 'restraints',
            'module' => 'Compliance',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $role = DB::table('roles')->where('name', 'support_worker')->value('id');
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
        $role = DB::table('roles')->where('name', 'support_worker')->value('id');
        $permission = DB::table('permissions')->where('key', self::KEY)->value('id');
        if ($role !== null && $permission !== null) {
            DB::table('role_permission')->where('role_id', $role)->where('permission_id', $permission)->delete();
        }
    }
};
