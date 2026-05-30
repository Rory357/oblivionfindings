<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The shifts index/detail pages run a bare week-range scan
     * (whereBetween('starts_at', [...])->orderBy('starts_at')) with no leading
     * status/user/client equality. The existing composite indexes all lead with
     * another column (idx_shifts_status_starts, idx_shifts_user_starts,
     * idx_shifts_client_starts), so none can serve that range + sort. Add an
     * index that leads with starts_at; pairing status keeps it useful when the
     * optional status filter is also applied.
     */
    public function up(): void
    {
        if (! Schema::hasTable('shifts')) {
            return;
        }

        if (! Schema::hasIndex('shifts', 'idx_shifts_starts_status')) {
            Schema::table('shifts', function (Blueprint $table): void {
                $table->index(['starts_at', 'status'], 'idx_shifts_starts_status');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('shifts')) {
            return;
        }

        if (Schema::hasIndex('shifts', 'idx_shifts_starts_status')) {
            Schema::table('shifts', function (Blueprint $table): void {
                $table->dropIndex('idx_shifts_starts_status');
            });
        }
    }
};
