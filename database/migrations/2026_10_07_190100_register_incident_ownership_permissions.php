<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            'incidents.owner.assign' => 'Assign an accountable owner within incident access and conflict boundaries',
            'incidents.owner.hold' => 'Hold accountable ownership of an eligible incident',
            'incidents.owner.holdRestricted' => 'Hold ownership of restricted incidents when separately authorized to read them',
            'incidents.owner.request' => 'Request a change of ownership for an owned incident',
        ];
        foreach ($permissions as $key => $description) {
            DB::table('permissions')->insertOrIgnore(['key' => $key, 'description' => $description, 'group' => 'incidents', 'module' => 'Compliance', 'created_at' => now(), 'updated_at' => now()]);
        }
        // Ownership does not widen any role's existing incident read boundary.
        // Specialist accounts need separately configured read/ownership grants.
        $roles = DB::table('roles')->whereIn('name', ['admin', 'provider_manager', 'coordinator'])->get(['id', 'name']);
        foreach ($roles as $role) {
            $keys = array_keys($permissions);
            if ($role->name === 'coordinator') {
                $keys = array_values(array_diff($keys, ['incidents.owner.holdRestricted']));
            }
            foreach (DB::table('permissions')->whereIn('key', $keys)->pluck('id') as $permissionId) {
                DB::table('role_permission')->insertOrIgnore(['role_id' => $role->id, 'permission_id' => $permissionId]);
            }
        }
    }

    public function down(): void
    {
        // Retain the explicit permission catalogue/grants on an application-code
        // rollback. Removing live role decisions needs a reviewed access migration.
    }
};
