<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * eMAR Lane C4 (EA-145, EA-153). The P08b migration
 * (2026_10_03_160000_add_medication_error_workflow) created
 * medications.errors.manage and granted it to team_lead, clinical_lead,
 * coordinator and provider_manager, but not admin. RbacSeeder gives admin
 * every key outside its restricted list, so a seeded environment's admin can
 * triage medication errors while a migrated one (the .com test server,
 * oblivion_emar_preview_20261003 locally) gets 403 on every triage action.
 *
 * Adds the admin row only; runs once, so a grant later removed in
 * Settings › Roles stays removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('roles') || ! Schema::hasTable('role_permission')) {
            return;
        }

        DB::transaction(function (): void {
            $roleId = DB::table('roles')->where('name', 'admin')->value('id');
            $permissionId = DB::table('permissions')->where('key', 'medications.errors.manage')->value('id');
            if ($roleId === null || $permissionId === null) {
                return;
            }

            DB::table('role_permission')->insertOrIgnore(['role_id' => $roleId, 'permission_id' => $permissionId]);
        });
    }

    /** A seeded admin held this key before; leave admin's grants as they are. */
    public function down(): void {}
};
