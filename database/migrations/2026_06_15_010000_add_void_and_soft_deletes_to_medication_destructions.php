<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The medication-destruction register must be immutable and retained (MoD Regs
 * 1977, reg 42). Replace hard-deletes with SoftDeletes, and add a "void"
 * supersession (the record stays visible, struck through, with a reason) rather
 * than removing it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medication_destructions', function (Blueprint $table) {
            if (! Schema::hasColumn('medication_destructions', 'deleted_at')) {
                $table->softDeletes();
            }
            if (! Schema::hasColumn('medication_destructions', 'voided_at')) {
                $table->timestamp('voided_at')->nullable()->after('destroyed_at');
            }
            if (! Schema::hasColumn('medication_destructions', 'void_reason')) {
                $table->string('void_reason', 1000)->nullable()->after('voided_at');
            }
            if (! Schema::hasColumn('medication_destructions', 'voided_by')) {
                $table->foreignId('voided_by')->nullable()->after('void_reason')->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('medication_destructions', function (Blueprint $table) {
            if (Schema::hasColumn('medication_destructions', 'voided_by')) {
                $table->dropConstrainedForeignId('voided_by');
            }
            foreach (['voided_at', 'void_reason'] as $col) {
                if (Schema::hasColumn('medication_destructions', $col)) {
                    $table->dropColumn($col);
                }
            }
            if (Schema::hasColumn('medication_destructions', 'deleted_at')) {
                $table->dropSoftDeletes();
            }
        });
    }
};
