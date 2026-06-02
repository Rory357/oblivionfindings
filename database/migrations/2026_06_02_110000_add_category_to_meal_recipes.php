<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('meal_recipes', function (Blueprint $table) {
            if (! Schema::hasColumn('meal_recipes', 'category')) {
                $table->string('category', 80)->nullable()->after('description');
            }
        });
    }

    public function down(): void
    {
        Schema::table('meal_recipes', function (Blueprint $table) {
            if (Schema::hasColumn('meal_recipes', 'category')) {
                $table->dropColumn('category');
            }
        });
    }
};
