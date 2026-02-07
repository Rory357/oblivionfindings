<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('meeting_agenda_items', function (Blueprint $table) {
            $table->foreign('resolution_id')
                ->references('id')
                ->on('resolutions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('meeting_agenda_items', function (Blueprint $table) {
            $table->dropForeign(['resolution_id']);
        });
    }
};
