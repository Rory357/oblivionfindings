<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cold-chain support: some medications must be kept refrigerated (e.g. insulin,
 * some antibiotics) or at a controlled room temperature. Record the required
 * storage condition per stock row so the board can surface a cold-chain cue and
 * filter by it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_medication_stocks', function (Blueprint $table) {
            if (! Schema::hasColumn('client_medication_stocks', 'storage_condition')) {
                $table->string('storage_condition', 32)->default('ambient')->after('supplier_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('client_medication_stocks', function (Blueprint $table) {
            if (Schema::hasColumn('client_medication_stocks', 'storage_condition')) {
                $table->dropColumn('storage_condition');
            }
        });
    }
};
