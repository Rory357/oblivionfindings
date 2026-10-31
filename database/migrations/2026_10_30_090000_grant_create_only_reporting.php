<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Create-only reporting (MASTER for Stephan, 30 Oct 2026; one yes/no for Stephan). Prompt reporting is safer, and
 * Ngā Paerewa expects every restraint recorded. Every grant here is create only — none adds reading:
 * - support_worker: report a restraint (restraints.report) and a workplace injury (injuries.report). No registers,
 *   evidence afterwards, behaviour support plans, review, approval or closing.
 * - team_lead: raise a safeguarding concern (safeguarding.create). Not incidents.create: an incident report needs a
 *   client the reporter can read, and team leads hold no client read access (that would widen reading).
 * - health_safety_officer: raise a safeguarding concern (safeguarding.create).
 * RbacSeeder grants the same keys.
 */
return new class extends Migration
{
    /** New create-only keys: key => [description, group]. */
    private const NEW_KEYS = [
        'restraints.report' => ['Report a restraint (create only; no register, review or plans)', 'restraints'],
        'injuries.report' => ['Report a workplace injury (create only; no register or recovery work)', 'hazards'],
    ];

    /** Role => keys it gains. */
    private const GRANTS = [
        'support_worker' => ['restraints.report', 'injuries.report'],
        'team_lead' => ['safeguarding.create'],
        'health_safety_officer' => ['safeguarding.create'],
    ];

    public function up(): void
    {
        foreach (self::NEW_KEYS as $key => [$description, $group]) {
            DB::table('permissions')->insertOrIgnore([
                'key' => $key, 'description' => $description, 'group' => $group, 'module' => 'Compliance',
                'created_at' => now(), 'updated_at' => now(),
            ]);
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
        // Retain the live role decision on an application-code rollback; removing it needs a reviewed access migration.
    }
};
