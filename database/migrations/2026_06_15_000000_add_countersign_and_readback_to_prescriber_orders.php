<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Verbal/telephone medication orders carry governance metadata the old page
 * never stored: how a prescriber countersignature was given, and the read-back
 * confirmation + witness captured when the order was taken. (eMAR Prescriptions
 * redesign, gaps 5 & 6.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medication_prescriber_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('medication_prescriber_orders', 'countersign_method')) {
                $table->string('countersign_method')->nullable()->after('countersigned_by');
            }
            if (! Schema::hasColumn('medication_prescriber_orders', 'read_back_confirmed')) {
                $table->boolean('read_back_confirmed')->default(false)->after('requires_countersign');
            }
            if (! Schema::hasColumn('medication_prescriber_orders', 'read_back_witnessed_by')) {
                $table->foreignId('read_back_witnessed_by')->nullable()->after('read_back_confirmed')->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('medication_prescriber_orders', function (Blueprint $table) {
            if (Schema::hasColumn('medication_prescriber_orders', 'read_back_witnessed_by')) {
                $table->dropConstrainedForeignId('read_back_witnessed_by');
            }
            foreach (['countersign_method', 'read_back_confirmed'] as $col) {
                if (Schema::hasColumn('medication_prescriber_orders', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
