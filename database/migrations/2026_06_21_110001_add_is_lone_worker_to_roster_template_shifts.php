<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Carry the explicit "lone / remote worker" marker onto roster template shifts, so a
 * lone shift defined in a reusable roster template flows the flag onto every Shift the
 * template generates (mirrors is_on_call). Nullable-safe default false.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roster_template_shifts', function (Blueprint $table) {
            $table->boolean('is_lone_worker')->default(false)->after('is_on_call');
        });
    }

    public function down(): void
    {
        Schema::table('roster_template_shifts', function (Blueprint $table) {
            $table->dropColumn('is_lone_worker');
        });
    }
};
