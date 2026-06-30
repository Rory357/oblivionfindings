<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_key_results', function (Blueprint $table) {
            // Baseline so "reduce 50 → 10" KRs measure progress correctly.
            $table->decimal('start_value', 10, 2)->default(0)->after('title');
            // KR measurement type drives formatting + progress maths.
            $table->string('kr_type')->default('number')->after('start_value'); // number, percent, currency, milestone, boolean
            // Weight drives the weighted roll-up.
            $table->unsignedInteger('weight')->default(1)->after('progress_percentage');
            // RAG confidence per KR.
            $table->string('confidence')->default('on_track')->after('status'); // on_track, at_risk, off_track
        });
    }

    public function down(): void
    {
        Schema::table('hr_key_results', function (Blueprint $table) {
            $table->dropColumn(['start_value', 'kr_type', 'weight', 'confidence']);
        });
    }
};
