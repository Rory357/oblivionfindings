<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasColumn('assets', 'room_id')) {
            Schema::table('assets', function (Blueprint $table) {
                $table->foreignId('room_id')
                    ->nullable()
                    ->after('site_id')
                    ->constrained('site_house_rooms')
                    ->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('assets', 'room_id')) {
            Schema::table('assets', function (Blueprint $table) {
                $table->dropConstrainedForeignId('room_id');
            });
        }
    }
};
