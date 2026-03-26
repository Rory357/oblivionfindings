<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('user_notification_preferences', function (Blueprint $table) {
            if (! Schema::hasColumn('user_notification_preferences', 'channel_inapp')) {
                $table->boolean('channel_inapp')->default(true)->after('enabled');
            }
            if (! Schema::hasColumn('user_notification_preferences', 'channel_email')) {
                $table->boolean('channel_email')->default(false)->after('channel_inapp');
            }
            if (! Schema::hasColumn('user_notification_preferences', 'channel_push')) {
                $table->boolean('channel_push')->default(false)->after('channel_email');
            }
        });

        Schema::table('role_notification_preferences', function (Blueprint $table) {
            if (! Schema::hasColumn('role_notification_preferences', 'channel_inapp')) {
                $table->boolean('channel_inapp')->default(true)->after('enabled');
            }
            if (! Schema::hasColumn('role_notification_preferences', 'channel_email')) {
                $table->boolean('channel_email')->default(false)->after('channel_inapp');
            }
            if (! Schema::hasColumn('role_notification_preferences', 'channel_push')) {
                $table->boolean('channel_push')->default(false)->after('channel_email');
            }
        });
    }

    public function down(): void
    {
        Schema::table('user_notification_preferences', function (Blueprint $table) {
            $table->dropColumn(['channel_inapp', 'channel_email', 'channel_push']);
        });

        Schema::table('role_notification_preferences', function (Blueprint $table) {
            $table->dropColumn(['channel_inapp', 'channel_email', 'channel_push']);
        });
    }
};
