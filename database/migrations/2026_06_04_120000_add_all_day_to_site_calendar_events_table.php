<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_calendar_events', function (Blueprint $table) {
            $table->boolean('all_day')->default(false)->after('end_at');
        });
    }

    public function down(): void
    {
        Schema::table('site_calendar_events', function (Blueprint $table) {
            $table->dropColumn('all_day');
        });
    }
};
