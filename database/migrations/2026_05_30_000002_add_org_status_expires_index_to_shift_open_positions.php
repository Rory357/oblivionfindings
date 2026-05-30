<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_open_positions', function (Blueprint $table) {
            // Covers the dominant listing/stats predicate
            // (organization_id = ? AND status = ? AND expires_at ...).
            $table->index(
                ['organization_id', 'status', 'expires_at'],
                'idx_open_positions_org_status_expires',
            );
        });
    }

    public function down(): void
    {
        Schema::table('shift_open_positions', function (Blueprint $table) {
            $table->dropIndex('idx_open_positions_org_status_expires');
        });
    }
};
