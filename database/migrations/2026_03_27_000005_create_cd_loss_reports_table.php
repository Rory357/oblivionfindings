<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('controlled_drug_loss_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained('clients')->nullOnDelete();
            $table->foreignId('client_medication_id')->nullable()->constrained('client_medications')->nullOnDelete();
            $table->string('medication_name', 255);
            $table->decimal('quantity_lost', 10, 2);
            $table->string('unit', 50)->default('tablets');
            $table->text('circumstances');
            $table->foreignId('discovered_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('discovered_at');
            $table->boolean('reported_to_police')->default(false);
            $table->string('police_reference', 100)->nullable();
            $table->timestamp('police_reported_at')->nullable();
            $table->boolean('reported_to_pharmacy')->default(false);
            $table->timestamp('pharmacy_notified_at')->nullable();
            $table->string('pharmacy_name', 255)->nullable();
            $table->enum('investigation_status', ['reported', 'investigating', 'resolved'])->default('reported');
            $table->text('investigation_notes')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('resolution_outcome')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['investigation_status', 'created_at'], 'cdlr_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('controlled_drug_loss_reports');
    }
};
