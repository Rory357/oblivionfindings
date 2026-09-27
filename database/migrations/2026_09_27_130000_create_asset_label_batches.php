<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Compatibility marker for isolated PKG-06B previews that used this name.
        // PKG-06A's earlier 120000 migration is now the sole schema owner and
        // adopts an existing label table without replacing its saved histories.
    }

    public function down(): void
    {
        // Batch history may already be owned by PKG-06A; retain it on rollback.
    }
};
