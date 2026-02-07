<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('break_glass_accesses', function (Blueprint $table) {
            $table->id();
            
            // Requester
            $table->foreignId('requested_by')->constrained('users');
            $table->datetime('requested_at');
            $table->text('reason');
            $table->string('requested_resource');
            
            // Approver (must be different person, board tier)
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->datetime('approved_at')->nullable();
            $table->text('approval_notes')->nullable();
            
            // Access window
            $table->boolean('access_granted')->default(false);
            $table->datetime('access_start')->nullable();
            $table->datetime('access_end')->nullable();
            
            // Audit trail
            $table->json('actions_taken')->nullable(); // Log of all actions during access
            $table->text('closure_notes')->nullable();
            $table->datetime('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            
            // Notification tracking
            $table->boolean('board_notified')->default(false);
            $table->datetime('board_notified_at')->nullable();
            
            $table->timestamps();

            $table->index(['requested_by', 'access_granted']);
            $table->index(['access_start', 'access_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('break_glass_accesses');
    }
};
