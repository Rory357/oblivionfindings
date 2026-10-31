<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Provider managers read the H&S Overview and Safety reviews (Stephan, 30 Oct 2026, option A: "Yes, let them read").
 * Reading only: hazards.view, limited to their own Sites by the existing H&S Site scope (provider managers hold no
 * healthSafety.viewAllSites). No write keys. Incident-linked records still need incident read. RbacSeeder grants the
 * same key.
 */
return new class extends Migration
{
    public function up(): void
    {
        $role = DB::table('roles')->where('name', 'provider_manager')->value('id');
        $permission = DB::table('permissions')->where('key', 'hazards.view')->value('id');
        if ($role === null || $permission === null) {
            return;
        }
        DB::table('role_permission')->insertOrIgnore(['role_id' => $role, 'permission_id' => $permission]);
    }

    public function down(): void
    {
        // Retain the live role decision on an application-code rollback; removing it needs a reviewed access migration.
    }
};
