<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Survey audience targeting. Stores how the survey was scoped — all staff, or a
 * chosen set of sites/teams — so recipient counts and nudge targeting are real
 * rather than "everyone active". Null = all active staff (legacy behaviour).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('hr_engagement_surveys')) {
            return;
        }

        Schema::table('hr_engagement_surveys', function (Blueprint $table) {
            if (! Schema::hasColumn('hr_engagement_surveys', 'audience_type')) {
                $table->string('audience_type')->default('all')->after('is_anonymous'); // all | site
            }
            if (! Schema::hasColumn('hr_engagement_surveys', 'audience_site_ids')) {
                $table->json('audience_site_ids')->nullable()->after('audience_type');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('hr_engagement_surveys')) {
            return;
        }

        Schema::table('hr_engagement_surveys', function (Blueprint $table) {
            if (Schema::hasColumn('hr_engagement_surveys', 'audience_site_ids')) {
                $table->dropColumn('audience_site_ids');
            }
            if (Schema::hasColumn('hr_engagement_surveys', 'audience_type')) {
                $table->dropColumn('audience_type');
            }
        });
    }
};
