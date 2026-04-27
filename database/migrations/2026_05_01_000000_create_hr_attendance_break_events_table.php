<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_attendance_sessions', function (Blueprint $table) {
            if (! Schema::hasColumn('hr_attendance_sessions', 'break_started_at')) {
                $table->dateTime('break_started_at')->nullable()->after('break_minutes');
            }

            if (! Schema::hasColumn('hr_attendance_sessions', 'break_count')) {
                $table->unsignedInteger('break_count')->default(0)->after('break_started_at');
            }
        });

        Schema::create('hr_attendance_break_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('hr_attendance_sessions')->cascadeOnDelete();
            $table->dateTime('started_at')->index();
            $table->dateTime('ended_at')->nullable()->index();
            $table->unsignedInteger('minutes')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['session_id', 'started_at'], 'hr_break_events_session_started_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_attendance_break_events');

        Schema::table('hr_attendance_sessions', function (Blueprint $table) {
            if (Schema::hasColumn('hr_attendance_sessions', 'break_count')) {
                $table->dropColumn('break_count');
            }

            if (Schema::hasColumn('hr_attendance_sessions', 'break_started_at')) {
                $table->dropColumn('break_started_at');
            }
        });
    }
};
