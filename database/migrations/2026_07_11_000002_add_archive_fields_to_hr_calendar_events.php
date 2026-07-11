<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_calendar_events', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->after('created_by')->index();
            $table->foreignId('archived_by')->nullable()->after('archived_at')->constrained('users')->nullOnDelete();
            $table->string('archive_reason', 500)->nullable()->after('archived_by');
        });
    }

    public function down(): void
    {
        Schema::table('hr_calendar_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('archived_by');
            $table->dropColumn(['archived_at', 'archive_reason']);
        });
    }
};
