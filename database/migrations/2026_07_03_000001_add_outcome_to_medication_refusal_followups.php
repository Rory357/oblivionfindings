<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medication_refusal_followups', function (Blueprint $table) {
            // What was actually done/decided when the follow-up was closed —
            // completion previously recorded only who/when, leaving no audit
            // trail of the resolution action (2026-07 eMAR governance audit).
            $table->text('follow_up_outcome')->nullable()->after('follow_up_completed_by');
        });
    }

    public function down(): void
    {
        Schema::table('medication_refusal_followups', function (Blueprint $table) {
            $table->dropColumn('follow_up_outcome');
        });
    }
};
