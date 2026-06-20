<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Injuries & RTW redesign — Step 3 (cross-module seam 4, WorkSafe register).
 * A worksafe_notifiable workplace injury (HSWA 2015 s.23/s.25 — hospitalisation,
 * amputation, serious head/eye injury, etc.) must reach the WorkSafe notifiable
 * register. notifiable_incidents previously only linked to client_incidents; this
 * adds an optional link to the source workplace injury so the observer can create /
 * track a NotifiableIncident and the register can site-attribute via the injury.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('notifiable_incidents') || Schema::hasColumn('notifiable_incidents', 'workplace_injury_id')) {
            return;
        }

        Schema::table('notifiable_incidents', function (Blueprint $table) {
            $table->foreignId('workplace_injury_id')
                ->nullable()
                ->after('related_incident_id')
                ->constrained('workplace_injuries')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('notifiable_incidents') || ! Schema::hasColumn('notifiable_incidents', 'workplace_injury_id')) {
            return;
        }

        Schema::table('notifiable_incidents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('workplace_injury_id');
        });
    }
};
