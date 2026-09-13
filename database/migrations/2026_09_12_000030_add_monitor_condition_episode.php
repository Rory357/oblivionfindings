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
            $table->json('condition_episode')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('monitors')->whereNotNull('condition_episode')->exists()) {
            throw new LogicException('Active technical-check episode evidence must be retained before rollback.');
        }
        Schema::table('monitors', function (Blueprint $table): void {
            $table->dropColumn('condition_episode');
        });
    }
};
