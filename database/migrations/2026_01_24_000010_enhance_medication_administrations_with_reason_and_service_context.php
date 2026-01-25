<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_medication_administrations', function (Blueprint $table) {
            if (!Schema::hasColumn('client_medication_administrations', 'reason')) {
                $table->string('reason')->nullable()->after('status');
            }

            if (!Schema::hasColumn('client_medication_administrations', 'service_context_id')) {
                $table->foreignId('service_context_id')
                    ->nullable()
                    ->after('shift_id')
                    ->constrained('service_contexts')
                    ->nullOnDelete();
            }

            // Status is already a string; we expand allowed values at the application layer.
        });
    }

    public function down(): void
    {
        Schema::table('client_medication_administrations', function (Blueprint $table) {
            if (Schema::hasColumn('client_medication_administrations', 'service_context_id')) {
                $table->dropConstrainedForeignId('service_context_id');
            }
            if (Schema::hasColumn('client_medication_administrations', 'reason')) {
                $table->dropColumn('reason');
            }
        });
    }
};
