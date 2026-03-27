<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_ird_filings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->string('ird_number');
            $table->enum('filing_type', ['gst', 'payday', 'rlwt', 'rwt', 'aim', 'ir3', 'ir4', 'ir7']);
            $table->date('period_from');
            $table->date('period_to');
            $table->foreignId('gst_return_id')->nullable()->constrained('fin_gst_returns')->nullOnDelete();
            $table->text('filing_data');
            $table->decimal('total_amount', 14, 2);
            $table->enum('status', ['draft', 'validated', 'submitted', 'accepted', 'rejected', 'error']);
            $table->datetime('submitted_at')->nullable();
            $table->string('ird_reference')->nullable();
            $table->json('ird_response')->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['organization_id', 'filing_type', 'status'], 'fin_ird_filings_org_type_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_ird_filings');
    }
};
