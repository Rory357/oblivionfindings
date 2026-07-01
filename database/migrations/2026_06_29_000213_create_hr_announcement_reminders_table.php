<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // One row per reminder sent to a recipient for an announcement — powers
        // the "reminded" roster status and the cooldown that prevents spamming.
        Schema::create('hr_announcement_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('announcement_id')->constrained('hr_announcements')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reminded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reminded_at');
            $table->timestamps();

            $table->index(['announcement_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('hr_announcement_reminders');
    }
};
