<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An interest's "From" date is when the interest started, which is not the
 * same as the day it was declared. Until now the form saved "From" into
 * `declared_at`; keep those historic values as the start date too, and store
 * the real declaration date going forward.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('board_member_interests') || Schema::hasColumn('board_member_interests', 'started_on')) {
            return;
        }

        Schema::table('board_member_interests', function (Blueprint $table) {
            $table->date('started_on')->nullable()->after('nature');
        });

        // Historic rows stored the start date in declared_at.
        DB::table('board_member_interests')
            ->whereNull('started_on')
            ->update(['started_on' => DB::raw('declared_at')]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('board_member_interests') || ! Schema::hasColumn('board_member_interests', 'started_on')) {
            return;
        }

        Schema::table('board_member_interests', function (Blueprint $table) {
            $table->dropColumn('started_on');
        });
    }
};
