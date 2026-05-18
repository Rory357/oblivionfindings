<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('clients') || Schema::hasColumn('clients', 'house_geofence_id')) {
            return;
        }

        Schema::table('clients', function (Blueprint $table) {
            $table->foreignId('house_geofence_id')
                ->nullable()
                ->after('site_id')
                ->constrained('asset_geofences')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('clients') || ! Schema::hasColumn('clients', 'house_geofence_id')) {
            return;
        }

        Schema::table('clients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('house_geofence_id');
        });
    }
};
