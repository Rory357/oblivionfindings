<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_personal_assets', function (Blueprint $table) {
            $table->foreignId('site_id')->nullable()->after('location')->constrained('sites')->nullOnDelete();
            $table->foreignId('room_id')->nullable()->after('site_id')->constrained('site_house_rooms')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('client_personal_assets', function (Blueprint $table) {
            $table->dropConstrainedForeignId('room_id');
            $table->dropConstrainedForeignId('site_id');
        });
    }
};
