<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::statement('ALTER TABLE client_medication_stocks ADD CONSTRAINT chk_stock_non_negative CHECK (on_hand >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('ALTER TABLE client_medication_stocks DROP CONSTRAINT chk_stock_non_negative');
    }
};
