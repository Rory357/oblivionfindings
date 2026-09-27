<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Deliberately unassigned: viewing personal evidence does not authorise export.
        DB::table('permissions')->insertOrIgnore(['key' => 'people_locations.export',
            'description' => 'Export authorised People Locations day and passenger journey reports',
            'group' => 'People Locations', 'module' => 'Operations', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('key', 'people_locations.export')->value('id');
        if ($id) {
            DB::table('role_permission')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
    }
};
