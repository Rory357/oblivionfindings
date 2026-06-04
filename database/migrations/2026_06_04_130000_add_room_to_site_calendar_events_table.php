<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_calendar_events', function (Blueprint $table) {
            // Optional room / location label so manual entries can flag where they
            // happen (drives same-room conflict detection + .ics LOCATION).
            $table->string('room', 120)->nullable()->after('description');
        });
    }

    public function down(): void
    {
        Schema::table('site_calendar_events', function (Blueprint $table) {
            $table->dropColumn('room');
        });
    }
};
