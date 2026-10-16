<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hub_event_drafts', function (Blueprint $table) {
            $table->timestamp('publish_at')->nullable();
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('schedule_blocked_at')->nullable();
            $table->string('schedule_error', 64)->nullable();
            $table->json('scheduled_files')->nullable();
            $table->index(['state', 'publish_at'], 'hub_event_due_publication_index');
        });
    }

    public function down(): void
    {
        Schema::table('hub_event_drafts', function (Blueprint $table) {
            $table->dropIndex('hub_event_due_publication_index');
            $table->dropColumn(['publish_at', 'scheduled_at', 'schedule_blocked_at', 'schedule_error', 'scheduled_files']);
        });
    }
};
