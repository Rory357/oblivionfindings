<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P11 B2 chunk 4: each house's on-call contact rule (v5 Alerts & access ›
 * On-call contacts). Only the rule and the backup person are kept — never a
 * typed name or phone number: the contact is worked out when a screen or an
 * alert needs it, from the roster (on-call shift, then the team lead on
 * shift) and the person's staff record (work phone).
 *
 * No row = "Not configured". Every save and removal is recorded in
 * medication_setting_changes and the audit log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medication_oncall_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_id')->unique()->constrained('sites')->cascadeOnDelete();
            // roster: on-call shift, then (if chosen) the team lead on shift, then the backup.
            // fixed: always the backup person.
            $table->string('mode', 10)->default('roster');
            $table->boolean('team_lead')->default(true);
            $table->foreignId('backup_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medication_oncall_rules');
    }
};
