<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A committee evaluation names the committee being evaluated. Nullable, so
 * existing evaluations (and whole-board, chair and self evaluations) are
 * unaffected.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('board_evaluations') || Schema::hasColumn('board_evaluations', 'board_committee_id')) {
            return;
        }

        Schema::table('board_evaluations', function (Blueprint $table) {
            $table->foreignId('board_committee_id')
                ->nullable()
                ->after('evaluation_type')
                ->constrained('board_committees')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('board_evaluations') || ! Schema::hasColumn('board_evaluations', 'board_committee_id')) {
            return;
        }

        Schema::table('board_evaluations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('board_committee_id');
        });
    }
};
