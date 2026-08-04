<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_command_requests', function (Blueprint $table): void {
            $table->foreignId('break_glass_reviewer_user_id')
                ->nullable()
                ->after('break_glass_reason')
                ->constrained('users')
                ->restrictOnDelete();
            $table->timestamp('break_glass_declared_at')->nullable()->after('break_glass_reviewer_user_id');
            $table->timestamp('break_glass_review_due_at')->nullable()->after('break_glass_declared_at');
            $table->timestamp('break_glass_notification_sent_at')->nullable()->after('break_glass_review_due_at');
            $table->string('break_glass_review_outcome', 40)->nullable()->after('break_glass_reviewed_by_user_id');
            $table->index(
                ['break_glass_reviewer_user_id', 'break_glass_reviewed_at'],
                'device_command_break_glass_reviewer_reviewed_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('device_command_requests', function (Blueprint $table): void {
            $table->dropIndex('device_command_break_glass_reviewer_reviewed_index');
            $table->dropConstrainedForeignId('break_glass_reviewer_user_id');
            $table->dropColumn([
                'break_glass_declared_at',
                'break_glass_review_due_at',
                'break_glass_notification_sent_at',
                'break_glass_review_outcome',
            ]);
        });
    }
};
