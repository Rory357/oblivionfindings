<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_medication_administrations', function (Blueprint $table) {
            if (!Schema::hasColumn('client_medication_administrations', 'witnessed_by')) {
                $table->foreignId('witnessed_by')
                    ->nullable()
                    ->after('administered_by')
                    ->constrained('users')
                    ->nullOnDelete();

                // MySQL identifier limit: keep index name short
                $table->index(['client_id', 'witnessed_by'], 'cma_client_wit_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('client_medication_administrations', function (Blueprint $table) {
            if (Schema::hasColumn('client_medication_administrations', 'witnessed_by')) {
                $table->dropIndex('cma_client_wit_idx');
                $table->dropConstrainedForeignId('witnessed_by');
            }
        });
    }
};
