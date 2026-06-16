<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persist the link from a controlled-drug discrepancy / loss report to the
 * ClientIncident that MedicationIncidentIntegrationService auto-creates, so the
 * detail modal can surface (and deep-link to) the governing incident. Nullable
 * FK, null-on-delete — existing rows unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_controlled_drug_discrepancies', function (Blueprint $table) {
            $table->foreignId('incident_id')->nullable()->after('service_context_id')
                ->constrained('client_incidents')->nullOnDelete();
        });

        Schema::table('controlled_drug_loss_reports', function (Blueprint $table) {
            $table->foreignId('incident_id')->nullable()->after('client_medication_id')
                ->constrained('client_incidents')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('client_controlled_drug_discrepancies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('incident_id');
        });

        Schema::table('controlled_drug_loss_reports', function (Blueprint $table) {
            $table->dropConstrainedForeignId('incident_id');
        });
    }
};
