<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('control_room_communications', function (Blueprint $table) {
            // Make alert_id nullable so broadcast messages don't require an alert
            $table->foreignId('alert_id')->nullable()->change();

            // Add broadcast_group_id to group broadcast communications together
            $table->uuid('broadcast_group_id')->nullable()->after('alert_id');
            $table->index('broadcast_group_id');
        });
    }

    public function down(): void
    {
        Schema::table('control_room_communications', function (Blueprint $table) {
            $table->dropIndex(['broadcast_group_id']);
            $table->dropColumn('broadcast_group_id');
        });
    }
};
