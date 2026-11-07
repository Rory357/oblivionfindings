<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Q1 (Stephan, 31 Oct; G3 boards approved 1 Nov 2026): a team lead reads an incident's "What happened" and "What we
 * did straight away" on the safety review at their own Sites — and nothing else from the incident. One new, narrow key
 * granted to team_lead only. It never counts as reading the incident: it gives no incident page, no incident list, no
 * ownership, WorkSafe decision, check or close on the review, and it never covers HR-confidential, safeguarding,
 * restricted or independently handled incidents (HsIncidentStoryReadService).
 *
 * Access-widening: lands with its own tests (HsIncidentStoryReadTest) and a merge-request line naming the key.
 * Additive and idempotent. down() removes only team_lead's grant; the catalogue row stays (as other key migrations do).
 */
return new class extends Migration
{
    private const KEY = 'healthSafety.events.readIncidentStory';

    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('role_permission')) {
            return;
        }
        DB::table('permissions')->insertOrIgnore([
            'key' => self::KEY,
            'description' => 'Read what happened and what was done straight away for an incident behind a safety review at your Sites (never restricted incidents)',
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
