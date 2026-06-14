<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Break-glass activations must be retained for audit (the design calls for a
 * 7-year, tamper-evident log). Revoking a grant previously hard-deleted the row,
 * erasing the audit trail. Make the record soft-deletable so a revoked grant is
 * retained (shown as "revoked" in the audit log) with the revoker recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_break_glass_accesses', function (Blueprint $table) {
            if (! Schema::hasColumn('client_break_glass_accesses', 'revoked_by')) {
                $table->foreignId('revoked_by')->nullable()->after('user_id')->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('client_break_glass_accesses', 'deleted_at')) {
                $table->softDeletes();
            }
        });
    }

    public function down(): void
    {
        Schema::table('client_break_glass_accesses', function (Blueprint $table) {
            if (Schema::hasColumn('client_break_glass_accesses', 'revoked_by')) {
                $table->dropConstrainedForeignId('revoked_by');
            }
            if (Schema::hasColumn('client_break_glass_accesses', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });
    }
};
