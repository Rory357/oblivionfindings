<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('permissions', function (Blueprint $table) {
            $table->string('group')->default('general')->after('key')->comment('Permission category/group');
            $table->string('module')->nullable()->after('group')->comment('Module this permission belongs to');
            
            $table->index('group');
            $table->index('module');
        });
        
        // Update existing permissions with groups based on key prefix
        $this->categorizePermissions();
    }

    public function down(): void
    {
        Schema::table('permissions', function (Blueprint $table) {
            $table->dropIndex(['group']);
            $table->dropIndex(['module']);
            $table->dropColumn(['group', 'module']);
        });
    }
    
    private function categorizePermissions(): void
    {
        $groupMap = [
            'settings.access' => ['group' => 'access_control', 'module' => 'System'],
            'settings' => ['group' => 'settings', 'module' => 'System'],
            'staff' => ['group' => 'staff', 'module' => 'HR'],
            'clients' => ['group' => 'clients', 'module' => 'Operations'],
            'shifts' => ['group' => 'shifts', 'module' => 'Operations'],
            'timesheets' => ['group' => 'timesheets', 'module' => 'Operations'],
            'medications' => ['group' => 'medications', 'module' => 'Clinical'],
            'incidents' => ['group' => 'incidents', 'module' => 'Compliance'],
            'safeguarding' => ['group' => 'safeguarding', 'module' => 'Compliance'],
            'assets' => ['group' => 'assets', 'module' => 'Resources'],
            'sites' => ['group' => 'sites', 'module' => 'Operations'],
            'audit' => ['group' => 'audit', 'module' => 'System'],
            'reports' => ['group' => 'reports', 'module' => 'System'],
            'integrations' => ['group' => 'integrations', 'module' => 'System'],
            'governance' => ['group' => 'governance', 'module' => 'Governance'],
            'hr' => ['group' => 'hr', 'module' => 'HR'],
            'privacy' => ['group' => 'privacy', 'module' => 'Compliance'],
            'compliance' => ['group' => 'compliance', 'module' => 'Compliance'],
            'respite' => ['group' => 'respite', 'module' => 'Operations'],
            'fleet' => ['group' => 'fleet', 'module' => 'Resources'],
            'control_room' => ['group' => 'control_room', 'module' => 'System'],
        ];
        
        $permissions = \DB::table('permissions')->get();
        
        foreach ($permissions as $permission) {
            $group = 'general';
            $module = null;
            
            foreach ($groupMap as $prefix => $data) {
                if (str_starts_with($permission->key, $prefix)) {
                    $group = $data['group'];
                    $module = $data['module'];
                    break;
                }
            }
            
            \DB::table('permissions')
                ->where('id', $permission->id)
                ->update(['group' => $group, 'module' => $module]);
        }
    }
};
