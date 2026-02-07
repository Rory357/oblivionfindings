<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resolution_id')->constrained()->onDelete('cascade');
            $table->foreignId('board_member_id')->constrained()->onDelete('cascade');
            
            // Vote: for, against, abstain
            $table->string('vote');
            $table->datetime('voted_at');
            $table->string('voting_method')->default('in_person'); // in_person, proxy, written, electronic
            
            // Conflict declaration
            $table->boolean('conflict_declared')->default(false);
            $table->text('conflict_note')->nullable();
            
            // Verification
            $table->string('vote_hash')->nullable(); // For audit trail integrity
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            
            $table->timestamps();

            $table->unique(['resolution_id', 'board_member_id']);
            $table->index(['resolution_id', 'vote']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('votes');
    }
};
