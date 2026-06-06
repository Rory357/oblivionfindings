<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('respite_medication_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stay_id')->constrained('respite_stays')->cascadeOnDelete();
            $table->enum('type', ['admission', 'discharge']);
            $table->enum('status', ['not_started', 'in_progress', 'completed', 'overridden'])->default('not_started');
            $table->string('source')->nullable();
            $table->unsignedInteger('count_received')->nullable();
            $table->json('discrepancies')->nullable();
            $table->timestamp('first_dose_due_at')->nullable();
            $table->foreignId('reconciled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reconciled_at')->nullable();
            $table->text('override_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['stay_id', 'type'], 'respite_med_rec_stay_type_unique');
            $table->index(['status', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('respite_medication_reconciliations');
    }
};
