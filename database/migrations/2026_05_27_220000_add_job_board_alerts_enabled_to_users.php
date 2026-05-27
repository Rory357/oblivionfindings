<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persist the "Alert me" toggle on the Job Board hero.
 *
 * When enabled, the user opts into in-app + email notifications for new
 * open positions that pass their eligibility / skill / schedule checks.
 * Defaults to false so existing users see no behaviour change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'job_board_alerts_enabled')) {
                $table->boolean('job_board_alerts_enabled')->default(false)->after('email_digest_frequency');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'job_board_alerts_enabled')) {
                $table->dropColumn('job_board_alerts_enabled');
            }
        });
    }
};
