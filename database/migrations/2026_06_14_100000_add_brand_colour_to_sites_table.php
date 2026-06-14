<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-site brand colour (#RRGGBB). Used to tint category-themed page heroes
     * for this site's surfaces — primarily the eMAR pages, whose hero colour
     * must resolve from the active site's brand colour. Null = inherit the
     * category/brand default (--category-* / --primary).
     */
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            if (! Schema::hasColumn('sites', 'brand_colour')) {
                $table->string('brand_colour', 9)->nullable()->after('type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            if (Schema::hasColumn('sites', 'brand_colour')) {
                $table->dropColumn('brand_colour');
            }
        });
    }
};
