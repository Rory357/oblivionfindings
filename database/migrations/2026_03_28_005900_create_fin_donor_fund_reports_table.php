<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_donor_fund_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fund_id')->constrained('fin_donor_funds')->cascadeOnDelete();
            $table->string('report_name');
            $table->date('period_from');
            $table->date('period_to');
            $table->decimal('opening_balance', 14, 2);
            $table->decimal('total_receipts', 14, 2);
            $table->decimal('total_expenditure', 14, 2);
            $table->decimal('closing_balance', 14, 2);
            $table->json('report_data')->nullable();
            $table->enum('status', ['draft', 'final', 'submitted'])->default('draft');
            $table->datetime('submitted_at')->nullable();
            $table->string('file_path')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_donor_fund_reports');
    }
};
