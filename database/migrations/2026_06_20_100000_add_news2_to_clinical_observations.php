<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the stored NEWS2 result to clinical_observations so the deterioration
 * watch + registers + trends can filter/chart on the band without recomputing.
 *
 * Additive only. The vitals `data` JSON keeps its existing keys (respiration_rate,
 * o2_saturation, …); the NEWS2-specific inputs (consciousness, on_oxygen,
 * spo2_scale) live inside that JSON — no historical-row rewrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clinical_observations', function (Blueprint $table) {
            $table->unsignedTinyInteger('news2_score')->nullable()->after('data');
            $table->string('news2_band', 20)->nullable()->after('news2_score');

            $table->index(['news2_band', 'recorded_at'], 'clin_obs_news2_band_recorded');
        });
    }

    public function down(): void
    {
        Schema::table('clinical_observations', function (Blueprint $table) {
            $table->dropIndex('clin_obs_news2_band_recorded');
            $table->dropColumn(['news2_score', 'news2_band']);
        });
    }
};
