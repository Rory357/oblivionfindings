<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_minutes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('governance_meeting_id')->unique()->constrained()->onDelete('cascade');
            
            // Content stored as JSON blocks for flexibility
            $table->json('content_blocks');
            
            // Version tracking
            $table->integer('version_number')->default(1);
            
            // Status: draft, reviewed, approved, signed
            $table->string('status')->default('draft');
            
            // Version history (for audit)
            $table->json('version_history')->nullable();
            
            // Workflow tracking
            $table->foreignId('drafted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->datetime('drafted_at')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('board_members')->nullOnDelete();
            $table->datetime('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            
            // Approval linked to resolution (FK added after resolutions table exists)
            $table->unsignedBigInteger('approval_resolution_id')->nullable();
            
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_minutes');
    }
};
