<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clinical_protocols', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();

            $table->string('name');                    // e.g. "Daily weight monitoring"
            $table->string('observation_type');        // ObservationType enum value
            $table->string('frequency');               // ProtocolFrequency enum value
            $table->unsignedInteger('custom_frequency_hours')->nullable(); // For custom frequency

            $table->text('instructions')->nullable();  // Displayed to recording staff
            $table->unsignedInteger('alert_if_missed_hours')->default(24);
            $table->json('threshold_rules')->nullable(); // e.g. {"weight_loss_kg_7d": 2, "systolic_above": 160}

            $table->boolean('is_active')->default(true);
            $table->date('starts_at')->nullable();
            $table->date('ends_at')->nullable();

            $table->timestamps();

            $table->index(['client_id', 'is_active']);
            $table->index(['observation_type', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clinical_protocols');
    }
};
