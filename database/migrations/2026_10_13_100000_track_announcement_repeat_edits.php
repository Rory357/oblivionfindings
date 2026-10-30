<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_announcements', function (Blueprint $table) {
            $table->dateTime('recurrence_anchor_at')->nullable();
            $table->dateTime('recurrence_cancelled_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('hr_announcements', function (Blueprint $table) {
            $table->dropColumn(['recurrence_anchor_at', 'recurrence_cancelled_at']);
        });
    }
};
