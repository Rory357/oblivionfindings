<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Answer 23 (Stephan, 1 Nov 2026): on safety reviews that come from an incident, where a team lead reads only the story
 * (Q1), the team lead may still be given an ACTION and start it, finish it and add files to it. Never owner of the
 * review, never the WorkSafe decision, never checking an action, never closing. One new, narrow key granted to
 * team_lead only.
 *
 * Access-widening: lands with its own tests (HsStoryReaderActionOwnershipTest) and a merge-request line naming the key.
 * Additive and idempotent. down() removes only team_lead's grant.
 */
return new class extends Migration
{
    private const KEY = 'healthSafety.actions.ownOnStoryReviews';

    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('role_permission')) {
            return;
        }
        DB::table('permissions')->insertOrIgnore([
            'key' => self::KEY,
            'description' => 'Own, start and finish an action on a safety review whose incident story you can read (never the review itself)',
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
