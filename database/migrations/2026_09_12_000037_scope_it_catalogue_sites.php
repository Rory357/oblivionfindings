<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('it_catalog_items', function (Blueprint $table): void {
            // Null preserves the existing all-approved-sites contract.
            $table->json('site_scope')->nullable();
        });
    }

    public function down(): void
    {
        if (DB::table('it_catalog_items')->whereNotNull('site_scope')->exists()
            || DB::table('it_catalog_versions')
                ->whereRaw("JSON_TYPE(JSON_EXTRACT(contract, '$.site_scope')) = 'ARRAY'")
                ->whereJsonLength('contract->site_scope', '>', 0)->exists()) {
            throw new RuntimeException('Review catalogue Site restrictions before removing their enforcement.');
        }
        Schema::table('it_catalog_items', fn (Blueprint $table) => $table->dropColumn('site_scope'));
    }
};
