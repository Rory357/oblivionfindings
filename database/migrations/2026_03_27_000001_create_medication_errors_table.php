<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medication_errors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained('clients')->cascadeOnDelete();
            $table->foreignId('client_medication_id')->nullable()->constrained('client_medications')->nullOnDelete();
            $table->foreignId('client_incident_id')->nullable()->constrained('client_incidents')->nullOnDelete();
            $table->enum('error_type', [
                'wrong_medication',
                'wrong_client',
                'wrong_dose',
                'wrong_time',
                'wrong_route',
                'omission',
                'unauthorised',
                'documentation',
                'other',
            ]);
            $table->enum('severity', ['near_miss', 'minor', 'moderate', 'major', 'critical']);
            $table->text('description');
            $table->text('immediate_action')->nullable();
            $table->text('contributing_factors')->nullable();
            $table->foreignId('reported_by')->constrained('users');
            $table->timestamp('reported_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->text('outcome')->nullable();
            $table->text('preventive_actions')->nullable();
            $table->enum('status', ['reported', 'investigating', 'resolved', 'closed'])->default('reported');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['client_id', 'status']);
            $table->index(['severity', 'status']);
            $table->index('reported_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('medication_errors');
    }
};
