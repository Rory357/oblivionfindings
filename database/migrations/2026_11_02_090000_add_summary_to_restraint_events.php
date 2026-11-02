<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Restraint summary (Stephan, 2 Nov 2026: "Add a summary box"): one short "what happened" line, so a restraint's title
 * reads in plain words ("Held arm to stop a fall — Aroha Kingi") on its record, its safety review and its lists.
 * At most 120 characters. Existing restraints keep null and read "Restraint — <person>" as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('restraint_events', 'summary')) {
            return;
        }

        Schema::table('restraint_events', function (Blueprint $table): void {
            $table->string('summary', 120)->nullable()->after('restraint_type');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('restraint_events', 'summary')) {
            return;
        }

        Schema::table('restraint_events', function (Blueprint $table): void {
            $table->dropColumn('summary');
        });
    }
};
