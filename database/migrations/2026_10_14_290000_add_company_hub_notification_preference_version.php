<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hub_preferences', function (Blueprint $table): void {
            $table->unsignedInteger('notifications_version')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('hub_preferences', fn (Blueprint $table) => $table->dropColumn('notifications_version'));
    }
};
