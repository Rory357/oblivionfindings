<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('family_visit_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->date('requested_date');
            $table->time('preferred_time_start')->nullable();
            $table->time('preferred_time_end')->nullable();
            $table->string('visit_type', 50)->default('in_person'); // in_person, video_call, outing
            $table->text('notes')->nullable();
            $table->string('status', 30)->default('pending'); // pending, approved, declined, cancelled
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'requested_date']);
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('family_visit_requests');
    }
};
