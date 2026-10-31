<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Team leads report incidents (Stephan, 30 Oct 2026). An incident report names a client the reporter can read, so
 * team leads gain:
 * - incidents.create: report an incident or near miss, from the standalone report page (/incidents/report). The
 *   incident register stays closed to them (no incidents.viewAny / viewAssigned).
 * - clients.viewAny: read the clients at the sites they work at (Stephan chose "all at their sites"; ClientPolicy
 *   still limits it to their sites).
 * RbacSeeder grants the same keys.
 */
return new class extends Migration
{
    private const GRANTS = ['incidents.create', 'clients.viewAny'];

    public function up(): void
    {
        $role = DB::table('roles')->where('name', 'team_lead')->value('id');
        if ($role === null) {
            return;
        }
        foreach (self::GRANTS as $key) {
            $permission = DB::table('permissions')->where('key', $key)->value('id');
            if ($permission !== null) {
                DB::table('role_permission')->insertOrIgnore(['role_id' => $role, 'permission_id' => $permission]);
            }
        }
    }

    public function down(): void
    {
        // Retain the live role decision on an application-code rollback; removing it needs a reviewed access migration.
    }
};
