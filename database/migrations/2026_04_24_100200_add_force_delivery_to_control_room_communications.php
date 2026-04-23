<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('control_room_communications', function (Blueprint $table) {
            if (! Schema::hasColumn('control_room_communications', 'force_delivery')) {
                // Broadcast messages with this flag bypass per-user notification
                // preferences (DND, channel disables). Used for genuine emergencies
                // like fire drills or lockdowns.
                $table->boolean('force_delivery')->default(false)->after('status_detail');
            }
        });
    }

    public function down(): void
    {
        Schema::table('control_room_communications', function (Blueprint $table) {
            if (Schema::hasColumn('control_room_communications', 'force_delivery')) {
                $table->dropColumn('force_delivery');
            }
        });
    }
};
