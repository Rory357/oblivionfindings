<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A member's optional reason for their vote gets its own column. Until now
 * the ballot sent it as `conflict_note`, which also marked the vote as a
 * declared conflict of interest (GOV plain-language audit P0-2).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('votes') || Schema::hasColumn('votes', 'vote_note')) {
            return;
        }

        Schema::table('votes', function (Blueprint $table) {
            $table->text('vote_note')->nullable()->after('conflict_note');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('votes') || ! Schema::hasColumn('votes', 'vote_note')) {
            return;
        }

        Schema::table('votes', function (Blueprint $table) {
            $table->dropColumn('vote_note');
        });
    }
};
