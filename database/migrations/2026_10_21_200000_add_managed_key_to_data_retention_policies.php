<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A retention policy created from a settings row (the referral retention periods on Privacy ›
 * Retention) carries that row's key, so the setting always finds its one policy. Ordinary policies
 * leave it empty. Additive and nullable; existing policies and their approvals are unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('data_retention_policies', 'managed_key')) {
            Schema::table('data_retention_policies', function (Blueprint $table): void {
                $table->string('managed_key', 80)->nullable()->unique()->after('model_type');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('data_retention_policies', 'managed_key')) {
            Schema::table('data_retention_policies', function (Blueprint $table): void {
                $table->dropUnique(['managed_key']);
                $table->dropColumn('managed_key');
            });
        }
    }
};
