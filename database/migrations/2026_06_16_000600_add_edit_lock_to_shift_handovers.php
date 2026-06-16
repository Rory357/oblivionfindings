<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Soft presence-lock for the shared handover draft: who currently has it open for
 * editing, and when they acquired it. A lock is "active" only within a short TTL
 * (see ShiftHandoverService::EDIT_LOCK_TTL_SECONDS) so an abandoned tab never
 * locks the record permanently. Complements the optimistic `version` token —
 * version blocks lost updates ON save; this warns a second editor BEFORE they
 * start.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_handovers', function (Blueprint $table) {
            $table->foreignId('locked_by')->nullable()->after('version')->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable()->after('locked_by');
        });
    }

    public function down(): void
    {
        Schema::table('shift_handovers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('locked_by');
            $table->dropColumn('locked_at');
        });
    }
};
