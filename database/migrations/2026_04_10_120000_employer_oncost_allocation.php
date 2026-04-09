<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Separate idempotency flag for employer on-cost allocation.
        // This allows backfilling on-cost allocations for runs that already
        // had wage allocations (cost_allocated_at) from PR8.
        Schema::table('hr_payroll_runs', function (Blueprint $table) {
            $table->datetime('oncost_allocated_at')->nullable()->after('cost_allocated_at');
        });
    }

    public function down(): void
    {
        Schema::table('hr_payroll_runs', function (Blueprint $table) {
            $table->dropColumn('oncost_allocated_at');
        });
    }
};
