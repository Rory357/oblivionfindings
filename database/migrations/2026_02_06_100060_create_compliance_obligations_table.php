<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('compliance_obligations', function (Blueprint $table) {
            $table->id();
            
            // Framework reference
            $table->string('framework'); // charities, nga_paerewa, hdsa_safety, privacy_act, hip_code, hswa, employment, funding_moh, funding_msd, funding_acc
            $table->string('obligation_code')->nullable(); // e.g., "NP-4.1.1"
            $table->string('obligation_title');
            $table->text('description');
            
            // Scheduling
            $table->string('frequency'); // monthly, quarterly, annual, ad_hoc, event_driven
            $table->date('due_date');
            $table->date('next_due_date');
            $table->json('reminder_days')->nullable();
            
            // Ownership
            $table->foreignId('owner_id')->constrained('users');
            $table->string('backup_owner_id')->nullable();
            
            // Status: not_due, due_soon, overdue, complete, exempt
            $table->string('status')->default('not_due');
            $table->datetime('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            
            // Evidence requirements
            $table->text('evidence_required');
            $table->boolean('evidence_provided')->default(false);
            
            // Sign-off
            $table->boolean('sign_off_required')->default(false);
            $table->string('sign_off_role')->nullable(); // compliance_manager, ceo, board
            $table->datetime('signed_off_at')->nullable();
            $table->foreignId('signed_off_by')->nullable()->constrained('users')->nullOnDelete();
            
            // Notes
            $table->text('notes')->nullable();
            
            $table->timestamps();
            $table->softDeletes();

            $table->index(['framework', 'status']);
            $table->index(['due_date', 'status']);
            $table->index(['owner_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_obligations');
    }
};
