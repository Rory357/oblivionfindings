<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Link recruitment to the establishment layer and give positions JD parity.
 *
 * - hr_job_requisitions.position_id  — a requisition can declare the position it fills.
 * - hr_offers.position_id            — carried through so a converted hire lands in the seat.
 * - hr_positions.summary / .responsibilities — JD parity with requisitions (position is canonical).
 *
 * All additive + nullable → safe to run on a populated database.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('hr_job_requisitions') && ! Schema::hasColumn('hr_job_requisitions', 'position_id')) {
            Schema::table('hr_job_requisitions', function (Blueprint $table) {
                $table->foreignId('position_id')
                    ->nullable()
                    ->after('position_role')
                    ->constrained('hr_positions')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasTable('hr_offers') && ! Schema::hasColumn('hr_offers', 'position_id')) {
            Schema::table('hr_offers', function (Blueprint $table) {
                $table->foreignId('position_id')
                    ->nullable()
                    ->constrained('hr_positions')
                    ->nullOnDelete();
            });
        }

        if (Schema::hasTable('hr_positions')) {
            Schema::table('hr_positions', function (Blueprint $table) {
                if (! Schema::hasColumn('hr_positions', 'summary')) {
                    $table->text('summary')->nullable()->after('requirements');
                }
                if (! Schema::hasColumn('hr_positions', 'responsibilities')) {
                    $table->longText('responsibilities')->nullable()->after('summary');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('hr_job_requisitions', 'position_id')) {
            Schema::table('hr_job_requisitions', function (Blueprint $table) {
                $table->dropConstrainedForeignId('position_id');
            });
        }
        if (Schema::hasColumn('hr_offers', 'position_id')) {
            Schema::table('hr_offers', function (Blueprint $table) {
                $table->dropConstrainedForeignId('position_id');
            });
        }
        if (Schema::hasTable('hr_positions')) {
            Schema::table('hr_positions', function (Blueprint $table) {
                foreach (['responsibilities', 'summary'] as $col) {
                    if (Schema::hasColumn('hr_positions', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
