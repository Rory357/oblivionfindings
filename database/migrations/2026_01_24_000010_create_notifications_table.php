<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->json('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('acknowledged_at')->nullable();
            $table->unsignedInteger('escalation_count')->default(0);
            $table->timestamp('last_escalated_at')->nullable();
            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id', 'read_at']);
            $table->index(
                ['notifiable_type', 'notifiable_id', 'acknowledged_at'],
                'notifications_ack_lookup_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
