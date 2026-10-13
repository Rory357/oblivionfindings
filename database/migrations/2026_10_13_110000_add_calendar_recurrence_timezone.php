<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Existing occurrence keys and schedules retain their original UTC semantics.
        Schema::table('hr_calendar_events', fn (Blueprint $table) => $table->string('recurrence_timezone', 64)->default('UTC'));
    }

    public function down(): void
    {
        Schema::table('hr_calendar_events', fn (Blueprint $table) => $table->dropColumn('recurrence_timezone'));
    }
};
