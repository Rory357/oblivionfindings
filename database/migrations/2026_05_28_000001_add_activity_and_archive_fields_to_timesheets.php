<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            $table->string('activity_type')->nullable()->after('shift_id')->index();
            $table->json('activity_items')->nullable()->after('activity_type');
            $table->foreignId('site_id')->nullable()->after('shift_service_context_id')->constrained('sites')->nullOnDelete();
            $table->dateTime('archived_at')->nullable()->after('exported_to_payroll_at')->index();
            $table->string('archived_reason')->nullable()->after('archived_at');
        });

        Schema::table('timesheets', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('site_id');
            $table->dropColumn(['activity_type', 'activity_items', 'archived_at', 'archived_reason']);
        });

        Schema::table('timesheets', function (Blueprint $table) {
            $table->foreignId('client_id')->nullable(false)->change();
        });
    }
};
