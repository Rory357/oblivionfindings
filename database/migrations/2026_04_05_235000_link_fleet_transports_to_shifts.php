<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fleet_resident_transports', function (Blueprint $table) {
            if (! Schema::hasColumn('fleet_resident_transports', 'shift_id')) {
                $table->foreignId('shift_id')->nullable()->after('booking_id')->constrained('shifts')->nullOnDelete();
            }

            if (! Schema::hasColumn('fleet_resident_transports', 'service_context_id')) {
                $table->foreignId('service_context_id')->nullable()->after('shift_id')->constrained('service_contexts')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('fleet_resident_transports', function (Blueprint $table) {
            if (Schema::hasColumn('fleet_resident_transports', 'service_context_id')) {
                $table->dropConstrainedForeignId('service_context_id');
            }

            if (Schema::hasColumn('fleet_resident_transports', 'shift_id')) {
                $table->dropConstrainedForeignId('shift_id');
            }
        });
    }
};
