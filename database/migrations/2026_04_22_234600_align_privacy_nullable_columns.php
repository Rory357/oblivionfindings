<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE legal_holds MODIFY holdable_type VARCHAR(255) NULL');
        DB::statement('ALTER TABLE legal_holds MODIFY holdable_id BIGINT UNSIGNED NULL');

        DB::statement('ALTER TABLE privacy_impact_assessments MODIFY description TEXT NULL');
        DB::statement("ALTER TABLE privacy_impact_assessments MODIFY residual_risk_level ENUM('low','medium','high','very_high') NULL");

        DB::statement('ALTER TABLE data_breach_logs MODIFY likely_consequences TEXT NULL');
        DB::statement('ALTER TABLE data_breach_logs MODIFY measures_taken TEXT NULL');
    }

    public function down(): void
    {
        DB::statement("UPDATE privacy_impact_assessments SET description = '' WHERE description IS NULL");
        DB::statement('UPDATE privacy_impact_assessments SET residual_risk_level = overall_risk_level WHERE residual_risk_level IS NULL');
        DB::statement("UPDATE data_breach_logs SET likely_consequences = '' WHERE likely_consequences IS NULL");
        DB::statement("UPDATE data_breach_logs SET measures_taken = '' WHERE measures_taken IS NULL");

        DB::statement('ALTER TABLE legal_holds MODIFY holdable_type VARCHAR(255) NOT NULL');
        DB::statement('ALTER TABLE legal_holds MODIFY holdable_id BIGINT UNSIGNED NOT NULL');

        DB::statement('ALTER TABLE privacy_impact_assessments MODIFY description TEXT NOT NULL');
        DB::statement("ALTER TABLE privacy_impact_assessments MODIFY residual_risk_level ENUM('low','medium','high','very_high') NOT NULL");

        DB::statement('ALTER TABLE data_breach_logs MODIFY likely_consequences TEXT NOT NULL');
        DB::statement('ALTER TABLE data_breach_logs MODIFY measures_taken TEXT NOT NULL');
    }
};
