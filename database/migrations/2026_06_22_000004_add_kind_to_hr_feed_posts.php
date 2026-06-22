<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the composer "kind" to feed posts (update / question / win) so the
 * community wall can badge the three Post-update flavours distinctly. Nullable
 * and additive — existing posts read as plain updates. `post_type` stays
 * `update` for all three so the Updates tab keeps showing them together.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasColumn('hr_feed_posts', 'kind')) {
            Schema::table('hr_feed_posts', function (Blueprint $table) {
                $table->string('kind', 16)->nullable()->after('post_type');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('hr_feed_posts', 'kind')) {
            Schema::table('hr_feed_posts', function (Blueprint $table) {
                $table->dropColumn('kind');
            });
        }
    }
};
