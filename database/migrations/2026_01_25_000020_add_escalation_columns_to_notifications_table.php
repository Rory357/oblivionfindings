<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->timestamp('acknowledged_at')->nullable()->after('read_at');
            $table->unsignedInteger('escalation_count')->default(0)->after('acknowledged_at');
            $table->timestamp('last_escalated_at')->nullable()->after('escalation_count');

            $table->index(
                ['notifiable_type', 'notifiable_id', 'acknowledged_at'],
                'notifications_ack_lookup_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex('notifications_ack_lookup_idx');
            $table->dropColumn(['acknowledged_at', 'escalation_count', 'last_escalated_at']);
        });
    }
};
