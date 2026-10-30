<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * eMAR P11 Staff & PINs › PIN status: each "Remind them to set a PIN". Kept
 * so a person is reminded at most once a day and the list can say when and
 * by whom.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('witness_pin_reminders', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reminded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('witness_pin_reminders');
    }
};
