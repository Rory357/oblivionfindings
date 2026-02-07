<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resolutions', function (Blueprint $table) {
            $table->id();
            $table->string('resolution_reference')->unique(); // RES-2026-001
            $table->foreignId('governance_meeting_id')->nullable()->constrained()->nullOnDelete();
            
            // Resolution content
            $table->string('title');
            $table->text('context'); // Background information
            $table->json('options'); // Array of options with costs, risks, benefits
            $table->text('recommendation')->nullable(); // Management recommendation
            
            // Voting configuration
            $table->string('voting_threshold')->default('simple_majority'); // simple_majority, two_thirds, unanimous, special
            $table->boolean('quorum_required')->default(true);
            
            // Status: draft, proposed, open, closed, cancelled, implemented
            $table->string('status')->default('draft');
            $table->datetime('opened_at')->nullable();
            $table->datetime('closed_at')->nullable();
            $table->datetime('deadline')->nullable(); // Voting deadline
            
            // Outcome
            $table->string('outcome')->nullable(); // carried, defeated, deferred, withdrawn
            $table->json('vote_summary')->nullable(); // {for: X, against: Y, abstain: Z}
            $table->text('outcome_notes')->nullable();
            
            // Proposer
            $table->foreignId('proposed_by')->constrained('users');
            $table->datetime('proposed_at');
            
            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'deadline']);
            $table->index(['governance_meeting_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resolutions');
    }
};
