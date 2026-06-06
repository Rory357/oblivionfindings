<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('funding_claim_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->foreignId('funding_claim_id')->constrained('funding_claims')->cascadeOnDelete();
            $table->unsignedBigInteger('service_agreement_line_item_id')->nullable();
            $table->unsignedBigInteger('shift_id')->nullable();
            $table->unsignedBigInteger('timesheet_id')->nullable();
            $table->string('description');
            $table->decimal('quantity', 10, 2);
            $table->decimal('unit_price', 8, 2);
            $table->decimal('total_amount', 12, 2);
            $table->date('service_date');
            $table->string('funding_contract_reference')->nullable();
            $table->timestamps();

            $table->foreign('service_agreement_line_item_id', 'fci_sali_foreign')
                ->references('id')->on('service_agreement_line_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('funding_claim_items');
    }
};
