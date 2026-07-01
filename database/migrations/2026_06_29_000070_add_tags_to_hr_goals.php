<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_goals', function (Blueprint $table) {
            // Free-form tags for slicing objectives (item 16). JSON keeps it
            // single-table in this single-tenant app.
            $table->json('tags')->nullable()->after('category');
        });
    }

    public function down(): void
    {
        Schema::table('hr_goals', function (Blueprint $table) {
            $table->dropColumn('tags');
        });
    }
};
