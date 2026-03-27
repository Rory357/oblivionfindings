<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_gst_returns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->date('period_start');
            $table->date('period_end');
            $table->enum('filing_frequency', ['monthly', 'two_monthly', 'six_monthly']);
            $table->enum('basis', ['invoice', 'payments', 'hybrid']);
            $table->decimal('total_sales', 14, 2)->default(0);
            $table->decimal('total_gst_collected', 14, 2)->default(0);
            $table->decimal('total_purchases', 14, 2)->default(0);
            $table->decimal('total_gst_paid', 14, 2)->default(0);
            $table->decimal('gst_payable', 14, 2)->default(0);
            $table->decimal('adjustments', 14, 2)->default(0);
            $table->enum('status', ['draft', 'filed', 'amended'])->default('draft');
            $table->datetime('filed_at')->nullable();
            $table->foreignId('filed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('ird_period')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->unique(['organization_id', 'period_start', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_gst_returns');
    }
};
