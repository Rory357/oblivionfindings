<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_availabilities', function (Blueprint $table): void {
            $table->boolean('ends_next_day')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('staff_availabilities', function (Blueprint $table): void {
            $table->dropColumn('ends_next_day');
        });
    }
};
