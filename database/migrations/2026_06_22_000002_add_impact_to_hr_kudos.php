<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the recognition "impact" dimension to kudos — the strength of the
 * shout-out (Thank You / Good Job / Impressive / Exceptional), shown as a badge
 * on the community feed alongside the existing value/category. Additive and
 * nullable with a sensible default so existing rows keep working.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('hr_kudos', 'impact')) {
            Schema::table('hr_kudos', function (Blueprint $table) {
                $table->string('impact', 32)->default('good_job')->after('category');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('hr_kudos', 'impact')) {
            Schema::table('hr_kudos', function (Blueprint $table) {
                $table->dropColumn('impact');
            });
        }
    }
};
