<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A private staff chat keeps a leaver's membership. Each absence is one gap: a re-hired member gets the
// chat back, but never the messages sent between left_at and returned_at (Stephan, 31 Oct 2026).
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('chat_membership_gaps')) {
            return;
        }
        Schema::create('chat_membership_gaps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participant_id')->constrained('ops_conversation_participants')->cascadeOnDelete();
            $table->timestamp('left_at');
            // Null while the person is still away.
            $table->timestamp('returned_at')->nullable();
            $table->timestamps();
            $table->index(['participant_id', 'left_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_membership_gaps');
    }
};
