<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Decision 18 (Stephan, 1 Nov 2026, H&S G4-2): the incident report asks quick timed ticks for what was done straight
 * away (made safe, first aid, nurse/GP/111 rung, manager told, family/whānau told), and the incident page shows them.
 * Stored as the reporter wrote them: a short JSON list on the incident. Existing incidents keep null (nothing ticked).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('client_incidents', 'immediate_response')) {
            return;
        }

        Schema::table('client_incidents', function (Blueprint $table): void {
            $table->json('immediate_response')->nullable()->after('immediate_action_taken');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('client_incidents', 'immediate_response')) {
            return;
        }

        Schema::table('client_incidents', function (Blueprint $table): void {
            $table->dropColumn('immediate_response');
        });
    }
};
