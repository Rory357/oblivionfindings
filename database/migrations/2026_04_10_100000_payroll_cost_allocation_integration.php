<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Track whether cost allocations have been created for a payroll run.
        // This is the idempotency guard — prevents double-allocation.
        Schema::table('hr_payroll_runs', function (Blueprint $table) {
            $table->datetime('cost_allocated_at')->nullable()->after('gl_posted_at');
        });
    }

    public function down(): void
    {
        Schema::table('hr_payroll_runs', function (Blueprint $table) {
            $table->dropColumn('cost_allocated_at');
        });
    }
};
