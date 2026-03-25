<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('hr_attendance_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained('shifts')->nullOnDelete();
            $table->foreignId('site_id')->nullable()->constrained('sites')->nullOnDelete();
            $table->dateTime('clock_in_at')->index();
            $table->dateTime('clock_out_at')->nullable()->index();
            $table->unsignedInteger('break_minutes')->default(0);
            $table->string('status')->default('open')->index();
            $table->string('source')->default('manual');
            $table->string('location')->nullable();
            $table->text('notes')->nullable();
            $table->json('meta')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['user_id', 'status', 'clock_in_at'], 'hr_attendance_user_status_clock_in_idx');
            $table->index(['tenant_id', 'clock_in_at'], 'hr_attendance_tenant_clock_in_idx');
        });

        Schema::table('timesheets', function (Blueprint $table) {
            if (! Schema::hasColumn('timesheets', 'attendance_session_id')) {
                $table->foreignId('attendance_session_id')
                    ->nullable()
                    ->after('shift_id')
                    ->constrained('hr_attendance_sessions')
                    ->nullOnDelete();
                $table->unique('attendance_session_id', 'timesheets_attendance_session_unique');
            }
        });
    }

    public function down(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            if (Schema::hasColumn('timesheets', 'attendance_session_id')) {
                $table->dropUnique('timesheets_attendance_session_unique');
                $table->dropConstrainedForeignId('attendance_session_id');
            }
        });

        Schema::dropIfExists('hr_attendance_sessions');
    }
};

