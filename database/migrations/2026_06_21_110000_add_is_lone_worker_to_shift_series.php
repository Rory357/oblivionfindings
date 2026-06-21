<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Carry the explicit "lone / remote worker" marker onto recurring shift series, so a
 * lone shift defined on a recurring pattern flows the flag onto every generated Shift
 * (mirrors is_on_call). Nullable-safe default false.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_series', function (Blueprint $table) {
            $table->boolean('is_lone_worker')->default(false)->after('is_on_call');
        });
    }

    public function down(): void
    {
        Schema::table('shift_series', function (Blueprint $table) {
            $table->dropColumn('is_lone_worker');
        });
    }
};
