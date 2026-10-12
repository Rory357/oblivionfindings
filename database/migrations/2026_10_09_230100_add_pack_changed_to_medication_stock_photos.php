<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * eMAR Lane C11 (approved P06 Q6, P02 Medicines › Photos): a photo taken
 * because the pack or brand looks different from last time is marked, so
 * the person record shows "Pack or brand changed — check the label" until a
 * newer photo replaces it. Existing photos are unchanged (false).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('medication_stock_photos') || Schema::hasColumn('medication_stock_photos', 'pack_changed')) {
            return;
        }

        Schema::table('medication_stock_photos', function (Blueprint $table): void {
            $table->boolean('pack_changed')->default(false)->after('description');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('medication_stock_photos', 'pack_changed')) {
            Schema::table('medication_stock_photos', function (Blueprint $table): void {
                $table->dropColumn('pack_changed');
            });
        }
    }
};
