<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('hr_employee_profiles', function (Blueprint $table) {
            $table->foreignId('manager_user_id')
                ->nullable()
                ->after('position_id')
                ->constrained('users')
                ->nullOnDelete();

            $table->string('department')->nullable()->after('manager_user_id');
            $table->string('team')->nullable()->after('department');
            $table->tinyInteger('reporting_level')->nullable()->after('team');

            $table->index(['tenant_id', 'manager_user_id'], 'hr_profiles_tenant_manager_idx');
        });
    }

    public function down(): void
    {
        Schema::table('hr_employee_profiles', function (Blueprint $table) {
            $table->dropIndex('hr_profiles_tenant_manager_idx');
            $table->dropConstrainedForeignId('manager_user_id');
            $table->dropColumn(['department', 'team', 'reporting_level']);
        });
    }
};
