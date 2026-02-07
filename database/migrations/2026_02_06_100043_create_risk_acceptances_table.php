<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('risk_acceptances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('risk_register_entry_id')->constrained()->onDelete('cascade');
            
            // Acceptance type
            $table->string('acceptance_type'); // board_resolution, delegated_authority
            $table->foreignId('resolution_id')->nullable()->constrained()->nullOnDelete();
            $table->string('delegated_to_role')->nullable();
            
            // Justification
            $table->text('justification');
            $table->json('conditions')->nullable(); // Conditions of acceptance
            
            // Time-bounded
            $table->date('expires_at');
            $table->boolean('expiry_notified')->default(false);
            
            // Who accepted
            $table->foreignId('accepted_by')->constrained('users');
            $table->datetime('accepted_at');
            
            // Review
            $table->date('review_due_date');
            $table->boolean('review_completed')->default(false);
            
            $table->timestamps();

            $table->index(['risk_register_entry_id', 'expires_at']);
            $table->index(['expires_at', 'expiry_notified']);
            $table->index('review_due_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('risk_acceptances');
    }
};
