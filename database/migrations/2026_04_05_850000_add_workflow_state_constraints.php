<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // client_incidents – status must be one of the known workflow states
        if (Schema::hasTable('client_incidents')) {
            DB::statement("ALTER TABLE client_incidents ADD CONSTRAINT chk_incident_status CHECK (status IN ('draft', 'submitted', 'reviewed', 'closed'))");
        }

        // client_medication_administrations – status must be a valid administration outcome
        if (Schema::hasTable('client_medication_administrations')) {
            DB::statement("ALTER TABLE client_medication_administrations ADD CONSTRAINT chk_admin_status CHECK (status IN ('given', 'refused', 'missed', 'withheld', 'pending'))");
        }

        // care_plans – status must be a valid plan lifecycle state
        if (Schema::hasTable('care_plans')) {
            DB::statement("ALTER TABLE care_plans ADD CONSTRAINT chk_care_plan_status CHECK (status IN ('draft', 'active', 'review', 'archived'))");
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('client_incidents')) {
            DB::statement('ALTER TABLE client_incidents DROP CONSTRAINT IF EXISTS chk_incident_status');
        }

        if (Schema::hasTable('client_medication_administrations')) {
            DB::statement('ALTER TABLE client_medication_administrations DROP CONSTRAINT IF EXISTS chk_admin_status');
        }

        if (Schema::hasTable('care_plans')) {
            DB::statement('ALTER TABLE care_plans DROP CONSTRAINT IF EXISTS chk_care_plan_status');
        }
    }
};
