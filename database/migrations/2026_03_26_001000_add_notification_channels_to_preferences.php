<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_notification_preferences', function (Blueprint $table) {
            if (!Schema::hasColumn('user_notification_preferences', 'channel_email')) {
                $table->boolean('channel_email')->default(true)->after('enabled');
            }
            if (!Schema::hasColumn('user_notification_preferences', 'channel_push')) {
                $table->boolean('channel_push')->default(false)->after('channel_email');
            }
            if (!Schema::hasColumn('user_notification_preferences', 'channel_sms')) {
                $table->boolean('channel_sms')->default(false)->after('channel_push');
            }
        });
    }

    public function down(): void
    {
        Schema::table('user_notification_preferences', function (Blueprint $table) {
            $columns = ['channel_email', 'channel_push', 'channel_sms'];
            foreach ($columns as $col) {
                if (Schema::hasColumn('user_notification_preferences', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
