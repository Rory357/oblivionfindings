<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fleet_trips', function (Blueprint $table) {
            $table->string('start_address')->nullable()->after('start_longitude');
            $table->string('end_address')->nullable()->after('end_longitude');
            $table->timestamp('reverse_geocoded_at')->nullable()->after('end_address');
        });
    }

    public function down(): void
    {
        Schema::table('fleet_trips', function (Blueprint $table) {
            $table->dropColumn(['start_address', 'end_address', 'reverse_geocoded_at']);
        });
    }
};
