<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('invoice_number');
            $table->date('invoice_date');
            $table->date('due_date');
            $table->string('client_name');
            $table->string('client_email')->nullable();
            $table->text('client_address')->nullable();
            $table->unsignedBigInteger('bill_id')->nullable();
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->char('currency_code', 3)->default('NZD');
            $table->enum('status', [
                'draft', 'sent', 'viewed', 'paid', 'overdue', 'cancelled',
            ])->default('draft');
            $table->datetime('sent_at')->nullable();
            $table->datetime('viewed_at')->nullable();
            $table->datetime('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->text('terms')->nullable();
            $table->string('pdf_path')->nullable();
            $table->string('email_subject')->nullable();
            $table->text('email_body')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'invoice_number'], 'fin_inv_org_num_unique');
            $table->index(['organization_id', 'status'], 'fin_inv_org_status_idx');
            $table->index(['organization_id', 'due_date'], 'fin_inv_org_due_idx');
            $table->index('bill_id', 'fin_inv_bill_id_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_invoices');
    }
};
