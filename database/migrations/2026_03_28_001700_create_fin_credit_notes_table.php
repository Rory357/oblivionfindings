<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fin_credit_notes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->string('credit_note_number');
            $table->enum('type', ['payable', 'receivable']);
            $table->unsignedBigInteger('vendor_id')->nullable();
            $table->unsignedBigInteger('client_id')->nullable();
            $table->unsignedBigInteger('bill_id')->nullable();
            $table->unsignedBigInteger('invoice_id')->nullable();
            $table->enum('status', ['draft', 'approved', 'applied', 'cancelled'])->default('draft');
            $table->date('credit_date');
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('gst_amount', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->text('reason')->nullable();
            $table->unsignedBigInteger('journal_id')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->datetime('approved_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['organization_id', 'credit_note_number']);
            $table->index(['organization_id', 'type', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fin_credit_notes');
    }
};
