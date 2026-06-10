<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Right-to-work tracking for the NZ care workforce: employers must hold
     * evidence of work rights and act before a visa expires.
     */
    public function up(): void
    {
        Schema::table('hr_employee_profiles', function (Blueprint $table) {
            $table->string('work_rights_status', 50)->nullable()->after('ethnicity');
            $table->string('visa_type', 100)->nullable()->after('work_rights_status');
            $table->date('visa_expires_at')->nullable()->after('visa_type');

            $table->index('visa_expires_at');
        });
    }

    public function down(): void
    {
        Schema::table('hr_employee_profiles', function (Blueprint $table) {
            $table->dropIndex(['visa_expires_at']);
            $table->dropColumn(['work_rights_status', 'visa_type', 'visa_expires_at']);
        });
    }
};
