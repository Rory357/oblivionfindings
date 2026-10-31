<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Reactions compare emoji exactly. Under utf8mb4_unicode_ci every emoji outside the basic plane
 * (👍 😊 🙏 😢 …) weighs the same, so the unique key let a person hold only one of them and removing
 * one could remove another. The column also grows to fit skin tones, flags and joined emoji.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ops_message_reactions') || DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE ops_message_reactions MODIFY emoji VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL');
    }

    public function down(): void
    {
        if (! Schema::hasTable('ops_message_reactions') || DB::getDriverName() !== 'mysql') {
            return;
        }

        // Keeps the wider column so longer emoji saved since are not cut off.
        DB::statement('ALTER TABLE ops_message_reactions MODIFY emoji VARCHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');
    }
};
