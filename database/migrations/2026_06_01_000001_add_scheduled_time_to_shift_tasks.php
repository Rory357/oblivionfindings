<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_tasks', function (Blueprint $table) {
            $table->time('scheduled_time')->nullable()->after('label');
            $table->timestamp('reminder_sent_at')->nullable()->after('completed_by');
            $table->index(['is_completed', 'reminder_sent_at'], 'shift_tasks_reminder_due_index');
        });
    }

    public function down(): void
    {
        Schema::table('shift_tasks', function (Blueprint $table) {
            $table->dropIndex('shift_tasks_reminder_due_index');
            $table->dropColumn(['scheduled_time', 'reminder_sent_at']);
        });
    }
};
