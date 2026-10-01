<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * eMAR P01 foundation C4: the house/day read path of the dose-slot
 * projection. Reads select the reader's people (client_id IN …, from their
 * Sites and the person rule) over a range of NZ days, ordered by due time.
 * A house is resolved through clients.site_id (already indexed), so a
 * person moving house never leaves stale Site values on old slots.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medication_dose_slots', function (Blueprint $table) {
            $table->index(['client_id', 'nz_date', 'due_at'], 'med_dose_slots_client_day_idx');
            // Org-wide day reads (internal jobs, P09 reports across every house).
            $table->index(['nz_date', 'due_at'], 'med_dose_slots_day_due_idx');
        });
    }

    public function down(): void
    {
        Schema::table('medication_dose_slots', function (Blueprint $table) {
            $table->dropIndex('med_dose_slots_day_due_idx');
            $table->dropIndex('med_dose_slots_client_day_idx');
        });
    }
};
