<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (!Schema::hasColumn('clients', 'service_context_id')) {
                $table->foreignId('service_context_id')
                    ->nullable()
                    ->after('site_id')
                    ->constrained('service_contexts')
                    ->nullOnDelete();
            }
        });

        Schema::table('shifts', function (Blueprint $table) {
            if (!Schema::hasColumn('shifts', 'service_context_id')) {
                $table->foreignId('service_context_id')
                    ->nullable()
                    ->after('client_id')
                    ->constrained('service_contexts')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            if (Schema::hasColumn('clients', 'service_context_id')) {
                $table->dropConstrainedForeignId('service_context_id');
            }
        });

        Schema::table('shifts', function (Blueprint $table) {
            if (Schema::hasColumn('shifts', 'service_context_id')) {
                $table->dropConstrainedForeignId('service_context_id');
            }
        });
    }
};
