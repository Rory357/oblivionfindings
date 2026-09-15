<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a voting rules profile was approved (GOV plain-language audit P0-4).
 *
 * Board voting is first switched on by the chair or secretary recording the
 * board's existing approval of its rules — the governing document, the date
 * the board approved them and the minutes reference, optionally linked to
 * the meeting. Later rule changes still need a passed resolution.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('governance_voting_profiles')) {
            return;
        }

        Schema::table('governance_voting_profiles', function (Blueprint $table) {
            if (! Schema::hasColumn('governance_voting_profiles', 'approval_source')) {
                // resolution | recorded_board_approval
                $table->string('approval_source')->nullable()->after('approved_at');
            }
            if (! Schema::hasColumn('governance_voting_profiles', 'approval_minutes_reference')) {
                $table->string('approval_minutes_reference')->nullable()->after('approval_source');
            }
            if (! Schema::hasColumn('governance_voting_profiles', 'approval_meeting_id')) {
                $table->foreignId('approval_meeting_id')
                    ->nullable()
                    ->after('approval_minutes_reference')
                    ->constrained('governance_meetings')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('governance_voting_profiles')) {
            return;
        }

        if (Schema::hasColumn('governance_voting_profiles', 'approval_meeting_id')) {
            Schema::table('governance_voting_profiles', function (Blueprint $table) {
                $table->dropForeign(['approval_meeting_id']);
                $table->dropColumn('approval_meeting_id');
            });
        }

        Schema::table('governance_voting_profiles', function (Blueprint $table) {
            foreach (['approval_minutes_reference', 'approval_source'] as $column) {
                if (Schema::hasColumn('governance_voting_profiles', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
