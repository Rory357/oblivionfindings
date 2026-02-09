<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Default all existing sites to 'house' type (most common for supported living)
        DB::table('sites')
            ->whereNull('type')
            ->update(['type' => 'house']);

        // Set NOT NULL after backfill
        Schema::table('sites', function ($table) {
            $table->enum('type', ['head_office', 'house', 'facility'])->nullable(false)->change();
        });
    }

    public function down(): void
    {
        // No-op
    }
};
