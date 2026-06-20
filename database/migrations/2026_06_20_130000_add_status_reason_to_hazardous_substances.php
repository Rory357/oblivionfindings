<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Capture the reason a hazardous substance was marked inactive/removed so the
 * lifecycle decision is visible on the record (the audit log records who/when;
 * this records why). Nullable + additive — no enum change to `status`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hazardous_substances', function (Blueprint $table) {
            $table->text('status_reason')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('hazardous_substances', function (Blueprint $table) {
            $table->dropColumn('status_reason');
        });
    }
};
