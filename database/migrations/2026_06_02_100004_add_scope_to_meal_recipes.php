<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('meal_recipes', function (Blueprint $table) {
            if (! Schema::hasColumn('meal_recipes', 'scope')) {
                $table->string('scope', 16)->default('shared')->after('is_active');
            }
            if (! Schema::hasColumn('meal_recipes', 'site_id')) {
                $table->foreignId('site_id')->nullable()->after('scope')->constrained('sites')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('meal_recipes', function (Blueprint $table) {
            if (Schema::hasColumn('meal_recipes', 'site_id')) {
                $table->dropConstrainedForeignId('site_id');
            }
            if (Schema::hasColumn('meal_recipes', 'scope')) {
                $table->dropColumn('scope');
            }
        });
    }
};
