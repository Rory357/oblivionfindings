<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_geofences', function (Blueprint $table) {
            $table->foreignId('site_id')->nullable()->after('asset_id')->constrained('sites')->nullOnDelete();
            $table->json('alert_config')->nullable()->after('time_rules');
            $table->string('scope')->default('vehicle')->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('asset_geofences', function (Blueprint $table) {
            $table->dropForeign(['site_id']);
            $table->dropColumn(['site_id', 'alert_config', 'scope']);
        });
    }
};
