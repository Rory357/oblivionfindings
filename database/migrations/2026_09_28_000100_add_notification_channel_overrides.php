<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_notification_preferences', function (Blueprint $table) {
            // Null preserves the meaning of existing explicit full-row choices.
            $table->json('channel_overrides')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('user_notification_preferences', fn (Blueprint $table) => $table->dropColumn('channel_overrides'));
    }
};
