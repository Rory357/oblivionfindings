<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional per-post audience scoping for community-wall posts — a Post-update can
 * target a single site instead of the whole org. `target_audience` is `all`
 * (default; the existing org-wide behaviour) or `site` with `target_value` = the
 * site id. Kudos always stay org-wide (`all`). Additive + defaulted so existing
 * rows keep showing everywhere.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('hr_feed_posts', function (Blueprint $table) {
            if (! Schema::hasColumn('hr_feed_posts', 'target_audience')) {
                $table->string('target_audience', 16)->default('all')->after('kind');
            }
            if (! Schema::hasColumn('hr_feed_posts', 'target_value')) {
                $table->string('target_value')->nullable()->after('target_audience');
            }
        });
    }

    public function down(): void
    {
        Schema::table('hr_feed_posts', function (Blueprint $table) {
            foreach (['target_audience', 'target_value'] as $column) {
                if (Schema::hasColumn('hr_feed_posts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
