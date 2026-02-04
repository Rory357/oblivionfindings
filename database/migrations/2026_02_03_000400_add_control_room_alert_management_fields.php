<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('control_room_alerts', function (Blueprint $table) {
            // User tracking for status changes
            $table->foreignId('acknowledged_by_user_id')->nullable()->after('acknowledged_at')
                ->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by_user_id')->nullable()->after('resolved_at')
                ->constrained('users')->nullOnDelete();

            // Closure tracking
            $table->timestamp('closed_at')->nullable()->after('resolved_by_user_id');
            $table->foreignId('closed_by_user_id')->nullable()->after('closed_at')
                ->constrained('users')->nullOnDelete();

            // Escalation tracking
            $table->timestamp('escalated_at')->nullable()->after('closed_by_user_id');
            $table->foreignId('escalated_by_user_id')->nullable()->after('escalated_at')
                ->constrained('users')->nullOnDelete();
            $table->unsignedTinyInteger('escalation_level')->default(0)->after('escalated_by_user_id');

            // Assignment tracking
            $table->foreignId('assigned_to_user_id')->nullable()->after('escalation_level')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable()->after('assigned_to_user_id');
            $table->foreignId('assigned_by_user_id')->nullable()->after('assigned_at')
                ->constrained('users')->nullOnDelete();

            // Creator tracking
            $table->foreignId('created_by_user_id')->nullable()->after('assigned_by_user_id')
                ->constrained('users')->nullOnDelete();

            // Notes field
            $table->text('notes')->nullable()->after('context');

            // Additional indexes for filtering
            $table->index('assigned_to_user_id');
            $table->index('escalation_level');
            $table->index(['status', 'assigned_to_user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('control_room_alerts', function (Blueprint $table) {
            $table->dropForeign(['acknowledged_by_user_id']);
            $table->dropForeign(['resolved_by_user_id']);
            $table->dropForeign(['closed_by_user_id']);
            $table->dropForeign(['escalated_by_user_id']);
            $table->dropForeign(['assigned_to_user_id']);
            $table->dropForeign(['assigned_by_user_id']);
            $table->dropForeign(['created_by_user_id']);

            $table->dropIndex(['assigned_to_user_id']);
            $table->dropIndex(['escalation_level']);
            $table->dropIndex(['status', 'assigned_to_user_id']);

            $table->dropColumn([
                'acknowledged_by_user_id',
                'resolved_by_user_id',
                'closed_at',
                'closed_by_user_id',
                'escalated_at',
                'escalated_by_user_id',
                'escalation_level',
                'assigned_to_user_id',
                'assigned_at',
                'assigned_by_user_id',
                'created_by_user_id',
                'notes',
            ]);
        });
    }
};
