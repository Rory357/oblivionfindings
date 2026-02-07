<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_attendances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('governance_meeting_id')->constrained()->onDelete('cascade');
            $table->foreignId('board_member_id')->constrained()->onDelete('cascade');
            
            // Attendance status
            $table->string('status'); // present, apology, no_show, late
            $table->datetime('marked_at');
            $table->foreignId('marked_by')->constrained('users');
            $table->text('apology_reason')->nullable();
            $table->boolean('arrived_late')->default(false);
            $table->time('arrival_time')->nullable();
            $table->boolean('left_early')->default(false);
            $table->time('departure_time')->nullable();
            
            $table->timestamps();

            $table->unique(['governance_meeting_id', 'board_member_id']);
            $table->index(['governance_meeting_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_attendances');
    }
};
