<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_calendar_events', fn (Blueprint $table) => $table->unsignedInteger('edit_version')->default(1));
    }

    public function down(): void
    {
        Schema::table('hr_calendar_events', fn (Blueprint $table) => $table->dropColumn('edit_version'));
    }
};
