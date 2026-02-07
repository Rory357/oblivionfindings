<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('board_packs', function (Blueprint $table) {
            $table->foreign('dashboard_snapshot_id')
                ->references('id')
                ->on('dashboard_snapshots')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('board_packs', function (Blueprint $table) {
            $table->dropForeign(['dashboard_snapshot_id']);
        });
    }
};
