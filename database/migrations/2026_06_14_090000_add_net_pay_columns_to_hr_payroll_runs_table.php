<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hr_payroll_runs', function (Blueprint $table) {
            $table->datetime('net_paid_at')->nullable()->after('gl_posted_at');
            $table->unsignedBigInteger('payment_journal_id')->nullable()->after('net_paid_at');
        });
    }

    public function down(): void
    {
        Schema::table('hr_payroll_runs', function (Blueprint $table) {
            $table->dropColumn(['net_paid_at', 'payment_journal_id']);
        });
    }
};
