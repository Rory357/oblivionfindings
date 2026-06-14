<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Comprehensive / triggered medication reviews capture the standard polypharmacy
 * measure — the Drug Burden Index — and the resident's falls in the last quarter
 * (a key deprescribing trigger, HQSC Frailty care guides). Persist them so the
 * Findings step has somewhere to record the clinical measure (gap G4).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medication_reviews', function (Blueprint $table) {
            if (! Schema::hasColumn('medication_reviews', 'drug_burden_index')) {
                $table->decimal('drug_burden_index', 4, 2)->nullable()->after('clinical_summary');
            }
            if (! Schema::hasColumn('medication_reviews', 'falls_last_quarter')) {
                $table->unsignedSmallInteger('falls_last_quarter')->nullable()->after('drug_burden_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('medication_reviews', function (Blueprint $table) {
            foreach (['drug_burden_index', 'falls_last_quarter'] as $col) {
                if (Schema::hasColumn('medication_reviews', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
