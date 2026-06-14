<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CQC requires every medication action to be attributable to a person. Starting
 * and ceasing a medication order previously stored no actor — the audit trail
 * surfaced them as "Not attributed to a staff member" (gap G1). Persist who
 * created and who ceased the order so those events carry a real signature.
 * Nullable: historical rows stay honestly unattributed rather than guessed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_medications', function (Blueprint $table) {
            if (! Schema::hasColumn('client_medications', 'created_by')) {
                $table->foreignId('created_by')->nullable()->after('client_id')
                    ->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('client_medications', 'ceased_by')) {
                $table->foreignId('ceased_by')->nullable()->after('ceased_reason')
                    ->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('client_medications', function (Blueprint $table) {
            foreach (['created_by', 'ceased_by'] as $col) {
                if (Schema::hasColumn('client_medications', $col)) {
                    $table->dropConstrainedForeignId($col);
                }
            }
        });
    }
};
