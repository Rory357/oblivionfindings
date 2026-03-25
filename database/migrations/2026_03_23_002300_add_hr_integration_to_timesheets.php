<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            $table->unsignedBigInteger('hr_time_entry_id')->nullable();
            $table->decimal('pay_rate', 8, 2)->nullable();
            $table->string('pay_type')->nullable();
            $table->dateTime('exported_to_payroll_at')->nullable();
            $table->string('payroll_reference')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('timesheets', function (Blueprint $table) {
            $table->dropColumn([
                'hr_time_entry_id',
                'pay_rate',
                'pay_type',
                'exported_to_payroll_at',
                'payroll_reference',
            ]);
        });
    }
};
