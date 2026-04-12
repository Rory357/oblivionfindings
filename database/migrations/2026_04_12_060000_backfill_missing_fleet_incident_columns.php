<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('fleet_incidents')) {
            return;
        }

        Schema::table('fleet_incidents', function (Blueprint $table) {
            if (! Schema::hasColumn('fleet_incidents', 'tenant_id')) {
                $table->unsignedBigInteger('tenant_id')->nullable()->after('id');
                $table->index('tenant_id');
            }

            if (! Schema::hasColumn('fleet_incidents', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    public function down(): void
    {
        // This repair migration is intentionally irreversible because the
        // restored columns are part of the canonical fleet incident schema.
    }
};
