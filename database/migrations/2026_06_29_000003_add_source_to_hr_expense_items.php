<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link an expense item back to the development goal / PIP it was incurred for,
 * so the shared claim flow can prefill and report on development spend.
 * Additive, nullable — reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_expense_items', function (Blueprint $table) {
            if (! Schema::hasColumn('hr_expense_items', 'source_type')) {
                $table->string('source_type')->nullable()->after('category');
            }
            if (! Schema::hasColumn('hr_expense_items', 'source_id')) {
                $table->unsignedBigInteger('source_id')->nullable()->after('source_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('hr_expense_items', function (Blueprint $table) {
            $table->dropColumn(['source_type', 'source_id']);
        });
    }
};
