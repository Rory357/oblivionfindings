<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_handovers', function (Blueprint $table) {
            // Two-person controlled-drug count reconciliation at shift change:
            // { result, witness_id, witness_name, notes, verified_at, verified_by,
            //   verified_by_name }. Nullable — not every shift handles CDs.
            $table->json('cd_verification')->nullable()->after('observations_summary');
        });
    }

    public function down(): void
    {
        Schema::table('shift_handovers', function (Blueprint $table) {
            $table->dropColumn('cd_verification');
        });
    }
};
