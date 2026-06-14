<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Competency best practice (CQC / NICE SC1 / NMC) needs more than pass/fail
 * booleans:
 *  - "Competent with restrictions" is standard (e.g. patches not yet observed).
 *  - A "not seen this time" state is required where no resident needed a given
 *    route/CD — otherwise the booleans force false data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medication_competency_assessments', function (Blueprint $table) {
            if (! Schema::hasColumn('medication_competency_assessments', 'restricted')) {
                $table->boolean('restricted')->default(false)->after('can_witness_controlled');
            }
            if (! Schema::hasColumn('medication_competency_assessments', 'restriction_notes')) {
                $table->text('restriction_notes')->nullable()->after('restricted');
            }
            if (! Schema::hasColumn('medication_competency_assessments', 'not_seen_areas')) {
                $table->json('not_seen_areas')->nullable()->after('restriction_notes');
            }
        });
    }

    public function down(): void
    {
        Schema::table('medication_competency_assessments', function (Blueprint $table) {
            foreach (['restricted', 'restriction_notes', 'not_seen_areas'] as $col) {
                if (Schema::hasColumn('medication_competency_assessments', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
