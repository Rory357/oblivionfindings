<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'dnd_enabled')) {
                $table->boolean('dnd_enabled')->default(false)->after('landing_route_preference');
            }
            if (! Schema::hasColumn('users', 'dnd_until')) {
                $table->timestamp('dnd_until')->nullable()->after('dnd_enabled');
            }
            if (! Schema::hasColumn('users', 'desktop_notifications_enabled')) {
                $table->boolean('desktop_notifications_enabled')->default(false)->after('dnd_until');
            }
            if (! Schema::hasColumn('users', 'notification_sounds_enabled')) {
                $table->boolean('notification_sounds_enabled')->default(true)->after('desktop_notifications_enabled');
            }
            // instant | daily | weekly | off
            if (! Schema::hasColumn('users', 'email_digest_frequency')) {
                $table->string('email_digest_frequency', 10)->default('instant')->after('notification_sounds_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $columns = [];
            foreach ([
                'dnd_enabled',
                'dnd_until',
                'desktop_notifications_enabled',
                'notification_sounds_enabled',
                'email_digest_frequency',
            ] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $columns[] = $column;
                }
            }
            if ($columns !== []) {
                $table->dropColumn($columns);
            }
        });
    }
};
