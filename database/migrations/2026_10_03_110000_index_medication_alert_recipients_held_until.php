<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * eMAR P11 B2 chunk 5: every 15 minutes the follow-up tick looks for email
 * and push held for quiet hours whose hours have ended. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medication_alert_recipients', function (Blueprint $table) {
            $table->index('held_until', 'medication_alert_recipients_held_until_index');
        });
    }

    public function down(): void
    {
        Schema::table('medication_alert_recipients', function (Blueprint $table) {
            $table->dropIndex('medication_alert_recipients_held_until_index');
        });
    }
};
