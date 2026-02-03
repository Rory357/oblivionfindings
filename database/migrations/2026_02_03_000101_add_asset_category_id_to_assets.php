<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->foreignId('asset_category_id')->nullable()->after('category')->constrained('asset_categories')->nullOnDelete();
            $table->index('asset_category_id');
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropForeign(['asset_category_id']);
            $table->dropIndex(['asset_category_id']);
            $table->dropColumn('asset_category_id');
        });
    }
};
