<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('governance_meetings', function (Blueprint $table) {
            $table->id();
            $table->string('meeting_type'); // full_board, audit_risk, people, finance, special_general, executive_session
            $table->foreignId('board_committee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title');
            $table->datetime('scheduled_at');
            $table->integer('duration_minutes')->default(120);
            $table->string('location')->nullable();
            $table->string('virtual_link')->nullable();
            $table->text('notes')->nullable();
            
            // Status workflow: scheduled -> agenda_draft -> agenda_final -> in_progress -> minutes_draft -> minutes_review -> minutes_approved -> minutes_signed -> archived
            $table->string('status')->default('scheduled');
            
            // Quorum tracking
            $table->integer('quorum_required')->default(50); // percentage
            $table->boolean('quorum_met')->default(false);
            
            // Key personnel
            $table->foreignId('chair_id')->nullable()->constrained('board_members')->nullOnDelete();
            $table->foreignId('secretary_id')->nullable()->constrained('board_members')->nullOnDelete();
            
            // Pack distribution
            $table->datetime('pack_distributed_at')->nullable();
            
            // Minutes approval
            $table->datetime('minutes_approved_at')->nullable();
            $table->foreignId('minutes_approved_by')->nullable()->constrained('board_members')->nullOnDelete();
            $table->datetime('minutes_signed_at')->nullable();
            $table->foreignId('minutes_signed_by')->nullable()->constrained('board_members')->nullOnDelete();
            
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['meeting_type', 'status']);
            $table->index(['scheduled_at', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('governance_meetings');
    }
};
