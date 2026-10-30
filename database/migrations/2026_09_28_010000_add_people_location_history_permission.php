<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Separate from current map access and deliberately unassigned to operational roles.
        DB::table('permissions')->insertOrIgnore(['key' => 'people_locations.history.view',
            'description' => 'Read authorised People Locations history and report previews',
            'group' => 'People Locations', 'module' => 'Operations', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('key', 'people_locations.history.view')->value('id');
        if ($id) {
            DB::table('role_permission')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
    }
};
