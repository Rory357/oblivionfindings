<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Retire the legacy `hr_applications.answers` column. Screening responses now
 * live in `screening_answers` (the careers portals already capture them there);
 * `answers` was only ever written null by createApplication and is dead. Backfill
 * any straggler rows, then drop it.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('hr_applications', 'answers')) {
            return;
        }

        // Preserve any historical responses that only ever landed in `answers`.
        DB::table('hr_applications')
            ->whereNull('screening_answers')
            ->whereNotNull('answers')
            ->update(['screening_answers' => DB::raw('answers')]);

        Schema::table('hr_applications', function (Blueprint $table) {
            $table->dropColumn('answers');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('hr_applications', 'answers')) {
            return;
        }

        Schema::table('hr_applications', function (Blueprint $table) {
            $table->json('answers')->nullable()->after('cover_letter');
        });
    }
};
