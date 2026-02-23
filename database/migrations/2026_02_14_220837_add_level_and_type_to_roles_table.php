<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->integer('level')->default(50)->after('label')->comment('Role hierarchy level (100=highest)');
            $table->enum('type', ['system', 'custom'])->default('custom')->after('level');
            $table->text('description')->nullable()->after('type');
            
            $table->index('level');
            $table->index('type');
        });
        
        // Set default levels for common roles
        $this->seedRoleLevels();
    }

    public function down(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->dropIndex(['level']);
            $table->dropIndex(['type']);
            $table->dropColumn(['level', 'type', 'description']);
        });
    }
    
    private function seedRoleLevels(): void
    {
        $roleLevels = [
            'admin' => ['level' => 100, 'type' => 'system', 'description' => 'Full system access across all sites'],
            'board_chair' => ['level' => 95, 'type' => 'system', 'description' => 'Board chairperson with governance oversight'],
            'board_secretary' => ['level' => 90, 'type' => 'system', 'description' => 'Board secretary with meeting management'],
            'board_member' => ['level' => 85, 'type' => 'system', 'description' => 'Board member with governance access'],
            'board_observer' => ['level' => 80, 'type' => 'system', 'description' => 'Board observer with read-only access'],
            'provider_manager' => ['level' => 70, 'type' => 'system', 'description' => 'Manages daily operations and staff'],
            'clinical_lead' => ['level' => 65, 'type' => 'system', 'description' => 'Clinical oversight and medication authority'],
            'support_worker' => ['level' => 40, 'type' => 'system', 'description' => 'Regular staff with limited access'],
            'client' => ['level' => 20, 'type' => 'system', 'description' => 'Client portal access'],
            'next_of_kin' => ['level' => 10, 'type' => 'system', 'description' => 'Family member portal access'],
        ];
        
        foreach ($roleLevels as $name => $data) {
            \DB::table('roles')
                ->where('name', $name)
                ->update($data);
        }
    }
};
