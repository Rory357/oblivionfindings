<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_meal_plan_entries', function (Blueprint $table): void {
            $table->unsignedInteger('version')->default(1)->after('client_ids');
        });
    }

    public function down(): void
    {
        Schema::table('site_meal_plan_entries', function (Blueprint $table): void {
            $table->dropColumn('version');
        });
    }
};
