<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            if (!Schema::hasColumn('shifts', 'respite_booking_id')) {
                $table->foreignId('respite_booking_id')
                    ->nullable()
                    ->after('client_id')
                    ->constrained('respite_bookings')
                    ->nullOnDelete();
                $table->index('respite_booking_id', 'idx_shifts_respite_booking');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shifts', function (Blueprint $table) {
            if (Schema::hasColumn('shifts', 'respite_booking_id')) {
                $table->dropForeign(['respite_booking_id']);
                $table->dropIndex('idx_shifts_respite_booking');
                $table->dropColumn('respite_booking_id');
            }
        });
    }
};
