<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The is_scheduled, schedule_frequency, schedule_recipients columns
        // already exist from migration 200004. This is a no-op safety check.
        if (! Schema::hasColumn('hr_saved_reports', 'is_scheduled')) {
            Schema::table('hr_saved_reports', function (Blueprint $table) {
                $table->boolean('is_scheduled')->default(false)->after('sort_direction');
                $table->string('schedule_frequency')->nullable()->after('is_scheduled');
                $table->json('schedule_recipients')->nullable()->after('schedule_frequency');
                $table->dateTime('last_run_at')->nullable()->after('schedule_recipients');
            });
        }
    }

    public function down(): void
    {
        // Not dropping columns that existed before this migration
    }
};
