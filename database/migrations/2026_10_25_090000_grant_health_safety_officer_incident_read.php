<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stephan (25 Oct 2026): H&S officers read incidents. Incident-linked H&S events
 * (and their investigations and corrective actions) need incident read access,
 * so health_safety_officer gets incidents.viewAny. Access stays limited by the
 * incident Site rule and the incident privacy rules (HR-confidential incidents
 * still need hr.cases.view). RbacSeeder grants the same key.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')->insertOrIgnore([
            'key' => 'incidents.viewAny', 'description' => 'View all incidents within Site access',
            'group' => 'incidents', 'module' => 'Compliance', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $role = DB::table('roles')->where('name', 'health_safety_officer')->value('id');
        $permission = DB::table('permissions')->where('key', 'incidents.viewAny')->value('id');
        if ($role !== null && $permission !== null) {
            DB::table('role_permission')->insertOrIgnore(['role_id' => $role, 'permission_id' => $permission]);
        }
    }

    public function down(): void
    {
        // Retain the live role decision on an application-code rollback; removing it needs a reviewed access migration.
    }
};
