<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_controlled_drug_discrepancies', function (Blueprint $table) {
            $table->id();

            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            // NOTE: MySQL has a 64-character limit for constraint identifiers.
            // The default Laravel-generated FK name for this column exceeds that limit,
            // so we provide an explicit, short constraint name.
            $table->foreignId('client_medication_id')->nullable()->index('idx_ccdd_client_med');
            $table->foreign('client_medication_id', 'fk_ccdd_client_med')
                ->references('id')
                ->on('client_medications')
                ->nullOnDelete();
            $table->foreignId('service_context_id')->nullable()->constrained('service_contexts')->nullOnDelete();

            $table->integer('on_hand_before')->nullable();
            $table->integer('on_hand_after')->nullable();
            $table->integer('difference')->nullable();

            $table->string('reason', 255)->nullable();
            $table->text('notes')->nullable();

            $table->timestamp('reported_at')->nullable();
            $table->foreignId('reported_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('witnessed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('status', 20)->default('open'); // open|closed
            $table->timestamp('resolved_at')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('resolution_notes')->nullable();

            $table->timestamps();

            // MySQL identifier length safety: explicitly name compound indexes.
            $table->index(['client_id', 'status'], 'idx_ccdd_client_status');
            $table->index(['client_medication_id', 'status'], 'idx_ccdd_med_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_controlled_drug_discrepancies');
    }
};
