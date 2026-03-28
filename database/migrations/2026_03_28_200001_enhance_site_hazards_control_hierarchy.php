<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_hazards', function (Blueprint $table) {
            $table->json('control_hierarchy')->nullable()->after('immediate_action_applied');
            $table->string('residual_risk_rating')->nullable()->after('control_hierarchy');
            $table->string('residual_likelihood')->nullable()->after('residual_risk_rating');
            $table->string('residual_severity')->nullable()->after('residual_likelihood');
            $table->string('control_effectiveness')->nullable()->after('residual_severity');
            $table->date('control_review_date')->nullable()->after('control_effectiveness');
        });
    }

    public function down(): void
    {
        Schema::table('site_hazards', function (Blueprint $table) {
            $table->dropColumn([
                'control_hierarchy',
                'residual_risk_rating',
                'residual_likelihood',
                'residual_severity',
                'control_effectiveness',
                'control_review_date',
            ]);
        });
    }
};
