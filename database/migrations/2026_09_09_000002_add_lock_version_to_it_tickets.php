<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('it_tickets', function (Blueprint $table): void {
            // Existing records begin at a measured version without changing
            // their lifecycle, historic timestamps or organisational context.
            $table->unsignedBigInteger('lock_version')->default(1);
        });
    }

    public function down(): void
    {
        if (DB::table('it_tickets')->where('lock_version', '>', 1)->exists()) {
            throw new RuntimeException('Ticket concurrency versions are in use and must not be discarded by rollback.');
        }

        Schema::table('it_tickets', function (Blueprint $table): void {
            $table->dropColumn('lock_version');
        });
    }
};
