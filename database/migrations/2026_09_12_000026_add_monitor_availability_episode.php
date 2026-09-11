<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('monitors', function (Blueprint $table): void {
            // Server-owned state of the existing monitor, not a second incident store.
            $table->json('availability_episode')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('monitors')->whereNotNull('availability_episode')->exists()) {
            throw new LogicException('Active monitoring episode evidence must be retained before rollback.');
        }
        Schema::table('monitors', fn (Blueprint $table) => $table->dropColumn('availability_episode'));
    }
};
