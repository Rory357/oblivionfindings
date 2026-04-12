<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('client_incidents')) {
            return;
        }

        Schema::table('client_incidents', function (Blueprint $table) {
            if (! Schema::hasColumn('client_incidents', 'service_context_id')) {
                $table->foreignId('service_context_id')
                    ->nullable()
                    ->after('shift_id')
                    ->constrained('service_contexts')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('client_incidents', 'metadata')) {
                $table->json('metadata')->nullable()->after('description');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('client_incidents')) {
            return;
        }

        Schema::table('client_incidents', function (Blueprint $table) {
            if (Schema::hasColumn('client_incidents', 'service_context_id')) {
                $table->dropConstrainedForeignId('service_context_id');
            }

            if (Schema::hasColumn('client_incidents', 'metadata')) {
                $table->dropColumn('metadata');
            }
        });
    }
};
